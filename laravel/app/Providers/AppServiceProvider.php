<?php

namespace App\Providers;

use App\Drivers\Auth\ClientAuthInterface;
use App\Drivers\Auth\LocalClientAuth;
use App\Drivers\Auth\TelkomClientAuth;
use App\Drivers\Notification\NotifierInterface;
use App\Drivers\Notification\NullNotifier;
use App\Drivers\Notification\SlackNotifier;
use App\Drivers\Notification\WebhookNotifier;
use App\Drivers\Storage\DatabaseStorage;
use App\Drivers\Storage\ResultStorageInterface;
use App\Drivers\Storage\S3Storage;
use App\Drivers\TelkomApi\ApiKeyAuth;
use App\Drivers\TelkomApi\BearerAuth;
use App\Drivers\TelkomApi\OAuth2Auth;
use App\Drivers\TelkomApi\SupabaseMockDriver;
use App\Drivers\TelkomApi\TelkomApiInterface;
use App\Services\CircuitBreakerService;
use App\Services\InferenceClientService;
use App\Services\ServiceJwtService;
use Illuminate\Support\ServiceProvider;

/**
 * Pluggable driver bindings.
 * Changing a provider requires ONLY an .env change — no code modifications.
 *
 * Adding a new provider:
 *   1. Create a new class implementing the relevant interface.
 *   2. Register it in this file.
 *   3. Set the matching driver env var.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ─── Client Auth Driver ──────────────────────────────────
        $this->app->singleton(ClientAuthInterface::class, function () {
            return match ((string) config('telkom.client_auth.driver')) {
                'telkom' => new TelkomClientAuth(
                    algorithm:     (string) config('telkom.client_auth.telkom.algorithm', 'HS256'),
                    issuer:        (string) config('telkom.client_auth.telkom.issuer', ''),
                    audience:      (string) config('telkom.client_auth.telkom.audience', ''),
                    claimSub:      (string) config('telkom.client_auth.telkom.claim_sub', 'sub'),
                    secret:        (string) config('telkom.client_auth.telkom.secret', ''),
                    publicKeyPath: (string) config('telkom.client_auth.telkom.public_key_path', ''),
                ),
                default => new LocalClientAuth(
                    secret:    (string) config('telkom.client_auth.local.secret'),
                    algorithm: 'HS256',
                ),
            };
        });

        // ─── Telkom API Driver ───────────────────────────────────
        $this->app->singleton(TelkomApiInterface::class, function () {
            return match ((string) config('telkom.api.auth_driver')) {
                'api_key'  => new ApiKeyAuth(),
                'oauth2'   => new OAuth2Auth(),
                'supabase' => new SupabaseMockDriver(),
                default    => new BearerAuth(),
            };
        });

        // ─── Result Storage Driver ───────────────────────────────
        $this->app->singleton(ResultStorageInterface::class, function () {
            return match ((string) config('telkom.storage.driver')) {
                's3'    => new S3Storage(),
                default => new DatabaseStorage(),
            };
        });

        // ─── Notifier Driver ─────────────────────────────────────
        $this->app->singleton(NotifierInterface::class, function () {
            return match ((string) config('telkom.notifier.driver')) {
                'webhook' => new WebhookNotifier(
                    webhookUrl: (string) config('telkom.notifier.webhook.url'),
                    secret:     (string) config('telkom.notifier.webhook.secret', ''),
                ),
                'slack' => new SlackNotifier(
                    webhookUrl: (string) config('telkom.notifier.slack.webhook_url'),
                    channel:    (string) config('telkom.notifier.slack.channel', '#alerts'),
                ),
                default => new NullNotifier(),
            };
        });

        // ─── Internal Services ───────────────────────────────────
        $this->app->singleton(ServiceJwtService::class, function () {
            return new ServiceJwtService(
                secret:     (string) config('telkom.service_jwt.secret'),
                issuer:     (string) config('telkom.service_jwt.issuer', 'tiang-worker'),
                audience:   (string) config('telkom.service_jwt.audience', 'tiang-inference'),
                ttlSeconds: (int) config('telkom.service_jwt.ttl_seconds', 30),
            );
        });

        $this->app->singleton('circuit_breaker.telkom', function () {
            return new CircuitBreakerService(
                name:              'telkom',
                failureThreshold:  (int) config('telkom.circuit_breaker.telkom.failure_threshold', 5),
                cooldownSeconds:   (int) config('telkom.circuit_breaker.telkom.cooldown_seconds', 300),
            );
        });

        $this->app->singleton('circuit_breaker.inference', function () {
            return new CircuitBreakerService(
                name:             'inference',
                failureThreshold: (int) config('telkom.circuit_breaker.inference.failure_threshold', 3),
                cooldownSeconds:  (int) config('telkom.circuit_breaker.inference.cooldown_seconds', 120),
            );
        });

        $this->app->singleton(InferenceClientService::class, function ($app) {
            return new InferenceClientService(
                jwtService:     $app->make(ServiceJwtService::class),
                circuitBreaker: $app->make('circuit_breaker.inference'),
                baseUrl:        (string) config('telkom.inference.base_url'),
                timeout:        (int) config('telkom.inference.timeout', 120),
            );
        });

        // PhotoFetcherService requires the Telkom circuit breaker explicitly
        $this->app->singleton(\App\Services\PhotoFetcherService::class, function ($app) {
            return new \App\Services\PhotoFetcherService(
                telkomApi:      $app->make(TelkomApiInterface::class),
                audit:          $app->make(\App\Services\AuditLogService::class),
                circuitBreaker: $app->make('circuit_breaker.telkom'),
            );
        });
    }

    public function boot(): void {}
}
