<?php

namespace App\Services;

use App\Drivers\TelkomApi\TelkomApiInterface;
use App\Models\AnalysisResult;
use Illuminate\Support\Facades\Log;

/**
 * Fetches photos from Telkom API, deduplicates against existing records,
 * and returns only new photos to be queued.
 */
class PhotoFetcherService
{
    public function __construct(
        private readonly TelkomApiInterface $telkomApi,
        private readonly AuditLogService $audit,
        private readonly CircuitBreakerService $circuitBreaker,
    ) {}

    /**
     * Fetch photos for a given date (YYYY-MM-DD).
     * Returns only photos not yet processed (deduplication by photo_id).
     *
     * @return array<int, array{photo_id: string, photo_url: string, metadata: array}>
     */
    public function fetch(string $date): array
    {
        if ($this->circuitBreaker->isOpen()) {
            Log::warning('PhotoFetcher: Telkom API circuit breaker is OPEN — skipping fetch', [
                'date' => $date,
            ]);
            return [];
        }

        try {
            $photos = $this->telkomApi->fetchPhotos($date);
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            $this->audit->log('photo.fetch.failed', 'error', [
                'date'  => $date,
                'error' => $e->getMessage(),
            ]);
            Log::error('PhotoFetcher: Telkom API fetch failed', [
                'date'  => $date,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        // Deduplicate: filter out photo_ids already processed
        $existingIds = AnalysisResult::whereIn(
            'photo_id',
            array_column($photos, 'photo_id')
        )->pluck('photo_id')->all();

        $existingSet = array_flip($existingIds);
        $newPhotos = array_filter($photos, fn($p) => ! isset($existingSet[$p['photo_id']]));

        $this->audit->log('photo.fetch.completed', 'success', [
            'date'       => $date,
            'total'      => count($photos),
            'new'        => count($newPhotos),
            'skipped'    => count($photos) - count($newPhotos),
        ]);

        Log::info('PhotoFetcher: fetched photos', [
            'date'    => $date,
            'total'   => count($photos),
            'new'     => count($newPhotos),
        ]);

        return array_values($newPhotos);
    }

    /**
     * Validate that a photo URL is from an allowed domain (SSRF protection).
     */
    public function validatePhotoUrl(string $url): bool
    {
        $allowedDomains = array_map(
            'trim',
            explode(',', (string) config('telkom.api.allowed_image_domains', ''))
        );

        if (empty(array_filter($allowedDomains))) {
            return true; // No whitelist configured — allow all (warn in logs)
        }

        $host = parse_url($url, PHP_URL_HOST);
        return in_array($host, $allowedDomains, true);
    }
}
