<?php

namespace App\Drivers\Auth;

interface ClientAuthInterface
{
    /**
     * Validate the incoming token.
     *
     * @param  string  $token  Raw bearer token
     * @return array           Decoded claims
     *
     * @throws \App\Exceptions\AuthenticationException  On invalid/expired token
     */
    public function validate(string $token): array;

    /**
     * Quick validity check without throwing.
     */
    public function isValid(string $token): bool;
}
