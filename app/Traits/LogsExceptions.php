<?php

declare(strict_types=1);

namespace App\Traits;

use App\Classes\Cipher\Exceptions\CipherApiException;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use JsonException;
use Throwable;

trait LogsExceptions
{
    /**
     * Log error messages if any exception occur during database interaction.
     *
     * @param  Exception|Throwable  $exception
     * @param  string  $logMessage
     * @param  string|null  $flashMessage  Custom flash message; defaults to messages.database_error
     * @return void
     */
    protected function handleDatabaseErrors(
        Exception|Throwable $exception,
        string $logMessage,
        ?string $flashMessage = null
    ): void {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        Log::channel('db_errors')->error($logMessage, [
            'class' => $caller['class'] ?? 'unknown_class',
            'method' => $caller['function'] ?? 'unknown_method',
            'error_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line_in_file' => $exception->getLine()
        ]);
        Session::flash('error', $flashMessage ?? __('messages.database_error'));
    }

    /**
     * Handle Cipher API exceptions with logging and user-facing flash message.
     *
     * @param  ConnectionException|CipherApiException|JsonException  $exception
     * @param  string  $logMessage
     * @return void
     */
    protected function handleCipherExceptions(
        ConnectionException|CipherApiException|JsonException $exception,
        string $logMessage
    ): void {
        if ($exception instanceof ConnectionException) {
            Log::channel('e_health_errors')->error($logMessage, [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]);
            Session::flash('error', __('messages.connection_exception'));

            return;
        }

        if ($exception instanceof JsonException) {
            $this->handleDatabaseErrors($exception, $logMessage);
            Session::flash('error', __('messages.database_error'));

            return;
        }

        Log::channel('api_errors')->error($logMessage, [
            'message' => $exception->response->json(['message']),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);
        Session::flash('error', $exception->getMessage());
    }
}
