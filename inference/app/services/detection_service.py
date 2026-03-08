"""
Object detection service.
Runs YOLOv8 detection model to locate pole bbox and reference_marker bbox.
Label names come from registry — nothing hardcoded here.
"""

import logging
from typing import Optional

from PIL import Image

from app.core.registry import (
    get_general_threshold,
    get_iou_threshold,
    get_reference_marker_config,
    get_registry,
    get_singleton_labels,
)
from app.models.yolo_models import get_detection_model
from app.schemas.response import BoundingBox, DetectedObject, DetectionResult

logger = logging.getLogger(__name__)


def _bbox_to_schema(box) -> BoundingBox:
    x1, y1, x2, y2 = float(box[0]), float(box[1]), float(box[2]), float(box[3])
    return BoundingBox(x1=x1, y1=y1, x2=x2, y2=y2, width=x2 - x1, height=y2 - y1)


def _boxes_overlap(b1: list[float], b2: list[float]) -> bool:
    """Return True if two [x1,y1,x2,y2] boxes have any overlap."""
    return max(b1[0], b2[0]) < min(b1[2], b2[2]) and max(b1[1], b2[1]) < min(b1[3], b2[3])


def _deduplicate(detections: list[DetectedObject]) -> list[DetectedObject]:
    """Same label + overlapping bbox → keep highest confidence only."""
    by_label: dict[str, list[DetectedObject]] = {}
    for d in detections:
        by_label.setdefault(d.label, []).append(d)

    result: list[DetectedObject] = []
    for segs in by_label.values():
        sorted_segs = sorted(segs, key=lambda x: x.confidence, reverse=True)
        kept: list[DetectedObject] = []
        for candidate in sorted_segs:
            if not any(_boxes_overlap(candidate.bbox_xyxy, k.bbox_xyxy) for k in kept):
                kept.append(candidate)
        result.extend(kept)
    return result


def _enforce_singleton_labels(
    detections: list[DetectedObject],
    singleton_labels: set[str],
) -> list[DetectedObject]:
    """
    Keep only one object per configured label (highest confidence).
    Other labels are kept as-is.
    """
    if not singleton_labels:
        return detections

    by_label: dict[str, list[DetectedObject]] = {}
    for det in detections:
        by_label.setdefault(det.label, []).append(det)

    result: list[DetectedObject] = []
    for label, group in by_label.items():
        if label in singleton_labels:
            result.append(max(group, key=lambda x: x.confidence))
        else:
            result.extend(group)
    return result


def run_detection(image: Image.Image) -> DetectionResult:
    """Run object detection. Returns pole bbox, reference_marker bbox, all detections."""
    registry = get_registry()
    conf_threshold = get_general_threshold(registry)
    iou_threshold = get_iou_threshold(registry)
    ref_cfg = get_reference_marker_config(registry)
    singleton_labels = get_singleton_labels(registry, "detection")
    pole_label = "pole"
    ref_label = ref_cfg["detection_label"]  # "reference_marker"

    model = get_detection_model()
    results = model.predict(
        source=image,
        conf=conf_threshold,
        iou=iou_threshold,
        verbose=False,
    )

    pole_bbox: Optional[BoundingBox] = None
    pole_bbox_height_px: Optional[float] = None
    pole_conf: float = -1.0
    ref_bbox: Optional[BoundingBox] = None
    ref_height_px: Optional[float] = None
    ref_conf: float = -1.0
    raw_detections: list[DetectedObject] = []

    if not results:
        return DetectionResult()

    result = results[0]
    if result.boxes is None:
        return DetectionResult()

    names = model.names  # {int: str}

    for box in result.boxes:
        cls_id = int(box.cls[0])
        label = names.get(cls_id, str(cls_id))
        confidence = float(box.conf[0])
        xyxy = box.xyxy[0].tolist()

        raw_detections.append(
            DetectedObject(label=label, confidence=confidence, bbox_xyxy=xyxy)
        )

        bbox = _bbox_to_schema(xyxy)

        if label == pole_label:
            # Keep highest-confidence pole bbox if multiple detected
            if pole_bbox is None or confidence > pole_conf:
                pole_bbox = bbox
                pole_bbox_height_px = bbox.height
                pole_conf = confidence

        elif label == ref_label:
            if ref_bbox is None or confidence > ref_conf:
                ref_bbox = bbox
                ref_height_px = bbox.height
                ref_conf = confidence

    deduplicated = _deduplicate(raw_detections)
    final_detections = _enforce_singleton_labels(deduplicated, singleton_labels)

    return DetectionResult(
        pole_bbox=pole_bbox,
        pole_bbox_height_px=pole_bbox_height_px,
        reference_marker_bbox=ref_bbox,
        reference_marker_height_px=ref_height_px,
        raw_detections=final_detections,
    )
