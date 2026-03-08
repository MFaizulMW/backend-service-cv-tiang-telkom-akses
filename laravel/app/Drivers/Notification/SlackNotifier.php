<?php

namespace App\Drivers\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Slack webhook notifier.
 * Configure with:
 *   NOTIFIER_DRIVER=slack
 *   NOTIFIER_SLACK_WEBHOOK_URL=https://hooks.slack.com/...
 *   NOTIFIER_SLACK_CHANNEL=#alerts
 */
class SlackNotifier implements NotifierInterface
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly string $channel = '#alerts',
    ) {}

    public function notify(string $event, string $message, array $context = []): void
    {
        $contextText = empty($context) ? '' : "\n```" . json_encode($context, JSON_PRETTY_PRINT) . '```';
        $text = "*[{$event}]* {$message}{$contextText}";

        try {
            Http::timeout(10)->post($this->webhookUrl, [
                'channel' => $this->channel,
                'text'    => $text,
            ]);
        } catch (\Throwable $e) {
            Log::error('SlackNotifier failed', ['error' => $e->getMessage(), 'event' => $event]);
        }
    }
}
