<?php

declare(strict_types=1);

namespace App\Dto\Ehealth\Error;

use App\Contracts\ApiErrorTranslator;

/**
 * Error response payload and translation context passed to Messages mapping.
 */
class ErrorResponseData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly ApiErrorTranslator $translator
    ) {
    }
}
