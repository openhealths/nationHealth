<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\Api\PatientApi;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * @property DiagnosticReport $model
 */
class DiagnosticReportRepository extends BaseRepository
{
    protected ?string $employeeUuid;

    public function __construct(Model $model)
    {
        parent::__construct($model);

        $this->employeeUuid = Auth::user()?->getDiagnosticReportWriterEmployee()?->uuid;
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
     * @param  int  $personId
     * @return int|null
     * @throws Throwable
     */
    public function store(array $data, int $personId): ?int
    {
        return DB::transaction(function () use ($data, $personId) {
            foreach ($data as $datum) {
                $code = Repository::identifier()->store($datum['code']['identifier']['value']);
                Repository::codeableConcept()->attach($code, $datum['code']);

                $recordedBy = Repository::identifier()->store($datum['recordedBy']['identifier']['value']);
                Repository::codeableConcept()->attach($recordedBy, $datum['recordedBy']);

                $encounter = Repository::identifier()->store($datum['encounter']['identifier']['value']);
                Repository::codeableConcept()->attach($encounter, $datum['encounter']);

                $managingOrganization = Repository::identifier()
                    ->store($datum['managingOrganization']['identifier']['value']);
                Repository::codeableConcept()->attach($managingOrganization, $datum['managingOrganization']);

                $division = null;
                if (isset($datum['division'])) {
                    $division = Repository::identifier()->store($datum['division']['identifier']['value']);
                    Repository::codeableConcept()->attach($division, $datum['division']);
                }

                $diagnosticReport = $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    'person_id' => $personId,
                    'status' => $datum['status'],
                    'code_id' => $code->id,
                    'issued' => $datum['issued'],
                    'conclusion' => $datum['conclusion'] ?? null,
                    'conclusion_code_id' => isset($datum['conclusionCode'])
                        ? Repository::codeableConcept()->store($datum['conclusionCode'])->id
                        : null,
                    'recorded_by_id' => $recordedBy->id,
                    'encounter_id' => $encounter->id ?? null,
                    'primary_source' => $datum['primarySource'],
                    'managing_organization_id' => $managingOrganization->id,
                    'division_id' => $division?->id,
                    'report_origin_id' => isset($datum['reportOrigin'])
                        ? Repository::codeableConcept()->store($datum['reportOrigin'])->id
                        : null
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
                    $reference = null;
                    if (isset($datum['performer']['reference'])) {
                        $reference = Repository::identifier()
                            ->store($datum['performer']['reference']['identifier']['value']);
                        Repository::codeableConcept()->attach($reference, $datum['performer']['reference']);
                    }

                    $diagnosticReport->performer()->create([
                        'reference_id' => $reference?->id,
                        'text' => $datum['performer']['text'] ?? null
                    ]);
                }

                if (isset($datum['resultsInterpreter'])) {
                    $reference = null;
                    if (isset($datum['resultsInterpreter']['reference'])) {
                        $reference = Repository::identifier()
                            ->store($datum['resultsInterpreter']['reference']['identifier']['value']);
                        Repository::codeableConcept()->attach(
                            $reference,
                            $datum['resultsInterpreter']['reference']
                        );
                    }

                    $diagnosticReport->resultsInterpreter()->create([
                        'reference_id' => $reference?->id,
                        'text' => $datum['resultsInterpreter']['text'] ?? null
                    ]);
                }

                $diagnosticReportId = $diagnosticReport->id;
            }

            // Return the ID when creating separately
            return $diagnosticReportId;
        });
    }

    /**
     * Get data that is related to the encounter.
     *
     * @param  string  $encounterUuid
     * @return array|null
     */
    public function get(string $encounterUuid): ?array
    {
        $results = $this->model::with([
            'basedOn.type.coding',
            'paperReferral',
            'code.type.coding',
            'category.coding',
            'effectivePeriod',
            'conclusionCode.coding',
            'recordedBy.type.coding',
            'encounter.type.coding',
            'managingOrganization.type.coding',
            'division.type.coding',
            'performer.reference.type.coding',
            'reportOrigin.coding',
            'resultsInterpreter.reference.type.coding'
        ])
            ->whereHas('encounter', fn (Builder $query) => $query->where('value', $encounterUuid))
            ->get()
            ->toArray();

        return $results;
    }

    /**
     * Get diagnostic reports data that is related to the person.
     *
     * @param  int  $personId
     * @return array
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
     * Get the diagnostic report for the clinical impression based on the provided UUID to display the selected supporting info.
     *
     * @param  array  $uuids
     * @return array
     */
    public function getDetailsMapByUuids(array $uuids): array
    {
        return $this->model->whereIn('uuid', $uuids)
            ->with('code')
            ->get()
            ->mapWithKeys(fn (DiagnosticReport $diagnosticReport) => [
                $diagnosticReport->uuid => [
                    'ehealthInsertedAt' => convertToAppDateFormat($diagnosticReport->ehealthInsertedAt),
                    'codeCode' => $diagnosticReport->code?->value,
                    'type' => 'diagnostic_report'
                ],
            ])
            ->toArray();
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
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingDiagnosticReports = $this->model->whereIn('uuid', $apiUuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingDiagnosticReports->get($data['uuid']);

                $basedOn = $this->syncIdentifier($existing, $data['based_on'] ?? null, 'basedOn');
                $code = $this->syncIdentifier($existing, $data['code'], 'code');
                $encounter = $this->syncIdentifier($existing, $data['encounter'] ?? null, 'encounter');
                $division = $this->syncIdentifier($existing, $data['division'] ?? null, 'division');
                $conclusionCode = $this->syncCodeableConcept(
                    $existing,
                    $data['conclusion_code'] ?? null,
                    'conclusionCode'
                );
                $recordedBy = $this->syncIdentifier($existing, $data['recorded_by'], 'recordedBy');
                $managingOrganization = $this->syncIdentifier(
                    $existing,
                    $data['managing_organization'] ?? null,
                    'managingOrganization'
                );
                $reportOrigin = $this->syncCodeableConcept($existing, $data['report_origin'] ?? null, 'reportOrigin');
                $originEpisode = $this->syncIdentifier($existing, $data['origin_episode'] ?? null, 'originEpisode');
                $cancellationReason = $this->syncCodeableConcept(
                    $existing,
                    $data['cancellation_reason'] ?? null,
                    'cancellationReason'
                );

                $diagnosticReportData = [
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
                    'cancellation_reason_id' => $cancellationReason?->id,
                    'status' => $data['status'],
                    'issued' => $data['issued'],
                    'conclusion' => $data['conclusion'] ?? null,
                    'primary_source' => $data['primary_source']
                ];

                if ($existing) {
                    $existing->update($diagnosticReportData);
                    $diagnosticReport = $existing;
                } else {
                    $diagnosticReport = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $diagnosticReportData)
                    );
                }

                if (isset($data['paper_referral'])) {
                    Repository::paperReferral()->sync($data['paper_referral'], $diagnosticReport, $existing);
                }

                $this->syncPivot(
                    $diagnosticReport,
                    'category',
                    $this->syncCodeableConcepts($existing, $data['category'], 'category')
                );

                Repository::period()->sync($diagnosticReport, $data['effective_period'] ?? [], 'effectivePeriod');

                $this->syncHasOneReference($diagnosticReport, 'performer', $data['performer'] ?? []);
                $this->syncHasOneReference($diagnosticReport, 'resultsInterpreter', $data['results_interpreter'] ?? []);

                $this->syncPivot(
                    $diagnosticReport,
                    'specimens',
                    $this->syncIdentifiers($existing, $data['specimens'] ?? [], 'specimens')
                );
                $this->syncPivot(
                    $diagnosticReport,
                    'usedReferences',
                    $this->syncIdentifiers($existing, $data['used_references'] ?? [], 'usedReferences')
                );
            }
        });
    }

    /**
     * Sync a HasOne reference entity (performer or resultsInterpreter).
     *
     * @param  DiagnosticReport  $diagnosticReport
     * @param  string  $relationName
     * @param  array  $entityData
     * @return void
     */
    private function syncHasOneReference(
        DiagnosticReport $diagnosticReport,
        string $relationName,
        array $entityData
    ): void {
        $existing = $diagnosticReport->relationLoaded($relationName) ? $diagnosticReport->{$relationName} : null;

        if (empty($entityData)) {
            $existing?->delete();

            return;
        }

        $referenceId = null;
        $text = $entityData['text'] ?? null;

        if (isset($entityData['reference'])) {
            if ($existing && $existing->reference) {
                $this->updateIdentifier($existing->reference, $entityData['reference']);
                $referenceId = $existing->reference->id;
            } else {
                $reference = Repository::identifier()->store($entityData['reference']['identifier']['value']);
                Repository::codeableConcept()->attach($reference, $entityData['reference']);
                $referenceId = $reference->id;
            }
        }

        if ($existing) {
            $hasChanged = ($existing->referenceId !== $referenceId) || ($existing->text !== $text);

            if ($hasChanged) {
                $existing->update([
                    'reference_id' => $referenceId,
                    'text' => $text
                ]);
            }
        } else {
            $diagnosticReport->{$relationName}()->create([
                'reference_id' => $referenceId,
                'text' => $text
            ]);
        }
    }
}
