from pydantic import BaseModel, HttpUrl, field_validator
from typing import Optional


class InferenceRequest(BaseModel):
    request_id: str
    photo_id: str
    image_url: str  # validated for SSRF in the route layer
    reference_marker_cm: Optional[float] = 100.0  # default 100 cm per spec
    segmentation_mode: Optional[str] = None  # auto | pole_roi | full_image
    metadata: Optional[dict] = None
