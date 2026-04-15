<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\MedicalEvents\Sql\Episode;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            try {
                $type = Repository::coding()->store($data['type']);

                $managingOrganization = Repository::identifier()
                    ->store($data['managingOrganization']['identifier']['value']);
                Repository::codeableConcept()->attach($managingOrganization, $data['managingOrganization']);

                $careManager = Repository::identifier()->store($data['careManager']['identifier']['value']);
                Repository::codeableConcept()->attach($careManager, $data['careManager']);

                /** @var Episode $episode */
                $episode = $this->model::create([
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
            } catch (Exception $e) {
                Log::channel('db_errors')->error('Error saving episode', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);

                throw $e;
            }
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
            $existingEpisodes = $this->model::whereIn('uuid', $apiUuids)
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
                    $episode = $this->model::create(
                        array_merge(['uuid' => $data['uuid']], $episodeData)
                    );
                }

                Repository::period()->sync($episode, $data['period']);
            }
        });
    }
}
