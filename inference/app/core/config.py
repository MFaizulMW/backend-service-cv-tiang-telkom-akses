from pydantic_settings import BaseSettings, SettingsConfigDict
from functools import lru_cache


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # Models
    model_detection_path: str = "models/object detection ai.pt"
    model_segmentation_path: str = "models/segmentation detection ai.pt"
    registry_path: str = "registry.json"

    # Internal JWT
    service_jwt_secret: str
    service_jwt_issuer: str = "tiang-worker"
    service_jwt_audience: str = "tiang-inference"

    # Redis (for JWT anti-replay)
    redis_host: str = "redis"
    redis_port: int = 6379
    redis_password: str = ""
    redis_db: int = 0

    # Inference
    conf_general: float = 0.50
    conf_iou: float = 0.45


@lru_cache
def get_settings() -> Settings:
    return Settings()
