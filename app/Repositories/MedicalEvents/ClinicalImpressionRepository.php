<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\ClinicalImpressionFinding;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property ClinicalImpression $model
 */
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

                $clinicalImpression = $this->model->create([
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

                        $clinicalImpression->problems()->create(['identifier_id' => $identifier->id]);
                    }
                }

                if (isset($datum['findings'])) {
                    foreach ($datum['findings'] as $problem) {
                        $identifier = Repository::identifier()
                            ->store($problem['itemReference']['identifier']['value']);
                        Repository::codeableConcept()->attach($identifier, $problem['itemReference']);

                        $clinicalImpression->findings()->create(['item_reference_id' => $identifier->id]);
                    }
                }

                if (isset($datum['supportingInfo'])) {
                    foreach ($datum['supportingInfo'] as $supporting) {
                        $identifier = Repository::identifier()->store($supporting['identifier']['value']);
                        Repository::codeableConcept()->attach($identifier, $supporting);

                        $clinicalImpression->supportingInfo()->create(['identifier_id' => $identifier->id]);
                    }
                }
            }
        });
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
        return collect($results)->map(function (array $result) {
            if (!empty($result['problems'])) {
                $result['problems'] = collect($result['problems'])
                    ->map(function (array $problem) {
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
        return collect($results)->map(function (array $result) {
            if (!empty($result['findings'])) {
                $result['findings'] = collect($result['findings'])
                    ->map(function (array $finding) {
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
        return collect($results)->map(function (array $result) {
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

                // Remove episode_of_care entries that are moved to supportingInfoEpisodes
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
        return collect($results)->map(function (array $result) {
            if (!empty($result['supportingInfo'])) {
                $result['supportingInfo'] = collect($result['supportingInfo'])
                    ->map(function (array $supportingInfo) {
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
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingClinicalImpressions = $this->model->whereIn('uuid', $apiUuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingClinicalImpressions->get($data['uuid']);

                $code = $this->syncCodeableConcept($existing, $data['code'], 'code');
                $encounter = $this->syncIdentifier($existing, $data['encounter'], 'encounter');
                $assessor = $this->syncIdentifier($existing, $data['assessor'], 'assessor');
                $previous = $this->syncIdentifier($existing, $data['previous'] ?? null, 'previous');

                $clinicalImpressionData = [
                    'person_id' => $personId,
                    'status' => $data['status'],
                    'description' => $data['description'] ?? null,
                    'code_id' => $code->id,
                    'encounter_id' => $encounter->id,
                    'assessor_id' => $assessor->id,
                    'previous_id' => $previous?->id,
                    'note' => $data['note'] ?? null
                ];

                if ($existing) {
                    $existing->update($clinicalImpressionData);
                    $clinicalImpression = $existing;
                } else {
                    $clinicalImpression = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $clinicalImpressionData)
                    );
                }

                $this->syncPivot(
                    $clinicalImpression,
                    'problems',
                    $this->syncIdentifiers($existing, $data['problems'], 'problems')
                );
                $this->syncPivot(
                    $clinicalImpression,
                    'supportingInfo',
                    $this->syncIdentifiers($existing, $data['supporting_info'], 'supportingInfo')
                );

                Repository::period()->sync($clinicalImpression, $data['effective_period'], 'effectivePeriod');

                $this->syncFindings($clinicalImpression, $data['findings'] ?? []);
            }
        });
    }

    /**
     * Sync findings for clinical impression.
     *
     * @param  ClinicalImpression  $clinicalImpression
     * @param  array  $findings
     * @return void
     */
    private function syncFindings(ClinicalImpression $clinicalImpression, array $findings): void
    {
        $existingFindings = $clinicalImpression->relationLoaded('findings')
            ? $clinicalImpression->findings
            : collect();

        if (empty($findings)) {
            $existingFindings->each(fn (ClinicalImpressionFinding $finding) => $finding->delete());

            return;
        }

        foreach ($findings as $index => $finding) {
            $existingFinding = $existingFindings[$index] ?? null;

            if ($existingFinding) {
                $existingFinding->update(['basis' => $finding['basis']]);

                if ($existingFinding->itemReference) {
                    $this->updateIdentifier($existingFinding->itemReference, $finding['item_reference']);
                }
            } else {
                $identifier = Repository::identifier()->store($finding['item_reference']['identifier']['value']);
                Repository::codeableConcept()->attach($identifier, $finding['item_reference']);

                $clinicalImpression->findings()->create([
                    'item_reference_id' => $identifier->id,
                    'basis' => $finding['basis']
                ]);
            }
        }

        foreach ($existingFindings->slice(count($findings)) as $extra) {
            $extra->delete();
        }
    }
}
