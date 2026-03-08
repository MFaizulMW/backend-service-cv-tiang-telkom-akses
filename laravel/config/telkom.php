<?php

/**
 * Telkom CV Service Configuration
 * All values sourced from environment variables — no hardcoded defaults for secrets.
 */

return [

    // ─── Admin API ───────────────────────────────────────────────
    'admin_api_key' => env('ADMIN_API_KEY'),

    // ─── Client Authentication ───────────────────────────────────
    'client_auth' => [
        'driver' => env('CLIENT_AUTH_DRIVER', 'local'),  // local | telkom

        'local' => [
            'secret' => env('CLIENT_JWT_SECRET'),
        ],

        'telkom' => [
            'algorithm'       => env('TELKOM_JWT_ALGORITHM', 'HS256'),
            'secret'          => env('TELKOM_JWT_SECRET'),
            'public_key_path' => env('TELKOM_JWT_PUBLIC_KEY_PATH'),
            'issuer'          => env('TELKOM_JWT_ISSUER'),
            'audience'        => env('TELKOM_JWT_AUDIENCE'),
            'claim_sub'       => env('TELKOM_CLAIM_SUB', 'sub'),
        ],
    ],

    // ─── Telkom External API ─────────────────────────────────────
    'api' => [
        'auth_driver'           => env('TELKOM_API_AUTH_DRIVER', 'bearer'),  // api_key | bearer | oauth2
        'base_url'              => env('TELKOM_API_BASE_URL'),
        'photo_endpoint'        => env('TELKOM_API_PHOTO_ENDPOINT', '/v1/photos'),
        'callback_enabled'      => env('TELKOM_API_CALLBACK_ENABLED', false),
        'callback_endpoint'     => env('TELKOM_API_CALLBACK_ENDPOINT', '/v1/photos/{photo_id}/result'),
        'timeout'               => env('TELKOM_API_TIMEOUT', 30),
        'photo_category'        => env('TELKOM_API_PHOTO_CATEGORY', 'tiang'),
        'allowed_image_domains' => env('ALLOWED_IMAGE_DOMAINS', 'cdn.telkom.co.id'),

        // API Key driver
        'api_key'        => env('TELKOM_API_KEY'),

        // Bearer driver
        'bearer_token'   => env('TELKOM_API_BEARER_TOKEN'),

        // OAuth2 driver
        'oauth' => [
            'token_url'     => env('TELKOM_API_OAUTH_TOKEN_URL'),
            'client_id'     => env('TELKOM_API_OAUTH_CLIENT_ID'),
            'client_secret' => env('TELKOM_API_OAUTH_CLIENT_SECRET'),
            'scope'         => env('TELKOM_API_OAUTH_SCOPE'),
        ],
    ],

    // ─── Webhooks ────────────────────────────────────────────────
    'webhook' => [
        'supabase_secret' => env('WEBHOOK_SUPABASE_SECRET'),  // X-Webhook-Secret dari Supabase
        'telkom_secret'   => env('WEBHOOK_TELKOM_SECRET'),    // secret dari Telkom (isi nanti)
    ],

    // ─── Supabase Mock Driver (demo/dev only) ────────────────────
    'supabase' => [
        'url'      => env('SUPABASE_URL'),
        'anon_key' => env('SUPABASE_ANON_KEY'),
        'table'    => env('SUPABASE_PHOTOS_TABLE', 'photos'),
    ],

    // ─── Internal Service JWT (Worker → Inference) ───────────────
    'service_jwt' => [
        'secret'      => env('SERVICE_JWT_SECRET'),
        'ttl_seconds' => env('SERVICE_JWT_TTL_SECONDS', 30),
        'issuer'      => env('SERVICE_JWT_ISSUER', 'tiang-worker'),
        'audience'    => env('SERVICE_JWT_AUDIENCE', 'tiang-inference'),
    ],

    // ─── Inference Service ───────────────────────────────────────
    'inference' => [
        'base_url' => env('INFERENCE_BASE_URL', 'http://inference:8000'),
        'timeout'  => env('INFERENCE_TIMEOUT', 120),
    ],

    // ─── Job Processing ──────────────────────────────────────────
    'jobs' => [
        'max_retries'          => env('JOB_MAX_RETRIES', 3),
        'retry_delay_seconds'  => env('JOB_RETRY_DELAY_SECONDS', 30),
        'worker_concurrency'   => env('WORKER_CONCURRENCY', 5),
    ],

    // ─── Circuit Breakers ────────────────────────────────────────
    'circuit_breaker' => [
        'telkom' => [
            'failure_threshold' => env('CB_TELKOM_FAILURE_THRESHOLD', 5),
            'cooldown_seconds'  => env('CB_TELKOM_COOLDOWN_SECONDS', 300),
        ],
        'inference' => [
            'failure_threshold' => env('CB_INFERENCE_FAILURE_THRESHOLD', 3),
            'cooldown_seconds'  => env('CB_INFERENCE_COOLDOWN_SECONDS', 120),
        ],
    ],

    // ─── Result Storage ──────────────────────────────────────────
    'storage' => [
        'driver' => env('STORAGE_DRIVER', 'database'),  // database | s3
    ],

    // ─── Notification ────────────────────────────────────────────
    'notifier' => [
        'driver' => env('NOTIFIER_DRIVER', 'null'),  // null | webhook | slack

        'webhook' => [
            'url'    => env('NOTIFIER_WEBHOOK_URL'),
            'secret' => env('NOTIFIER_WEBHOOK_SECRET'),
        ],

        'slack' => [
            'webhook_url' => env('NOTIFIER_SLACK_WEBHOOK_URL'),
            'channel'     => env('NOTIFIER_SLACK_CHANNEL', '#alerts'),
        ],
    ],

];
