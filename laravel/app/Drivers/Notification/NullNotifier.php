<?php

namespace App\Drivers\Notification;

use Illuminate\Support\Facades\Log;

/**
 * No-op notifier. Logs the event but sends nothing externally.
 * Use when NOTIFIER_DRIVER=null.
 */
class NullNotifier implements NotifierInterface
{
    public function notify(string $event, string $message, array $context = []): void
    {
        Log::info("[NullNotifier] {$event}: {$message}", $context);
    }
}
