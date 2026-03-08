<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed circuit breaker.
 * State is shared across all worker instances (no in-memory state).
 *
 * States:
 *   closed  → normal operation
 *   open    → stop calls, wait for cooldown
 *
 * Keys in Redis:
 *   cb:{name}:failures  — consecutive failure count
 *   cb:{name}:open      — existence indicates circuit is open
 */
class CircuitBreakerService
{
    public function __construct(
        private readonly string $name,
        private readonly int $failureThreshold,
        private readonly int $cooldownSeconds,
    ) {}

    public function isOpen(): bool
    {
        return (bool) Redis::exists("cb:{$this->name}:open");
    }

    public function recordSuccess(): void
    {
        Redis::del("cb:{$this->name}:failures");
        Redis::del("cb:{$this->name}:open");
    }

    public function recordFailure(): void
    {
        $key = "cb:{$this->name}:failures";
        $failures = (int) Redis::incr($key);
        Redis::expire($key, $this->cooldownSeconds * 2);

        if ($failures >= $this->failureThreshold) {
            // Open the circuit
            Redis::setex("cb:{$this->name}:open", $this->cooldownSeconds, '1');
            Redis::del($key);
        }
    }

    public function getFailureCount(): int
    {
        return (int) Redis::get("cb:{$this->name}:failures");
    }
}
