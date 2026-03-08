<?php

namespace App\Drivers\Storage;

use App\Models\AnalysisResult;

class DatabaseStorage implements ResultStorageInterface
{
    public function store(string $photoId, string $jobId, array $payload): void
    {
        AnalysisResult::updateOrCreate(
            ['photo_id' => $photoId],
            [
                'job_id'           => $jobId,
                'pole_type'        => $payload['measurement']['pole_type'] ?? null,
                'measurement_method' => $payload['measurement']['measurement_method'] ?? null,
                'total_visible_px' => $payload['measurement']['total_visible_px'] ?? null,
                'underground_depth_px' => $payload['measurement']['underground_depth_px'] ?? null,
                'total_pole_px'    => $payload['measurement']['total_pole_px'] ?? null,
                'total_visible_cm' => $payload['measurement']['total_visible_cm'] ?? null,
                'underground_depth_cm' => $payload['measurement']['underground_depth_cm'] ?? null,
                'total_pole_cm'    => $payload['measurement']['total_pole_cm'] ?? null,
                'is_compliant'     => $payload['compliance']['is_compliant'] ?? null,
                'inference_raw'    => $payload,
                'status'           => 'completed',
            ]
        );
    }

    public function exists(string $photoId): bool
    {
        return AnalysisResult::where('photo_id', $photoId)->exists();
    }

    public function find(string $photoId): ?array
    {
        $result = AnalysisResult::where('photo_id', $photoId)->first();
        return $result?->toArray();
    }
}
