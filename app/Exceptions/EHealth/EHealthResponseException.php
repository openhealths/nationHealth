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

        return $response->status() . ': ' . $errorMessage;
    }
}
