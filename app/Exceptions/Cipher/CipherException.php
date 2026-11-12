<?php

declare(strict_types=1);

namespace App\Exceptions\Cipher;

use App\Exceptions\Signature\SignatureException;
use Exception;

abstract class CipherException extends Exception implements SignatureException
{
    /**
     * Log the exception and flash a user-facing error message.
     *
     * @param  string  $logMessage
     * @param  string|null  $flashMessage  Optional override for the user-facing flash message
     * @return void
     */
    abstract public function handle(string $logMessage, ?string $flashMessage = null): void;
}
