<?php

namespace App\Drivers\Auth;

use App\Exceptions\AuthenticationException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Development/local JWT auth driver.
 * Validates tokens using a shared secret from CLIENT_JWT_SECRET.
 * Switch to TelkomClientAuth for production by changing CLIENT_AUTH_DRIVER=telkom.
 */
class LocalClientAuth implements ClientAuthInterface
{
    public function __construct(
        private readonly string $secret,
        private readonly string $algorithm = 'HS256',
    ) {}

    public function validate(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
            return (array) $decoded;
        } catch (Throwable $e) {
            throw new AuthenticationException('Local JWT validation failed: ' . $e->getMessage());
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
