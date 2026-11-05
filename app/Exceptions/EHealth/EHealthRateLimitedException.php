<?php

declare(strict_types=1);

namespace App\Exceptions\EHealth;

use Illuminate\Support\Facades\Session;

/** Local capacity wait; no HTTP request was sent and no server response exists. */
class EHealthRateLimitedException extends EHealthException
{
    public function __construct(private readonly int $delaySeconds)
    {
        parent::__construct(__('errors.ehealth.messages.too_many_requests'), 429);
    }

    public function retryAfter(): int
    {
        return max(1, $this->delaySeconds);
    }

    public function handle(string $logMessage, ?string $flashMessage = null): void
    {
        Session::flash('error', $flashMessage ?? $this->getMessage());
    }
}
