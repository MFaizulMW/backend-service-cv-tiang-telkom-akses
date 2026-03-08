<?php

namespace App\Services;

use App\Models\JobLog;
use Illuminate\Support\Facades\Log;

/**
 * Structured audit logging for all critical actions.
 * Writes to both the database (job_logs) and the application log channel.
 */
class AuditLogService
{
    public function log(
        string $event,
        string $status,
        array $context = [],
        ?string $jobId = null,
        ?string $photoId = null,
    ): void {
        $entry = [
            'event'    => $event,
            'status'   => $status,
            'job_id'   => $jobId,
            'photo_id' => $photoId,
            'context'  => $context,
        ];

        // Structured JSON log
        Log::info('audit', $entry);

        // Persist to database
        JobLog::create([
            'job_id'   => $jobId,
            'photo_id' => $photoId,
            'event'    => $event,
            'status'   => $status,
            'context'  => $context,
        ]);
    }
}
