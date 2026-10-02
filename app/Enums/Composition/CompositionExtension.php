<?php

declare(strict_types=1);

namespace App\Enums\Composition;

enum CompositionExtension: string
{
    case INFORM_WITH = 'INFORM_WITH';
    case IS_ACCIDENT = 'IS_ACCIDENT';
    case IS_INTOXICATED = 'IS_INTOXICATED';
    case IS_FOREIGN_TREATMENT = 'IS_FOREIGN_TREATMENT';
    case IS_FORCE_RENEW = 'IS_FORCE_RENEW';
    case TREATMENT_VIOLATION = 'TREATMENT_VIOLATION';
    case TREATMENT_VIOLATION_DATE = 'TREATMENT_VIOLATION_DATE';
    case NEWBORN_BIRTH_DATE = 'NEWBORN_BIRTH_DATE';
    case NEWBORN_SEX = 'NEWBORN_SEX';

    public function column(): string
    {
        return $this === self::INFORM_WITH ? 'inform_with_uuid' : strtolower($this->value);
    }

    public function valueKey(): string
    {
        return match ($this) {
            self::INFORM_WITH => 'valueUuid',
            self::IS_ACCIDENT, self::IS_INTOXICATED, self::IS_FOREIGN_TREATMENT, self::IS_FORCE_RENEW => 'valueBoolean',
            self::TREATMENT_VIOLATION_DATE, self::NEWBORN_BIRTH_DATE => 'valueDate',
            default => 'valueString',
        };
    }
}
