"""
Compliance service.
Checks for safety violations (e.g., Batas gali) using thresholds from registry.
"""

from app.core.registry import get_registry, get_safety_labels
from app.schemas.response import ComplianceResult, SegmentationResult


def check_compliance(segmentation: SegmentationResult) -> ComplianceResult:
    """
    Step 12: If any safety label detected above its threshold → is_compliant = False.
    Thresholds come from registry safety_labels — e.g., Batas gali at 0.30.
    """
    registry = get_registry()
    safety_labels = get_safety_labels(registry)

    notes: list[str] = []
    is_compliant = True
    batas_gali_detected = False
    batas_gali_confidence: float | None = None

    # Build lookup: label → detected segment
    detected_map: dict[str, list] = {}
    for seg in segmentation.deduplicated_segments:
        detected_map.setdefault(seg.label, []).append(seg)

    for safety in safety_labels:
        label = safety["label"]
        threshold = safety["confidence_threshold"]

        if label in detected_map:
            # Find highest confidence detection for this safety label
            best = max(detected_map[label], key=lambda s: s.confidence)
            if best.confidence >= threshold:
                is_compliant = False
                notes.append(
                    f"'{label}' detected with confidence {best.confidence:.2f} "
                    f"(threshold: {threshold}). Pole is non-compliant."
                )
                if label == "Batas gali":
                    batas_gali_detected = True
                    batas_gali_confidence = round(best.confidence, 4)

    if is_compliant:
        notes.append("No safety violations detected.")

    return ComplianceResult(
        is_compliant=is_compliant,
        batas_gali_detected=batas_gali_detected,
        batas_gali_confidence=batas_gali_confidence,
        notes=notes,
    )
