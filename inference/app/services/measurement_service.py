"""
Measurement service.
Implements the complete 13-step measurement flow from the spec.
All rules (pole types, ratios, required segments) come from registry.json.
"""

import logging
from typing import Optional

from app.core.registry import (
    get_registry,
    get_pole_types,
    get_structural_labels,
    get_underground_ratio,
    get_coverage_ratio_min,
    get_reference_marker_config,
)
from app.schemas.response import (
    BoundingBox,
    CoverageCheck,
    DetectionResult,
    MeasurementResult,
    SegmentationResult,
    SegmentedObject,
)

logger = logging.getLogger(__name__)


def _infer_pole_type(detected_labels: set[str], registry: dict) -> dict:
    """
    Pole type inferred from detected segment labels per spec:
    IF "Segmen 3" OR "Joint_2" present → 3-segmen
    ELSE → 2-segmen
    Indicators and required_segments come from registry.
    """
    pole_types = get_pole_types(registry)
    for pt in pole_types:
        indicators = pt.get("indicators", [])
        if indicators and any(ind in detected_labels for ind in indicators):
            return pt
    # Default to 2-segmen (first entry with no indicators)
    return next(pt for pt in pole_types if not pt.get("indicators"))


def calculate_measurements(
    detection: DetectionResult,
    segmentation: SegmentationResult,
    reference_marker_cm: Optional[float],
) -> MeasurementResult:
    """
    Execute the full measurement flow defined in the spec (steps 5–13).
    Steps 1–4 (detection, segmentation, filtering, deduplication) are done upstream.
    """
    registry = get_registry()
    underground_ratio = get_underground_ratio(registry)
    coverage_ratio_min = get_coverage_ratio_min(registry)
    ref_cfg = get_reference_marker_config(registry)

    structural_segments: list[SegmentedObject] = segmentation.structural_segments
    detected_labels: set[str] = {s.label for s in segmentation.deduplicated_segments}

    # Step 5 — Infer pole type
    pole_type_cfg = _infer_pole_type(detected_labels, registry)
    pole_type_name: str = pole_type_cfg["name"]
    required_segments: list[str] = pole_type_cfg["required_segments"]

    # Step 6 — Determine missing segments
    structural_detected = {s.label for s in structural_segments}
    missing_segments = [r for r in required_segments if r not in structural_detected]

    pole_bbox_height_px: Optional[float] = detection.pole_bbox_height_px
    segmentation_height_px = sum(s.height_px for s in structural_segments)

    # Step 7–8 — Trigger 1: missing required segments
    measurement_method = "segmentation"
    total_visible_px: float

    coverage_check = CoverageCheck(
        coverage_ratio=None,
        threshold=coverage_ratio_min,
        is_partial_coverage=None,
        warning=None,
    )

    if missing_segments:
        measurement_method = "detection_bbox_fallback"
        total_visible_px = pole_bbox_height_px if pole_bbox_height_px else segmentation_height_px
        logger.info(
            "Trigger 1 fired — missing segments: %s. Using bbox fallback.", missing_segments
        )
    else:
        # Step 9 — Trigger 2: coverage ratio guard
        if pole_bbox_height_px and pole_bbox_height_px > 0:
            coverage_ratio = segmentation_height_px / pole_bbox_height_px
            is_partial = coverage_ratio < coverage_ratio_min

            if is_partial:
                unaccounted_px = pole_bbox_height_px - segmentation_height_px
                unaccounted_pct = (1 - coverage_ratio) * 100
                warning = (
                    f"Segments only cover {coverage_ratio * 100:.0f}% of pole bbox. "
                    f"~{unaccounted_px:.0f}px ({unaccounted_pct:.0f}%) unaccounted. "
                    f"Possible undetected upper segments. Measurement redirected to bbox detection."
                )
                coverage_check = CoverageCheck(
                    coverage_ratio=round(coverage_ratio, 4),
                    threshold=coverage_ratio_min,
                    is_partial_coverage=True,
                    warning=warning,
                )
                measurement_method = "detection_bbox_fallback"
                total_visible_px = pole_bbox_height_px
                logger.info("Trigger 2 fired — coverage %.2f < %.2f", coverage_ratio, coverage_ratio_min)
            else:
                coverage_check = CoverageCheck(
                    coverage_ratio=round(coverage_ratio, 4),
                    threshold=coverage_ratio_min,
                    is_partial_coverage=False,
                    warning=None,
                )
                total_visible_px = segmentation_height_px
        else:
            # No pole bbox available — use segmentation sum
            total_visible_px = segmentation_height_px

    # Step 10 — Underground and total
    underground_depth_px = total_visible_px * underground_ratio
    total_pole_px = total_visible_px + underground_depth_px

    # Step 11 — Unit conversion
    scale_cm_per_px: Optional[float] = None
    total_visible_cm: Optional[float] = None
    underground_depth_cm: Optional[float] = None
    total_pole_cm: Optional[float] = None

    ref_height_px = detection.reference_marker_height_px
    if ref_height_px and ref_height_px > 0 and reference_marker_cm:
        scale_cm_per_px = reference_marker_cm / ref_height_px
        total_visible_cm = round(total_visible_px * scale_cm_per_px, 2)
        underground_depth_cm = round(underground_depth_px * scale_cm_per_px, 2)
        total_pole_cm = round(total_pole_px * scale_cm_per_px, 2)

    return MeasurementResult(
        pole_type=pole_type_name,
        required_segments=required_segments,
        detected_labels=sorted(detected_labels),
        missing_segments=missing_segments,
        measurement_method=measurement_method,
        total_visible_px=round(total_visible_px, 2),
        underground_depth_px=round(underground_depth_px, 2),
        total_pole_px=round(total_pole_px, 2),
        scale_cm_per_px=round(scale_cm_per_px, 6) if scale_cm_per_px else None,
        total_visible_cm=total_visible_cm,
        underground_depth_cm=underground_depth_cm,
        total_pole_cm=total_pole_cm,
        coverage_check=coverage_check,
        pole_bbox=detection.pole_bbox,
    )
