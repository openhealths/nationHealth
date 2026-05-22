<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\ConditionEvidence;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property Condition $model
 */
class ConditionRepository extends BaseRepository
{
    /**
     * Store condition in DB.
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
                $reportOrigin = null;
                $asserter = null;
                $severity = null;

                if (isset($datum['asserter'])) {
                    $asserter = Repository::identifier()->store($datum['asserter']['identifier']['value']);
                    Repository::codeableConcept()->attach($asserter, $datum['asserter']);
                }

                $context = Repository::identifier()->store($datum['context']['identifier']['value']);
                Repository::codeableConcept()->attach($context, $datum['context']);

                if (isset($datum['reportOrigin'])) {
                    $reportOrigin = Repository::codeableConcept()->store($datum['reportOrigin']);
                }

                $code = Repository::codeableConcept()->store($datum['code']);

                if (isset($datum['severity'])) {
                    $severity = Repository::codeableConcept()->store($datum['severity']);
                }

                $condition = $this->model->create([
                    'uuid' => $datum['id'],
                    'person_id' => $personId,
                    'primary_source' => $datum['primarySource'],
                    'asserter_id' => $asserter?->id,
                    'report_origin_id' => $reportOrigin?->id,
                    'context_id' => $context->id,
                    'code_id' => $code->id,
                    'clinical_status' => $datum['clinicalStatus'],
                    'verification_status' => $datum['verificationStatus'],
                    'severity_id' => $severity?->id,
                    'onset_date' => $datum['onsetDate'],
                    'asserted_date' => $datum['assertedDate'] ?? null
                ]);

                if (!empty($datum['evidences'])) {
                    foreach ($datum['evidences'] as $evidence) {
                        if (!empty($evidence['codes'])) {
                            foreach ($evidence['codes'] as $evidenceCode) {
                                $code = Repository::codeableConcept()->store($evidenceCode);
                                $condition->evidencesRelation()->create(['codes_id' => $code->id]);
                            }
                        }

                        if (!empty($evidence['details'])) {
                            foreach ($evidence['details'] as $evidenceDetail) {
                                $identifier = Repository::identifier()
                                    ->store($evidenceDetail['identifier']['value']);
                                Repository::codeableConcept()->attach($identifier, $evidenceDetail);

                                $condition->evidencesRelation()->create(['details_id' => $identifier->id]);
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Get conditions with all relationships needed for the edit form.
     *
     * @param  array  $uuids
     * @return array
     */
    public function getByUuids(array $uuids): array
    {
        return $this->model::with([
            'asserter.type.coding',
            'reportOrigin.coding',
            'context.type.coding',
            'code.coding',
            'severity.coding',
            'stageSummary'
        ])
            ->whereIn('uuid', $uuids)
            ->get()
            ->toArray();
    }

    /**
     * Get condition data that is related to the person.
     *
     * @param  int  $personId
     * @return array|null
     */
    public function getByPersonId(int $personId): array
    {
        return $this->model
            ->withAllRelations()
            ->where('person_id', $personId)
            ->get()
            ->toArray();
    }

    /**
     * Get condition data that is related to the person with pagination.
     *
     * @param  int  $personId
     * @param  int  $page
     * @param  int  $pageSize
     * @return array|null
     */
    public function getByPersonIdPaginated(int $personId, int $page, int $pageSize): array
    {
        return $this->model
            ->withAllRelations()
            ->where('person_id', $personId)
            ->orderByDesc('onset_date')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->toArray();
    }

    /**
     * Get conunt of condition data that is related to the person.
     *
     * @param  int  $personId
     * @return array|null
     */
    public function countByPersonId(int $personId): int
    {
        return $this->model
            ->where('person_id', $personId)
            ->count();
    }

    /**
     * Build a UUID => [insertedAt, codeCode] map for the given condition/observation UUIDs.
     *
     * @param  array  $uuids
     * @return array
     */
    public function getDetailsMapByUuids(array $uuids): array
    {
        return collect($this->model->whereIn('uuid', $uuids)->with(['code.coding', 'stageSummary'])->get()->toArray())
            ->mapWithKeys(fn (array $condition) => [
                $condition['uuid'] => [
                    'insertedAt' => $condition['ehealthInsertedAt'] ?? null,
                    'codeCode' => data_get($condition, 'code.coding.0.code'),
                    'type' => 'condition'
                ]
            ])
            ->toArray();
    }

    /**
     * Build a UUID => [insertedAt, codeCode] map for evidence details across conditions and observations.
     *
     * @param  array  $conditions
     * @return array
     */
    public function getDetailsMapForEvidences(array $conditions): array
    {
        $detailUuids = collect($conditions)
            ->flatMap(fn (array $condition) => data_get($condition, 'evidences.0.details', []))
            ->pluck('identifier.value')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return array_merge(
            $this->getDetailsMapByUuids($detailUuids),
            Repository::observation()->getDetailsMapByUuids($detailUuids)
        );
    }

    /**
     * Get the condition for the procedure based on the provided UUID to display the selected reason reference and complication detail.
     *
     * @param  string  $uuid
     * @return array|null
     */
    public function getForProcedure(string $uuid): ?array
    {
        return Condition::whereUuid($uuid)
            ->select(['id', 'onset_date', 'code_id'])
            ->with('code.coding')
            ->first()
            ?->toArray();
    }

    /**
     * Sync condition data and related data by deleting and creating.
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

            $existingConditions = $this->model->whereIn('uuid', $apiUuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingConditions->get($data['uuid']);

                $asserter = $this->syncIdentifier($existing, $data['asserter'] ?? null, 'asserter');
                $reportOrigin = $this->syncCodeableConcept($existing, $data['report_origin'] ?? null, 'reportOrigin');
                $context = $this->syncIdentifier($existing, $data['context'], 'context');
                $code = $this->syncCodeableConcept($existing, $data['code'], 'code');
                $severity = $this->syncCodeableConcept($existing, $data['severity'] ?? null, 'severity');
                $stageSummary = $this->syncCodeableConcept(
                    $existing,
                    $data['stage']['summary'] ?? null,
                    'stageSummary'
                );

                $conditionData = [
                    'person_id' => $personId,
                    'asserter_id' => $asserter?->id,
                    'report_origin_id' => $reportOrigin?->id,
                    'context_id' => $context->id,
                    'code_id' => $code->id,
                    'severity_id' => $severity?->id,
                    'stage_summary_id' => $stageSummary?->id,
                    'clinical_status' => $data['clinical_status'],
                    'verification_status' => $data['verification_status'],
                    'primary_source' => $data['primary_source'],
                    'onset_date' => $data['onset_date'],
                    'asserted_date' => $data['asserted_date'] ?? null,
                    'ehealth_inserted_at' => $data['ehealth_inserted_at'] ?? null,
                    'ehealth_updated_at' => $data['ehealth_updated_at'] ?? null
                ];

                if ($existing) {
                    $existing->update($conditionData);
                    $condition = $existing;
                } else {
                    $condition = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $conditionData)
                    );
                }

                $this->syncPivot(
                    $condition,
                    'bodySites',
                    $this->syncCodeableConcepts($existing, $data['body_sites'] ?? [], 'bodySites')
                );

                $this->syncEvidences($condition, $data['evidences'] ?? []);
            }
        });
    }

    /**
     * Sync condition evidences (codes and details).
     *
     * @param  Condition  $condition
     * @param  array  $evidences
     * @return void
     */
    private function syncEvidences(Condition $condition, array $evidences): void
    {
        $existingEvidences = $condition->relationLoaded('evidencesRelation')
            ? $condition->evidencesRelation
            : collect();

        if (empty($evidences)) {
            $existingEvidences->each(fn (ConditionEvidence $evidence) => $evidence->delete());

            return;
        }

        $newEvidenceIds = [];

        foreach ($evidences as $evidence) {
            if (!empty($evidence['codes'])) {
                $existingEvidenceCodes = $existingEvidences->whereNotNull('codes_id')->values();

                foreach ($evidence['codes'] as $index => $codeData) {
                    $existingEvidence = $existingEvidenceCodes[$index] ?? null;

                    if ($existingEvidence) {
                        $this->updateCodeableConcept($existingEvidence->codes, $codeData);
                        $newEvidenceIds[] = $existingEvidence->id;
                    } else {
                        $codeableConcept = Repository::codeableConcept()->store($codeData);
                        $newEvidence = $condition->evidencesRelation()->create([
                            'codes_id' => $codeableConcept->id,
                            'details_id' => null
                        ]);
                        $newEvidenceIds[] = $newEvidence->id;
                    }
                }
            }

            if (!empty($evidence['details'])) {
                $existingEvidenceDetails = $existingEvidences->whereNotNull('details_id')->values();

                foreach ($evidence['details'] as $index => $detailData) {
                    $existingEvidence = $existingEvidenceDetails[$index] ?? null;

                    if ($existingEvidence) {
                        $this->updateIdentifier($existingEvidence->details, $detailData);
                        $newEvidenceIds[] = $existingEvidence->id;
                    } else {
                        $identifier = Repository::identifier()->store(
                            $detailData['identifier']['value'],
                            $detailData['display_value'] ?? null
                        );
                        if (isset($detailData['identifier']['type'])) {
                            Repository::codeableConcept()->attach($identifier, $detailData);
                        }
                        $newEvidence = $condition->evidencesRelation()->create([
                            'codes_id' => null,
                            'details_id' => $identifier->id
                        ]);
                        $newEvidenceIds[] = $newEvidence->id;
                    }
                }
            }
        }

        $existingEvidences->filter(fn (ConditionEvidence $evidence) => !in_array($evidence->id, $newEvidenceIds, true))
            ->each(fn (ConditionEvidence $evidence) => $evidence->delete());
    }
}
