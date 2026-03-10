<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPolePhoto;
use App\Models\AnalysisResult;
use App\Services\AuditLogService;
use App\Services\PhotoFetcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function __construct(
        private readonly PhotoFetcherService $fetcher,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * POST /api/webhooks/supabase
     *
     * Triggered by Supabase Database Webhook on INSERT to `photos` table.
     * Setup: Supabase Dashboard → Database → Webhooks → Create webhook
     *   Table  : photos
     *   Events : INSERT
     *   URL    : https://your-service.com/api/webhooks/supabase
     *   Header : X-Webhook-Secret: {WEBHOOK_SUPABASE_SECRET}
     *
     * Supabase payload shape:
     * {
     *   "type": "INSERT",
     *   "table": "photos",
     *   "schema": "public",
     *   "record": {
     *     "photo_id": "tiang-99",
     *     "photo_url": "https://...",
     *     "category": "tiang",
     *     "captured_date": "2026-03-07",
     *     "location": "...",
     *     "captured_at": "..."
     *   },
     *   "old_record": null
     * }
     */
    public function supabase(Request $request): JsonResponse
    {
        // Validate webhook secret — always required, never optional
        $secret = config('telkom.webhook.supabase_secret');
        if (! $secret || ! hash_equals($secret, (string) $request->header('X-Webhook-Secret'))) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->json()->all();

        // Only handle INSERT events
        if (($payload['type'] ?? '') !== 'INSERT') {
            return response()->json(['status' => 'ignored', 'reason' => 'not an insert event']);
        }

        $record = $payload['record'] ?? [];

        return $this->dispatchPhoto(
            photoId:  $record['photo_id'] ?? null,
            photoUrl: $record['photo_url'] ?? null,
            category: $record['category'] ?? null,
            metadata: [
                'location'    => $record['location'] ?? null,
                'captured_at' => $record['captured_at'] ?? null,
            ],
            source: 'supabase-webhook',
        );
    }

    /**
     * POST /api/webhooks/telkom
     *
     * Placeholder for real Telkom External API webhook.
     * When Telkom provides their push notification spec, implement the auth
     * and payload parsing here — business logic (dispatch job) stays the same.
     *
     * Likely auth options from Telkom:
     *   - HMAC-SHA256 signature on payload (header: X-Telkom-Signature)
     *   - Bearer token (header: Authorization: Bearer xxx)
     *   - API Key (header: X-Api-Key)
     *
     * Fill in once Telkom provides their webhook spec/documentation.
     */
    public function telkom(Request $request): JsonResponse
    {
        // TODO: Implement Telkom-specific auth validation here
        // Example (HMAC):
        // $signature = $request->header('X-Telkom-Signature');
        // $expected  = hash_hmac('sha256', $request->getContent(), config('telkom.webhook.telkom_secret'));
        // if (!hash_equals($expected, $signature)) return response()->json(['error' => 'Unauthorized'], 401);

        $payload = $request->json()->all();

        // TODO: Map Telkom payload fields to photo_id, photo_url, category, metadata
        // Adjust field names below once Telkom provides their payload spec
        return $this->dispatchPhoto(
            photoId:  $payload['photo_id'] ?? null,
            photoUrl: $payload['photo_url'] ?? null,
            category: $payload['category'] ?? null,
            metadata: $payload['metadata'] ?? [],
            source:   'telkom-webhook',
        );
    }

    /**
     * Shared logic: validate → deduplicate → dispatch job.
     */
    private function dispatchPhoto(
        ?string $photoId,
        ?string $photoUrl,
        ?string $category,
        array $metadata,
        string $source,
    ): JsonResponse {
        if (! $photoId || ! $photoUrl) {
            return response()->json(['error' => 'Missing photo_id or photo_url'], 422);
        }

        // Category filter
        $expectedCategory = config('telkom.api.photo_category', 'tiang');
        if ($category && $category !== $expectedCategory) {
            return response()->json(['status' => 'ignored', 'reason' => "category '{$category}' is not '{$expectedCategory}'"]);
        }

        // SSRF protection
        if (! $this->fetcher->validatePhotoUrl($photoUrl)) {
            return response()->json(['error' => 'Photo URL not in allowed domains'], 403);
        }

        // Idempotency: skip if already processed or in-progress
        if (AnalysisResult::where('photo_id', $photoId)->exists()) {
            return response()->json(['status' => 'skipped', 'reason' => 'already exists', 'photo_id' => $photoId]);
        }

        $jobId = Str::uuid()->toString();

        ProcessPolePhoto::dispatch(
            jobId:      $jobId,
            photoId:    $photoId,
            photoUrl:   $photoUrl,
            metadata:   $metadata,
            enqueuedAt: now()->toIso8601String(),
            attempt:    1,
        );

        $this->audit->log('webhook.photo.queued', 'info', [
            'source'   => $source,
            'photo_id' => $photoId,
            'job_id'   => $jobId,
        ]);

        return response()->json([
            'status'   => 'queued',
            'photo_id' => $photoId,
            'job_id'   => $jobId,
        ], 202);
    }
}
