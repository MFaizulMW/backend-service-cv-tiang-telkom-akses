<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPolePhoto;
use App\Services\PhotoFetcherService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Artisan command: php artisan photos:fetch [--date=YYYY-MM-DD]
 *
 * Called by the scheduler daily (configurable via FETCH_SCHEDULE env var).
 * Also callable manually via POST /admin/jobs/run.
 */
class FetchAndQueuePhotos extends Command
{
    protected $signature = 'photos:fetch {--date= : Date to fetch photos for (YYYY-MM-DD, defaults to today)}';
    protected $description = 'Fetch Telkom pole photos and dispatch processing jobs to queue';

    public function handle(PhotoFetcherService $fetcher): int
    {
        $date = $this->option('date') ?? now()->toDateString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error("Invalid date format: {$date}. Expected YYYY-MM-DD.");
            return Command::FAILURE;
        }

        $this->info("Fetching photos for date: {$date}");

        $photos = $fetcher->fetch($date);

        if (empty($photos)) {
            $this->info('No new photos to process.');
            return Command::SUCCESS;
        }

        $enqueued = 0;
        $skipped  = 0;
        $now = now()->toIso8601String();

        foreach ($photos as $photo) {
            if (! $fetcher->validatePhotoUrl($photo['photo_url'] ?? '')) {
                $this->warn("Skipping photo {$photo['photo_id']} — URL not in allowed domain list (SSRF protection)");
                $skipped++;
                continue;
            }

            $jobId = Str::uuid()->toString();
            ProcessPolePhoto::dispatch(
                jobId:      $jobId,
                photoId:    $photo['photo_id'],
                photoUrl:   $photo['photo_url'],
                metadata:   $photo['metadata'] ?? [],
                enqueuedAt: $now,
                attempt:    1,
            );
            $enqueued++;
        }

        $this->info("Enqueued: {$enqueued} | Skipped (SSRF): {$skipped}");
        return Command::SUCCESS;
    }
}
