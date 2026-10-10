<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\MedicalEvents\Sql\Identifier;

/**
 * Helpers for the FHIR Identifier FKs used by medication/service request tables
 * (based_on_id / context_id → identifiers.id, not care_plan_activities.id).
 */
trait CreatesFhirRequestIdentifiers
{
    protected function fhirIdentifierId(string $uuid): int
    {
        return (int) Identifier::firstOrCreate(['value' => $uuid])->id;
    }

    /**
     * @return array{based_on_id: int, context_id: int|null}
     */
    protected function fhirBasedOnContextIds(string $basedOnUuid, ?string $contextUuid = null): array
    {
        return [
            'based_on_id' => $this->fhirIdentifierId($basedOnUuid),
            'context_id' => $contextUuid !== null ? $this->fhirIdentifierId($contextUuid) : null,
        ];
    }
}
