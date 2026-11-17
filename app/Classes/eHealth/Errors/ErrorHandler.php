<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Errors;

use App\Contracts\ApiErrorTranslator;
use App\Dto\Ehealth\Error\ErrorResponseData;
use App\Dto\Ehealth\Error\Messages;
use Symfony\Component\ObjectMapper\ObjectMapper;

class ErrorHandler
{
    protected readonly ApiErrorTranslator $errorTranslator;

    public function __construct($errorTranslator = new BaseErrorTranslator())
    {
        $this->errorTranslator = $errorTranslator;
    }

    public function handleError(array $error): array
    {
        $messages = new ObjectMapper()->map(
            new ErrorResponseData(payload: $error, translator: $this->errorTranslator),
            Messages::class
        );

        return ['errors' => $messages->toArray()];
    }
}
