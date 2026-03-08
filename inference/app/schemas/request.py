from pydantic import BaseModel, HttpUrl, field_validator
from typing import Optional


class InferenceRequest(BaseModel):
    request_id: str
    photo_id: str
    image_url: str  # validated for SSRF in the route layer
    reference_marker_cm: Optional[float] = 100.0  # default 100 cm per spec
    metadata: Optional[dict] = None
