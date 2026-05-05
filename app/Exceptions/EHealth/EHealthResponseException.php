<?php

declare(strict_types=1);

namespace App\Exceptions\EHealth;

use Exception;
use Illuminate\Http\Client\Response;

class EHealthResponseException extends Exception
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
        return $this->details ?? [];
    }

    /**
     * Report the exception.
     *
     * @return void
     */
    public function report(): void
    {
        \Illuminate\Support\Facades\Log::error('eHealth API Error Detail', [
            'status' => $this->response->status(),
            'reason' => $this->response->reason(),
            'url'    => $this->response->effectiveUri()?->__toString(),
            'body'   => $this->response->body(),
        ]);
    }

    /**
     * Helper method to extract the most relevant error message.
     *
     * @param Response $response
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
