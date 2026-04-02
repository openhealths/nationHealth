<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\Api\PatientApi;
use App\Core\Arr;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\DiagnosticReportPerformer;
use App\Models\MedicalEvents\Sql\DiagnosticReportResultsInterpreter;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DiagnosticReportRepository extends BaseRepository
{
    protected string $employeeUuid;

    public function __construct(Model $model)
    {
        parent::__construct($model);

        $this->employeeUuid = Auth::user()?->getDiagnosticReportWriterEmployee()->uuid;
    }

    /**
     * Format data before request.
     *
     * @param  array  $diagnosticReport
     * @return array
     */
    public function formatEHealthRequest(array $diagnosticReport): array
    {
        $diagnosticReport['id'] = Str::uuid()->toString();
        $diagnosticReport['status'] = 'final';

        if ($diagnosticReport['referralType'] === '') {
            unset($diagnosticReport['paperReferral'], $diagnosticReport['basedOn']);
        }

        if ($diagnosticReport['referralType'] === 'electronic') {
            unset($diagnosticReport['paperReferral']);
        }

        if ($diagnosticReport['referralType'] === 'paper') {
            unset($diagnosticReport['basedOn']);
        }

        unset($diagnosticReport['referralType']);

        if ($diagnosticReport['primarySource']) {
            unset($diagnosticReport['reportOrigin']);

            $diagnosticReport['performer']['reference']['identifier']['value'] = $this->employeeUuid;
        } else {
            unset($diagnosticReport['performer']);
        }

        if (empty($diagnosticReport['conclusionCode']['coding'][0]['code'])) {
            unset($diagnosticReport['conclusionCode']);
        }

        $diagnosticReport['recordedBy']['identifier']['value'] = $this->employeeUuid;

        $diagnosticReport['issued'] = convertToISO8601(
            $diagnosticReport['issuedDate'] . $diagnosticReport['issuedTime']
        );
        unset($diagnosticReport['issuedDate'], $diagnosticReport['issuedTime']);

        $diagnosticReport['managingOrganization'] = [
            'identifier' => [
                'type' => [
                    'coding' => [['system' => 'eHealth/resources', 'code' => 'legal_entity']],
                    'text' => ''
                ],
                'value' => legalEntity()->uuid
            ],
        ];

        if (empty($diagnosticReport['division']['identifier']['value'])) {
            unset($diagnosticReport['division']);
        }

        if (empty($diagnosticReport['resultsInterpreter']['reference']['identifier']['value'])) {
            unset($diagnosticReport['resultsInterpreter']);
        }

        $normalizedData = schemaService()
            ->setDataSchema(['diagnostic_report' => $diagnosticReport], app(PatientApi::class))
            ->requestSchemaNormalize('schemaDiagnosticReportPackageRequest')
            ->camelCaseKeys()
            ->getNormalizedData();

        // schema service delete effectivePeriod, so manually add it
        $normalizedData['diagnosticReport']['effectivePeriod'] = [
            'start' => convertToISO8601(
                $diagnosticReport['effectivePeriodStartDate'] . $diagnosticReport['effectivePeriodStartTime']
            ),
            'end' => convertToISO8601(
                $diagnosticReport['effectivePeriodEndDate'] . $diagnosticReport['effectivePeriodEndTime']
            ),
        ];
        unset($diagnosticReport['effectivePeriodStartDate'], $diagnosticReport['effectivePeriodStartTime'], $diagnosticReport['effectivePeriodEndDate'], $diagnosticReport['effectivePeriodEndTime']);

        return $normalizedData;
    }

    /**
     * Store condition in DB.
     *
     * @param  array  $data
     * @param  int|null  $createdEncounterId
     * @return int|null
     * @throws Throwable
     */
    public function store(array $data, ?int $createdEncounterId = null): ?int
    {
        try {
            return DB::transaction(function () use ($data, $createdEncounterId) {
                $diagnosticReportId = null;

                foreach ($data as $datum) {
                    $code = Repository::identifier()->store($datum['code']['identifier']['value']);
                    Repository::codeableConcept()->attach($code, $datum['code']);

                    if (isset($datum['conclusionCode'])) {
                        $conclusionCode = Repository::codeableConcept()->store($datum['conclusionCode']);
                    }

                    $recordedBy = Repository::identifier()->store($datum['recordedBy']['identifier']['value']);
                    Repository::codeableConcept()->attach($recordedBy, $datum['recordedBy']);

                    if ($createdEncounterId) {
                        $encounter = Repository::identifier()->store($datum['encounter']['identifier']['value']);
                        Repository::codeableConcept()->attach($encounter, $datum['encounter']);
                    }

                    $managingOrganization = Repository::identifier()
                        ->store($datum['managingOrganization']['identifier']['value']);
                    Repository::codeableConcept()->attach($managingOrganization, $datum['managingOrganization']);

                    if (isset($datum['division'])) {
                        $division = Repository::identifier()->store($datum['division']['identifier']['value']);
                        Repository::codeableConcept()->attach($division, $datum['division']);
                    }

                    if (isset($datum['reportOrigin'])) {
                        $reportOrigin = Repository::codeableConcept()->store($datum['reportOrigin']);
                    }

                    /** @var DiagnosticReport $diagnosticReport */
                    $diagnosticReport = $this->model::create([
                        'uuid' => $datum['uuid'] ?? $datum['id'],
                        'encounter_internal_id' => $createdEncounterId,
                        'status' => $datum['status'],
                        'code_id' => $code->id,
                        'issued' => $datum['issued'],
                        'conclusion' => $datum['conclusion'] ?? null,
                        'conclusion_code_id' => $conclusionCode->id ?? null,
                        'recorded_by_id' => $recordedBy->id,
                        'encounter_id' => $encounter->id ?? null,
                        'primary_source' => $datum['primarySource'],
                        'managing_organization_id' => $managingOrganization->id,
                        'division_id' => $division->id ?? null,
                        'report_origin_id' => $reportOrigin->id ?? null
                    ]);

                    if (isset($datum['paperReferral'])) {
                        Repository::paperReferral()->store($datum['paperReferral'], $diagnosticReport);
                    }

                    $categoryIds = [];
                    foreach ($datum['category'] as $categoryData) {
                        $category = Repository::codeableConcept()->store($categoryData);

                        $categoryIds[] = $category->id;
                    }

                    $diagnosticReport->category()->attach($categoryIds);

                    $diagnosticReport->effectivePeriod()->create([
                        'start' => $datum['effectivePeriod']['start'],
                        'end' => $datum['effectivePeriod']['end']
                    ]);

                    if (isset($datum['performer'])) {
                        if (isset($datum['performer']['reference'])) {
                            $reference = Repository::identifier()
                                ->store($datum['performer']['reference']['identifier']['value']);
                            Repository::codeableConcept()->attach($reference, $datum['performer']['reference']);
                        }

                        DiagnosticReportPerformer::create([
                            'diagnostic_report_id' => $diagnosticReport->id,
                            'reference_id' => $reference->id ?? null,
                            'text' => $datum['performer']['text'] ?? null
                        ]);
                    }

                    if (isset($datum['resultsInterpreter'])) {
                        if (isset($datum['resultsInterpreter']['reference'])) {
                            $reference = Repository::identifier()
                                ->store($datum['resultsInterpreter']['reference']['identifier']['value']);
                            Repository::codeableConcept()->attach(
                                $reference,
                                $datum['resultsInterpreter']['reference']
                            );
                        }

                        DiagnosticReportResultsInterpreter::create([
                            'diagnostic_report_id' => $diagnosticReport->id,
                            'reference_id' => $reference->id ?? null,
                            'text' => $datum['resultsInterpreter']['text'] ?? null
                        ]);
                    }

                    $diagnosticReportId = $diagnosticReport->id;
                }

                // Return the ID when creating separately
                return $createdEncounterId === null ? $diagnosticReportId : null;
            });
        } catch (Exception $e) {
            Log::channel('db_errors')->error('Error saving diagnostic report', [
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
            'basedOn.type.coding',
            'paperReferral',
            'code.type.coding',
            'category.coding',
            'conclusionCode.coding',
            'recordedBy.type.coding',
            'encounter.type.coding',
            'managingOrganization.type.coding',
            'division.type.coding',
            'performer.reference',
            'reportOrigin.coding',
            'resultsInterpreter.reference'
        ])
            ->where('encounter_internal_id', $encounterId)
            ->get()
            ->toArray();

        // Hide array of relationship data, accessories are used
        return array_map(static fn (array $item) => Arr::except($item, ['effectivePeriod']), $results);
    }

    /**
     * Get the diagnostic report for the clinical impression based on the provided UUID to display the selected supporting info.
     *
     * @param  string  $uuid
     * @return array|null
     */
    public function getForClinicalImpression(string $uuid): ?array
    {
        return DiagnosticReport::whereUuid($uuid)
            ->select(['id', 'issued', 'code_id'])
            ->with(['code.coding'])
            ->first()
            ?->toArray();
    }

    /**
     * Formatting to show on the frontend.
     *
     * @param  array  $diagnosticReports
     * @return array
     */
    public function formatForView(array $diagnosticReports): array
    {
        return array_map(static function (array $diagnosticReport) {
            // Set value to checkbox isReferralAvailable
            if (empty($diagnosticReport['basedOn']) && empty($diagnosticReport['paperReferral'])) {
                $diagnosticReport['isReferralAvailable'] = false;
            } else {
                $diagnosticReport['isReferralAvailable'] = true;
            }

            // Set referral type if referral is available
            if ($diagnosticReport['isReferralAvailable']) {
                $diagnosticReport['referralType'] = !empty($diagnosticReport['basedOn']) ? 'electronic' : 'paper';
            }

            // Set default value to avoid error
            if (empty($diagnosticReport['reportOrigin'])) {
                $diagnosticReport['reportOrigin'] = [
                    'coding' => [
                        ['code' => '']
                    ]
                ];
            }

            return $diagnosticReport;
        }, $diagnosticReports);
    }

    /**
     * Sync diagnostic report data and related data by deleting and creating.
     *
     * @param  int  $personId
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(int $personId, array $validatedData): void
    {
        DB::transaction(function () use ($personId, $validatedData) {
            // Load existing diagnostic reports with relations
            $uuids = collect($validatedData)->pluck('uuid')->toArray();
            $existingDiagnosticReports = $this->model::whereIn('uuid', $uuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingDiagnosticReports->get($data['uuid']);

                // Store relationships
                $basedOn = null;
                if (isset($data['based_on'])) {
                    $basedOn = Repository::identifier()->store($data['based_on']['identifier']['value']);
                    Repository::codeableConcept()->attach($basedOn, $data['based_on']);
                }

                $code = Repository::identifier()->store($data['code']['identifier']['value']);
                Repository::codeableConcept()->attach($code, $data['code']);

                $encounter = null;
                if (isset($data['encounter'])) {
                    $encounter = Repository::identifier()->store($data['encounter']['identifier']['value']);
                    Repository::codeableConcept()->attach($encounter, $data['encounter']);
                }

                $division = null;
                if (isset($data['division'])) {
                    $division = Repository::identifier()->store($data['division']['identifier']['value']);
                    Repository::codeableConcept()->attach($division, $data['division']);
                }

                $conclusionCode = null;
                if (isset($data['conclusion_code'])) {
                    $conclusionCode = Repository::codeableConcept()->store($data['conclusion_code']);
                }

                $recordedBy = Repository::identifier()->store($data['recorded_by']['identifier']['value']);
                Repository::codeableConcept()->attach($recordedBy, $data['recorded_by']);

                $managingOrganization = null;
                if (isset($data['managing_organization'])) {
                    $managingOrganization = Repository::identifier()->store(
                        $data['managing_organization']['identifier']['value']
                    );
                    Repository::codeableConcept()->attach($managingOrganization, $data['managing_organization']);
                }

                $reportOrigin = null;
                if (isset($data['report_origin'])) {
                    $reportOrigin = Repository::codeableConcept()->store($data['report_origin']);
                }

                $originEpisode = null;
                if (isset($data['origin_episode'])) {
                    $originEpisode = Repository::identifier()->store($data['origin_episode']['identifier']['value']);
                    Repository::codeableConcept()->attach($originEpisode, $data['origin_episode']);
                }

                $cancellationReason = null;
                if (isset($data['cancellation_reason'])) {
                    $cancellationReason = Repository::codeableConcept()->store($data['cancellation_reason']);
                }

                // Create or update main diagnostic report
                /** @var DiagnosticReport $diagnosticReport */
                $diagnosticReport = $this->model::updateOrCreate(
                    ['uuid' => $data['uuid']],
                    array_merge(
                        [
                        'person_id' => $personId,
                        'based_on_id' => $basedOn?->id,
                        'code_id' => $code->id,
                        'encounter_id' => $encounter?->id,
                        'division_id' => $division?->id,
                        'conclusion_code_id' => $conclusionCode?->id,
                        'recorded_by_id' => $recordedBy->id,
                        'managing_organization_id' => $managingOrganization?->id,
                        'report_origin_id' => $reportOrigin?->id,
                        'origin_episode_id' => $originEpisode?->id,
                        'cancellation_reason_id' => $cancellationReason?->id
                    ],
                        Arr::except($data, [
                            'based_on',
                            'code',
                            'encounter',
                            'division',
                            'conclusion_code',
                            'recorded_by',
                            'managing_organization',
                            'report_origin',
                            'origin_episode',
                            'cancellation_reason',
                            'paper_referral',
                            'category',
                            'effective_period',
                            'performer',
                            'results_interpreter',
                            'specimens',
                            'used_references'
                        ])
                    )
                );

                if (isset($data['paper_referral'])) {
                    Repository::paperReferral()->sync($data['paper_referral'], $diagnosticReport);
                }

                $categoriesIds = [];
                if (isset($data['category'])) {
                    foreach ($data['category'] as $categoryData) {
                        $category = Repository::codeableConcept()->store($categoryData);
                        $categoriesIds[] = $category->id;
                    }
                }
                $diagnosticReport->category()->sync($categoriesIds);

                if (isset($data['effective_period'])) {
                    $diagnosticReport->effectivePeriod()->delete();
                    $diagnosticReport->effectivePeriod()->create([
                        'start' => $data['effective_period']['start'],
                        'end' => $data['effective_period']['end']
                    ]);
                }

                $diagnosticReport->performer()->delete();
                if (isset($data['performer'])) {
                    $performerReference = null;
                    if (isset($data['performer']['reference'])) {
                        $performerReference = Repository::identifier()->store(
                            $data['performer']['reference']['identifier']['value']
                        );
                        Repository::codeableConcept()->attach($performerReference, $data['performer']['reference']);
                    }

                    DiagnosticReportPerformer::create([
                        'diagnostic_report_id' => $diagnosticReport->id,
                        'reference_id' => $performerReference?->id,
                        'text' => $data['performer']['text'] ?? null
                    ]);
                }

                $diagnosticReport->resultsInterpreter()->delete();
                if (isset($data['results_interpreter'])) {
                    $interpreterReference = null;
                    if (isset($data['results_interpreter']['reference'])) {
                        $interpreterReference = Repository::identifier()->store(
                            $data['results_interpreter']['reference']['identifier']['value']
                        );
                        Repository::codeableConcept()->attach(
                            $interpreterReference,
                            $data['results_interpreter']['reference']
                        );
                    }

                    DiagnosticReportResultsInterpreter::create([
                        'diagnostic_report_id' => $diagnosticReport->id,
                        'reference_id' => $interpreterReference?->id,
                        'text' => $data['results_interpreter']['text'] ?? null
                    ]);
                }

                // Sync specimens
                $specimenIds = [];
                if (isset($data['specimens'])) {
                    foreach ($data['specimens'] as $specimenData) {
                        $specimen = Repository::identifier()->store($specimenData['identifier']['value']);
                        Repository::codeableConcept()->attach($specimen, $specimenData);
                        $specimenIds[] = $specimen->id;
                    }
                }
                $diagnosticReport->specimens()->sync($specimenIds);

                // Sync used references
                $usedReferenceIds = [];
                if (isset($data['used_references'])) {
                    foreach ($data['used_references'] as $usedReferenceData) {
                        $usedReference = Repository::identifier()->store($usedReferenceData['identifier']['value']);
                        Repository::codeableConcept()->attach($usedReference, $usedReferenceData);
                        $usedReferenceIds[] = $usedReference->id;
                    }
                }
                $diagnosticReport->usedReferences()->sync($usedReferenceIds);

                // Cleanup old relationships after all updates are done
                if ($existing) {
                    $this->cleanupRelations($existing);
                }
            }
        });
    }

    /**
     * Remove orphaned relations after diagnostic report FK update.
     *
     * @param  DiagnosticReport  $existing
     * @return void
     */
    private function cleanupRelations(DiagnosticReport $existing): void
    {
        RelationshipCleaner::cleanRelations($existing, [
            'basedOn' => 'identifier',
            'code' => 'identifier',
            'encounter' => 'identifier',
            'division' => 'identifier',
            'recordedBy' => 'identifier',
            'managingOrganization' => 'identifier',
            'originEpisode' => 'identifier',
            'conclusionCode' => 'codeable_concept',
            'reportOrigin' => 'codeable_concept',
            'cancellationReason' => 'codeable_concept',
            'category' => 'codeable_concept_collection',
        ]);

        RelationshipCleaner::cleanPerformerRelation($existing->performer);
        RelationshipCleaner::cleanPerformerRelation($existing->resultsInterpreter);
        RelationshipCleaner::cleanCodeableConceptCollection($existing->specimens);
        RelationshipCleaner::cleanCodeableConceptCollection($existing->usedReferences);
    }
}
