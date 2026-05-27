<?php

declare(strict_types=1);

namespace App\Exceptions\EHealth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class EHealthConnectionException extends ConnectionException
{
    /**
     * Log the exception and flash a user-facing error message.
     *
     * @param  string  $logMessage
     * @return void
     */
    public function handle(string $logMessage): void
    {
        Log::channel('e_health_errors')->error($logMessage, [
            'message' => $this->getMessage(),
            'file' => $this->getFile(),
            'line' => $this->getLine()
        ]);

        Session::flash('error', __('messages.connection_exception'));
    }
}
