<?php

declare(strict_types=1);

namespace App\Exceptions\EHealth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class EHealthConnectionException extends ConnectionException
{
    /**
     * Connection exception messages may contain the full request URL.
     */
    public function report(): void
    {
        Log::error('eHealth connection failed', [
            'exception_type' => static::class,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ]);
    }

    /**
     * Log the exception and flash a user-facing error message.
     *
     * @param  string  $logMessage
     * @param  string|null  $flashMessage  Optional override for the user-facing flash message
     * @return void
     */
    public function handle(string $logMessage, ?string $flashMessage = null): void
    {
        Log::channel('e_health_errors')->error($logMessage, [
            'exception_type' => static::class,
            'file' => $this->getFile(),
            'line' => $this->getLine()
        ]);

        Session::flash('error', $flashMessage ?? __('messages.connection_exception'));
    }
}
