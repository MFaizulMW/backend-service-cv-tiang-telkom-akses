<?php

use Laravel\Horizon\Horizon;

/*
|--------------------------------------------------------------------------
| Horizon Configuration
|--------------------------------------------------------------------------
|
| Horizon is used for queue monitoring dashboard.
| Workers scale via `docker compose up --scale worker=N` using queue:work,
| not via Horizon supervisors, to support true horizontal Docker scaling.
| Set `supervisor.processes = 0` to disable Horizon-managed workers.
|
*/

return [

    'domain' => null,
    'path'   => 'horizon',

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue'      => ['default'],
            'balance'    => 'auto',
            'processes'  => 0,  // Workers managed externally via docker compose scale
            'tries'      => (int) env('JOB_MAX_RETRIES', 3),
            'timeout'    => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 0,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

];
