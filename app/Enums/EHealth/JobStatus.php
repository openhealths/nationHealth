<?php

declare(strict_types=1);

namespace App\Enums\EHealth;

/** Remote medical-event job statuses; distinct from the local queue JobStatus. */
enum JobStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Accepted = 'accepted';
    case Queued = 'queued';
    case Processed = 'processed';
    case Completed = 'completed';
    case Success = 'success';
    case Active = 'active';
    case Failed = 'failed';
    case Error = 'error';

    public function isPending(): bool
    {
        return match ($this) {
            self::Pending, self::Processing, self::Accepted, self::Queued => true,
            default => false,
        };
    }

    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Processed, self::Completed, self::Success, self::Active => true,
            default => false,
        };
    }

    /** Creation responses may contain a job verdict rather than a clinical resource status. */
    public function isCreationEnvelopeStatus(): bool
    {
        return $this->isPending() || in_array($this, [self::Processed, self::Completed, self::Success], true);
    }
}
