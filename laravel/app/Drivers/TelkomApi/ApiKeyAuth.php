<?php

namespace App\Drivers\TelkomApi;

class ApiKeyAuth extends AbstractTelkomApi
{
    protected function getAuthHeaders(): array
    {
        return [
            'X-API-Key' => (string) config('telkom.api.api_key'),
        ];
    }
}
