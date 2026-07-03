<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\ConditionEvidence;
use App\Models\Person\Person;
use App\Models\Preperson;
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
     * @param  Person|Preperson  $patient
     * @return void
     * @throws Throwable
     */
    public function store(array $data, Person|Preperson $patient): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($data, $ownerColumn, $ownerId) {
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
                    $ownerColumn => $ownerId,
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
        return $this->model->withAllRelations()
            ->whereIn('uuid', $uuids)
            ->get()
            ->toArray();
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
                    'ehealthInsertedAt' => $condition['ehealthInsertedAt'] ?? null,
                    'codeCode' => data_get($condition, 'code.coding.0.code'),
                    'codeSystem' => data_get($condition, 'code.coding.0.system'),
                    'type' => 'condition'
                ]
            ])
            ->toArray();
    }

    /**
     * Build a lightweight details map for Procedure condition references.
     *
     * @param  array<string>  $uuids
     * @return array<string, array{
     *     ehealthInsertedAt: ?string,
     *     codeCode: ?string,
     *     codeSystem: ?string,
     *     type: string
     * }>
     */
    public function getProcedureReferenceDetailsMapByUuids(array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        return $this->model
            ->newQuery()
            ->select(['uuid', 'code_id', 'ehealth_inserted_at', 'asserted_date', 'onset_date'])
            ->whereIn('uuid', $uuids)
            ->with('code.coding')
            ->get()
            ->mapWithKeys(static function (Condition $condition): array {
                $coding = $condition->code?->coding?->first();

                $date = $condition->ehealth_inserted_at
                    ?? $condition->asserted_date
                    ?? $condition->onset_date;

                return [
                    $condition->uuid => [
                        'ehealthInsertedAt' => $date ? convertToAppDateFormat($date) : null,
                        'codeCode' => $coding?->code,
                        'codeSystem' => $coding?->system,
                        'type' => 'condition',
                    ],
                ];
            })
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
     * Sync condition data and related data by deleting and creating.
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
                    $ownerColumn => $ownerId,
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
