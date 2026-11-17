<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Errors;

use App\Contracts\ApiErrorTranslator;
use App\Dto\Ehealth\Error\ErrorDetails;
use App\Dto\Ehealth\Error\ErrorResponseData;
use App\Dto\Ehealth\Error\Messages;

class ErrorHandler
{
    protected readonly ApiErrorTranslator $errorTranslator;

    public function __construct($errorTranslator = new BaseErrorTranslator())
    {
        $this->errorTranslator = $errorTranslator;
    }

    /**
     * @return list<string>
     */
    public function handleError(array $error): array
    {
        $mapper = objectMapper();

        $details = $mapper->map(
            new ErrorResponseData(payload: $error, translator: $this->errorTranslator),
            ErrorDetails::class
        );

        return $mapper->map($details, Messages::class)->toArray();
    }
}
