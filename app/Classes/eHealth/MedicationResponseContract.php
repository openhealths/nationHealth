<?php

declare(strict_types=1);

namespace App\Classes\eHealth;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\SchemaResponseContract;

/** Stable native medication boundary shared by the clinical and pharmacy clients. */
class MedicationResponseContract
{
    public function validate(EHealthResponse $response, string $operation): array
    {
        return (new SchemaResponseContract())->validate($response, $operation, 'medication');
    }
}
