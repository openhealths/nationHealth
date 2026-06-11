<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\ClinicalImpressionFinding;
use Illuminate\Database\Eloquent\Builder;
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
     * @return void
     * @throws Throwable
     */
    public function store(array $data, int $personId): void
    {
        DB::transaction(function () use ($data, $personId) {
            foreach ($data as $datum) {
                $code = Repository::codeableConcept()->store($datum['code']);

                $encounter = Repository::identifier()->store($datum['encounter']['identifier']['value']);
                Repository::codeableConcept()->attach($encounter, $datum['encounter']);

                $assessor = Repository::identifier()->store($datum['assessor']['identifier']['value']);
                Repository::codeableConcept()->attach($assessor, $datum['assessor']);

                $previous = null;
                if (isset($datum['previous'])) {
                    $previous = Repository::identifier()->store($datum['previous']['identifier']['value']);
                    Repository::codeableConcept()->attach($previous, $datum['previous']);
                }

                $clinicalImpression = $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    'person_id' => $personId,
                    'status' => $datum['status'],
                    'description' => $datum['description'] ?? null,
                    'code_id' => $code->id,
                    'encounter_id' => $encounter->id,
                    'assessor_id' => $assessor->id,
                    'previous_id' => $previous?->id,
                    'note' => $datum['note'] ?? null,
                ]);

                $clinicalImpression->effectivePeriod()->create([
                    'start' => $datum['effectivePeriod']['start'],
                    'end' => $datum['effectivePeriod']['end']
                ]);

                if (isset($datum['problems'])) {
                    foreach ($datum['problems'] as $problem) {
                        $identifier = Repository::identifier()->store($problem['identifier']['value']);
                        Repository::codeableConcept()->attach($identifier, $problem);

                        $clinicalImpression->problems()->attach($identifier->id);
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

                        $clinicalImpression->supportingInfo()->attach($identifier->id);
                    }
                }
            }
        });
    }

    /**
     * Build a UUID => [insertedAt, codeCode] map for clinical impressions by their UUIDs.
     *
     * @param  array  $uuids
     * @return array
     */
    public function getDetailsMapByUuids(array $uuids): array
    {
        return $this->model->whereIn('uuid', $uuids)
            ->with('code.coding')
            ->get()
            ->mapWithKeys(fn (ClinicalImpression $clinicalImpression) => [
                $clinicalImpression->uuid => [
                    'ehealthInsertedAt' => convertToAppDateFormat($clinicalImpression->ehealthInsertedAt),
                    'codeCode' => $clinicalImpression->code?->coding->first()?->code,
                    'type' => 'clinical_impression',
                ],
            ])
            ->toArray();
    }

    /**
     * Get data that is related to the encounter.
     *
     * @param  string  $encounterUuid
     * @return array
     */
    public function get(string $encounterUuid): array
    {
        return $this->model->withAllRelations()
            ->whereHas('encounter', fn (Builder $query) => $query->where('value', $encounterUuid))
            ->get()
            ->toArray();
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
                    'note' => $data['note'] ?? null,
                    'explanatory_letter' => $datum['explanatory_letter'] ?? null,
                    'ehealth_inserted_at' => $datum['ehealth_inserted_at'] ?? null,
                    'ehealth_updated_at' => $datum['ehealth_updated_at'] ?? null,
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
                $existingFinding->update(['basis' => $finding['basis'] ?? null]);

                if ($existingFinding->itemReference) {
                    $this->updateIdentifier($existingFinding->itemReference, $finding['item_reference']);
                }
            } else {
                $identifier = Repository::identifier()->store($finding['item_reference']['identifier']['value']);
                Repository::codeableConcept()->attach($identifier, $finding['item_reference']);

                $clinicalImpression->findings()->create([
                    'item_reference_id' => $identifier->id,
                    'basis' => $finding['basis'] ?? null,
                ]);
            }
        }

        foreach ($existingFindings->slice(count($findings)) as $extra) {
            $extra->delete();
        }
    }
}
