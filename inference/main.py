"""
Inference service entry point.
Internal only — must NOT be exposed to the public internet.
Accessible only from the internal Docker network.
"""

import logging
import sys

from fastapi import FastAPI

from app.api.routes import router as infer_router
from app.models.yolo_models import get_detection_model, get_segmentation_model

# ─── Structured logging ──────────────────────────────────────
logging.basicConfig(
    stream=sys.stdout,
    level=logging.INFO,
    format='{"time":"%(asctime)s","level":"%(levelname)s","logger":"%(name)s","message":"%(message)s"}',
)
logger = logging.getLogger(__name__)

# ─── App ─────────────────────────────────────────────────────
app = FastAPI(
    title="Tiang CV Inference Service",
    description="Internal-only YOLO inference service for Telkom pole analysis.",
    version="1.0.0",
    docs_url=None,      # Disable Swagger UI in production
    redoc_url=None,
    openapi_url=None,
)

app.include_router(infer_router)


@app.get("/health")
async def health() -> dict:
    return {"status": "ok", "service": "inference"}


@app.on_event("startup")
async def startup() -> None:
    """Pre-load models at startup to avoid cold-start latency on first request."""
    logger.info("Pre-loading YOLO models...")
    get_detection_model()
    get_segmentation_model()
    logger.info("Models loaded and ready.")
