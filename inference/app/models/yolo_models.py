"""
YOLO model loader with singleton pattern.
Models are loaded once at startup to avoid repeated disk I/O.
"""

import hashlib
import logging
from functools import lru_cache
from pathlib import Path

from ultralytics import YOLO

from app.core.config import get_settings
from app.core.registry import get_registry

logger = logging.getLogger(__name__)


def _verify_sha256(path: Path, expected: str) -> None:
    """Verify model file integrity against registry sha256."""
    if not expected:
        logger.warning("No SHA256 in registry for %s — skipping integrity check", path)
        return
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(8192), b""):
            h.update(chunk)
    actual = h.hexdigest()
    if actual != expected:
        raise RuntimeError(
            f"Model integrity check FAILED for {path}.\n"
            f"  Expected: {expected}\n"
            f"  Got:      {actual}"
        )
    logger.info("Model integrity OK: %s", path.name)


@lru_cache
def get_detection_model() -> YOLO:
    s = get_settings()
    registry = get_registry()
    path = Path(s.model_detection_path)
    expected_sha = registry["models"]["detection"].get("sha256", "")
    _verify_sha256(path, expected_sha)
    logger.info("Loading detection model: %s", path)
    return YOLO(str(path))


@lru_cache
def get_segmentation_model() -> YOLO:
    s = get_settings()
    registry = get_registry()
    path = Path(s.model_segmentation_path)
    expected_sha = registry["models"]["segmentation"].get("sha256", "")
    _verify_sha256(path, expected_sha)
    logger.info("Loading segmentation model: %s", path)
    return YOLO(str(path))
