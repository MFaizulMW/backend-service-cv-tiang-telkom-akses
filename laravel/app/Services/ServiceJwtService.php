<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;

/**
 * Mints short-lived internal service JWTs for Worker → Inference communication.
 *
 * Properties (from env):
 *   Algorithm : HS256
 *   TTL       : SERVICE_JWT_TTL_SECONDS (default 30)
 *   JTI       : UUID v4, unique per request (anti-replay enforced on inference side)
 */
class ServiceJwtService
{
    public function __construct(
        private readonly string $secret,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $ttlSeconds = 30,
    ) {}

    public function mint(): string
    {
        $now = time();
        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            'jti' => (string) Str::uuid(),
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }
}
