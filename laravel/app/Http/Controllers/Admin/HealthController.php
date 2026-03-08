<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /**
     * GET /health
     * Returns service health including database and Redis connectivity.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [];

        // Database
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'error: ' . $e->getMessage();
        }

        // Redis
        try {
            Redis::ping();
            $checks['redis'] = 'ok';
        } catch (\Throwable $e) {
            $checks['redis'] = 'error: ' . $e->getMessage();
        }

        $allOk = ! str_contains(implode('', $checks), 'error');

        return response()->json([
            'status'  => $allOk ? 'ok' : 'degraded',
            'service' => 'tiang-cv-worker',
            'checks'  => $checks,
            'time'    => now()->toIso8601String(),
        ], $allOk ? 200 : 503);
    }
}
