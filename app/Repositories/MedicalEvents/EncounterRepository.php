<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Encounter as EncounterSql;
use App\Models\MedicalEvents\Sql\EncounterDiagnose;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property Encounter $model
 */
class EncounterRepository extends BaseRepository
{
    /**
     * Create encounter in DB for person with related data.
     *
     * @param  array  $data
     * @param  int  $personId
     * @return false|int
     * @throws Throwable
     */
    public function store(array $data, int $personId): false|int
    {
        return DB::transaction(function () use ($data, $personId) {
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
                    $data['incomingReferral']['identifier']['value']
                );
                Repository::codeableConcept()->attach($incomingReferral, $data['incomingReferral']);
            }

            $encounter = $this->model->create([
                'person_id' => $personId,
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

            $reasonIds = [];

            foreach (($data['reasons'] ?? []) as $reasonData) {
                $reason = Repository::codeableConcept()->store($reasonData);

                $reasonIds[] = $reason->id;
            }

            if (!empty($reasonIds)) {
                $encounter->reasons()->attach($reasonIds);
            }

            foreach (($data['diagnoses'] ?? []) as $diagnoseData) {
                $condition = Repository::identifier()->store($diagnoseData['condition']['identifier']['value']);
                Repository::codeableConcept()->attach($condition, $diagnoseData['condition']);

                $role = Repository::codeableConcept()->store($diagnoseData['role']);

                $encounter->diagnoses()->create([
                    'condition_id' => $condition->id,
                    'role_id' => $role->id,
                    'rank' => $diagnoseData['rank'] ?? null
                ]);
            }

            $actionIds = [];

            foreach (($data['actions'] ?? []) as $actionData) {
                $action = Repository::codeableConcept()->store($actionData);

                $actionIds[] = $action->id;
            }

            if (!empty($actionIds)) {
                $encounter->actions()->attach($actionIds);
            }

            $this->attachIdentifierReferences($encounter, 'actionReferences', $data['actionReferences'] ?? []);
            $this->attachIdentifierReferences($encounter, 'participants', $data['participant'] ?? []);
            $this->attachIdentifierReferences($encounter, 'supportingInfo', $data['supportingInfo'] ?? []);

            return $encounter->id;
        });
    }


    /**
     * Attach identifier-based encounter relations on create.
     *
     * @param  Encounter  $encounter
     * @param  string  $relation
     * @param  array  $items
     * @return void
     */
    private function attachIdentifierReferences(Encounter $encounter, string $relation, array $items): void
    {
        $identifierIds = [];

        foreach ($items as $item) {
            $value = data_get($item, 'identifier.value');

            if (empty($value)) {
                continue;
            }

            $identifier = Repository::identifier()->store(
                $value,
                data_get($item, 'display_value')
            );

            if (data_get($item, 'identifier.type')) {
                Repository::codeableConcept()->attach($identifier, $item);
            }

            $identifierIds[] = $identifier->id;
        }

        if (!empty($identifierIds)) {
            $encounter->{$relation}()->syncWithoutDetaching(array_unique($identifierIds));
        }
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
                        'codeCode' => data_get($condition?->toArray(), 'code.coding.0.code'),
                        'type' => 'encounter'
                    ]
                ];
            })
            ->toArray();
    }

    /**
     * Get encounter data that is related to the person.
     *
     * @param  int  $personId
     * @return array
     */
    public function getByPersonId(int $personId): array
    {
        return $this->model
            ->withRelationships()
            ->where('person_id', $personId)
            ->get()
            ->toArray();
    }

    /**
     * Sync encounter data and related data by comparing existing data with API data.
     *
     * @param  int  $personId
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(int $personId, array $validatedData): void
    {
        DB::transaction(function () use ($personId, $validatedData) {
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
                    'person_id' => $personId,
                    'status' => $data['status'],
                    'cancellation_reason' => $data['cancellation_reason'] ?? null,
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
