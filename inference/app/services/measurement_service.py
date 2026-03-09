"""
Measurement service.
Implements the complete 13-step measurement flow from the spec.
All rules (pole types, ratios, required segments) come from registry.json.
"""

import logging
import math
from typing import Optional, List

from app.core.registry import (
    get_coverage_ratio_min,
    get_fixed_depth_by_nominal_height_cm,
    get_height_consistency_tolerance_cm,
    get_reference_marker_config,
    get_registry,
    get_pole_types,
)
from app.schemas.response import (
    BoundingBox,
    CoverageCheck,
    DetectionResult,
    HeightConsistencyCheck,
    MeasurementResult,
    SegmentationResult,
    SegmentedObject,
)

logger = logging.getLogger(__name__)


def _calculate_tilt(structural_segments: List["SegmentedObject"]) -> Optional[float]:
    """
    Calculate pole tilt angle relative to image vertical using centerline regression.

    Fits x = m*y + b through structural segment centers (y = independent axis,
    since the pole is near-vertical). Returns the deviation from vertical in degrees.

    Convention: positive = leans right, negative = leans left (viewer's perspective).
    Returns None if fewer than 2 structural segments are available.
    """
    # Use all structural segments (Segmen 1/2/3, Joint_1/2, tapak)
    centers = []
    for s in structural_segments:
        bbox = s.bbox_xyxy
        cx = (bbox[0] + bbox[2]) / 2
        cy = (bbox[1] + bbox[3]) / 2
        centers.append((cx, cy))

    if len(centers) < 2:
        return None

    n = len(centers)
    sum_y  = sum(c[1] for c in centers)
    sum_x  = sum(c[0] for c in centers)
    sum_yy = sum(c[1] ** 2 for c in centers)
    sum_xy = sum(c[0] * c[1] for c in centers)

    denom = n * sum_yy - sum_y ** 2
    if abs(denom) < 1e-9:
        return 0.0  # perfectly vertical — no tilt

    # Regression slope: Δx per Δy
    # m < 0 → pole leans right (as y decreases going up, x increases)
    # We negate so that positive output = lean right (intuitive convention)
    m = (n * sum_xy - sum_x * sum_y) / denom
    tilt_deg = -math.degrees(math.atan(m))
    return round(tilt_deg, 2)


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


def _resolve_nominal_profile(
    pole_type_name: str,
    total_visible_cm: Optional[float],
    fixed_depth_map: dict[str, float],
) -> tuple[Optional[float], Optional[float], Optional[str]]:
    """
    Resolve nominal pole height (cm) and fixed underground depth (cm).

    Rules requested:
    - 7m pole => depth 1.4m (140cm), can be 2 or 3 segments
    - 9m pole => depth 1.8m (180cm), only 2 segments
    """
    depth_700 = float(fixed_depth_map.get("700", 140.0))
    depth_900 = float(fixed_depth_map.get("900", 180.0))

    if pole_type_name == "3-segmen":
        return 700.0, depth_700, "7m"

    # 2-segmen can be 7m or 9m; choose nearest profile if visible cm exists.
    if total_visible_cm is not None:
        vis_target_700 = 700.0 - depth_700
        vis_target_900 = 900.0 - depth_900
        if abs(total_visible_cm - vis_target_900) < abs(total_visible_cm - vis_target_700):
            return 900.0, depth_900, "9m"

    return 700.0, depth_700, "7m"


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
    coverage_ratio_min = get_coverage_ratio_min(registry)
    fixed_depth_map = get_fixed_depth_by_nominal_height_cm(registry)
    height_tol_cm = get_height_consistency_tolerance_cm(registry)
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

    # Step 7–9 — Fallback policy
    # Trigger 1: coverage ratio guard
    # Trigger 2: Segmen 1 or Segmen 2 not detected (critical structural segments)
    measurement_method = "segmentation"
    total_visible_px: float

    coverage_check = CoverageCheck(
        coverage_ratio=None,
        threshold=coverage_ratio_min,
        is_partial_coverage=None,
        warning=None,
    )

    # Trigger 2: missing critical segments
    # - Segmen 1 or Segmen 2 always required
    # - Segmen 3 required if Joint_2 detected (implies 3-segmen pole)
    critical_missing = [s for s in ["Segmen 1", "Segmen 2"] if s not in structural_detected]
    if "Joint_2" in structural_detected and "Segmen 3" not in structural_detected:
        critical_missing.append("Segmen 3")

    # Trigger 1: coverage ratio guard
    if pole_bbox_height_px and pole_bbox_height_px > 0:
        coverage_ratio = segmentation_height_px / pole_bbox_height_px
        is_partial = coverage_ratio < coverage_ratio_min

        if is_partial or critical_missing:
            unaccounted_px = pole_bbox_height_px - segmentation_height_px
            unaccounted_pct = (1 - coverage_ratio) * 100 if pole_bbox_height_px > 0 else 0

            if critical_missing and not is_partial:
                warning = (
                    f"Segmen tidak terdeteksi: {', '.join(critical_missing)}. "
                    f"Measurement redirected to bbox detection."
                )
            elif critical_missing and is_partial:
                warning = (
                    f"Segments only cover {coverage_ratio * 100:.0f}% of pole bbox. "
                    f"~{unaccounted_px:.0f}px ({unaccounted_pct:.0f}%) unaccounted. "
                    f"Segmen tidak terdeteksi: {', '.join(critical_missing)}. "
                    f"Measurement redirected to bbox detection."
                )
            else:
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
            logger.info(
                "Trigger fired — coverage %.2f < %.2f, critical_missing=%s",
                coverage_ratio, coverage_ratio_min, critical_missing,
            )
        else:
            coverage_check = CoverageCheck(
                coverage_ratio=round(coverage_ratio, 4),
                threshold=coverage_ratio_min,
                is_partial_coverage=False,
                warning=None,
            )
            total_visible_px = segmentation_height_px
            if missing_segments:
                coverage_check.warning = (
                    "Required segments are incomplete, but coverage is still acceptable. "
                    "Staying in segmentation mode."
                )
    else:
        # No pole bbox available — use segmentation sum
        total_visible_px = segmentation_height_px
        if critical_missing:
            measurement_method = "detection_bbox_fallback"
            coverage_check.warning = (
                f"Segmen tidak terdeteksi: {', '.join(critical_missing)}. "
                f"Pole bbox unavailable — measurement accuracy may be limited."
            )
            logger.info("Trigger fired — critical_missing=%s, no pole bbox", critical_missing)
        elif missing_segments:
            coverage_check.warning = (
                "Required segments are incomplete, and pole bbox is unavailable. "
                "Staying in segmentation mode."
            )

    # Step 10b — Tilt angle (pure geometry, uses structural_segments regardless of method)
    tilt_angle_deg = _calculate_tilt(structural_segments)

    # Step 11 — Unit conversion
    scale_cm_per_px: Optional[float] = None
    total_visible_cm: Optional[float] = None
    underground_depth_cm: Optional[float] = None
    total_pole_cm: Optional[float] = None

    ref_height_px = detection.reference_marker_height_px
    if ref_height_px and ref_height_px > 0 and reference_marker_cm:
        scale_cm_per_px = reference_marker_cm / ref_height_px
        total_visible_cm = round(total_visible_px * scale_cm_per_px, 2)

    # Step 11b — Joint height correction (segmentation mode only)
    # If all structural segments of a pole type are visible but a joint is missing,
    # add 20cm per missing joint to compensate for the undetected connector piece.
    # Conditions:
    #   2-segment: Segmen 1 + Segmen 2 present, Joint_1 missing → +20cm
    #   3-segment: Segmen 1 + 2 + 3 all present, Joint_1 and/or Joint_2 missing → +20cm each
    JOINT_HEIGHT_CM = 20.0
    joint_correction_cm = 0.0

    if measurement_method == "segmentation" and total_visible_cm is not None:
        has_seg1   = "Segmen 1" in structural_detected
        has_seg2   = "Segmen 2" in structural_detected
        has_seg3   = "Segmen 3" in structural_detected
        has_joint1 = "Joint_1"  in structural_detected
        has_joint2 = "Joint_2"  in structural_detected

        # 2-segment pole: both segments present, Joint_1 absent
        if has_seg1 and has_seg2 and not has_seg3 and not has_joint1:
            joint_correction_cm += JOINT_HEIGHT_CM

        # 3-segment pole: all 3 segments present, missing joints
        if has_seg1 and has_seg2 and has_seg3:
            if not has_joint1:
                joint_correction_cm += JOINT_HEIGHT_CM
            if not has_joint2:
                joint_correction_cm += JOINT_HEIGHT_CM

        if joint_correction_cm > 0:
            total_visible_cm = round(total_visible_cm + joint_correction_cm, 2)
            if scale_cm_per_px and scale_cm_per_px > 0:
                total_visible_px += joint_correction_cm / scale_cm_per_px
            logger.info("Joint correction +%.0fcm applied (segmentation mode)", joint_correction_cm)

    # Step 12 — Fixed underground depth by nominal pole profile
    nominal_height_cm, fixed_depth_cm, nominal_profile = _resolve_nominal_profile(
        pole_type_name=pole_type_name,
        total_visible_cm=total_visible_cm,
        fixed_depth_map=fixed_depth_map,
    )

    underground_depth_px: Optional[float] = None
    if scale_cm_per_px and fixed_depth_cm is not None and scale_cm_per_px > 0:
        underground_depth_px = fixed_depth_cm / scale_cm_per_px
    underground_depth_cm = round(fixed_depth_cm, 2) if fixed_depth_cm is not None else None

    if underground_depth_px is not None:
        total_pole_px = total_visible_px + underground_depth_px
    else:
        total_pole_px = total_visible_px

    if total_visible_cm is not None and fixed_depth_cm is not None:
        total_pole_cm = round(total_visible_cm + fixed_depth_cm, 2)

    # Step 13 — Suspicious check against nominal type height
    height_check_warning = None
    is_suspicious: Optional[bool] = None
    if total_pole_cm is not None and nominal_height_cm is not None:
        is_suspicious = abs(total_pole_cm - nominal_height_cm) > height_tol_cm
        if is_suspicious:
            height_check_warning = (
                f"Estimasi total {total_pole_cm:.2f}cm tidak sesuai profil {nominal_profile} "
                f"({nominal_height_cm:.0f}cm) dengan toleransi {height_tol_cm:.1f}cm."
            )
    elif nominal_height_cm is not None:
        height_check_warning = "Tidak dapat validasi konsistensi tinggi nominal karena skala cm/px tidak tersedia."

    height_consistency_check = HeightConsistencyCheck(
        nominal_height_cm=nominal_height_cm,
        fixed_depth_cm=round(fixed_depth_cm, 2) if fixed_depth_cm is not None else None,
        estimated_total_cm=total_pole_cm,
        tolerance_cm=height_tol_cm,
        is_suspicious=is_suspicious,
        warning=height_check_warning,
    )

    return MeasurementResult(
        pole_type=pole_type_name,
        required_segments=required_segments,
        detected_labels=sorted(detected_labels),
        missing_segments=missing_segments,
        measurement_method=measurement_method,
        total_visible_px=round(total_visible_px, 2),
        underground_depth_px=round(underground_depth_px, 2) if underground_depth_px is not None else None,
        total_pole_px=round(total_pole_px, 2),
        scale_cm_per_px=round(scale_cm_per_px, 6) if scale_cm_per_px else None,
        total_visible_cm=total_visible_cm,
        underground_depth_cm=underground_depth_cm,
        total_pole_cm=total_pole_cm,
        coverage_check=coverage_check,
        height_consistency_check=height_consistency_check,
        pole_bbox=detection.pole_bbox,
        tilt_angle_deg=tilt_angle_deg,
    )
