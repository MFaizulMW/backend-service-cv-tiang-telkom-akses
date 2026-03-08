<?php

namespace App\Drivers\TelkomApi;

class BearerAuth extends AbstractTelkomApi
{
    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . config('telkom.api.bearer_token'),
        ];
    }
}
