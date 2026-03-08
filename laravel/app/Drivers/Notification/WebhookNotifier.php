<?php

namespace App\Drivers\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP webhook notifier.
 * Configure with:
 *   NOTIFIER_DRIVER=webhook
 *   NOTIFIER_WEBHOOK_URL=https://...
 *   NOTIFIER_WEBHOOK_SECRET=<hmac secret>
 */
class WebhookNotifier implements NotifierInterface
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly string $secret = '',
    ) {}

    public function notify(string $event, string $message, array $context = []): void
    {
        $payload = json_encode([
            'event'     => $event,
            'message'   => $message,
            'context'   => $context,
            'timestamp' => now()->toIso8601String(),
        ]);

        $headers = ['Content-Type' => 'application/json'];
        if ($this->secret) {
            $headers['X-Signature'] = 'sha256=' . hash_hmac('sha256', $payload, $this->secret);
        }

        try {
            Http::withHeaders($headers)->timeout(10)->withBody($payload, 'application/json')->post($this->webhookUrl);
        } catch (\Throwable $e) {
            Log::error('WebhookNotifier failed', ['error' => $e->getMessage(), 'event' => $event]);
        }
    }
}
