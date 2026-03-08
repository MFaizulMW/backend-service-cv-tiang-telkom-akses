<?php

use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\JobController;
use App\Http\Controllers\Admin\ResultController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\AdminApiKeyMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API Routes (Internal Only)
|--------------------------------------------------------------------------
|
| All routes are protected by X-Admin-Key middleware.
| These endpoints must NEVER be exposed to the public internet.
| Access should be restricted to internal network / VPN at the infrastructure level.
|
*/

// ─── Webhooks (auth via secret header, not Admin Key) ───────
Route::prefix('webhooks')->group(function () {
    Route::post('/supabase', [WebhookController::class, 'supabase']);
    Route::post('/telkom',   [WebhookController::class, 'telkom']);
});

Route::middleware(AdminApiKeyMiddleware::class)->group(function () {

    // Health check
    Route::get('/health', HealthController::class);

    // Job management
    Route::prefix('admin/jobs')->group(function () {
        Route::get('/status', [JobController::class, 'status']);
        Route::post('/run', [JobController::class, 'run']);
    });

    // Results
    Route::prefix('admin/results')->group(function () {
        Route::get('/', [ResultController::class, 'index']);
        Route::get('/{photo_id}', [ResultController::class, 'show'])
            ->where('photo_id', '.+');
    });
});
