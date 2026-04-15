<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\ConditionEvidence;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConditionRepository extends BaseRepository
{
    /**
     * Store condition in DB.
     *
     * @param  array  $data
     * @param  int  $encounterId
     * @return void
     * @throws Throwable
     */
    public function store(array $data, int $encounterId): void
    {
        DB::transaction(function () use ($data, $encounterId) {
            try {
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

                    /** @var Condition $condition */
                    $condition = $this->model::create([
                        'uuid' => $datum['id'],
                        'encounter_id' => $encounterId,
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
                                    ConditionEvidence::create([
                                        'condition_id' => $condition->id,
                                        'codes_id' => $code->id
                                    ]);
                                }
                            }

                            if (!empty($evidence['details'])) {
                                foreach ($evidence['details'] as $evidenceDetail) {
                                    $identifier = Repository::identifier()
                                        ->store($evidenceDetail['identifier']['value']);
                                    Repository::codeableConcept()->attach($identifier, $evidenceDetail);

                                    ConditionEvidence::create([
                                        'condition_id' => $condition->id,
                                        'details_id' => $identifier->id
                                    ]);
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                Log::channel('db_errors')->error('Error saving condition', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);

                throw $e;
            }
        });
    }

    /**
     * Get condition data that is related to the encounter.
     *
     * @param  int  $encounterId
     * @return array|null
     */
    public function get(int $encounterId): ?array
    {
        return $this->model::with([
            'asserter',
            'reportOrigin.coding',
            'context.type.coding',
            'code.coding',
            'severity.coding'
        ])
            ->where('encounter_id', $encounterId)
            ->get()
            ?->toArray();
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
     * Formatting for showing in frontend.
     *
     * @param  array  $conditions
     * @param  array  $diagnoses
     * @return array
     */
    public function formatForView(array $conditions, array $diagnoses): array
    {
        return collect($conditions)
            ->map(function (array $condition, int $index) use ($diagnoses) {
                // add diagnoses array to conditions
                if (isset($diagnoses[$index])) {
                    $condition['diagnoses'] = $diagnoses[$index];
                }

                if (empty($condition['code']['coding'][1]['code'])) {
                    $condition['code']['coding'][1] = [
                        'system' => 'eHealth/ICD10_AM/condition_codes',
                        'code' => ''
                    ];
                }

                return $condition;
            })
            ->toArray();
    }

    /**
     * Sync observation data and related data by deleting and creating.
     *
     * @param  int  $personId
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(int $personId, array $validatedData): void
    {
        DB::transaction(function () use ($personId, $validatedData) {
            // Get UUIDs from API data
            $apiUuids = collect($validatedData)->pluck('uuid');

            $existingConditions = $this->model::whereIn('uuid', $apiUuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingConditions->get($data['uuid']);

                // Sync relationships
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

                // Create or update main observation
                $condition = $this->model::updateOrCreate(
                    ['uuid' => $data['uuid']],
                    array_merge(
                        [
                            'person_id' => $personId,
                            'asserter_id' => $asserter?->id,
                            'report_origin_id' => $reportOrigin?->id,
                            'context_id' => $context->id,
                            'code_id' => $code->id,
                            'severity_id' => $severity?->id,
                            'stage_summary_id' => $stageSummary?->id
                        ],
                        Arr::except($data, [
                            'asserter',
                            'report_origin',
                            'context',
                            'code',
                            'severity',
                            'stage',
                            'body_sites',
                            'evidences'
                        ])
                    )
                );

                // Sync body sites
                $categoryIds = $this->syncCodeableConcepts($existing, $data['body_sites'], 'bodySites');
                $condition->bodySites()->sync($categoryIds);

                // Sync evidences
                $this->syncEvidences($condition, $existing, $data['evidences'] ?? []);
            }
        });
    }

    /**
     * Sync condition evidences (codes and details).
     *
     * @param  Condition  $condition
     * @param  Condition|null  $existing
     * @param  array  $evidencesData
     * @return void
     */
    private function syncEvidences(Condition $condition, ?Condition $existing, array $evidencesData): void
    {
        if (empty($evidencesData)) {
            // Remove all evidences if empty array
            $condition->evidencesRelation->each(fn (ConditionEvidence $evidence) => $evidence->delete());

            return;
        }

        $newEvidenceIds = [];

        // Get existing evidences
        $existingEvidences = $existing->evidencesRelation ?? collect();

        foreach ($evidencesData as $evidenceData) {
            // Sync codes
            if (!empty($evidenceData['codes'])) {
                $existingCodes = $existingEvidences
                    ->whereNotNull('codes_id')
                    ->pluck('codes')
                    ->filter()
                    ->values();

                foreach ($evidenceData['codes'] as $index => $codeData) {
                    $existingCode = $existingCodes->get($index);

                    if ($existingCode) {
                        // Update existing codeable concept
                        $this->updateCodeableConcept($existingCode, $codeData);
                        $codeId = $existingCode->id;
                    } else {
                        // Create new codeable concept
                        $codeableConcept = Repository::codeableConcept()->store($codeData);
                        $codeId = $codeableConcept->id;
                    }

                    $evidence = $condition->evidencesRelation()->updateOrCreate([
                        'codes_id' => $codeId,
                        'details_id' => null
                    ]);
                    $newEvidenceIds[] = $evidence->id;
                }
            }

            // Sync details
            if (!empty($evidenceData['details'])) {
                $existingDetails = $existingEvidences
                    ->whereNotNull('details_id')
                    ->pluck('details')
                    ->filter()
                    ->values();

                foreach ($evidenceData['details'] as $index => $detailData) {
                    $existingDetail = $existingDetails->get($index);

                    if ($existingDetail) {
                        // Update existing identifier
                        $this->updateIdentifier($existingDetail, $detailData);
                        $identifierId = $existingDetail->id;
                    } else {
                        // Create new identifier
                        $identifier = Repository::identifier()->store(
                            $detailData['identifier']['value'],
                            $detailData['display_value'] ?? null
                        );
                        if (isset($detailData['identifier']['type'])) {
                            Repository::codeableConcept()->attach($identifier, $detailData);
                        }
                        $identifierId = $identifier->id;
                    }

                    $evidence = $condition->evidencesRelation()->updateOrCreate([
                        'codes_id' => null,
                        'details_id' => $identifierId
                    ]);
                    $newEvidenceIds[] = $evidence->id;
                }
            }
        }

        // Remove evidences that are no longer present
        $condition->evidencesRelation()
            ->whereNotIn('id', $newEvidenceIds)
            ->get()
            ->each(fn (ConditionEvidence $evidence) => $evidence->delete());
    }
}
