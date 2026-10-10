<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents\Concerns;

use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\MedicalEvents\BaseRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** @mixin BaseRepository */
trait FindsOwnedRequests
{
    /** Resolve client-supplied UUIDs only within the caller's patient and facility. */
    public function findOwnedByPerson(string $uuid, int $personId, ?int $legalEntityId): Model
    {
        $record = $this->model->newQuery()
            ->where('uuid', $uuid)
            ->where('person_id', $personId)
            ->firstOrFail();

        $employeeId = $record->employeeId;
        if ($employeeId !== null && $legalEntityId !== null && !Employee::query()
            ->whereKey($employeeId)
            ->where('legal_entity_id', $legalEntityId)
            ->exists()) {
            throw (new ModelNotFoundException())->setModel(Employee::class);
        }

        return $record;
    }

    public function findOwnedForEncounter(string $uuid, Encounter $encounter, ?int $legalEntityId): Model
    {
        $record = $this->findOwnedByPerson($uuid, (int) $encounter->person_id, $legalEntityId);

        // context_id identifies a FHIR Identifier, not the local Encounter row.
        $contextUuid = $record->context?->value;
        if (!$contextUuid || $contextUuid !== $encounter->uuid) {
            throw (new ModelNotFoundException())->setModel($this->model::class, [$uuid]);
        }

        return $record;
    }
}
