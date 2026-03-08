<?php

namespace App\Drivers\Notification;

interface NotifierInterface
{
    /**
     * Send an alert/notification.
     *
     * @param  string  $event    Short event name, e.g. "job.failed", "circuit.open"
     * @param  string  $message  Human-readable message
     * @param  array   $context  Additional structured context
     */
    public function notify(string $event, string $message, array $context = []): void;
}
