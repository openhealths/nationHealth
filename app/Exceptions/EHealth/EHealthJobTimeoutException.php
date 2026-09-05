<?php

declare(strict_types=1);

namespace App\Exceptions\EHealth;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * Raised when an asynchronous eHealth job is still pending after the configured
 * number of polls.
 *
 * The outcome is genuinely unknown at this point: eHealth may still complete the
 * job. Callers must therefore not record a final local status — they should leave
 * the record as-is and let a later sync reconcile it.
 */
class EHealthJobTimeoutException extends EHealthException
{
    public function __construct(
        public readonly string $jobId,
        public readonly int $attempts,
        public readonly ?string $lastStatus = null
    ) {
        parent::__construct(__('errors.ehealth.messages.job_timeout'));
    }

    public function report(): void
    {
        Log::channel('e_health_errors')->warning('eHealth job did not finish in time', [
            'job_id' => $this->jobId,
            'attempts' => $this->attempts,
            'last_status' => $this->lastStatus,
        ]);
    }

    public function handle(string $logMessage, ?string $flashMessage = null): void
    {
        Log::channel('e_health_errors')->warning($logMessage, [
            'job_id' => $this->jobId,
            'attempts' => $this->attempts,
            'last_status' => $this->lastStatus,
        ]);

        Session::flash('error', $flashMessage ?? $this->getMessage());
    }
}
