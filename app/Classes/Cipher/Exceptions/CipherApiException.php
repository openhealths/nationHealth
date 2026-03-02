<?php

declare(strict_types=1);

namespace App\Classes\Cipher\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Custom exception for errors returned by the Cipher API.
 */
class CipherApiException extends Exception
{
    /**
     * The original response object from the HTTP client.
     *
     * @var Response
     */
    public Response $response;

    /**
     * CipherApiException constructor.
     *
     * @param  string  $message  The exception message.
     * @param  Response  $response  The response that caused the exception.
     * @param  int  $code  The exception code.
     * @param  Throwable|null  $previous  The previous throwable used for the exception chaining.
     */
    public function __construct(string $message, Response $response, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    /**
     * Helper to get the response body for logging purposes.
     *
     * @return array|null
     */
    public function getContext(): ?array
    {
        return $this->response->json();
    }
}
