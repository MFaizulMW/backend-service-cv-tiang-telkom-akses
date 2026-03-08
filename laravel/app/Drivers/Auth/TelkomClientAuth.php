<?php

namespace App\Drivers\Auth;

use App\Exceptions\AuthenticationException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Production Telkom JWT auth driver.
 * Supports HS256 (shared secret) and RS256 (public key).
 * All configuration comes from environment variables — zero code changes needed
 * when switching from LocalClientAuth.
 *
 * Required env vars:
 *   TELKOM_JWT_ALGORITHM     = HS256 | RS256
 *   TELKOM_JWT_SECRET        = <secret>   (HS256 only)
 *   TELKOM_JWT_PUBLIC_KEY_PATH = /run/secrets/telkom.pem  (RS256 only)
 *   TELKOM_JWT_ISSUER        = <expected issuer>
 *   TELKOM_JWT_AUDIENCE      = <expected audience>
 *   TELKOM_CLAIM_SUB         = sub
 */
class TelkomClientAuth implements ClientAuthInterface
{
    private readonly Key $key;

    public function __construct(
        private readonly string $algorithm,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly string $claimSub,
        string $secret = '',
        string $publicKeyPath = '',
    ) {
        if ($algorithm === 'RS256') {
            if (! $publicKeyPath || ! file_exists($publicKeyPath)) {
                throw new \RuntimeException("Telkom RS256 public key not found at: {$publicKeyPath}");
            }
            $this->key = new Key(file_get_contents($publicKeyPath), 'RS256');
        } else {
            $this->key = new Key($secret, $algorithm);
        }
    }

    public function validate(string $token): array
    {
        try {
            $decoded = JWT::decode($token, $this->key);
            $claims = (array) $decoded;

            if ($this->issuer && ($claims['iss'] ?? '') !== $this->issuer) {
                throw new AuthenticationException('JWT issuer mismatch');
            }

            if ($this->audience) {
                $aud = (array) ($claims['aud'] ?? []);
                if (! in_array($this->audience, $aud, true)) {
                    throw new AuthenticationException('JWT audience mismatch');
                }
            }

            return $claims;
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AuthenticationException('Telkom JWT validation failed: ' . $e->getMessage());
        }
    }

    public function isValid(string $token): bool
    {
        try {
            $this->validate($token);
            return true;
        } catch (AuthenticationException) {
            return false;
        }
    }
}
