<?php

namespace App\Drivers\TelkomApi;

interface TelkomApiInterface
{
    /**
     * Fetch photos from Telkom External API for a given date.
     * Only returns items with category matching TELKOM_API_PHOTO_CATEGORY.
     *
     * @return array<int, array{photo_id: string, photo_url: string, metadata: array}>
     */
    public function fetchPhotos(string $date): array;

    /**
     * Send analysis result back to Telkom API (optional callback).
     */
    public function sendCallback(string $photoId, array $result): void;
}
