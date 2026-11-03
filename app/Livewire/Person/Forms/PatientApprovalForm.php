<?php

declare(strict_types=1);

namespace App\Livewire\Person\Forms;

use App\Core\BaseForm;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\Person\Person;
use App\Services\MedicalEvents\PatientApprovalService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PatientApprovalForm extends BaseForm
{
    private const array WRITABLE_RESOURCES = [
        'diagnostic_report',
        'encounter',
        'procedure',
        'care_plan',
    ];

    public function validateSelection(Person $person, array $data): array
    {
        $selection = match ($data['mode']) {
            'patient', 'person' => [
                'mode' => $data['mode'],
                'person_uuid' => $person->uuid,
                'access_level' => 'read',
                'grant_to_legal_entity' => false,
            ],

            'resource' => $this->validateResources($person, $data),
            'child' => $this->validateChild($person, $data),
            'service_request' => $this->validateReferral($person, $data),

            'diagnoses_group',
            'forbidden_group',
            'service_group' => $this->validateGroup($data),

            'resource_types' => $this->validateResourceTypes($data),

            default => throw ValidationException::withMessages([
                'mode' => __('approval.create.select_access_type'),
            ]),
        };

        if ($data['grantToLegalEntity'] && $data['mode'] !== 'service_request') {
            throw ValidationException::withMessages([
                'grantToLegalEntity' => __('approval.create.legal_entity_referrals_only'),
            ]);
        }

        if (!in_array($selection['access_level'], ['read', 'write'], true)) {
            throw ValidationException::withMessages([
                'accessLevel' => __('approval.create.invalid_access_level'),
            ]);
        }

        return $selection;
    }

    private function validateResources(Person $person, array $data): array
    {
        $type = $data['resourceType'];

        if (!isset(PatientApprovalService::RESOURCE_MODELS[$type]) || $type === 'service_request') {
            throw ValidationException::withMessages([
                'resourceType' => __('approval.create.select_record_type'),
            ]);
        }

        $ids = $data['resourceIds'];

        if ($ids === [] || collect($ids)->contains(static fn (mixed $uuid): bool => !is_string($uuid) || !Str::isUuid($uuid))) {
            throw ValidationException::withMessages([
                'resourceIds' => __('approval.create.select_records'),
            ]);
        }

        $ids = array_values(array_unique($ids));
        $model = PatientApprovalService::RESOURCE_MODELS[$type];

        if (
            $model::query()
                ->where('person_id', $person->id)
                ->whereIn('uuid', $ids)
                ->count() !== count($ids)
        ) {
            throw ValidationException::withMessages([
                'resourceIds' => __('approval.create.record_patient_mismatch'),
            ]);
        }

        $invalidRecord = $model::query()
            ->where('person_id', $person->id)
            ->whereIn('uuid', $ids)
            ->get(['uuid', 'status'])
            ->first(fn ($record): bool => !PatientApprovalService::approvalStatusAllowed($type, $record->getRawOriginal('status')));

        if ($invalidRecord !== null) {
            throw ValidationException::withMessages([
                'resourceIds' => $type === 'specimen' ? __('approval.create.specimen_not_available') : __('approval.create.invalid_record_status'),
            ]);
        }

        $access = in_array($type, self::WRITABLE_RESOURCES, true) ? $data['accessLevel'] : 'read';

        if (in_array($type, ['encounter', 'procedure'], true) && $access !== 'write') {
            throw ValidationException::withMessages([
                'accessLevel' => __('approval.create.write_only_resource'),
            ]);
        }

        if ($type === 'specimen') {
            $access = 'write';
        }

        return [
            'mode' => 'resource',
            'resource_type' => $type,
            'resource_ids' => $ids,
            'access_level' => $access,
            'grant_to_legal_entity' => false,
        ];
    }

    private function validateChild(Person $person, array $data): array
    {
        $type = $data['childType'];
        $uuid = $data['childUuid'];

        if (!isset(PatientApprovalService::CHILD_MODELS[$type])) {
            throw ValidationException::withMessages([
                'childType' => __('approval.create.invalid_record_type'),
            ]);
        }

        if (!is_string($uuid) || !Str::isUuid($uuid)) {
            throw ValidationException::withMessages([
                'childUuid' => __('approval.create.select_child_record'),
            ]);
        }

        $model = PatientApprovalService::CHILD_MODELS[$type];
        $query = $model::query()->where('uuid', $uuid);

        if ($type === 'activity') {
            $query->whereHas('carePlan', static fn ($query) => $query->where('person_id', $person->id));
        } else {
            $query->where('person_id', $person->id);
        }

        $record = $query->first();

        if ($record === null) {
            throw ValidationException::withMessages([
                'childUuid' => __('approval.create.record_not_found'),
            ]);
        }

        if (
            !PatientApprovalService::approvalStatusAllowed($type, $record->getRawOriginal('status'))
            || ($type === 'condition' && $record->getRawOriginal('verification_status') === 'entered_in_error')
        ) {
            throw ValidationException::withMessages([
                'childUuid' => __('approval.create.invalid_record_status'),
            ]);
        }

        $contextType = 'episode_of_care';
        $contextUuid = match ($type) {
            'activity' => $record->carePlan?->uuid,
            'encounter' => $record->episode?->value,
            default => null,
        };

        if ($type === 'activity') {
            $contextType = 'care_plan';
        }

        if ($type === 'observation' && $record->diagnosticReport?->value) {
            $report = DiagnosticReport::query()
                ->where('person_id', $person->id)
                ->where('uuid', $record->diagnosticReport->value)
                ->where('status', 'final')
                ->first();

            if ($report !== null) {
                $contextType = 'diagnostic_report';
                $contextUuid = $report->uuid;
            }
        }

        if ($contextUuid === null && !in_array($type, ['activity', 'encounter'], true)) {
            $encounterUuid = match ($type) {
                'diagnostic_report', 'procedure', 'clinical_impression' => $record->encounter?->value,
                'condition', 'observation', 'immunization', 'device' => $record->context?->value,
            };

            if ($encounterUuid) {
                $encounter = Encounter::query()
                    ->where('person_id', $person->id)
                    ->where('uuid', $encounterUuid)
                    ->with('episode')
                    ->first();

                $contextUuid = $encounter?->episode?->value;
            }

            if (in_array($type, ['diagnostic_report', 'procedure'], true)) {
                $contextUuid ??= $record->originEpisode?->value;
            }
        }

        if (!$contextUuid) {
            throw ValidationException::withMessages([
                'childUuid' => __('approval.create.missing_context_episode'),
            ]);
        }

        return [
            'mode' => 'child',
            'child_type' => $type,
            'child_uuid' => $record->uuid,
            'context_type' => $contextType,
            'context_uuid' => $contextUuid,
            'access_level' => 'read',
            'grant_to_legal_entity' => false,
        ];
    }

    private function validateReferral(Person $person, array $data): array
    {
        $uuid = $data['resourceIds'][0] ?? null;

        if (!is_string($uuid) || !Str::isUuid($uuid)) {
            throw ValidationException::withMessages([
                'resourceIds' => __('approval.create.select_referral'),
            ]);
        }

        try {
            $referral = app(PatientApprovalService::class)
                ->referralDetails($person, $uuid);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'resourceIds' => __('approval.create.referral_check_failed'),
            ]);
        }

        if (($referral['status'] ?? null) !== 'active') {
            throw ValidationException::withMessages([
                'resourceIds' => __('approval.create.referral_not_active'),
            ]);
        }

        if (empty($referral['permitted_resources'])) {
            throw ValidationException::withMessages([
                'resourceIds' => __('approval.create.referral_no_resources'),
            ]);
        }

        return [
            'mode' => 'service_request',
            'service_request_uuid' => $uuid,
            'access_level' => 'read',
            'grant_to_legal_entity' => $data['grantToLegalEntity'],
        ];
    }

    private function validateGroup(array $data): array
    {
        $group = collect($data['selectableGroups'])
            ->firstWhere('id', $data['groupId']);

        if (!$group) {
            throw ValidationException::withMessages([
                'groupId' => __('approval.create.select_catalog_group'),
            ]);
        }

        return [
            'mode' => $data['mode'],
            'group_id' => $data['groupId'],
            'access_level' => 'read',
            'grant_to_legal_entity' => false,
        ];
    }

    private function validateResourceTypes(array $data): array
    {
        $types = ['device', 'device_association'];

        if ($data['includeDetectedIssue']) {
            $types[] = 'detected_issue';
        }

        return [
            'mode' => 'resource_types',
            'resource_types' => $types,
            'access_level' => 'read',
            'grant_to_legal_entity' => false,
        ];
    }
}
