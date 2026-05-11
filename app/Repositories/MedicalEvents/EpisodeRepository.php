<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\EpisodeDiagnosesHistory;
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
     * @param  int  $personId
     * @param  int|null  $encounterId
     * @return void
     * @throws Throwable
     */
    public function store(array $data, int $personId, ?int $encounterId = null): void
    {
        DB::transaction(function () use ($data, $personId, $encounterId) {
            $type = Repository::coding()->store($data['type']);

            $managingOrganization = Repository::identifier()
                ->store($data['managingOrganization']['identifier']['value']);
            Repository::codeableConcept()->attach($managingOrganization, $data['managingOrganization']);

            $careManager = Repository::identifier()->store($data['careManager']['identifier']['value']);
            Repository::codeableConcept()->attach($careManager, $data['careManager']);

            $episode = $this->model->create([
                'uuid' => $data['id'],
                'person_id' => $personId,
                'encounter_id' => $encounterId,
                'episode_type_id' => $type->id,
                'status' => $data['status'],
                'name' => $data['name'],
                'managing_organization_id' => $managingOrganization->id,
                'care_manager_id' => $careManager->id
            ]);

            $episode->period()->create([
                'start' => $data['period']['start']
            ]);
        });
    }

    /**
     * Get episode data that is related to the encounter.
     *
     * @param  int  $encounterId
     * @return array|null
     */
    public function get(int $encounterId): ?array
    {
        return $this->model::with([
            'type',
            'managingOrganization',
            'careManager'
        ])
            ->where('encounter_id', $encounterId)
            ->first()
            ?->toArray();
    }

    /**
     * Get the episode for the clinical impression based on the provided UUID to display the selected supporting info.
     *
     * @param  string  $uuid
     * @return array|null
     */
    public function getForClinicalImpression(string $uuid): ?array
    {
        return Episode::whereUuid($uuid)
            ->select(['name', 'created_at'])
            ->first()
            ?->toArray();
    }

    /**
     * Sync episodes from eHealth API to database.
     *
     * @param  int  $personId
     * @param  array  $validatedData
     * @throws Throwable
     */
    public function sync(int $personId, array $validatedData): void
    {
        DB::transaction(function () use ($personId, $validatedData) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            // Load existing episodes with relations
            $existingEpisodes = $this->model->whereIn('uuid', $apiUuids)
                ->with('period')
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingEpisodes->get($data['uuid']);

                $episodeData = array_merge(
                    ['person_id' => $personId],
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
     * @param  int  $personId
     * @param  array  $validatedData
     * @throws Throwable
     */
    public function syncFull(int $personId, array $validatedData): void
    {
        DB::transaction(function () use ($personId, $validatedData) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingEpisodes = $this->model->whereIn('uuid', $apiUuids)
                ->with([
                    'type',
                    'managingOrganization.type.coding',
                    'careManager.type.coding',
                    'statusReason.coding',
                    'period',
                    'currentDiagnoses.code.coding',
                    'currentDiagnoses.condition.type.coding',
                    'currentDiagnoses.role.coding',
                    'diagnosesHistory.evidence.type.coding',
                    'diagnosesHistory.diagnoses.code.coding',
                    'diagnosesHistory.diagnoses.condition.type.coding',
                    'diagnosesHistory.diagnoses.role.coding',
                    'statusHistory.statusReason.coding'
                ])
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingEpisodes->get($data['uuid']);

                $type = $this->syncCoding($existing, $data['type'] ?? null, 'type');
                $managingOrganization = $this->syncIdentifier(
                    $existing,
                    $data['managing_organization'] ?? null,
                    'managingOrganization'
                );
                $careManager = $this->syncIdentifier($existing, $data['care_manager'] ?? null, 'careManager');
                $statusReason = $this->syncCodeableConcept($existing, $data['status_reason'] ?? null, 'statusReason');

                $episodeData = [
                    'person_id' => $personId,
                    'status' => $data['status'],
                    'name' => $data['name'],
                    'closing_summary' => $data['closing_summary'] ?? null,
                    'explanatory_letter' => $data['explanatory_letter'] ?? null,
                    'episode_type_id' => $type?->id,
                    'managing_organization_id' => $managingOrganization?->id,
                    'care_manager_id' => $careManager?->id,
                    'status_reason_id' => $statusReason?->id,
                    'ehealth_inserted_at' => $data['ehealth_inserted_at'] ?? null,
                    'ehealth_updated_at' => $data['ehealth_updated_at'] ?? null
                ];

                if ($existing) {
                    $existing->update($episodeData);
                    $episode = $existing;
                } else {
                    $episode = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $episodeData)
                    );
                }

                Repository::period()->sync($episode, $data['period'] ?? null);
                $this->syncCurrentDiagnoses($episode, $existing, $data['current_diagnoses'] ?? []);
                $this->syncDiagnosesHistory($episode, $existing, $data['diagnoses_history'] ?? []);
                $this->syncStatusHistory($episode, $existing, $data['status_history'] ?? []);
            }
        });
    }

    /**
     * Sync current diagnoses by comparing existing entries with API data by index.
     *
     * @param  Episode  $episode
     * @param  Episode|null  $existing
     * @param  array  $items
     * @return void
     */
    private function syncCurrentDiagnoses(Episode $episode, ?Episode $existing, array $items): void
    {
        $existingDiagnoses = $existing?->currentDiagnoses ?? collect();

        foreach ($items as $index => $item) {
            $existingDiagnosis = $existingDiagnoses[$index] ?? null;

            if ($existingDiagnosis) {
                $this->updateCodeableConcept($existingDiagnosis->code, $item['code']);
                $this->updateIdentifier($existingDiagnosis->condition, $item['condition']);
                $this->updateCodeableConcept($existingDiagnosis->role, $item['role']);
                $existingDiagnosis->update(['rank' => $item['rank'] ?? null]);
            } else {
                $code = Repository::codeableConcept()->store($item['code']);
                $condition = Repository::identifier()->store($item['condition']['identifier']['value']);
                Repository::codeableConcept()->attach($condition, $item['condition']);
                $role = Repository::codeableConcept()->store($item['role']);

                $episode->currentDiagnoses()->create([
                    'code_id' => $code->id,
                    'condition_id' => $condition->id,
                    'role_id' => $role->id,
                    'rank' => $item['rank'] ?? null
                ]);
            }
        }

        foreach ($existingDiagnoses->slice(count($items)) as $extra) {
            $extra->delete();
        }
    }

    /**
     * Sync diagnoses history by comparing existing entries with API data by index.
     *
     * @param  Episode  $episode
     * @param  Episode|null  $existing
     * @param  array  $items
     * @return void
     */
    private function syncDiagnosesHistory(Episode $episode, ?Episode $existing, array $items): void
    {
        $existingHistory = $existing?->diagnosesHistory ?? collect();

        foreach ($items as $index => $item) {
            $existingEntry = $existingHistory[$index] ?? null;

            if ($existingEntry) {
                if ($existingEntry->evidence && !empty($item['evidence'])) {
                    $this->updateIdentifier($existingEntry->evidence, $item['evidence']);
                } elseif (!$existingEntry->evidence && !empty($item['evidence'])) {
                    $evidence = Repository::identifier()->store($item['evidence']['identifier']['value']);
                    Repository::codeableConcept()->attach($evidence, $item['evidence']);
                    $existingEntry->update(['evidence_id' => $evidence->id]);
                }

                $existingEntry->update([
                    'date' => $item['date'] ?? null,
                    'is_active' => $item['is_active'] ?? false
                ]);

                $this->syncDiagnosesHistoryItems($existingEntry, $item['diagnoses'] ?? []);
            } else {
                $evidence = null;

                if (!empty($item['evidence'])) {
                    $evidence = Repository::identifier()->store($item['evidence']['identifier']['value']);
                    Repository::codeableConcept()->attach($evidence, $item['evidence']);
                }

                $historyEntry = $episode->diagnosesHistory()->create([
                    'evidence_id' => $evidence?->id,
                    'date' => $item['date'] ?? null,
                    'is_active' => $item['is_active'] ?? false
                ]);

                foreach ($item['diagnoses'] ?? [] as $diagnosisData) {
                    $code = Repository::codeableConcept()->store($diagnosisData['code']);
                    $condition = Repository::identifier()->store($diagnosisData['condition']['identifier']['value']);
                    Repository::codeableConcept()->attach($condition, $diagnosisData['condition']);
                    $role = Repository::codeableConcept()->store($diagnosisData['role']);

                    $historyEntry->diagnoses()->create([
                        'code_id' => $code->id,
                        'condition_id' => $condition->id,
                        'role_id' => $role->id,
                        'rank' => $diagnosisData['rank'] ?? null
                    ]);
                }
            }
        }

        foreach ($existingHistory->slice(count($items)) as $extra) {
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
        $existingItems = $historyEntry->diagnoses ?? collect();

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
     * @param  Episode|null  $existing
     * @param  array  $items
     * @return void
     */
    private function syncStatusHistory(Episode $episode, ?Episode $existing, array $items): void
    {
        $existingHistory = $existing?->statusHistory ?? collect();

        foreach ($items as $index => $item) {
            $existingEntry = $existingHistory[$index] ?? null;

            if ($existingEntry) {
                $statusReasonId = $existingEntry->status_reason_id;

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
    }
}
