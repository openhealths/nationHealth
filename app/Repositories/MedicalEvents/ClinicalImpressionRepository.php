<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\ClinicalImpressionFinding;
use App\Models\MedicalEvents\Sql\ClinicalImpressionProblem;
use App\Models\MedicalEvents\Sql\ClinicalImpressionSupportingInfo;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClinicalImpressionRepository extends BaseRepository
{
    /**
     * Store clinical impression in DB.
     *
     * @param  array  $data
     * @param  int  $personId
     * @param  int  $createdEncounterId
     * @return void
     * @throws Throwable
     */
    public function store(array $data, int $personId, int $createdEncounterId): void
    {
        try {
            DB::transaction(function () use ($data, $personId, $createdEncounterId) {
                foreach ($data as $datum) {
                    $code = Repository::codeableConcept()->store($datum['code']);

                    $encounter = Repository::identifier()->store($datum['encounter']['identifier']['value']);
                    Repository::codeableConcept()->attach($encounter, $datum['encounter']);

                    $assessor = Repository::identifier()->store($datum['assessor']['identifier']['value']);
                    Repository::codeableConcept()->attach($assessor, $datum['assessor']);

                    if (isset($datum['previous'])) {
                        $previous = Repository::identifier()->store($datum['previous']['identifier']['value']);
                        Repository::codeableConcept()->attach($previous, $datum['previous']);
                    }

                    /** @var ClinicalImpression $clinicalImpression */
                    $clinicalImpression = $this->model::create([
                        'uuid' => $datum['uuid'] ?? $datum['id'],
                        'person_id' => $personId,
                        'encounter_internal_id' => $createdEncounterId,
                        'status' => $datum['status'],
                        'description' => $datum['description'] ?? null,
                        'code_id' => $code->id,
                        'encounter_id' => $encounter->id,
                        'assessor_id' => $assessor->id,
                        'previous_id' => $previous->id ?? null,
                        'note' => $datum['note'] ?? null
                    ]);

                    $clinicalImpression->effectivePeriod()->create([
                        'start' => $datum['effectivePeriod']['start'],
                        'end' => $datum['effectivePeriod']['end']
                    ]);

                    if (isset($datum['problems'])) {
                        foreach ($datum['problems'] as $problem) {
                            $identifier = Repository::identifier()->store($problem['identifier']['value']);
                            Repository::codeableConcept()->attach($identifier, $problem);

                            ClinicalImpressionProblem::create([
                                'clinical_impression_id' => $clinicalImpression->id,
                                'identifier_id' => $identifier->id
                            ]);
                        }
                    }

                    if (isset($datum['findings'])) {
                        foreach ($datum['findings'] as $problem) {
                            $identifier = Repository::identifier()
                                ->store($problem['itemReference']['identifier']['value']);
                            Repository::codeableConcept()->attach($identifier, $problem['itemReference']);

                            ClinicalImpressionFinding::create([
                                'clinical_impression_id' => $clinicalImpression->id,
                                'item_reference_id' => $identifier->id
                            ]);
                        }
                    }

                    if (isset($datum['supportingInfo'])) {
                        foreach ($datum['supportingInfo'] as $supporting) {
                            $identifier = Repository::identifier()->store($supporting['identifier']['value']);
                            Repository::codeableConcept()->attach($identifier, $supporting);

                            ClinicalImpressionSupportingInfo::create([
                                'clinical_impression_id' => $clinicalImpression->id,
                                'identifier_id' => $identifier->id
                            ]);
                        }
                    }
                }
            });
        } catch (Exception $e) {
            Log::channel('db_errors')->error('Error saving clinical impression', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            throw $e;
        }
    }

    /**
     * Get data that is related to the encounter.
     *
     * @param  int  $encounterId
     * @return array|null
     */
    public function get(int $encounterId): ?array
    {
        $results = $this->model::with([
            'code.coding',
            'encounter.type.coding',
            'effectivePeriod',
            'assessor.type.coding',
            'previous.type.coding',
            'problems',
            'findings.itemReference',
            'supportingInfo.type.coding'
        ])
            ->where('encounter_internal_id', $encounterId)
            ->get()
            ->toArray();

        $results = $this->resolveProblems($results);
        $results = $this->resolveFindings($results);
        $results = $this->resolveSupportingInfoEpisodes($results);
        $results = $this->resolveSupportingInfo($results);

        // Hide array of relationship data, accessories are used
        return array_map(static fn (array $item) => Arr::except($item, ['effectivePeriod']), $results);
    }

    /**
     * Get related condition from the DB.
     *
     * @param  array  $results
     * @return array
     */
    protected function resolveProblems(array $results): array
    {
        return collect($results)->map(function ($result) {
            if (!empty($result['problems'])) {
                $result['problems'] = collect($result['problems'])
                    ->map(function ($problem) {
                        $condition = Repository::condition()->getForProcedure($problem['identifier']['value']);

                        if ($condition) {
                            $problem['inserted_at'] = $condition['onsetDate'];
                            $problem['code']['coding'][0]['code'] = $condition['code']['coding'][0]['code'];
                        }

                        return $problem;
                    })->toArray();
            }

            return $result;
        })->toArray();
    }

    /**
     * Get related condition and observation from the DB.
     *
     * @param  array  $results
     * @return array
     */
    protected function resolveFindings(array $results): array
    {
        return collect($results)->map(function ($result) {
            if (!empty($result['findings'])) {
                $result['findings'] = collect($result['findings'])
                    ->map(function ($finding) {
                        if ($finding['item_reference']['identifier']['type'][0]['coding'][0]['code'] === 'condition') {
                            $condition = Repository::condition()->getForProcedure(
                                $finding['item_reference']['identifier']['value']
                            );
                            if ($condition) {
                                $finding['inserted_at'] = $condition['onsetDate'];
                                $finding['code']['coding'][0]['code'] = $condition['code']['coding'][0]['code'];
                            }
                        } else {
                            $observation = Repository::observation()
                                ->getForProcedure($finding['item_reference']['identifier']['value']);
                            if ($observation) {
                                $finding['inserted_at'] = $observation['issued'];
                                $finding['code']['coding'][0]['code'] = $observation['code']['coding'][0]['code'];
                            }
                        }

                        return $finding;
                    })->toArray();
            }

            return $result;
        })->toArray();
    }

    /**
     * Get related episode from the DB.
     *
     * @param  array  $results
     * @return array
     */
    protected function resolveSupportingInfoEpisodes(array $results): array
    {
        return collect($results)->map(function ($result) {
            if (!empty($result['supportingInfo'])) {
                $result['supportingInfoEpisodes'] = collect($result['supportingInfo'])
                    ->filter(static function (array $supportingInfo) {
                        return $supportingInfo['identifier']['type'][0]['coding'][0]['code'] === 'episode_of_care';
                    })
                    ->map(static function (array $supportingInfo) {
                        $clinicalImpression = Repository::episode()->getForClinicalImpression(
                            $supportingInfo['identifier']['value']
                        );

                        if ($clinicalImpression) {
                            $supportingInfo['name'] = $clinicalImpression['name'];
                            $supportingInfo['created_at'] = $clinicalImpression['createdAt'];
                        }

                        return $supportingInfo;
                    })
                    ->values()
                    ->toArray();

                // Remove episode_of_care from supportingInfo
                $result['supportingInfo'] = collect($result['supportingInfo'])
                    ->reject(static function (array $supportingInfo) {
                        return $supportingInfo['identifier']['type'][0]['coding'][0]['code'] === 'episode_of_care';
                    })
                    ->values()
                    ->toArray();
            }

            return $result;
        })->toArray();
    }

    /**
     * Get related procedure, diagnostic report and encounter from the DB.
     *
     * @param  array  $results
     * @return array
     */
    protected function resolveSupportingInfo(array $results): array
    {
        return collect($results)->map(function ($result) {
            if (!empty($result['supportingInfo'])) {
                $result['supportingInfo'] = collect($result['supportingInfo'])
                    ->map(function ($supportingInfo) {
                        if ($supportingInfo['identifier']['type'][0]['coding'][0]['code'] === 'procedure') {
                            $procedure = Repository::procedure()
                                ->getForClinicalImpression($supportingInfo['identifier']['value']);
                            if ($procedure) {
                                $supportingInfo['inserted_at'] = $procedure['effectivePeriodStartDate'];
                                $supportingInfo['code']['coding'][0]['code'] = $procedure['code']['coding'][0]['code'];
                            }
                        } elseif ($supportingInfo['identifier']['type'][0]['coding'][0]['code'] === 'diagnostic_report') {
                            $diagnosticReport = Repository::diagnosticReport()
                                ->getForClinicalImpression($supportingInfo['identifier']['value']);
                            if ($diagnosticReport) {
                                $supportingInfo['inserted_at'] = $diagnosticReport['issued'];
                                $supportingInfo['code']['coding'][0]['code'] = $diagnosticReport['code']['coding'][0]['code'];
                            }
                        } elseif ($supportingInfo['identifier']['type'][0]['coding'][0]['code'] === 'encounter') {
                            $encounter = Repository::encounter()
                                ->getForClinicalImpression($supportingInfo['identifier']['value']);
                            if ($encounter) {
                                $supportingInfo['inserted_at'] = $encounter['periodStart'];
                                $supportingInfo['code']['coding'][0]['code'] = $encounter['code']['coding'][0]['code'];
                            }
                        }

                        return $supportingInfo;
                    })->toArray();
            }

            return $result;
        })->toArray();
    }

    /**
     * Sync clinical impression data and related data by deleting and creating.
     *
     * @param  int  $personId
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(int $personId, array $validatedData): void
    {
        DB::transaction(function () use ($personId, $validatedData) {
            // Load existing clinical impressions with relations
            $uuids = collect($validatedData)->pluck('uuid')->toArray();
            $existingClinicalImpressions = $this->model::whereIn('uuid', $uuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingClinicalImpressions->get($data['uuid']);

                // Store relationships
                $code = Repository::codeableConcept()->store($data['code']);

                $encounter = Repository::identifier()->store($data['encounter']['identifier']['value']);
                Repository::codeableConcept()->attach($encounter, $data['encounter']);

                $assessor = Repository::identifier()->store($data['assessor']['identifier']['value']);
                Repository::codeableConcept()->attach($assessor, $data['assessor']);

                $previous = null;
                if (isset($data['previous'])) {
                    $previous = Repository::identifier()->store($data['previous']['identifier']['value']);
                    Repository::codeableConcept()->attach($previous, $data['previous']);
                }

                $clinicalImpression = $this->model::updateOrCreate(
                    ['uuid' => $data['uuid']],
                    array_merge(
                        [
                        'person_id' => $personId,
                        'code_id' => $code->id,
                        'encounter_id' => $encounter->id,
                        'assessor_id' => $assessor->id,
                        'previous_id' => $previous?->id,
                    ],
                        Arr::except($data, [
                            'assessor',
                            'code',
                            'effective_period',
                            'encounter',
                            'findings',
                            'previous',
                            'problems',
                            'supporting_info'
                        ])
                    )
                );

                // Sync effective period
                if (isset($data['effective_period'])) {
                    $clinicalImpression->effectivePeriod()->delete();
                    $clinicalImpression->effectivePeriod()->create([
                        'start' => $data['effective_period']['start'],
                        'end' => $data['effective_period']['end']
                    ]);
                }

                // Sync problems
                if (isset($data['problems'])) {
                    $problemIds = collect($data['problems'])->map(function (array $problem) {
                        $identifier = Repository::identifier()->store($problem['identifier']['value']);
                        Repository::codeableConcept()->attach($identifier, $problem);

                        return $identifier->id;
                    })->toArray();

                    $clinicalImpression->problems()->sync($problemIds);
                }

                // Sync findings
                if (isset($data['findings'])) {
                    foreach ($data['findings'] as $finding) {
                        $identifier = Repository::identifier()
                            ->store($finding['item_reference']['identifier']['value']);
                        Repository::codeableConcept()->attach($identifier, $finding['item_reference']);

                        $clinicalImpression->findings()->create([
                            'item_reference_id' => $identifier->id,
                            'basis' => $finding['basis'] ?? null
                        ]);
                    }
                }

                // Sync supporting info
                if (isset($data['supporting_info'])) {
                    $supportingIds = collect($data['supporting_info'])->map(function (array $supporting) {
                        $identifier = Repository::identifier()->store($supporting['identifier']['value']);
                        Repository::codeableConcept()->attach($identifier, $supporting);

                        return $identifier->id;
                    })->toArray();

                    $clinicalImpression->supportingInfo()->sync($supportingIds);
                }

                // Cleanup old relationships after all updates are done
                if ($existing) {
                    $this->cleanupRelations($existing);
                }
            }
        });
    }

    /**
     * Remove orphaned relations after clinical impression FK update.
     *
     * @param  ClinicalImpression  $existing
     * @return void
     */
    private function cleanupRelations(ClinicalImpression $existing): void
    {
        RelationshipCleaner::cleanRelations($existing, [
            'encounter' => 'identifier',
            'assessor' => 'identifier',
            'previous' => 'identifier',
            'code' => 'codeable_concept',
        ]);

        // Handle collections with special logic
        foreach ($existing->problems as $problem) {
            RelationshipCleaner::cleanIdentifierRelation($problem);
        }

        foreach ($existing->findings as $finding) {
            RelationshipCleaner::cleanIdentifierRelation($finding->itemReference);
        }

        foreach ($existing->supportingInfo as $supportingInfo) {
            RelationshipCleaner::cleanIdentifierRelation($supportingInfo);
        }
    }
}
