<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\Person\ClinicalImpressionStatus;
use App\Enums\Person\ConditionVerificationStatus;
use App\Enums\Person\DiagnosticReportStatus;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\ImmunizationStatus;
use App\Enums\Person\ObservationStatus;
use App\Enums\Person\ProcedureStatus;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Encounter as EncounterSql;
use App\Models\MedicalEvents\Sql\EncounterDiagnose;
use App\Models\MedicalEvents\Sql\Immunization;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property Encounter $model
 */
class EncounterRepository extends BaseRepository
{
    /**
     * Create encounter in DB for the patient (person or preperson) with related data.
     *
     * @param  array  $data
     * @param  Person|Preperson  $patient
     * @return false|int
     * @throws Throwable
     */
    public function store(array $data, Person|Preperson $patient): false|int
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        return DB::transaction(function () use ($data, $ownerColumn, $ownerId) {
            $visit = Repository::identifier()->store($data['visit']['identifier']['value']);
            Repository::codeableConcept()->attach($visit, $data['visit']);

            $episode = Repository::identifier()->store($data['episode']['identifier']['value']);
            Repository::codeableConcept()->attach($episode, $data['episode']);

            $class = Repository::coding()->store($data['class']);

            $type = Repository::codeableConcept()->store($data['type']);

            if (isset($data['priority'])) {
                $priority = Repository::codeableConcept()->store($data['priority']);
            }

            $performer = Repository::identifier()->store($data['performer']['identifier']['value']);
            Repository::codeableConcept()->attach($performer, $data['performer']);

            if (isset($data['division'])) {
                $division = Repository::identifier()->store($data['division']['identifier']['value']);
                Repository::codeableConcept()->attach($division, $data['division']);
            }

            if (isset($data['incomingReferral'])) {
                $incomingReferral = Repository::identifier()->store(
                    $data['incomingReferral']['identifier']['value'],
                    $data['incomingReferral']['display_value'] ?? $data['incomingReferral']['displayValue'] ?? null
                );
                Repository::codeableConcept()->attach($incomingReferral, $data['incomingReferral']);
            }

            $encounter = $this->model->create([
                $ownerColumn => $ownerId,
                'uuid' => $data['uuid'] ?? $data['id'],
                'status' => $data['status'],
                'visit_id' => $visit->id,
                'episode_id' => $episode->id,
                'class_id' => $class->id,
                'type_id' => $type->id,
                'priority_id' => $priority->id ?? null,
                'performer_id' => $performer->id,
                'division_id' => $division->id ?? null,
                'incoming_referral_id' => $incomingReferral->id ?? null,
                'prescriptions' => $data['prescriptions'] ?? null
            ]);

            $encounter->period()->create([
                'start' => $data['period']['start'],
                'end' => $data['period']['end']
            ]);

            if (!empty($data['paperReferral'])) {
                Repository::paperReferral()->store($data['paperReferral'], $encounter);
            }

            if (!empty($data['reasons'])) {
                $encounter->reasons()->attach(
                    collect($data['reasons'])
                        ->map(static fn (array $reasonData) => Repository::codeableConcept()->store($reasonData)->id)
                        ->all()
                );
            }

            foreach ($data['diagnoses'] as $diagnoseData) {
                $condition = Repository::identifier()->store($diagnoseData['condition']['identifier']['value']);
                Repository::codeableConcept()->attach($condition, $diagnoseData['condition']);

                $role = Repository::codeableConcept()->store($diagnoseData['role']);

                $encounter->diagnoses()->create([
                    'condition_id' => $condition->id,
                    'role_id' => $role->id,
                    'rank' => $diagnoseData['rank'] ?? null
                ]);
            }

            if (!empty($data['actions'])) {
                $encounter->actions()->attach(
                    collect($data['actions'])
                        ->map(static fn (array $actionData) => Repository::codeableConcept()->store($actionData)->id)
                        ->all()
                );
            }

            if (isset($data['actionReferences'])) {
                foreach ($data['actionReferences'] as $actionReference) {
                    $identifier = Repository::identifier()->store(
                        $actionReference['identifier']['value'],
                        $actionReference['display_value'] ?? null
                    );
                    Repository::codeableConcept()->attach($identifier, $actionReference);
                    $encounter->actionReferences()->attach($identifier->id);
                }
            }

            if (isset($data['participant'])) {
                foreach ($data['participant'] as $participant) {
                    $identifier = Repository::identifier()->store(
                        $participant['identifier']['value'],
                        $participant['display_value'] ?? null
                    );
                    Repository::codeableConcept()->attach($identifier, $participant);
                    $encounter->participants()->attach($identifier->id);
                }
            }

            if (isset($data['supportingInfo'])) {
                foreach ($data['supportingInfo'] as $supporting) {
                    $identifier = Repository::identifier()->store(
                        $supporting['identifier']['value'],
                        $supporting['display_value'] ?? null
                    );
                    Repository::codeableConcept()->attach($identifier, $supporting);
                    $encounter->supportingInfo()->attach($identifier->id);
                }
            }

            return $encounter->id;
        });
    }

    /**
     * The field each kind of record of the encounter package carries its status in, and the cancelled status.
     *
     * @var array
     */
    private const array CANCELLED_STATUSES = [
        Condition::class => ['verification_status', ConditionVerificationStatus::ENTERED_IN_ERROR],
        Observation::class => ['status', ObservationStatus::ENTERED_IN_ERROR],
        Immunization::class => ['status', ImmunizationStatus::ENTERED_IN_ERROR],
        DiagnosticReport::class => ['status', DiagnosticReportStatus::ENTERED_IN_ERROR],
        Procedure::class => ['status', ProcedureStatus::ENTERED_IN_ERROR],
        ClinicalImpression::class => ['status', ClinicalImpressionStatus::ENTERED_IN_ERROR]
    ];

    /**
     * The same for the records that may be marked as entered in error on their own, keyed by the package section
     * they arrive in. Conditions are left out on purpose: a diagnosis only goes with the whole encounter.
     *
     * @var array
     */
    private const array SEPARATELY_CANCELLED_STATUSES = [
        'observations' => [Observation::class, 'status', ObservationStatus::ENTERED_IN_ERROR],
        'immunizations' => [Immunization::class, 'status', ImmunizationStatus::ENTERED_IN_ERROR],
        'diagnosticReports' => [DiagnosticReport::class, 'status', DiagnosticReportStatus::ENTERED_IN_ERROR],
        'procedures' => [Procedure::class, 'status', ProcedureStatus::ENTERED_IN_ERROR],
        'clinicalImpressions' => [ClinicalImpression::class, 'status', ClinicalImpressionStatus::ENTERED_IN_ERROR]
    ];

    /**
     * Mark the given records of the encounter package as entered in error, leaving the encounter and every
     * record left out as they are.
     *
     * @param  string  $encounterId  eHealth ID of the encounter the records belong to
     * @param  array  $recordIds  eHealth IDs of the records, keyed by package section
     * @param  string  $explanatoryLetter
     * @return void
     * @throws Throwable
     */
    public function markRecordsAsEnteredInError(
        string $encounterId,
        array $recordIds,
        string $explanatoryLetter
    ): void {
        DB::transaction(static function () use ($encounterId, $recordIds, $explanatoryLetter): void {
            foreach (self::SEPARATELY_CANCELLED_STATUSES as $packageKey => [$model, $statusField, $status]) {
                $uuids = $recordIds[$packageKey] ?? [];

                if ($uuids === []) {
                    continue;
                }

                $model::forEncounter($encounterId)
                    ->whereIn('uuid', $uuids)
                    ->where($statusField, '!=', $status->value)
                    ->update([
                        $statusField => $status->value,
                        'explanatory_letter' => $explanatoryLetter
                    ]);
            }
        });
    }

    /**
     * eHealth IDs of the package records already marked as entered in error, keyed by package section.
     *
     * @param  string  $encounterId  eHealth ID of the encounter
     * @return array
     */
    public function cancelledRecordIds(string $encounterId): array
    {
        $cancelled = [];

        foreach (self::SEPARATELY_CANCELLED_STATUSES as $packageKey => [$model, $statusField, $status]) {
            $cancelled[$packageKey] = $model::forEncounter($encounterId)
                ->where($statusField, $status->value)
                ->pluck('uuid')
                ->toArray();
        }

        return $cancelled;
    }

    /**
     * Mark the encounter and every record created alongside it as entered in error.
     *
     * @param  Encounter  $encounter
     * @param  array  $cancellationReason
     * @param  string  $explanatoryLetter
     * @return void
     * @throws Throwable
     */
    public function markAsEnteredInError(
        Encounter $encounter,
        array $cancellationReason,
        string $explanatoryLetter
    ): void {
        DB::transaction(static function () use ($encounter, $cancellationReason, $explanatoryLetter): void {
            $encounter->loadMissing(['cancellationReason.coding']);

            $cancellationReasonModel = $encounter->cancellationReason
                ? Repository::codeableConcept()->update($encounter->cancellationReason, $cancellationReason)
                : Repository::codeableConcept()->store($cancellationReason);

            $encounter->update([
                'status' => EncounterStatus::ENTERED_IN_ERROR->value,
                'cancellation_reason_id' => $cancellationReasonModel->id,
                'explanatory_letter' => $explanatoryLetter
            ]);

            foreach (self::CANCELLED_STATUSES as $model => [$statusField, $cancelledStatus]) {
                $model::forEncounter($encounter->uuid)
                    ->where($statusField, '!=', $cancelledStatus->value)
                    ->update([
                        $statusField => $cancelledStatus->value,
                        'explanatory_letter' => $explanatoryLetter
                    ]);
            }
        });
    }

    /**
     * Get the encounter for the clinical impression based on the provided UUID to display the selected supporting info.
     *
     * @param  array  $uuids
     * @return array
     */
    public function getDetailsMapByUuids(array $uuids): array
    {
        $encounters = EncounterSql::whereIn('uuid', $uuids)
            ->with(['period', 'diagnoses'])
            ->get();

        $conditionUuids = $encounters
            ->map(fn (EncounterSql $encounter) => data_get($encounter->toArray(), 'diagnoses.0.condition.identifier.value'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $conditions = Condition::whereIn('uuid', $conditionUuids)
            ->with('code.coding')
            ->get()
            ->keyBy('uuid');

        return $encounters
            ->mapWithKeys(function (EncounterSql $encounter) use ($conditions) {
                $conditionUuid = data_get($encounter->toArray(), 'diagnoses.0.condition.identifier.value');
                $condition = $conditionUuid ? $conditions->get($conditionUuid) : null;

                return [
                    $encounter->uuid => [
                        'ehealthInsertedAt' => convertToAppDateFormat($encounter->period?->start),
                        'codeCode' => $condition?->code?->coding?->first()?->code,
                        'type' => 'encounter'
                    ]
                ];
            })
            ->toArray();
    }

    /**
     * Get encounter data that is related to the patient (person or preperson).
     * Every record carries a readable `name` built from the action and class codes, so it can label a filter option.
     *
     * @param  Person|Preperson  $patient
     * @return array
     */
    public function getByPersonId(Person|Preperson $patient): array
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        return $this->model
            ->withRelationships()
            ->where($ownerColumn, $ownerId)
            ->get()
            ->map(static function (Encounter $encounter): array {
                $data = $encounter->toArray();

                $label = collect([
                    data_get($data, 'actions.0.coding.0.code'),
                    data_get($data, 'class.code')
                ])->filter()->implode(' | ');

                $data['name'] = $label ?: $encounter->uuid;

                return $data;
            })
            ->toArray();
    }

    /**
     * Sync encounter data and related data by comparing existing data with API data.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(Person|Preperson $patient, array $validatedData): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($ownerColumn, $ownerId, $validatedData) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingEncounters = $this->model->whereIn('uuid', $apiUuids)
                ->withRelationships()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingEncounters->get($data['uuid']);

                $class = $this->syncCoding($existing, $data['class'], 'class');
                $type = $this->syncCodeableConcept($existing, $data['type'], 'type');
                $priority = $this->syncCodeableConcept($existing, $data['priority'] ?? null, 'priority');
                $cancellationReason = $this->syncCodeableConcept(
                    $existing,
                    $data['cancellation_reason'] ?? null,
                    'cancellationReason'
                );
                $performerSpeciality = $this->syncCodeableConcept(
                    $existing,
                    $data['performer_speciality'] ?? null,
                    'performerSpeciality'
                );

                $visit = $this->syncIdentifier($existing, $data['visit'] ?? null, 'visit');
                $episode = $this->syncIdentifier($existing, $data['episode'], 'episode');
                $incomingReferral = $this->syncIdentifier(
                    $existing,
                    $data['incoming_referral'] ?? null,
                    'incomingReferral'
                );
                $originEpisode = $this->syncIdentifier(
                    $existing,
                    $data['origin_episode'] ?? null,
                    'originEpisode'
                );
                $performer = $this->syncIdentifier($existing, $data['performer'] ?? null, 'performer');
                $division = $this->syncIdentifier($existing, $data['division'] ?? null, 'division');

                $encounterData = [
                    $ownerColumn => $ownerId,
                    'status' => $data['status'],
                    'cancellation_reason_id' => $cancellationReason?->id,
                    'explanatory_letter' => $data['explanatory_letter'] ?? null,
                    'prescriptions' => $data['prescriptions'] ?? null,
                    'class_id' => $class->id,
                    'type_id' => $type->id,
                    'priority_id' => $priority?->id,
                    'performer_speciality_id' => $performerSpeciality?->id,
                    'visit_id' => $visit?->id,
                    'episode_id' => $episode->id,
                    'incoming_referral_id' => $incomingReferral?->id,
                    'origin_episode_id' => $originEpisode?->id,
                    'performer_id' => $performer?->id,
                    'division_id' => $division?->id,
                    'ehealth_inserted_at' => $data['ehealth_inserted_at'] ?? null,
                    'ehealth_updated_at' => $data['ehealth_updated_at'] ?? null
                ];

                if ($existing) {
                    $existing->update($encounterData);
                    $encounter = $existing;
                } else {
                    $encounter = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $encounterData)
                    );
                }

                Repository::period()->sync($encounter, $data['period']);

                $this->syncPivot(
                    $encounter,
                    'reasons',
                    $this->syncCodeableConcepts($existing, $data['reasons'] ?? null, 'reasons')
                );
                $this->syncPivot(
                    $encounter,
                    'actions',
                    $this->syncCodeableConcepts($existing, $data['actions'] ?? null, 'actions')
                );
                $this->syncPivot(
                    $encounter,
                    'actionReferences',
                    $this->syncIdentifiers($existing, $data['action_references'] ?? null, 'actionReferences')
                );
                $this->syncPivot(
                    $encounter,
                    'participants',
                    $this->syncIdentifiers($existing, $data['participant'] ?? null, 'participants')
                );
                $this->syncPivot(
                    $encounter,
                    'supportingInfo',
                    $this->syncIdentifiers($existing, $data['supporting_info'] ?? null, 'supportingInfo')
                );

                $this->syncDiagnoses($encounter, $data['diagnoses'] ?? []);
                $this->syncHospitalization($encounter, $data['hospitalization'] ?? null);

                if (!empty($data['paper_referral'])) {
                    Repository::paperReferral()->sync($data['paper_referral'], $encounter, $existing);
                } else {
                    $encounter->paperReferral?->delete();
                }
            }
        });
    }

    /**
     * Sync encounter diagnoses (HasMany) with nested condition identifiers and role codeable concepts.
     *
     * @param  Encounter  $encounter
     * @param  array  $diagnosesData
     * @return void
     */
    protected function syncDiagnoses(Encounter $encounter, array $diagnosesData): void
    {
        $existingDiagnoses = $encounter->relationLoaded('diagnoses') ? $encounter->diagnoses : collect();

        if (empty($diagnosesData)) {
            $existingDiagnoses->each(fn (EncounterDiagnose $diagnose) => $diagnose->delete());

            return;
        }

        $existingByConditionValue = $existingDiagnoses->keyBy(
            fn (EncounterDiagnose $diagnose) => $diagnose->condition?->value
        );

        $newConditionValues = collect($diagnosesData)
            ->pluck('condition.identifier.value')
            ->filter()
            ->toArray();

        $existingDiagnoses->filter(
            fn (EncounterDiagnose $diagnose) => !in_array($diagnose->condition->value, $newConditionValues, true)
        )
            ->each(fn (EncounterDiagnose $diagnose) => $diagnose->delete());

        foreach ($diagnosesData as $diagnoseData) {
            $conditionValue = $diagnoseData['condition']['identifier']['value'];
            /** @var EncounterDiagnose|null $existingDiagnose */
            $existingDiagnose = $existingByConditionValue->get($conditionValue);

            if ($existingDiagnose) {
                $this->updateIdentifier($existingDiagnose->condition, $diagnoseData['condition']);
                $this->updateCodeableConcept($existingDiagnose->role, $diagnoseData['role']);
                $existingDiagnose->update(['rank' => $diagnoseData['rank'] ?? null]);
            } else {
                $condition = Repository::identifier()->store($conditionValue);
                Repository::codeableConcept()->attach($condition, $diagnoseData['condition']);

                $role = Repository::codeableConcept()->store($diagnoseData['role']);

                $encounter->diagnoses()->create([
                    'condition_id' => $condition->id,
                    'role_id' => $role->id,
                    'rank' => $diagnoseData['rank'] ?? null
                ]);
            }
        }
    }

    /**
     * Sync encounter hospitalization (HasOne) with nested codings and destination identifier.
     *
     * @param  Encounter  $encounter
     * @param  array|null  $hospitalization
     * @return void
     */
    protected function syncHospitalization(Encounter $encounter, ?array $hospitalization): void
    {
        if (empty($hospitalization)) {
            $encounter->hospitalization?->delete();

            return;
        }

        $existingHospitalization = $encounter->wasRecentlyCreated ? null : $encounter->hospitalization;

        $admitSource = $this->syncCoding(
            $existingHospitalization,
            $hospitalization['admit_source']['coding'][0] ?? null,
            'admitSource'
        );
        $reAdmission = $this->syncCoding(
            $existingHospitalization,
            $hospitalization['re_admission']['coding'][0] ?? null,
            'reAdmission'
        );
        $dischargeDisposition = $this->syncCoding(
            $existingHospitalization,
            $hospitalization['discharge_disposition']['coding'][0] ?? null,
            'dischargeDisposition'
        );
        $dischargeDepartment = $this->syncCoding(
            $existingHospitalization,
            $hospitalization['discharge_department']['coding'][0] ?? null,
            'dischargeDepartment'
        );
        $destination = $this->syncIdentifier($existingHospitalization, $hospitalization['destination'] ?? null, 'destination');

        $hospitalizationData = [
            'pre_admission_identifier' => $hospitalization['pre_admission_identifier'] ?? null,
            'admit_source_id' => $admitSource?->id,
            're_admission_id' => $reAdmission?->id,
            'destination_id' => $destination?->id,
            'discharge_disposition_id' => $dischargeDisposition?->id,
            'discharge_department_id' => $dischargeDepartment?->id
        ];

        if ($existingHospitalization) {
            $existingHospitalization->update($hospitalizationData);
        } else {
            $encounter->hospitalization()->create($hospitalizationData);
        }
    }
}
