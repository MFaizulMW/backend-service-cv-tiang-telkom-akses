<?php

namespace App\Jobs;

use App\Drivers\Notification\NotifierInterface;
use App\Drivers\Storage\ResultStorageInterface;
use App\Drivers\TelkomApi\TelkomApiInterface;
use App\Models\AnalysisResult;
use App\Services\AuditLogService;
use App\Services\InferenceClientService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Queue job payload structure (per spec):
 * {
 *   "job_id":      "uuid-v4",
 *   "photo_id":    "id-from-telkom-api",
 *   "photo_url":   "https://cdn.telkom.co.id/photos/xxx.jpg",
 *   "metadata":    { "location": "...", "captured_at": "..." },
 *   "enqueued_at": "ISO-8601",
 *   "attempt":     1
 * }
 */
class ProcessPolePhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $backoff;

    public function __construct(
        public readonly string $jobId,
        public readonly string $photoId,
        public readonly string $photoUrl,
        public readonly array $metadata = [],
        public readonly string $enqueuedAt = '',
        public int $attempt = 1,
    ) {
        $this->tries   = (int) config('telkom.jobs.max_retries', 3);
        $this->backoff = (int) config('telkom.jobs.retry_delay_seconds', 30);
        $this->queue   = 'default';
    }

    public function handle(
        InferenceClientService $inference,
        ResultStorageInterface $storage,
        TelkomApiInterface $telkomApi,
        AuditLogService $audit,
        NotifierInterface $notifier,
    ): void {
        $audit->log('job.started', 'info', [
            'attempt'  => $this->attempt,
            'photo_url' => $this->photoUrl,
        ], $this->jobId, $this->photoId);

        // Guard: already processed (idempotency)
        if ($storage->exists($this->photoId)) {
            Log::info('ProcessPolePhoto: skipping already-processed photo', [
                'photo_id' => $this->photoId,
            ]);
            $audit->log('job.skipped', 'info', ['reason' => 'already_processed'], $this->jobId, $this->photoId);
            return;
        }

        try {
            // Run inference
            $result = $inference->infer(
                requestId:          Str::uuid()->toString(),
                photoId:            $this->photoId,
                imageUrl:           $this->photoUrl,
                referenceMarkerCm:  100.0, // default; override from metadata if provided
                metadata:           $this->metadata,
            );

            // Inject photo_url so dashboard can render before/after
            $result['photo_url'] = $this->photoUrl;

            // Persist result
            $storage->store($this->photoId, $this->jobId, $result);

            // Optional callback to Telkom
            $telkomApi->sendCallback($this->photoId, $result);

            $audit->log('job.completed', 'success', [
                'pole_type'          => $result['measurement']['pole_type'] ?? null,
                'measurement_method' => $result['measurement']['measurement_method'] ?? null,
                'is_compliant'       => $result['compliance']['is_compliant'] ?? null,
            ], $this->jobId, $this->photoId);

            Log::info('ProcessPolePhoto: completed', [
                'job_id'   => $this->jobId,
                'photo_id' => $this->photoId,
            ]);

        } catch (\Throwable $e) {
            $audit->log('job.failed', 'error', [
                'attempt' => $this->attempt,
                'error'   => $e->getMessage(),
            ], $this->jobId, $this->photoId);

            Log::error('ProcessPolePhoto: failed', [
                'job_id'   => $this->jobId,
                'photo_id' => $this->photoId,
                'attempt'  => $this->attempt,
                'error'    => $e->getMessage(),
            ]);

            throw $e; // Laravel will retry or move to failed jobs table
        }
    }

    /**
     * Move to dead-letter queue after all retries exhausted.
     */
    public function failed(\Throwable $e): void
    {
        $notifier = app(NotifierInterface::class);
        $notifier->notify('job.dead_letter', "Job {$this->jobId} for photo {$this->photoId} moved to dead-letter queue", [
            'job_id'   => $this->jobId,
            'photo_id' => $this->photoId,
            'error'    => $e->getMessage(),
        ]);

        // Update DB status to failed
        AnalysisResult::updateOrCreate(
            ['photo_id' => $this->photoId],
            ['job_id' => $this->jobId, 'status' => 'failed', 'inference_raw' => ['error' => $e->getMessage()]]
        );
    }
}
