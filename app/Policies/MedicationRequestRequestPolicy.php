<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use Illuminate\Auth\Access\Response;

class MedicationRequestRequestPolicy
{
    public function view(User $user, MedicationRequestRequest $record): Response
    {
        return $this->scope($user, $record, $record->resourceType === MedicationRequestRequest::TYPE_REQUEST
            ? 'medication_request_request:read' : 'medication_request:read');
    }

    public function checkValidity(User $user, MedicationRequestRequest $record): Response
    {
        return $this->view($user, $record);
    }

    public function sign(User $user, MedicationRequestRequest $record): Response
    {
        $permission = $this->scope($user, $record, 'medication_request_request:sign');
        if ($permission->denied() || $record->resourceType !== MedicationRequestRequest::TYPE_REQUEST || strtolower($record->status) !== 'new') {
            return Response::denyAsNotFound();
        }
        $context = MedicalEventsRepository::medicationRequest()->clinicalContext($record);
        if ($context instanceof CarePlan) {
            return $user->can('manageActivities', [$context, $record->employee?->uuid]) ? Response::allow() : Response::denyAsNotFound();
        }
        $employee = Repository::employee()->activeForLegalEntity($user, legalEntity(), ['DOCTOR', 'SPECIALIST', 'MED_COORDINATOR'], $record->employee?->uuid);
        if (!$context instanceof Encounter || $employee === null || (int) $record->employeeId !== (int) $employee->id
            || $user->cannot('view', [$context, Person::findOrFail($record->personId)])) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function reject(User $user, MedicationRequestRequest $record): Response
    {
        if (!in_array(strtolower($record->status), ['new', 'active'], true)) {
            return Response::denyAsNotFound();
        }

        return $this->scope($user, $record, $record->resourceType === MedicationRequestRequest::TYPE_REQUEST && strtolower($record->status) === 'new'
            ? 'medication_request_request:reject' : 'medication_request:reject');
    }

    public function resend(User $user, MedicationRequestRequest $record): Response
    {
        if (strtolower($record->status) !== 'active' && !(strtolower($record->status) === 'signed' && data_get($record->ehealthPayload, 'active_id'))) {
            return Response::denyAsNotFound();
        }

        return $this->scope($user, $record, 'medication_request:resend');
    }

    public function dispenses(User $user, MedicationRequestRequest $record): Response
    {
        return $this->scope($user, $record, 'medication_dispense:read');
    }

    public function priceCatalog(User $user, MedicationRequestRequest $record): Response
    {
        return $this->scope($user, $record, 'medication_request:details');
    }

    private function scope(User $user, MedicationRequestRequest $record, string $scope): Response
    {
        $entity = legalEntity();
        if ($entity === null) {
            return Response::denyAsNotFound();
        }
        $employee = Repository::employee()->activeForLegalEntity($user, $entity, ['DOCTOR', 'SPECIALIST', 'ASSISTANT', 'MED_COORDINATOR', 'MED_ADMIN']);
        $owner = data_get($record->ehealthPayload, 'legal_entity.id');
        if ($entity === null || (int) getPermissionsTeamId() !== (int) $entity->id || $user->cannot($scope) || $employee === null
            || ($owner !== null && $scope !== 'medication_request:read' && $scope !== 'medication_request_request:read' && $scope !== 'medication_dispense:read' && $scope !== 'medication_request:details' && $owner !== $entity->uuid)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
