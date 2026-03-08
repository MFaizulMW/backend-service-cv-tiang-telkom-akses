"""
Segmentation service.
Runs YOLOv8 instance segmentation to detect pole segments.
Applies confidence filtering, deduplication, and outputs per-segment polygons.
All label logic driven by registry.json.
"""

import logging
from typing import Any, Optional

from PIL import Image

from app.core.config import get_settings
from app.core.registry import (
    get_iou_threshold,
    get_registry,
    get_safety_labels,
    get_singleton_labels,
    get_structural_labels,
)
from app.models.yolo_models import get_segmentation_model
from app.schemas.response import SegmentedObject, SegmentationResult

logger = logging.getLogger(__name__)


def _boxes_overlap(b1: list[float], b2: list[float]) -> bool:
    """Return True if two [x1,y1,x2,y2] boxes have any overlap (area > 0)."""
    ix1 = max(b1[0], b2[0])
    iy1 = max(b1[1], b2[1])
    ix2 = min(b1[2], b2[2])
    iy2 = min(b1[3], b2[3])
    return ix1 < ix2 and iy1 < iy2


def _deduplicate(segments: list[SegmentedObject]) -> list[SegmentedObject]:
    """
    Per spec: same label + overlapping bbox → keep highest confidence.
    Same label + non-overlapping bbox → keep both.
    """
    by_label: dict[str, list[SegmentedObject]] = {}
    for seg in segments:
        by_label.setdefault(seg.label, []).append(seg)

    result: list[SegmentedObject] = []
    for label, segs in by_label.items():
        # Sort by confidence descending — greedy keep
        sorted_segs = sorted(segs, key=lambda x: x.confidence, reverse=True)
        kept: list[SegmentedObject] = []
        for candidate in sorted_segs:
            overlaps_any = any(
                _boxes_overlap(candidate.bbox_xyxy, k.bbox_xyxy) for k in kept
            )
            if not overlaps_any:
                kept.append(candidate)
        result.extend(kept)
    return result


def _get_confidence_threshold(label: str, registry: dict) -> float:
    """Return per-label confidence threshold; fall back to general threshold."""
    for safety in get_safety_labels(registry):
        if safety["label"] == label:
            return safety["confidence_threshold"]
    return registry["confidence_thresholds"]["general"]


def _enforce_singleton_labels(
    segments: list[SegmentedObject],
    singleton_labels: set[str],
) -> list[SegmentedObject]:
    """
    Keep one object per configured label (highest confidence), keep all others.
    """
    if not singleton_labels:
        return segments

    by_label: dict[str, list[SegmentedObject]] = {}
    for seg in segments:
        by_label.setdefault(seg.label, []).append(seg)

    result: list[SegmentedObject] = []
    for label, group in by_label.items():
        if label in singleton_labels:
            result.append(max(group, key=lambda x: x.confidence))
        else:
            result.extend(group)
    return result


def run_segmentation(image: Image.Image) -> SegmentationResult:
    """Run segmentation; returns raw, deduplicated, and structural segments."""
    registry = get_registry()
    structural_labels = get_structural_labels(registry)
    singleton_labels = get_singleton_labels(registry, "segmentation")
    iou_threshold = get_iou_threshold(registry)

    model = get_segmentation_model()
    results = model.predict(
        source=image,
        conf=0.01,          # very low — we filter manually per label
        iou=iou_threshold,
        verbose=False,
    )

    if not results:
        return SegmentationResult()

    result = results[0]
    if result.boxes is None:
        return SegmentationResult()

    names = model.names
    raw: list[SegmentedObject] = []

    for i, box in enumerate(result.boxes):
        cls_id = int(box.cls[0])
        label = names.get(cls_id, str(cls_id))
        confidence = float(box.conf[0])

        # Per-label confidence filter
        threshold = _get_confidence_threshold(label, registry)
        if confidence < threshold:
            continue

        xyxy = box.xyxy[0].tolist()
        x1, y1, x2, y2 = xyxy
        height_px = y2 - y1

        # Extract mask polygon
        mask_polygon: list[list[float]] = []
        if result.masks is not None:
            try:
                xy = result.masks.xy[i]
                mask_polygon = xy.tolist() if xy is not None else []
            except (IndexError, AttributeError):
                pass

        raw.append(
            SegmentedObject(
                label=label,
                confidence=confidence,
                bbox_xyxy=xyxy,
                mask_polygon=mask_polygon,
                height_px=height_px,
            )
        )

    deduplicated = _deduplicate(raw)
    structural = [s for s in deduplicated if s.label in structural_labels]

    return SegmentationResult(
        raw_segments=raw,
        deduplicated_segments=deduplicated,
        structural_segments=structural,
    )


def filter_by_pole_bbox(
    segmentation: SegmentationResult,
    pole_bbox: Optional[Any],
    margin_ratio: float = 0.5,
) -> SegmentationResult:
    """
    Filter deduplicated and structural segments to only those whose horizontal
    centroid falls within the target pole's detection bbox (plus margin).

    This prevents segments from other poles in the same photo from being
    included in the measurement of the target pole.

    margin_ratio: fraction of pole_bbox width added as tolerance on each side.
                  Default 0.5 = 50% of pole width, handles segments that
                  slightly exceed the detection bbox.

    Returns a new SegmentationResult; raw_segments is kept unfiltered.
    If pole_bbox is None, returns the original segmentation unchanged.
    """
    if pole_bbox is None:
        return segmentation

    pole_width = pole_bbox.x2 - pole_bbox.x1
    margin = max(pole_width * margin_ratio, 20.0)  # at least 20px tolerance
    x_min = pole_bbox.x1 - margin
    x_max = pole_bbox.x2 + margin

    def in_pole_range(seg: SegmentedObject) -> bool:
        cx = (seg.bbox_xyxy[0] + seg.bbox_xyxy[2]) / 2.0
        return x_min <= cx <= x_max

    registry = get_registry()
    singleton_labels = get_singleton_labels(registry, "segmentation")

    filtered_dedup = [s for s in segmentation.deduplicated_segments if in_pole_range(s)]
    filtered_dedup = _enforce_singleton_labels(filtered_dedup, singleton_labels)
    filtered_structural = [s for s in filtered_dedup if s.label in get_structural_labels(registry)]

    excluded = len(segmentation.structural_segments) - len(filtered_structural)
    if excluded:
        logger.info(
            "pole_bbox_filter: excluded %d structural segment(s) outside x=[%.1f, %.1f] "
            "(pole_bbox x=[%.1f, %.1f] margin=%.1fpx)",
            excluded, x_min, x_max, pole_bbox.x1, pole_bbox.x2, margin,
        )

    return SegmentationResult(
        raw_segments=segmentation.raw_segments,
        deduplicated_segments=filtered_dedup,
        structural_segments=filtered_structural,
    )


def offset_segmentation_coordinates(
    segmentation: SegmentationResult,
    offset_x: float,
    offset_y: float,
) -> SegmentationResult:
    """
    Translate segmentation geometry from cropped-ROI coordinates back to
    original-image coordinates.
    """
    def offset_segment(seg: SegmentedObject) -> SegmentedObject:
        x1, y1, x2, y2 = seg.bbox_xyxy
        shifted_bbox = [x1 + offset_x, y1 + offset_y, x2 + offset_x, y2 + offset_y]
        shifted_polygon = [[x + offset_x, y + offset_y] for x, y in seg.mask_polygon]
        return SegmentedObject(
            label=seg.label,
            confidence=seg.confidence,
            bbox_xyxy=shifted_bbox,
            mask_polygon=shifted_polygon,
            height_px=seg.height_px,
        )

    return SegmentationResult(
        raw_segments=[offset_segment(s) for s in segmentation.raw_segments],
        deduplicated_segments=[offset_segment(s) for s in segmentation.deduplicated_segments],
        structural_segments=[offset_segment(s) for s in segmentation.structural_segments],
    )
