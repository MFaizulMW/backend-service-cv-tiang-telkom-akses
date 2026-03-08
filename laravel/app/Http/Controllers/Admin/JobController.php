<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPolePhoto;
use App\Models\AnalysisResult;
use App\Models\JobLog;
use App\Services\PhotoFetcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class JobController extends Controller
{
    public function __construct(
        private readonly PhotoFetcherService $fetcher,
    ) {}

    /**
     * GET /admin/jobs/status
     * Returns queue statistics and recent job activity.
     */
    public function status(): JsonResponse
    {
        $stats = [
            'total_processed'   => AnalysisResult::where('status', 'completed')->count(),
            'total_failed'      => AnalysisResult::where('status', 'failed')->count(),
            'total_pending'     => AnalysisResult::where('status', 'pending')->count(),
            'recent_logs'       => JobLog::orderByDesc('created_at')->limit(20)->get(),
            'time'              => now()->toIso8601String(),
        ];

        return response()->json($stats);
    }

    /**
     * POST /admin/jobs/run?date=YYYY-MM-DD
     * Manually trigger photo fetch and queue for a given date.
     */
    public function run(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());
        $segmentationMode = strtolower((string) $request->query('segmentation_mode', 'auto'));
        $allowedModes = ['auto', 'pole_roi', 'full_image'];

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'Invalid date format. Use YYYY-MM-DD.'], 422);
        }
        if (! in_array($segmentationMode, $allowedModes, true)) {
            return response()->json([
                'error' => 'Invalid segmentation_mode. Use one of: auto, pole_roi, full_image.',
            ], 422);
        }

        $photos = $this->fetcher->fetch($date);

        if (empty($photos)) {
            return response()->json([
                'status'   => 'ok',
                'message'  => 'No new photos to process',
                'date'     => $date,
                'enqueued' => 0,
                'segmentation_mode' => $segmentationMode,
            ]);
        }

        $enqueued = 0;
        $now = now()->toIso8601String();

        foreach ($photos as $photo) {
            if (! $this->fetcher->validatePhotoUrl($photo['photo_url'] ?? '')) {
                continue; // SSRF protection — skip disallowed URLs
            }

            $jobId = Str::uuid()->toString();
            ProcessPolePhoto::dispatch(
                jobId:       $jobId,
                photoId:     $photo['photo_id'],
                photoUrl:    $photo['photo_url'],
                metadata:    array_merge($photo['metadata'] ?? [], ['segmentation_mode' => $segmentationMode]),
                enqueuedAt:  $now,
                attempt:     1,
            );
            $enqueued++;
        }

        return response()->json([
            'status'   => 'ok',
            'date'     => $date,
            'fetched'  => count($photos),
            'enqueued' => $enqueued,
            'segmentation_mode' => $segmentationMode,
            'time'     => $now,
        ]);
    }
}
