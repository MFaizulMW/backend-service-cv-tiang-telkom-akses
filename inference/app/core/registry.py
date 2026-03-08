"""
Loads and exposes registry.json at startup.
All detection/measurement rules come from here — nothing is hardcoded.
"""

import json
from functools import lru_cache
from pathlib import Path

from app.core.config import get_settings


@lru_cache
def get_registry() -> dict:
    path = Path(get_settings().registry_path)
    with path.open() as f:
        return json.load(f)


def get_structural_labels(registry: dict) -> set[str]:
    return set(registry["measurement_rules"]["structural_labels"])


def get_safety_labels(registry: dict) -> list[dict]:
    return registry["measurement_rules"]["safety_labels"]


def get_pole_types(registry: dict) -> list[dict]:
    return registry["measurement_rules"]["pole_types"]


def get_underground_ratio(registry: dict) -> float:
    return registry["measurement_rules"]["underground_ratio"]


def get_coverage_ratio_min(registry: dict) -> float:
    return registry["measurement_rules"]["coverage_ratio_min"]


def get_reference_marker_config(registry: dict) -> dict:
    return registry["reference_marker"]


def get_general_threshold(registry: dict) -> float:
    return registry["confidence_thresholds"]["general"]


def get_iou_threshold(registry: dict) -> float:
    return registry["confidence_thresholds"]["iou"]


def get_fixed_depth_by_nominal_height_cm(registry: dict) -> dict[str, float]:
    return registry["measurement_rules"].get(
        "fixed_depth_by_nominal_height_cm",
        {"700": 140.0, "900": 180.0},
    )


def get_height_consistency_tolerance_cm(registry: dict) -> float:
    return float(registry["measurement_rules"].get("height_consistency_tolerance_cm", 1.0))


def get_singleton_labels(registry: dict, domain: str) -> set[str]:
    """
    Return labels that must keep only one object per class after post-processing.
    Domain: "detection" | "segmentation".
    """
    defaults = {
        "detection": {"pole", "reference_marker"},
        "segmentation": {"tapak", "Segmen 1", "Joint_1", "Segmen 2", "Joint_2", "Segmen 3", "Bendera"},
    }
    cfg = registry.get("postprocess", {}).get("singleton_labels", {})
    labels = cfg.get(domain)
    if isinstance(labels, list) and labels:
        return set(labels)
    return defaults.get(domain, set())
