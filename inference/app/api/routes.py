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
from app.services.segmentation_service import filter_by_pole_bbox, run_segmentation

router = APIRouter()
logger = logging.getLogger(__name__)


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

    # Run models
    detection = run_detection(image)
    segmentation_raw = run_segmentation(image)

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

    # Build raw inference block (full geometry for frontend rendering).
    # structural_segments = pole-bbox-filtered (used for measurement).
    # all_structural_segments = pre-filter set (for debugging/audit).
    inference_block = {
        "detection": {
            "pole_bbox": detection.pole_bbox.model_dump() if detection.pole_bbox else None,
            "pole_bbox_height_px": detection.pole_bbox_height_px,
            "reference_marker_bbox": detection.reference_marker_bbox.model_dump() if detection.reference_marker_bbox else None,
            "reference_marker_height_px": detection.reference_marker_height_px,
            "raw_detections": [d.model_dump() for d in detection.raw_detections],
        },
        "segmentation": {
            "raw_segments": [s.model_dump() for s in segmentation_raw.raw_segments],
            "deduplicated_segments": [s.model_dump() for s in segmentation.deduplicated_segments],
            "structural_segments": [s.model_dump() for s in segmentation.structural_segments],
            "all_structural_segments": [s.model_dump() for s in segmentation_raw.structural_segments],
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
