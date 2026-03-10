<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the internal inference service.
 * Attaches a short-lived service JWT per request.
 * Protected by circuit breaker.
 */
class InferenceClientService
{
    public function __construct(
        private readonly ServiceJwtService $jwtService,
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly string $baseUrl,
        private readonly int $timeout = 120,
    ) {}

    /**
     * Send a photo to the inference service and return the result.
     *
     * @throws \RuntimeException  If circuit breaker is open or request fails
     */
    public function infer(
        string $requestId,
        string $photoId,
        string $imageUrl,
        ?float $referenceMarkerCm = 100.0,
        ?string $segmentationMode = null,
        array $metadata = [],
    ): array {
        if ($this->circuitBreaker->isOpen()) {
            throw new \RuntimeException('Inference circuit breaker is OPEN — inference service unavailable');
        }

        $token = $this->jwtService->mint();

        try {
            $response = Http::withToken($token)
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/infer", [
                    'request_id'          => $requestId,
                    'photo_id'            => $photoId,
                    'image_url'           => $imageUrl,
                    'reference_marker_cm' => $referenceMarkerCm ?? 100.0,
                    'segmentation_mode'   => $segmentationMode,
                    'metadata'            => $metadata,
                ]);

            if ($response->serverError()) {
                $this->circuitBreaker->recordFailure();
                throw new \RuntimeException(
                    "Inference service returned {$response->status()}: {$response->body()}"
                );
            }

            $response->throw();
            $this->circuitBreaker->recordSuccess();

            return $response->json();
        } catch (\Throwable $e) {
            // Record failure for ALL exceptions (including RuntimeException from serverError block above)
            // so the circuit breaker always tracks inference unavailability correctly
            $this->circuitBreaker->recordFailure();
            Log::error('InferenceClient: request failed', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
            ]);
            throw new \RuntimeException('Inference request failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
