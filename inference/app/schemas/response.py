from pydantic import BaseModel
from typing import Optional, List, Any


class BoundingBox(BaseModel):
    x1: float
    y1: float
    x2: float
    y2: float
    width: float
    height: float


class DetectedObject(BaseModel):
    label: str
    confidence: float
    bbox_xyxy: List[float]  # [x1, y1, x2, y2]


class SegmentedObject(BaseModel):
    label: str
    confidence: float
    bbox_xyxy: List[float]        # [x1, y1, x2, y2]
    mask_polygon: List[List[float]]  # [[x, y], ...]
    height_px: float


class DetectionResult(BaseModel):
    pole_bbox: Optional[BoundingBox] = None
    pole_bbox_height_px: Optional[float] = None
    reference_marker_bbox: Optional[BoundingBox] = None
    reference_marker_height_px: Optional[float] = None
    raw_detections: List[DetectedObject] = []


class SegmentationResult(BaseModel):
    raw_segments: List[SegmentedObject] = []
    deduplicated_segments: List[SegmentedObject] = []
    structural_segments: List[SegmentedObject] = []


class CoverageCheck(BaseModel):
    coverage_ratio: Optional[float]
    threshold: float
    is_partial_coverage: Optional[bool]
    warning: Optional[str]


class MeasurementResult(BaseModel):
    pole_type: str
    required_segments: List[str]
    detected_labels: List[str]
    missing_segments: List[str]

    measurement_method: str  # "segmentation" | "detection_bbox_fallback"

    # Pixel measurements (always present)
    total_visible_px: float
    underground_depth_px: float
    total_pole_px: float

    # CM measurements (null if no reference marker)
    scale_cm_per_px: Optional[float]
    total_visible_cm: Optional[float]
    underground_depth_cm: Optional[float]
    total_pole_cm: Optional[float]

    coverage_check: CoverageCheck

    pole_bbox: Optional[BoundingBox] = None


class ComplianceResult(BaseModel):
    is_compliant: bool
    batas_gali_detected: bool
    batas_gali_confidence: Optional[float]
    notes: List[str]


class ImageMeta(BaseModel):
    width: int
    height: int
    channels: int


class InferenceResponse(BaseModel):
    request_id: str
    photo_id: str
    status: str  # "ok" | "error"
    error: Optional[str] = None

    inference: Optional[dict] = None          # raw detection + segmentation outputs
    measurement: Optional[MeasurementResult] = None
    compliance: Optional[ComplianceResult] = None
    image_meta: Optional[ImageMeta] = None
