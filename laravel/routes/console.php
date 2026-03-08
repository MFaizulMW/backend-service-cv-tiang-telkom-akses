<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console / Scheduler Routes
|--------------------------------------------------------------------------
|
| The scheduler triggers FetchAndQueuePhotos daily.
| FETCH_SCHEDULE env var controls the cron expression.
| Default: "0 1 * * *" — every day at 01:00 server time.
|
*/

Schedule::command('photos:fetch')
    ->cron((string) env('FETCH_SCHEDULE', '0 1 * * *'))
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
