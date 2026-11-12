<?php

declare(strict_types=1);

namespace App\Exceptions\Signature;

use Throwable;

/**
 * Any failure of the signature provider, so callers do not depend on a concrete provider's exceptions.
 */
interface SignatureException extends Throwable
{
    /**
     * Log the exception and flash a user-facing error message.
     *
     * @param  string  $logMessage
     * @param  string|null  $flashMessage  Optional override for the user-facing flash message
     * @return void
     */
    public function handle(string $logMessage, ?string $flashMessage = null): void;
}
