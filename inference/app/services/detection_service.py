"""
Object detection service.
Runs YOLOv8 detection model to locate pole bbox and reference_marker bbox.
Label names come from registry — nothing hardcoded here.
"""

import logging
from typing import Optional

import numpy as np
from PIL import Image

from app.core.config import get_settings
from app.core.registry import get_registry, get_reference_marker_config, get_general_threshold, get_iou_threshold
from app.models.yolo_models import get_detection_model
from app.schemas.response import BoundingBox, DetectedObject, DetectionResult

logger = logging.getLogger(__name__)


def _bbox_to_schema(box) -> BoundingBox:
    x1, y1, x2, y2 = float(box[0]), float(box[1]), float(box[2]), float(box[3])
    return BoundingBox(x1=x1, y1=y1, x2=x2, y2=y2, width=x2 - x1, height=y2 - y1)


def run_detection(image: Image.Image) -> DetectionResult:
    """Run object detection. Returns pole bbox, reference_marker bbox, all detections."""
    registry = get_registry()
    s = get_settings()
    conf_threshold = get_general_threshold(registry)
    iou_threshold = get_iou_threshold(registry)
    ref_cfg = get_reference_marker_config(registry)
    pole_label = "pole"
    ref_label = ref_cfg["detection_label"]  # "reference_marker"

    model = get_detection_model()
    results = model.predict(
        source=np.array(image),
        conf=conf_threshold,
        iou=iou_threshold,
        verbose=False,
    )

    pole_bbox: Optional[BoundingBox] = None
    pole_bbox_height_px: Optional[float] = None
    ref_bbox: Optional[BoundingBox] = None
    ref_height_px: Optional[float] = None
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
            if pole_bbox is None or confidence > (pole_bbox_height_px or 0):
                pole_bbox = bbox
                pole_bbox_height_px = bbox.height

        elif label == ref_label:
            if ref_bbox is None or confidence > (ref_height_px or 0):
                ref_bbox = bbox
                ref_height_px = bbox.height

    return DetectionResult(
        pole_bbox=pole_bbox,
        pole_bbox_height_px=pole_bbox_height_px,
        reference_marker_bbox=ref_bbox,
        reference_marker_height_px=ref_height_px,
        raw_detections=raw_detections,
    )
