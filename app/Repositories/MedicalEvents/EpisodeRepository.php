<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Dto\Episode\Model as EpisodeData;
use App\Dto\Episode\ModelCancel as EpisodeCancelData;
use App\Dto\Episode\ModelClose as EpisodeCloseData;
use App\Dto\Episode\ModelUpdate as EpisodeUpdateData;
use App\Enums\Episode\Status;
use App\Enums\JobStatus;
use App\Models\EhealthJob;
use App\Models\EhealthLink;
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
     * Create the episode in DB with its type, identifiers and period.
     * The array is the FHIR structure still built for an encounter package.
     *
     * @param  EpisodeData|array  $data
     * @param  Person|Preperson  $patient
     * @param  int|null  $encounterId
     * @return Episode
     * @throws Throwable
     */
    public function store(EpisodeData|array $data, Person|Preperson $patient, ?int $encounterId = null): Episode
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        if ($data instanceof EpisodeData) {
            return DB::transaction(static function () use ($data, $ownerColumn, $ownerId, $encounterId): Episode {
                $episode = objectMapper()->map($data, new Episode([$ownerColumn => $ownerId, 'encounter_id' => $encounterId]));

                $episode->type()->associate(Repository::coding()->store($data->type));
                $episode->managingOrganization()->associate(Repository::identifier()->store($data->managingOrganization));
                $episode->careManager()->associate(Repository::identifier()->store($data->careManager));
                $episode->saveOrFail();

                $episode->period()->create(['start' => $data->periodStart]);

                return $episode;
            });
        }

        return DB::transaction(function () use ($data, $ownerColumn, $ownerId, $encounterId): Episode {
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

            return $episode;
        });
    }

    /**
     * Update the episode fields that eHealth allows to change.
     *
     * @param  Episode  $episode
     * @param  EpisodeUpdateData  $data
     * @return void
     * @throws Throwable
     */
    public function update(Episode $episode, EpisodeUpdateData $data): void
    {
        DB::transaction(static function () use ($episode, $data): void {
            Repository::identifier()->update($episode->careManager, $data->careManager);

            $episode->update(['name' => $data->name]);
        });
    }

    /**
     * Update a draft episode, which is stored locally only and therefore editable as a whole.
     * The managing organization is left as stored, since a draft stays with the legal entity it was created in.
     * The array is the FHIR structure still built for an encounter package.
     *
     * @param  Episode  $episode
     * @param  EpisodeData|array  $data
     * @return void
     * @throws Throwable
     */
    public function updateDraft(Episode $episode, EpisodeData|array $data): void
    {
        if ($data instanceof EpisodeData) {
            DB::transaction(static function () use ($episode, $data): void {
                objectMapper()->map($data, $episode);

                $episode->type->update($data->type->toArray());
                Repository::identifier()->update($episode->careManager, $data->careManager);
                $episode->period->update(['start' => $data->periodStart]);
                $episode->saveOrFail();
            });

            return;
        }

        DB::transaction(function () use ($episode, $data) {
            $this->updateIdentifier($episode->careManager, $data['careManager']);

            $episode->type->update([
                'system' => $data['type']['system'],
                'code' => $data['type']['code']
            ]);
            $episode->period->update(['start' => $data['period']['start']]);

            $episode->update([
                'name' => $data['name'],
                'status' => $data['status']
            ]);
        });
    }

    /**
     * Mark the episode as entered in error, keeping the reason and the explanation eHealth processed the cancellation with.
     *
     * @param  Episode  $episode
     * @param  EpisodeCancelData  $data
     * @return void
     * @throws Throwable
     */
    public function markAsEnteredInError(Episode $episode, EpisodeCancelData $data): void
    {
        DB::transaction(function () use ($episode, $data): void {
            $episode->update([
                'status' => Status::ENTERED_IN_ERROR->value,
                'status_reason_id' => $this->syncCodeableConcept($episode, $data->statusReason, 'statusReason')->id,
                'explanatory_letter' => $data->explanatoryLetter
            ]);
        });
    }

    /**
     * Close the episode, keeping the reason, the summary and the moment eHealth processed the closing with.
     * An episode already stored as entered in error is left as it is, since its cancellation is newer than the closing.
     *
     * @param  Episode  $episode
     * @param  EpisodeCloseData  $data
     * @return void
     * @throws Throwable
     */
    public function markAsClosed(Episode $episode, EpisodeCloseData $data): void
    {
        DB::transaction(function () use ($episode, $data): void {
            $episode = $episode->newQuery()->whereKey($episode->id)->lockForUpdate()->firstOrFail();

            if ($episode->status === Status::ENTERED_IN_ERROR) {
                return;
            }

            $episode->update([
                'status' => Status::CLOSED->value,
                'status_reason_id' => $this->syncCodeableConcept($episode, $data->statusReason, 'statusReason')->id,
                'closing_summary' => $data->closingSummary
            ]);

            $episode->period()->update(['end' => $data->periodEnd]);
        });
    }

    /**
     * Delete a draft episode together with every row it refers to: identifiers, codeable concepts, codings, its period,
     * the records of its diagnoses and status history, and its rejected submissions.
     *
     * @param  Episode  $episode
     * @return void
     * @throws Throwable
     */
    public function deleteDraft(Episode $episode): void
    {
        DB::transaction(static function () use ($episode): void {
            Repository::encounter()->deleteWithLoadedRows(collect([
                Episode::withRelationships()
                    ->with(['ehealthLinks.job', 'ehealthLinks.processingData'])
                    ->findOrFail($episode->id)
            ]));
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
            $this->model->whereIn('uuid', $uuids)
                ->select(['uuid', 'name', 'ehealth_inserted_at'])
                ->get()
                ->toArray()
        )
            ->mapWithKeys(fn (array $episode) => [
                $episode['uuid'] => [
                    'ehealthInsertedAt' => convertToAppDateFormat($episode['ehealthInsertedAt'] ?? null),
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

    public function getByUuid(Person|Preperson $patient, string $uuid): ?array
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        return $this->model::with('period')
            ->where($ownerColumn, $ownerId)
            ->where('uuid', $uuid)
            ->first()
            ?->toArray();
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

            $this->confirmPendingSubmissions($existingEpisodes->modelKeys());
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

            $this->confirmPendingSubmissions($existingEpisodes->modelKeys());
        });
    }

    /**
     * Mark the pending submissions of the synced episodes as processed once the sync confirms them. An existing episode
     * confirms its creation, while a closing or a cancellation is confirmed only once the episode has the status it was
     * sent for. This also releases a submission whose job could not be followed.
     *
     * @param  array  $episodeIds
     * @return void
     */
    protected function confirmPendingSubmissions(array $episodeIds): void
    {
        if ($episodeIds === []) {
            return;
        }

        $confirmedSubmissions = EhealthLink::with(['job', 'linkable'])
            ->whereLinkableType(Episode::class)
            ->whereIn('linkable_id', $episodeIds)
            ->whereStatus(JobStatus::PENDING->value)
            ->get()
            ->filter(static fn (EhealthLink $link): bool => match ($link->job->request_data['status']) {
                Status::CLOSED->value, Status::ENTERED_IN_ERROR->value => $link->linkable->status->value === $link->job->request_data['status'],
                default => true
            });

        if ($confirmedSubmissions->isEmpty()) {
            return;
        }

        EhealthJob::whereIn('id', $confirmedSubmissions->pluck('ehealth_job_id'))
            ->update(['status' => JobStatus::PROCESSED->value]);

        EhealthLink::whereIn('id', $confirmedSubmissions->modelKeys())
            ->update(['status' => JobStatus::PROCESSED->value]);
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
