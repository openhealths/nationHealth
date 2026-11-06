<?php

declare(strict_types=1);

namespace App\Classes\eHealth;

use App\Exceptions\EHealth\EHealthRateLimitedException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use RuntimeException;

/** Shared request counts for any eHealth endpoint group; URL and token selection belong to the HTTP client. */
class EHealthRateLimiter extends RateLimiter
{
    protected const int LOCK_SECONDS = 5;
    protected const int LOCK_WAIT_SECONDS = 1;

    /**
     * Retry-After deadlines also stay in this process if the shared cache lock is busy.
     *
     * @var array<string, int>
     */
    protected array $localCooldowns = [];

    /**
     * Count one request before it is sent, or ask the caller to wait without sending it.
     * For example, reserve($key, 29, 60) allows 29 requests with this key in the preceding 60 seconds.
     * The caller builds the key from the API host, endpoint group and a hash of the token.
     *
     * @throws EHealthRateLimitedException when the request limit, Retry-After or a busy cache lock requires waiting
     */
    public function reserve(string $key, int $maxRequests, int $windowSeconds): void
    {
        $localDelay = ($this->localCooldowns[$key] ?? 0) - $this->currentTime();
        if ($localDelay > 0) {
            throw new EHealthRateLimitedException($localDelay);
        }

        try {
            $delay = $this->cache->lock($key.':lock', static::LOCK_SECONDS)->block(
                static::LOCK_WAIT_SECONDS,
                fn (): int => $this->recordRequest($key, max(1, $maxRequests), max(1, $windowSeconds)),
            );
        } catch (LockTimeoutException) {
            // The cache is busy. No request was counted or sent; the queue can try again in one second.
            throw new EHealthRateLimitedException(static::LOCK_WAIT_SECONDS);
        }

        if ($delay > 0) {
            throw new EHealthRateLimitedException($delay);
        }
    }

    /**
     * Pause requests with this key for the server's Retry-After duration.
     * For example, cooldown($key, 90) blocks this token/group for 90 seconds.
     * A later 20-second response must not shorten an already recorded 90-second wait.
     *
     * @throws LockTimeoutException when the pause could not be saved; callers must preserve the original HTTP 429
     */
    public function cooldown(string $key, int $seconds): void
    {
        $now = $this->currentTime();
        $this->localCooldowns = array_filter($this->localCooldowns, static fn (int $until): bool => $until > $now);
        $this->localCooldowns[$key] = max($this->localCooldowns[$key] ?? 0, $now + max(1, $seconds));

        $this->cache->lock($key.':lock', static::LOCK_SECONDS)->block(static::LOCK_WAIT_SECONDS, function () use ($key, $seconds): void {
            $cooldownKey = $key.':cooldown';
            if ($this->availableIn($cooldownKey) < max(1, $seconds)) {
                $this->clear($cooldownKey);
                $this->hit($cooldownKey, max(1, $seconds));
            }
        });
    }

    /**
     * Under the lock, remove expired request times and count this request if allowed.
     * Return 0 when counted, otherwise the seconds the caller must wait.
     * Each request expires separately: calls near a minute boundary cannot produce two full bursts.
     * Timestamps use whole seconds, so the extra second avoids admitting a request fractionally early.
     */
    protected function recordRequest(string $key, int $maxRequests, int $windowSeconds): int
    {
        $cooldown = $this->availableIn($key.':cooldown');
        if ($cooldown > 0) {
            return $cooldown;
        }

        $now = $this->currentTime();
        $requests = array_values(array_filter(
            $this->cache->get($key.':requests', []),
            static fn (int $at): bool => $at >= $now - $windowSeconds,
        ));
        if (count($requests) >= $maxRequests) {
            return min($requests) + $windowSeconds + 1 - $now;
        }

        $requests[] = $now;
        if (!$this->cache->put($key.':requests', $requests, $windowSeconds + 1)) {
            throw new RuntimeException('Could not record the eHealth request count in the shared cache.');
        }

        return 0;
    }
}
