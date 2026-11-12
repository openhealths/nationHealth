<?php

declare(strict_types=1);

namespace App\Exceptions\Cipher;

use App\Exceptions\Signature\SignatureException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class CipherConnectionException extends ConnectionException implements SignatureException
{
    /**
     * Log the exception and flash a user-facing error message.
     *
     * @param  string  $logMessage
     * @param  string|null  $flashMessage  Optional override for the user-facing flash message
     * @return void
     */
    public function handle(string $logMessage, ?string $flashMessage = null): void
    {
        Log::channel('cipher_errors')->error($logMessage, [
            'message' => $this->getMessage(),
            'file' => $this->getFile(),
            'line' => $this->getLine()
        ]);

        Session::flash('error', $flashMessage ?? __('messages.connection_exception'));
    }
}
