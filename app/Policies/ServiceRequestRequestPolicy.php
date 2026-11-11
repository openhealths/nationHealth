<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\JobStatus;
use App\Enums\Specimen\Status as SpecimenStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\User;
use App\Services\Dictionary\ServiceSearch;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Throwable;

class ServiceRequestRequestPolicy
{
    public function search(User $user): Response
    {
        return $user->can('service_request:read') ? Response::allow() : Response::denyWithStatus(404);
    }

    public function viewRecord(User $user, ServiceRequestRequest $record): Response
    {
        $allowed = $this->search($user)->allowed() && $record->detailsSyncedAt !== null
            && !in_array($record->status, ['draft', 'new'], true);

        return $allowed ? Response::allow() : Response::denyWithStatus(404);
    }

    public function recall(User $user, ServiceRequestRequest $record): Response
    {
        return $this->changeRegisteredStatus($user, $record, 'service_request:recall');
    }

    public function cancel(User $user, ServiceRequestRequest $record): Response
    {
        return $this->changeRegisteredStatus($user, $record, 'service_request:cancel');
    }

    /** TV39 permits the author or another current employee of the author's legal entity. */
    private function changeRegisteredStatus(User $user, ServiceRequestRequest $record, string $scope): Response
    {
        $allowed = $user->can($scope) && $this->viewRecord($user, $record)->allowed()
            // Execution changes the legacy display status; ESOZ's clinical status governs recall and cancellation.
            && $record->clinicalStatus === 'active' && $record->requesterLegalEntity?->value === legalEntity()->uuid
            && !$record->ehealthLinks()->where('status', JobStatus::PENDING->value)->exists()
            && $user->employees()->get()->contains(fn (Employee $employee): bool => $this->linkedEmployee($user, $employee));

        return $allowed ? Response::allow() : Response::denyWithStatus(404);
    }

    public function registeredAction(User $user, array $native, string $action): Response
    {
        if (!$this->search($user)->allowed() || !in_array($action, ['view', 'print', 'recall', 'error'], true)
            || in_array($native['status'] ?? '', ['draft', 'new'], true)) {
            return Response::denyWithStatus(404);
        }
        if (in_array($action, ['view', 'print'], true)) {
            return Response::allow();
        }
        $scope = $action === 'recall' ? 'service_request:recall' : 'service_request:cancel';
        $allowed = $user->can($scope) && ($native['status'] ?? null) === 'active'
            && data_get($native, 'requester_legal_entity.identifier.value') === legalEntity()->uuid
            && $user->employees()->get()->contains(fn (Employee $employee): bool => $this->linkedEmployee($user, $employee));

        return $allowed ? Response::allow() : Response::denyWithStatus(404);
    }

    /** A selected executor's own role determines whether they may use this referral. */
    public function use(User $user, ?Employee $employee = null, ?string $category = null): Response
    {
        if ($user->cannot('service_request:use')) {
            return Response::denyWithStatus(404);
        }
        if ($employee !== null && (!$this->linkedEmployee($user, $employee)
            || !in_array($employee->employeeType, [Role::DOCTOR->value, Role::SPECIALIST->value, Role::ASSISTANT->value, Role::LABORANT->value], true)
            || ($employee->employeeType === Role::DOCTOR->value && $category === 'counselling'))) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    public function create(User $user, Employee $employee, string $category, ?Encounter $encounter = null): Response
    {
        if ($user->cannot('service_request:write') || !$this->linkedEmployee($user, $employee)
            || !in_array($employee->employeeType, [Role::DOCTOR->value, Role::SPECIALIST->value, Role::MED_COORDINATOR->value, Role::ASSISTANT->value], true)) {
            return Response::denyWithStatus(404);
        }
        if ($encounter?->class?->code === 'INPATIENT') {
            $type = $encounter->type?->coding?->first()?->code;
            if ($employee->employeeType !== Role::SPECIALIST->value
                || !in_array($type, ['service_delivery_location', 'discharge'], true)
                || !in_array($category, ['transfer_of_care', 'laboratory_procedure', 'counselling'], true)
                || ($category === 'counselling' && ($type !== 'discharge' || $encounter->getAttribute('person_id') === null))) {
                return Response::denyWithStatus(404);
            }
        }

        return Response::allow();
    }

    /** An assistant's actual service catalog categories cannot be changed through the referral category field. */
    public function createCode(User $user, Employee $employee, string $serviceId): Response
    {
        if (!$this->linkedEmployee($user, $employee)) {
            return Response::denyWithStatus(404);
        }
        if ($employee->employeeType !== Role::ASSISTANT->value) {
            return Response::allow();
        }
        $catalog = dictionary()->services()->flattened();
        $choice = $catalog->firstWhere('id', $serviceId);
        if ($choice === null || empty($choice['request_allowed'])) {
            return Response::denyWithStatus(404);
        }
        $categories = ($choice['type'] ?? '') === 'service_group'
            ? $catalog->whereIn('id', ServiceSearch::memberServiceIds($serviceId, dictionary()->services()->toArray()))->pluck('category')->unique()->all()
            : [$choice['category'] ?? null];
        $allowed = $categories !== [] && array_diff($categories, config('ehealth.referrals.assistant_allowed_categories', [])) === [];

        return $allowed ? Response::allow() : Response::denyWithStatus(404);
    }

    public function completeRecord(User $user, ServiceRequestRequest $record): Response
    {
        $allowed = $this->complete($user)->allowed()
            && $record->usedByLegalEntity?->value === legalEntity()->uuid
            && ($record->status === 'in_progress' || ($record->status === 'active' && $record->programProcessingStatus === 'in_progress'));

        return $allowed ? Response::allow() : Response::denyWithStatus(404);
    }

    public function viewCompletedResource(User $user, ServiceRequestRequest $record, string $type): Response
    {
        $allowed = $this->search($user)->allowed()
            && $record->requesterLegalEntity?->value === legalEntity()->uuid
            && ($record->status === 'completed' || $record->programProcessingStatus === 'completed')
            && in_array($type, ['encounter', 'procedure', 'diagnostic_report'], true)
            && $user->can($type.':read');

        return $allowed ? Response::allow() : Response::denyWithStatus(404);
    }

    public function createFromSpecimen(User $user, Employee $employee, Specimen $specimen): Response
    {
        if ($user->cannot('specimen:read') || $specimen->status !== SpecimenStatus::AVAILABLE) {
            return Response::denyWithStatus(404);
        }

        return $this->create($user, $employee, 'laboratory_procedure');
    }

    /** Foreign-facility reuse is offered only after ESOZ's configured interval. */
    public function takeIntoWork(User $user, array $referral): Response
    {
        if (!$this->use($user)->allowed()
            || !in_array($referral['status'] ?? '', ['active', 'new'], true)
            || strtolower((string) ($referral['program_processing_status'] ?? '')) === 'completed') {
            return Response::denyWithStatus(404);
        }
        $owner = data_get($referral, 'used_by_legal_entity.identifier.value');
        if (blank($owner)) {
            return Response::allow();
        }
        if ($owner === legalEntity()->uuid) {
            return Response::denyWithStatus(404);
        }
        $minutes = config('ehealth.referrals.reuse_after_minutes');
        $history = collect($referral['used_by_legal_entity_history'] ?? [])->sortByDesc('inserted_at')->first();
        $startedAt = $history['inserted_at'] ?? null;
        if (!is_numeric($minutes) || (float) $minutes < 0 || !is_string($startedAt) || data_get($history, 'used_by_legal_entity.identifier.value') !== $owner) {
            return Response::denyWithStatus(404);
        }
        try {
            $eligible = CarbonImmutable::parse($startedAt)->addMinutes((float) $minutes)->lessThanOrEqualTo(now());
        } catch (Throwable) {
            $eligible = false;
        }

        return $eligible ? Response::allow() : Response::denyWithStatus(404);
    }

    public function manageUsage(User $user, array $referral): Response
    {
        $allowed = $this->use($user)->allowed()
            && in_array($referral['status'] ?? '', ['active', 'in_progress', 'in_queue'], true)
            && data_get($referral, 'used_by_legal_entity.identifier.value') === legalEntity()->uuid
            && (in_array($referral['status'] ?? '', ['in_progress', 'in_queue'], true)
                || strtolower((string) ($referral['program_processing_status'] ?? '')) === 'in_progress');

        return $allowed ? Response::allow() : Response::denyWithStatus(404);
    }

    public function completeReferral(User $user, array $referral): Response
    {
        $allowed = $this->complete($user)->allowed()
            && data_get($referral, 'used_by_legal_entity.identifier.value') === legalEntity()->uuid
            && (($referral['status'] ?? '') === 'in_progress'
                || (($referral['status'] ?? '') === 'active' && ($referral['program_processing_status'] ?? '') === 'in_progress'));

        return $allowed ? Response::allow() : Response::denyWithStatus(404);
    }

    public function complete(User $user): Response
    {
        return $user->can('service_request:complete') ? Response::allow() : Response::denyWithStatus(404);
    }

    private function linkedEmployee(User $user, Employee $employee): bool
    {
        // A stale active flag cannot authorize a future or already ended position.
        // Read stored ISO dates rather than the model's localized display-date cast.
        $startDate = $employee->getRawOriginal('start_date');
        $endDate = $employee->getRawOriginal('end_date');
        if (($startDate !== null && CarbonImmutable::parse($startDate)->startOfDay()->greaterThan(today()))
            || ($endDate !== null && CarbonImmutable::parse($endDate)->startOfDay()->lessThanOrEqualTo(today()))) {
            return false;
        }

        return $employee->legalEntityId === legalEntity()->id
            && $employee->status === Status::APPROVED
            && $employee->isActive
            && $user->employees()->whereKey($employee->id)->exists()
            && $user->hasAllowedRole($employee->employeeType);
    }
}
