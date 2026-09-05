<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Resolves medical-request and approval rows only inside the current aggregate.
 *
 * Livewire action arguments are client-controlled. Callers must not look up
 * requests globally by UUID.
 */
final class MedicalRequestOwnership
{
    public function medicationForPerson(string $uuid, int $personId): MedicationRequestRequest
    {
        /** @var MedicationRequestRequest */
        return $this->owned(MedicationRequestRequest::class, $uuid, $personId);
    }

    public function serviceForPerson(string $uuid, int $personId): ServiceRequestRequest
    {
        /** @var ServiceRequestRequest */
        return $this->owned(ServiceRequestRequest::class, $uuid, $personId);
    }

    public function deviceForPerson(string $uuid, int $personId): DeviceRequestRequest
    {
        /** @var DeviceRequestRequest */
        return $this->owned(DeviceRequestRequest::class, $uuid, $personId);
    }

    public function medicationForEncounter(string $uuid, Encounter $encounter): MedicationRequestRequest
    {
        return $this->forEncounter(MedicationRequestRequest::class, $uuid, $encounter);
    }

    public function serviceForEncounter(string $uuid, Encounter $encounter): ServiceRequestRequest
    {
        return $this->forEncounter(ServiceRequestRequest::class, $uuid, $encounter);
    }

    public function deviceForEncounter(string $uuid, Encounter $encounter): DeviceRequestRequest
    {
        return $this->forEncounter(DeviceRequestRequest::class, $uuid, $encounter);
    }

    public function referralForPerson(string $uuid, int $personId): ServiceRequestRequest|DeviceRequestRequest
    {
        try {
            return $this->serviceForPerson($uuid, $personId);
        } catch (ModelNotFoundException) {
            return $this->deviceForPerson($uuid, $personId);
        }
    }

    public function approvalForCarePlan(CarePlan $carePlan, string $uuid): Approval
    {
        $approval = $carePlan->approvals()->where('uuid', $uuid)->first();

        if (!$approval instanceof Approval) {
            throw (new ModelNotFoundException())->setModel(Approval::class, [$uuid]);
        }

        return $approval;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function forEncounter(string $class, string $uuid, Encounter $encounter): Model
    {
        $personId = (int) ($encounter->personId ?? $encounter->person_id);

        /** @var T $record */
        $record = $this->owned($class, $uuid, $personId);

        $contextId = $record->contextId ?? null;
        if ($contextId !== null && (int) $contextId !== (int) $encounter->id) {
            throw (new ModelNotFoundException())->setModel($class, [$uuid]);
        }

        return $record;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function owned(string $class, string $uuid, int $personId): Model
    {
        /** @var T|null $record */
        $record = $class::query()
            ->where('uuid', $uuid)
            ->where('person_id', $personId)
            ->first();

        if ($record === null) {
            throw (new ModelNotFoundException())->setModel($class, [$uuid]);
        }

        $this->assertEmployeeLegalEntity($record->employeeId ?? null);

        return $record;
    }

    private function assertEmployeeLegalEntity(mixed $employeeId): void
    {
        if ($employeeId === null || legalEntity() === null) {
            return;
        }

        $belongs = Employee::query()
            ->whereKey($employeeId)
            ->where('legal_entity_id', legalEntity()->id)
            ->exists();

        if (!$belongs) {
            abort(404);
        }
    }
}
