<?php

namespace App\Drivers\Storage;

interface ResultStorageInterface
{
    /**
     * Persist analysis result for a photo.
     *
     * @param  string  $photoId
     * @param  string  $jobId
     * @param  array   $payload  Full inference + measurement + compliance result
     */
    public function store(string $photoId, string $jobId, array $payload): void;

    /**
     * Check whether a result already exists for this photo_id.
     */
    public function exists(string $photoId): bool;

    /**
     * Retrieve stored result by photo_id.
     */
    public function find(string $photoId): ?array;
}
