<?php

declare(strict_types=1);

namespace App\Classes\eHealth;

/** Stable native medication boundary shared by the clinical and pharmacy clients. */
class MedicationResponseContract
{
    public static function validate(EHealthResponse $response, string $operation): array
    {
        return SchemaResponseContract::validate($response, $operation, 'medication');
    }
}
