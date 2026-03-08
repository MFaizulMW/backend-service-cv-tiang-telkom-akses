<?php

namespace App\Drivers\TelkomApi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OAuth2Auth extends AbstractTelkomApi
{
    private function fetchAccessToken(): string
    {
        return Cache::remember('telkom_oauth_token', 3500, function () {
            $response = Http::asForm()->post(config('telkom.api.oauth.token_url'), [
                'grant_type'    => 'client_credentials',
                'client_id'     => config('telkom.api.oauth.client_id'),
                'client_secret' => config('telkom.api.oauth.client_secret'),
                'scope'         => config('telkom.api.oauth.scope'),
            ]);

            $response->throw();
            return $response->json('access_token');
        });
    }

    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->fetchAccessToken(),
        ];
    }
}
