<?php

declare(strict_types=1);

namespace App\Exceptions\EHealth;

use Exception;

abstract class EHealthException extends Exception
{
    /**
     * Log the exception and flash a user-facing error message.
     *
     * @param  string  $logMessage
     * @return void
     */
    abstract public function handle(string $logMessage): void;
}
