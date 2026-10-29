<?php

declare(strict_types=1);

namespace App\Exceptions\EHealth;

use App\Classes\eHealth\Errors\BaseErrorTranslator;
use App\Contracts\ApiErrorTranslator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class EHealthResponseException extends EHealthException
{
    private readonly ApiErrorTranslator $errorTranslator;

    /**
     * @param  Response  $response
     * @param  ApiErrorTranslator|null  $errorTranslator  Translator of the resource that returned the error, the base one when omitted
     */
    public function __construct(public readonly Response $response, ?ApiErrorTranslator $errorTranslator = null)
    {
        $this->errorTranslator = $errorTranslator ?? new BaseErrorTranslator();

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

        if ($this->isPartyDeceased()) {
            Session::flash('error', __('errors.ehealth.messages.party_deceased'));

            return;
        }

        if ($this->response->status() === 429) {
            $flashMessage ??= __('errors.ehealth.messages.too_many_requests');
        }

        $extracted = $this->response->json('error.message')
            ?? $this->response->json('details.errorMessage')
            ?? $this->response->json('description');

        $flashMessage ??= is_string($extracted) ? $this->errorTranslator->translate($extracted) : null;

        $translationKey = \is_string($extracted) ? $this->getErrorTranslationKey($extracted) : null;

        $message = $flashMessage ?? ($extracted !== null
            ? $this->response->status() . ': ' . ($translationKey !== null ? __($translationKey) : $extracted)
            : __('messages.ehealth_error', ['message' => $this->getMessage()]));

        if ($flashMessage === null && $this->response->status() === 409) {
            $raw = $this->response->json('error.message') ?? $this->getMessage();
            $message = $this->translateConflictMessage((string) $raw);
        }

        if ($flashMessage === null && $this->response->json('error.message') === 'Invalid verification code') {
            $message = __('errors.ehealth.messages.invalid_verification_code');
        }

        if ($flashMessage === null
            && $this->response->status() === 403
            && $this->response->json('error.message') === 'Access denied') {
            $message = __('errors.ehealth.messages.access_denied');
        }

        Session::flash('error', $message);
    }

    /**
     * Map known eHealth 409 conflict messages to Ukrainian copy.
     */
    protected function translateConflictMessage(string $message): string
    {
        if (preg_match('/^License with type (.+) is already present$/u', $message, $matches) === 1) {
            return __('errors.ehealth.messages.license_type_already_present', ['type' => $matches[1]]);
        }

        if (str_contains($message, 'At least one of action references, diagnostic reports or procedures should reference the same service')) {
            return __('errors.ehealth.messages.referral_service_mismatch');
        }

        return match ($message) {
            'client_id refers to legal entity that is not active' => __('errors.ehealth.messages.legal_entity_not_active'),
            'Legal entity type and license type mismatch' => __('errors.ehealth.messages.license_type_mismatch'),
            'License is expired' => __('errors.ehealth.messages.license_expired'),
            'No active primary license found for legal entity' => __('errors.ehealth.messages.no_active_primary_license'),
            default => $message,
        };
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
     * Returns true when the eHealth API denied the request because the
     * employee's party is marked as deceased (BLOCK_DECEASED_PARTY_USERS=true).
     */
    public function isPartyDeceased(): bool
    {
        return $this->response->status() === 403
            && str_contains($this->response->json('error.message', ''), 'Party is deceased');
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
        $errorMessage = $response->json('error.message')
            ?? $response->json('details.errorMessage')
            ?? $response->json('description')
            ?? $response->json('message')
            ?? $response->reason();

        if ($errorMessage === 'Invalid signature') {
            return __('forms.invalid_kep_password');
        }

        $translationKey = $this->getErrorTranslationKey($errorMessage);

        if ($translationKey !== null) {
            return $response->status() . ': ' . __($translationKey);
        }

        if ($response->status() === 409) {
            $translated = $this->translateConflictMessage((string) $errorMessage);
            if ($translated !== $errorMessage) {
                return $translated;
            }
        }

        // Hide detailed technical errors in production unless debug is enabled
        if (!config('app.debug') && !app()->isLocal()) {
            return __('care-plan.unexpected_error') ?? 'Виникла помилка при взаємодії з eHealth';
        }

        return $response->status() . ': ' . $errorMessage;
    }

    /**
     * Get the translation key for a known eHealth error message.
     */
    protected function getErrorTranslationKey(string $message): ?string
    {
        return match ($message) {
            'Active parent declaration was not found' => 'errors.ehealth.messages.active_parent_declaration_not_found',
            'Identity, password combination is wrong.' => 'auth.login.error.validation.credentials',
            'You reached login attempts limit. Try again later' => 'auth.login.error.throttle',
            'The redirection URI provided does not match a pre-registered value.' => 'errors.ehealth.messages.redirect_uri_mismatch',
            default => null,
        };
    }
}
