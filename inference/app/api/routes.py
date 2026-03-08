"""
/infer endpoint — the only public-facing route of the inference service.
Accessible only from internal Docker network.
"""

import logging
import urllib.parse
from io import BytesIO

import httpx
from fastapi import APIRouter, Depends, HTTPException
from PIL import Image

from app.core.config import get_settings
from app.core.security import validate_service_token
from app.schemas.request import InferenceRequest
from app.schemas.response import ImageMeta, InferenceResponse
from app.services.compliance_service import check_compliance
from app.services.detection_service import run_detection
from app.services.measurement_service import calculate_measurements
from app.services.overlay_service import build_overlay_data_url
from app.services.segmentation_service import (
    filter_by_pole_bbox,
    offset_segmentation_coordinates,
    run_segmentation,
)

router = APIRouter()
logger = logging.getLogger(__name__)
VALID_SEGMENTATION_MODES = {"auto", "pole_roi", "full_image"}


def _crop_to_pole_roi(
    image: Image.Image,
    pole_bbox,
) -> tuple[Image.Image, int, int, dict]:
    """
    Crop image to pole detection ROI.
    Returns (cropped_image, offset_x, offset_y, roi_meta) in original coordinates.
    If bbox is invalid, returns the original image with zero offset.
    """
    if pole_bbox is None:
        return image, 0, 0, {
            "used_pole_roi": False,
            "reason": "pole_bbox_not_found",
            "roi_bbox_xyxy": None,
            "roi_size": None,
        }

    width, height = image.size
    x1_raw = max(0, min(width, int(pole_bbox.x1)))
    y1_raw = max(0, min(height, int(pole_bbox.y1)))
    x2_raw = max(0, min(width, int(pole_bbox.x2)))
    y2_raw = max(0, min(height, int(pole_bbox.y2)))

    pole_w = max(0, x2_raw - x1_raw)
    pole_h = max(0, y2_raw - y1_raw)

    # Keep ROI anchored on pole class, but add context margin so segmentation
    # does not lose upper/lower segments due overly-tight crop.
    x_margin = max(int(pole_w * 2.5), 60)
    y_margin = max(int(pole_h * 0.05), 20)

    x1 = max(0, x1_raw - x_margin)
    y1 = max(0, y1_raw - y_margin)
    x2 = min(width, x2_raw + x_margin)
    y2 = min(height, y2_raw + y_margin)

    if x2 <= x1 or y2 <= y1:
        logger.warning(
            "Invalid pole bbox for ROI crop: x1=%s y1=%s x2=%s y2=%s. Using full image.",
            pole_bbox.x1,
            pole_bbox.y1,
            pole_bbox.x2,
            pole_bbox.y2,
        )
        return image, 0, 0, {
            "used_pole_roi": False,
            "reason": "invalid_pole_bbox",
            "roi_bbox_xyxy": None,
            "roi_size": None,
        }

    return image.crop((x1, y1, x2, y2)), x1, y1, {
        "used_pole_roi": True,
        "reason": "pole_bbox",
        "roi_bbox_xyxy": [x1, y1, x2, y2],
        "pole_bbox_xyxy": [x1_raw, y1_raw, x2_raw, y2_raw],
        "roi_size": {"width": x2 - x1, "height": y2 - y1},
        "roi_margin": {"x": x_margin, "y": y_margin},
    }


def _build_pole_identity_status(detection) -> dict:
    """
    Build business-status marker based on detection presence:
    - pole detected, reference marker missing -> not a Telkom pole
    - pole missing, reference marker missing -> both not detected
    """
    pole_detected = detection.pole_bbox is not None
    marker_detected = detection.reference_marker_bbox is not None

    if not pole_detected and not marker_detected:
        return {
            "code": "pole_and_flag_not_detected",
            "message": "tiang dan bendera tidak terdeteksi",
        }

    if pole_detected and not marker_detected:
        return {
            "code": "non_telkom_pole",
            "message": "tiang bukan tiang telkom",
        }

    return {
        "code": "telkom_pole_detected",
        "message": "tiang telkom terdeteksi",
    }


def _validate_image_url(url: str) -> None:
    """
    SSRF protection: only allow downloads from whitelisted domains.
    The whitelist is configured via ALLOWED_IMAGE_DOMAINS env var (in Laravel).
    Inference only runs on URLs already validated by the worker, but we add
    a secondary parse check here as defense-in-depth.
    """
    parsed = urllib.parse.urlparse(url)
    if parsed.scheme not in ("http", "https"):
        raise HTTPException(status_code=400, detail="Invalid image URL scheme")
    if not parsed.netloc:
        raise HTTPException(status_code=400, detail="Invalid image URL")


async def _download_image(url: str) -> Image.Image:
    """Download image with timeout and size limit."""
    async with httpx.AsyncClient(timeout=30, follow_redirects=False) as client:
        resp = await client.get(url)
        if resp.status_code != 200:
            raise HTTPException(
                status_code=502,
                detail=f"Failed to download image: HTTP {resp.status_code}",
            )
        content_type = resp.headers.get("content-type", "")
        if not content_type.startswith("image/"):
            raise HTTPException(
                status_code=422, detail=f"URL did not return an image (content-type: {content_type})"
            )
        return Image.open(BytesIO(resp.content)).convert("RGB")


@router.post("/infer", response_model=InferenceResponse)
async def infer(
    request: InferenceRequest,
    _token: dict = Depends(validate_service_token),
) -> InferenceResponse:
    """
    Full inference pipeline:
    1. Download image
    2. Run detection (pole bbox, reference_marker)
    3. Run segmentation (segments with masks)
    4. Measurement (13-step flow)
    5. Compliance check
    """
    logger.info("Inference request %s for photo %s", request.request_id, request.photo_id)

    _validate_image_url(request.image_url)

    try:
        image = await _download_image(request.image_url)
    except HTTPException:
        raise
    except Exception as exc:
        logger.error("Image download error: %s", exc)
        raise HTTPException(status_code=502, detail=f"Image download failed: {exc}")

    width, height = image.size
    image_meta = ImageMeta(width=width, height=height, channels=3)

    # Run detection on full image.
    detection = run_detection(image)

    requested_mode = (
        (request.segmentation_mode or (request.metadata or {}).get("segmentation_mode") or "auto")
        .strip()
        .lower()
    )
    if requested_mode not in VALID_SEGMENTATION_MODES:
        logger.warning("Unknown segmentation_mode '%s' for photo %s; defaulting to auto", requested_mode, request.photo_id)
        requested_mode = "auto"

    # Run segmentation based on requested mode.
    if requested_mode == "full_image":
        segmentation_raw = run_segmentation(image)
        roi_meta = {
            "requested_mode": requested_mode,
            "applied_mode": "full_image",
            "used_pole_roi": False,
            "reason": "forced_full_image",
            "roi_bbox_xyxy": None,
            "roi_size": None,
        }
    else:
        roi_image, roi_x, roi_y, roi_meta = _crop_to_pole_roi(image, detection.pole_bbox)
        segmentation_roi = run_segmentation(roi_image)
        segmentation_raw = offset_segmentation_coordinates(segmentation_roi, roi_x, roi_y)
        roi_meta["requested_mode"] = requested_mode
        if requested_mode == "pole_roi":
            roi_meta["applied_mode"] = "pole_roi" if roi_meta.get("used_pole_roi") else "full_image"
        else:
            roi_meta["applied_mode"] = "pole_roi" if roi_meta.get("used_pole_roi") else "full_image_auto_fallback"

    # Filter out segments belonging to other poles in the same photo.
    # Uses the object-detection pole_bbox as the spatial boundary:
    # any segment whose horizontal centroid falls outside the pole bbox
    # (plus margin) is excluded from measurement.
    segmentation = filter_by_pole_bbox(segmentation_raw, detection.pole_bbox)

    # Measurement
    measurement = calculate_measurements(
        detection=detection,
        segmentation=segmentation,
        reference_marker_cm=request.reference_marker_cm,
    )

    # Compliance
    compliance = check_compliance(segmentation)

    overlay_data_url = None
    try:
        overlay_data_url = build_overlay_data_url(
            image=image,
            detection=detection,
            segmentation=segmentation,
            measurement=measurement,
        )
    except Exception as exc:
        logger.error("Overlay rendering failed: %s", exc)

    # Build raw inference block (full geometry for frontend rendering).
    # structural_segments = pole-bbox-filtered (used for measurement).
    # all_structural_segments = pre-filter set (for debugging/audit).
    identity_status = _build_pole_identity_status(detection)
    overlay_labels: list[str]
    if measurement.measurement_method == "detection_bbox_fallback":
        overlay_labels = [d.label for d in detection.raw_detections]
    else:
        overlay_labels = [s.label for s in segmentation.structural_segments]
        has_ref = any("reference" in lbl.lower() or "marker" in lbl.lower() or lbl.lower() == "bendera" for lbl in overlay_labels)
        if not has_ref and detection.reference_marker_bbox is not None:
            overlay_labels.append("reference_marker")

    inference_block = {
        "detection": {
            "pole_bbox": detection.pole_bbox.model_dump() if detection.pole_bbox else None,
            "pole_bbox_height_px": detection.pole_bbox_height_px,
            "reference_marker_bbox": detection.reference_marker_bbox.model_dump() if detection.reference_marker_bbox else None,
            "reference_marker_height_px": detection.reference_marker_height_px,
            "pole_identity_status": identity_status,
            "raw_detection_count": len(detection.raw_detections),
            "raw_detections": [d.model_dump() for d in detection.raw_detections],
        },
        "segmentation": {
            "input": roi_meta,
            "raw_segment_count": len(segmentation_raw.raw_segments),
            "deduplicated_segment_count": len(segmentation.deduplicated_segments),
            "structural_segment_count": len(segmentation.structural_segments),
            "raw_segments": [s.model_dump() for s in segmentation_raw.raw_segments],
            "deduplicated_segments": [s.model_dump() for s in segmentation.deduplicated_segments],
            "structural_segments": [s.model_dump() for s in segmentation.structural_segments],
            "all_structural_segments": [s.model_dump() for s in segmentation_raw.structural_segments],
        },
        "overlay": {
            "mode": measurement.measurement_method,
            "overlay_count": len(overlay_labels),
            "overlay_labels": overlay_labels,
            "image_data_url": overlay_data_url,
            "format": "image/png",
        },
    }

    return InferenceResponse(
        request_id=request.request_id,
        photo_id=request.photo_id,
        status="ok",
        inference=inference_block,
        measurement=measurement,
        compliance=compliance,
        image_meta=image_meta,
    )
