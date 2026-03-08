<?php

namespace App\Drivers\TelkomApi;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

/**
 * Shared Telkom API logic.
 * Subclasses only need to implement getAuthHeaders().
 */
abstract class AbstractTelkomApi implements TelkomApiInterface
{
    protected string $baseUrl;
    protected string $photoEndpoint;
    protected string $callbackEndpoint;
    protected string $photoCategory;
    protected int $timeout;
    protected bool $callbackEnabled;

    public function __construct()
    {
        $this->baseUrl          = rtrim((string) config('telkom.api.base_url'), '/');
        $this->photoEndpoint    = (string) config('telkom.api.photo_endpoint');
        $this->callbackEndpoint = (string) config('telkom.api.callback_endpoint');
        $this->photoCategory    = (string) config('telkom.api.photo_category');
        $this->timeout          = (int) config('telkom.api.timeout', 30);
        $this->callbackEnabled  = (bool) config('telkom.api.callback_enabled', false);
    }

    abstract protected function getAuthHeaders(): array;

    public function fetchPhotos(string $date): array
    {
        $response = HttpFacade::withHeaders($this->getAuthHeaders())
            ->timeout($this->timeout)
            ->get($this->baseUrl . $this->photoEndpoint, [
                'date'     => $date,
                'category' => $this->photoCategory,
            ]);

        $response->throw();

        $data = $response->json();
        $photos = $data['data'] ?? $data ?? [];

        // Filter by category defensively
        return array_values(array_filter(
            $photos,
            fn($p) => strtolower($p['category'] ?? '') === strtolower($this->photoCategory)
        ));
    }

    public function sendCallback(string $photoId, array $result): void
    {
        if (! $this->callbackEnabled) {
            return;
        }

        $endpoint = str_replace('{photo_id}', $photoId, $this->callbackEndpoint);

        HttpFacade::withHeaders($this->getAuthHeaders())
            ->timeout($this->timeout)
            ->post($this->baseUrl . $endpoint, $result)
            ->throw();
    }
}
