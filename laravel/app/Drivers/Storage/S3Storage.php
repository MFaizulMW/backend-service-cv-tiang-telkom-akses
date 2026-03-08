<?php

namespace App\Drivers\Storage;

use Illuminate\Support\Facades\Storage;

/**
 * Stores analysis results as JSON objects in S3.
 * Key format: results/{photo_id}.json
 *
 * Configure via:
 *   STORAGE_DRIVER=s3
 *   AWS_BUCKET=my-bucket
 *   AWS_ACCESS_KEY_ID=...
 *   AWS_SECRET_ACCESS_KEY=...
 *   AWS_DEFAULT_REGION=...
 */
class S3Storage implements ResultStorageInterface
{
    private function key(string $photoId): string
    {
        return "results/{$photoId}.json";
    }

    public function store(string $photoId, string $jobId, array $payload): void
    {
        Storage::disk('s3')->put(
            $this->key($photoId),
            json_encode(array_merge($payload, ['job_id' => $jobId, 'photo_id' => $photoId])),
        );
    }

    public function exists(string $photoId): bool
    {
        return Storage::disk('s3')->exists($this->key($photoId));
    }

    public function find(string $photoId): ?array
    {
        if (! $this->exists($photoId)) {
            return null;
        }
        return json_decode(Storage::disk('s3')->get($this->key($photoId)), true);
    }
}
