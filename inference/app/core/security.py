"""
Internal service JWT validation with Redis-backed anti-replay.

Workers mint a short-lived HS256 token (TTL=30s, unique JTI).
This service validates the token and marks the JTI as used in Redis
to prevent replay attacks within the token's lifetime.
"""

import time

import jwt
import redis as redis_lib
from fastapi import HTTPException, Security
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app.core.config import get_settings

_bearer = HTTPBearer()

# Module-level connection pool — shared across all requests
_redis_pool: redis_lib.ConnectionPool | None = None


def _get_redis() -> redis_lib.Redis:
    global _redis_pool
    s = get_settings()
    if _redis_pool is None:
        _redis_pool = redis_lib.ConnectionPool(
            host=s.redis_host,
            port=s.redis_port,
            password=s.redis_password or None,
            db=s.redis_db,
            decode_responses=True,
            max_connections=10,
        )
    return redis_lib.Redis(connection_pool=_redis_pool)


def validate_service_token(
    credentials: HTTPAuthorizationCredentials = Security(_bearer),
) -> dict:
    """Decode and validate internal service JWT, reject replays."""
    s = get_settings()
    token = credentials.credentials

    try:
        payload = jwt.decode(
            token,
            s.service_jwt_secret,
            algorithms=["HS256"],
            issuer=s.service_jwt_issuer,
            audience=s.service_jwt_audience,
        )
    except jwt.ExpiredSignatureError:
        raise HTTPException(status_code=401, detail="Token expired")
    except jwt.InvalidTokenError as exc:
        raise HTTPException(status_code=401, detail=f"Invalid token: {exc}")

    jti = payload.get("jti")
    if not jti:
        raise HTTPException(status_code=401, detail="Missing JTI claim")

    # Anti-replay: JTI must not have been seen before
    r = _get_redis()
    replay_key = f"jwt:jti:{jti}"
    if r.exists(replay_key):
        raise HTTPException(status_code=401, detail="Token already used (replay detected)")

    # Mark JTI as consumed for its remaining TTL + buffer for clock skew / network latency
    exp = payload.get("exp", 0)
    ttl = max(60, int(exp - time.time()) + 30)
    r.setex(replay_key, ttl, "1")

    return payload
