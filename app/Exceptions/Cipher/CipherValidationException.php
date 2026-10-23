<?php

declare(strict_types=1);

namespace App\Exceptions\Cipher;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class CipherValidationException extends CipherException
{
    public function __construct(string $message, public readonly ?Response $response = null)
    {
        parent::__construct($message);
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
        Log::channel('cipher_errors')->error($logMessage, [
            'message' => $this->getMessage(),
            'response' => $this->response?->json(),
            'file' => $this->getFile(),
            'line' => $this->getLine()
        ]);

        Session::flash('error', $flashMessage ?? $this->getMessage());
    }

    /**
     * Get the full JSON response from Cipher.
     *
     * @return array|null
     */
    public function getContext(): ?array
    {
        return $this->response?->json();
    }
}
