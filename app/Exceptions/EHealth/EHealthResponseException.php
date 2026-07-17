<?php

declare(strict_types=1);

namespace App\Exceptions\EHealth;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class EHealthResponseException extends EHealthException
{
    public function __construct(public readonly Response $response)
    {
        $message = $this->extractErrorMessage($this->response);
        $code = $this->response->status();
        parent::__construct($message, $code);
    }

    /**
     * Get the full JSON response from eHealth.
     *
     * @return array
     */
    public function getDetails(): array
    {
        return $this->response->json() ?? [];
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
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        Log::channel('e_health_errors')->error($logMessage, [
            'class' => $caller['class'] ?? 'unknown_class',
            'method' => $caller['function'] ?? 'unknown_method',
            'exception_type' => static::class,
            'error_message' => $this->getDetails(),
        ]);

        // Always show the official informational message (section 3.1.1.4)
        // when the API returns 403 "Party is not verified", even if the caller
        // provided a custom flash message. This check is placed here so that
        // every ->handle() call across the project benefits automatically.
        if ($this->isPartyNotVerified()) {
            Session::flash('error', __('errors.ehealth.messages.party_not_verified'));

            return;
        }

        $message = $flashMessage ?? __('messages.ehealth_error', ['message' => $this->getMessage()]);

        if ($flashMessage === null && $this->response->status() === 409) {
            $message = $this->response->json('error.message') ?? $this->getMessage();
        }

        Session::flash('error', $message);
    }

    /**
     * Returns true when the eHealth API denied the request because the
     * employee's party is not verified (BLOCK_UNVERIFIED_PARTY_USERS=true).
     */
    public function isPartyNotVerified(): bool
    {
        return $this->response->status() === 403
            && str_contains($this->response->json('error.message', ''), 'Party is not verified');
    }

    /**
     * Report the exception.
     *
     * @return void
     */
    public function report(): void
    {
        Log::error('eHealth API Error Detail', [
            'status' => $this->response->status(),
            'reason' => $this->response->reason(),
            'url' => $this->response->effectiveUri()?->__toString(),
            'body' => $this->response->body(),
        ]);
    }

    /**
     * Helper method to extract the most relevant error message.
     *
     * @param  Response  $response
     * @return string
     */
    protected function extractErrorMessage(Response $response): string
    {
        $errorMessage = $response->json('error.message') ?? $response->reason();

        if ($errorMessage === 'Invalid signature') {
            return __('forms.invalid_kep_password');
        }

        // Hide detailed technical errors in production unless debug is enabled
        if (!config('app.debug') && !app()->isLocal()) {
            return __('care-plan.unexpected_error') ?? 'Виникла помилка при взаємодії з eHealth';
        }

        return $response->status() . ': ' . $errorMessage;
    }
}
