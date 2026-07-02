<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\EpisodeCurrentDiagnosis;
use App\Models\MedicalEvents\Sql\EpisodeDiagnosesHistory;
use App\Models\MedicalEvents\Sql\EpisodeDiagnosesHistoryItem;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property Episode $model
 */
class EpisodeRepository extends BaseRepository
{
    /**
     * Create episode for encounter in DB.
     *
     * @param  array  $data
     * @param  Person|Preperson  $patient
     * @param  int|null  $encounterId
     * @return void
     * @throws Throwable
     */
    public function store(array $data, Person|Preperson $patient, ?int $encounterId = null): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($data, $ownerColumn, $ownerId, $encounterId) {
            $type = Repository::coding()->store($data['type']);

            $managingOrganization = Repository::identifier()
                ->store($data['managingOrganization']['identifier']['value']);
            Repository::codeableConcept()->attach($managingOrganization, $data['managingOrganization']);

            $careManager = Repository::identifier()->store($data['careManager']['identifier']['value']);
            Repository::codeableConcept()->attach($careManager, $data['careManager']);

            $episode = $this->model->create([
                'uuid' => $data['id'],
                $ownerColumn => $ownerId,
                'encounter_id' => $encounterId,
                'episode_type_id' => $type->id,
                'status' => $data['status'],
                'name' => $data['name'],
                'managing_organization_id' => $managingOrganization->id,
                'care_manager_id' => $careManager->id
            ]);

            $episode->period()->create(['start' => $data['period']['start']]);
        });
    }

    /**
     * Get the episode for the clinical impression based on the provided UUID to display the selected supporting info.
     *
     * @param  array  $uuids
     * @return array
     */
    public function getDetailsMapByUuids(array $uuids): array
    {
        return collect(
            Episode::whereIn('uuid', $uuids)
                ->select(['uuid', 'name', 'created_at'])
                ->get()
                ->toArray()
        )
            ->mapWithKeys(fn (array $episode) => [
                $episode['uuid'] => [
                    'ehealthInsertedAt' => convertToAppDateFormat($episode['createdAt'] ?? null),
                    'codeCode' => $episode['name'] ?? null,
                    'type' => 'episode_of_care'
                ],
            ])
            ->toArray();
    }

    /**
     * Get episode data that is related to the patient (person or preperson).
     *
     * @param  Person|Preperson  $patient
     * @return array
     */
    public function getByPersonId(Person|Preperson $patient): array
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        return $this->model
            ->where($ownerColumn, $ownerId)
            ->recentlyUpdatedFirst()
            ->get()
            ->toArray();
    }

    /**
     * Sync episodes from eHealth API to database.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $validatedData
     * @throws Throwable
     */
    public function sync(Person|Preperson $patient, array $validatedData): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($ownerColumn, $ownerId, $validatedData) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingEpisodes = $this->model->whereIn('uuid', $apiUuids)
                ->with('period')
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingEpisodes->get($data['uuid']);

                $episodeData = array_merge(
                    [$ownerColumn => $ownerId],
                    Arr::except($data, ['uuid', 'period'])
                );

                if ($existing) {
                    $existing->update($episodeData);
                    $episode = $existing;
                } else {
                    $episode = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $episodeData)
                    );
                }

                Repository::period()->sync($episode, $data['period']);
            }
        });
    }

    /**
     * Sync full episode data from getBySearchParams endpoint.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $validatedData
     * @throws Throwable
     */
    public function syncFull(Person|Preperson $patient, array $validatedData): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($ownerColumn, $ownerId, $validatedData) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingEpisodes = $this->model->whereIn('uuid', $apiUuids)
                ->withRelationships()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingEpisodes->get($data['uuid']);

                $type = $this->syncCoding($existing, $data['type'], 'type');
                $managingOrganization = $this->syncIdentifier(
                    $existing,
                    $data['managing_organization'],
                    'managingOrganization'
                );
                $careManager = $this->syncIdentifier($existing, $data['care_manager'], 'careManager');
                $statusReason = $this->syncCodeableConcept($existing, $data['status_reason'], 'statusReason');

                $episodeData = [
                    $ownerColumn => $ownerId,
                    'status' => $data['status'],
                    'name' => $data['name'],
                    'closing_summary' => $data['closing_summary'] ?? null,
                    'explanatory_letter' => $data['explanatory_letter'] ?? null,
                    'episode_type_id' => $type->id,
                    'managing_organization_id' => $managingOrganization->id,
                    'care_manager_id' => $careManager->id,
                    'status_reason_id' => $statusReason?->id,
                    'ehealth_inserted_at' => $data['ehealth_inserted_at'],
                    'ehealth_updated_at' => $data['ehealth_updated_at']
                ];

                if ($existing) {
                    $existing->update($episodeData);
                    $episode = $existing;
                } else {
                    $episode = $this->model->create(array_merge(['uuid' => $data['uuid']], $episodeData));
                }

                Repository::period()->sync($episode, $data['period']);
                $this->syncCurrentDiagnoses($episode, $data['current_diagnoses'] ?? []);
                $this->syncDiagnosesHistory($episode, $data['diagnoses_history'] ?? []);
                $this->syncStatusHistory($episode, $data['status_history']);
            }
        });
    }

    /**
     * Sync current diagnoses by comparing existing entries with API data by index.
     *
     * @param  Episode  $episode
     * @param  array  $currentDiagnoses
     * @return void
     */
    private function syncCurrentDiagnoses(Episode $episode, array $currentDiagnoses): void
    {
        $existingDiagnoses = $episode->relationLoaded('currentDiagnoses') ? $episode->currentDiagnoses : collect();

        if (empty($currentDiagnoses)) {
            $existingDiagnoses->each(fn (EpisodeCurrentDiagnosis $diagnose) => $diagnose->delete());

            return;
        }

        foreach ($currentDiagnoses as $index => $currentDiagnose) {
            $existingDiagnose = $existingDiagnoses[$index] ?? null;

            if ($existingDiagnose) {
                $this->updateCodeableConcept($existingDiagnose->code, $currentDiagnose['code']);
                $this->updateIdentifier($existingDiagnose->condition, $currentDiagnose['condition']);
                $this->updateCodeableConcept($existingDiagnose->role, $currentDiagnose['role']);
                $existingDiagnose->update(['rank' => $currentDiagnose['rank'] ?? null]);
            } else {
                $code = Repository::codeableConcept()->store($currentDiagnose['code']);
                $condition = Repository::identifier()->store($currentDiagnose['condition']['identifier']['value']);
                Repository::codeableConcept()->attach($condition, $currentDiagnose['condition']);
                $role = Repository::codeableConcept()->store($currentDiagnose['role']);

                $episode->currentDiagnoses()->create([
                    'code_id' => $code->id,
                    'condition_id' => $condition->id,
                    'role_id' => $role->id,
                    'rank' => $currentDiagnose['rank'] ?? null
                ]);
            }
        }

        foreach ($existingDiagnoses->slice(count($currentDiagnoses)) as $extra) {
            $extra->delete();
        }
    }

    /**
     * Sync diagnoses history by comparing existing entries with API data by index.
     *
     * @param  Episode  $episode
     * @param  array  $diagnosesHistory
     * @return void
     */
    private function syncDiagnosesHistory(Episode $episode, array $diagnosesHistory): void
    {
        $existingHistory = $episode->relationLoaded('diagnosesHistory') ? $episode->diagnosesHistory : collect();

        if (empty($diagnosesHistory)) {
            $existingHistory->each(fn (EpisodeDiagnosesHistory $entry) => $entry->delete());

            return;
        }

        foreach ($diagnosesHistory as $index => $diagnoseHistory) {
            $existingEntry = $existingHistory[$index] ?? null;

            if ($existingEntry) {
                if ($existingEntry->evidence) {
                    $this->updateIdentifier($existingEntry->evidence, $diagnoseHistory['evidence']);
                } else {
                    $evidence = Repository::identifier()->store($diagnoseHistory['evidence']['identifier']['value']);
                    Repository::codeableConcept()->attach($evidence, $diagnoseHistory['evidence']);
                    $existingEntry->update(['evidence_id' => $evidence->id]);
                }

                $existingEntry->update([
                    'date' => $diagnoseHistory['date'],
                    'is_active' => $diagnoseHistory['is_active']
                ]);

                $this->syncDiagnosesHistoryItems($existingEntry, $diagnoseHistory['diagnoses']);
            } else {
                $evidence = Repository::identifier()->store($diagnoseHistory['evidence']['identifier']['value']);
                Repository::codeableConcept()->attach($evidence, $diagnoseHistory['evidence']);

                $historyEntry = $episode->diagnosesHistory()->create([
                    'evidence_id' => $evidence->id,
                    'date' => $diagnoseHistory['date'],
                    'is_active' => $diagnoseHistory['is_active']
                ]);

                $this->syncDiagnosesHistoryItems($historyEntry, $diagnoseHistory['diagnoses']);
            }
        }

        foreach ($existingHistory->slice(count($diagnosesHistory)) as $extra) {
            $extra->delete();
        }
    }

    /**
     * Sync diagnoses history items by comparing existing items with API data by index.
     *
     * @param  EpisodeDiagnosesHistory  $historyEntry
     * @param  array  $items
     * @return void
     */
    private function syncDiagnosesHistoryItems(EpisodeDiagnosesHistory $historyEntry, array $items): void
    {
        $existingItems = $historyEntry->relationLoaded('diagnoses') ? $historyEntry->diagnoses : collect();

        if (empty($items)) {
            $existingItems->each(fn (EpisodeDiagnosesHistoryItem $item) => $item->delete());

            return;
        }

        foreach ($items as $index => $item) {
            $existingItem = $existingItems[$index] ?? null;

            if ($existingItem) {
                $this->updateCodeableConcept($existingItem->code, $item['code']);
                $this->updateIdentifier($existingItem->condition, $item['condition']);
                $this->updateCodeableConcept($existingItem->role, $item['role']);
                $existingItem->update(['rank' => $item['rank'] ?? null]);
            } else {
                $code = Repository::codeableConcept()->store($item['code']);
                $condition = Repository::identifier()->store($item['condition']['identifier']['value']);
                Repository::codeableConcept()->attach($condition, $item['condition']);
                $role = Repository::codeableConcept()->store($item['role']);

                $historyEntry->diagnoses()->create([
                    'code_id' => $code->id,
                    'condition_id' => $condition->id,
                    'role_id' => $role->id,
                    'rank' => $item['rank'] ?? null
                ]);
            }
        }

        foreach ($existingItems->slice(count($items)) as $extra) {
            $extra->delete();
        }
    }

    /**
     * Sync status history by comparing existing entries with API data by index.
     *
     * @param  Episode  $episode
     * @param  array  $statusHistory
     * @return void
     */
    private function syncStatusHistory(Episode $episode, array $statusHistory): void
    {
        $existingHistory = $episode->relationLoaded('statusHistory') ? $episode->statusHistory : collect();

        foreach ($statusHistory as $index => $item) {
            $existingEntry = $existingHistory[$index] ?? null;

            if ($existingEntry) {
                $statusReasonId = $existingEntry->statusReasonId;

                if ($existingEntry->statusReason && !empty($item['status_reason'])) {
                    $this->updateCodeableConcept($existingEntry->statusReason, $item['status_reason']);
                } elseif (!$existingEntry->statusReason && !empty($item['status_reason'])) {
                    $statusReason = Repository::codeableConcept()->store($item['status_reason']);
                    $statusReasonId = $statusReason->id;
                }

                $existingEntry->update([
                    'status' => $item['status'],
                    'ehealth_inserted_by' => $item['ehealth_inserted_by'],
                    'ehealth_inserted_at' => $item['ehealth_inserted_at'],
                    'status_reason_id' => $statusReasonId
                ]);
            } else {
                $statusReasonId = null;

                if (!empty($item['status_reason'])) {
                    $statusReason = Repository::codeableConcept()->store($item['status_reason']);
                    $statusReasonId = $statusReason->id;
                }

                $episode->statusHistory()->create([
                    'status' => $item['status'],
                    'ehealth_inserted_by' => $item['ehealth_inserted_by'],
                    'ehealth_inserted_at' => $item['ehealth_inserted_at'],
                    'status_reason_id' => $statusReasonId
                ]);
            }
        }

        foreach ($existingHistory->slice(count($statusHistory)) as $extra) {
            $extra->delete();
        }
    }
}
