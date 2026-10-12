<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;

class ApiException extends Exception
{
    public function __construct(string $message, int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function render(): string
    {
        return "API Exception: $this->message";
    }

    public function report(): void
    {
        Log::channel('api_errors')->error('eHealth API exception', [
            'exception_type' => static::class,
            'status' => $this->getCode(),
        ]);
    }
}
