<?php

declare(strict_types=1);

namespace App\Enums\EHealth;

enum ErrorType: string
{
    case VALIDATION_FAILED = 'validation_failed';
    case INTERNAL_ERROR = 'internal_error';

    public static function fromPayload(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
