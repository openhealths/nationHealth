<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property DiagnosticReport $model
 */
class DiagnosticReportRepository extends BaseRepository
{
    protected ?string $employeeUuid;

    protected ?string $employeeFullName;

    public function __construct(Model $model)
    {
        parent::__construct($model);

        $employee = Auth::user()?->getDiagnosticReportWriterEmployee();

        $this->employeeUuid = $employee?->uuid;
        $this->employeeFullName = $employee?->fullName;
    }

    private function getEmployeeDisplayValue(?string $employeeUuid): ?string
    {
        if (!$employeeUuid) {
            return null;
        }

        if ($employeeUuid === $this->employeeUuid) {
            return $this->employeeFullName;
        }

        return Employee::query()
            ->select(['uuid', 'party_id'])
            ->with('party:id,last_name,first_name,second_name')
            ->where('uuid', $employeeUuid)
            ->first()
            ?->fullName;
    }

    private function getServiceDisplayValue(?string $serviceId): ?string
    {
        if (!$serviceId) {
            return null;
        }

        return collect(dictionary()->services()->flattened()->toArray())
            ->firstWhere('id', $serviceId)['name'] ?? null;
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
                $codeValue = $datum['code']['identifier']['value'];

                $code = Repository::identifier()->store(
                    $codeValue,
                    $this->getServiceDisplayValue($codeValue)
                );

                Repository::codeableConcept()->attach($code, $datum['code']);

                $recordedByValue = $datum['recordedBy']['identifier']['value'];
                $recordedBy = Repository::identifier()->store(
                    $recordedByValue,
                    $this->getEmployeeDisplayValue($recordedByValue)
                );
                Repository::codeableConcept()->attach($recordedBy, $datum['recordedBy']);

                $encounter = null;

                if (isset($datum['encounter'])) {
                    $encounter = Repository::identifier()->store($datum['encounter']['identifier']['value']);
                    Repository::codeableConcept()->attach($encounter, $datum['encounter']);
                }

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
                        : null,
                    'ehealth_inserted_at' => now(),
                    'ehealth_updated_at' => now(),
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
                        $performerValue = $datum['performer']['reference']['identifier']['value'];
                        $reference = Repository::identifier()->store(
                            $performerValue,
                            $this->getEmployeeDisplayValue($performerValue)
                        );
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
                        $resultsInterpreterValue = $datum['resultsInterpreter']['reference']['identifier']['value'];
                        $reference = Repository::identifier()->store(
                            $resultsInterpreterValue,
                            $this->getEmployeeDisplayValue($resultsInterpreterValue)
                        );
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

                if (!empty($datum['usedReferences'])) {
                    $usedReferenceIds = [];

                    foreach ($datum['usedReferences'] as $usedReferenceData) {
                        $equipmentUuid = data_get($usedReferenceData, 'identifier.value');

                        if (!$equipmentUuid) {
                            continue;
                        }

                        $identifier = Repository::identifier()->store($equipmentUuid);
                        Repository::codeableConcept()->attach($identifier, $usedReferenceData);

                        $usedReferenceIds[] = $identifier->id;
                    }

                    $diagnosticReport->usedReferences()->attach($usedReferenceIds);
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
            'resultsInterpreter.reference.type.coding',
            'usedReferences.type.coding',
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
                $codeValue = data_get($data, 'code.identifier.value');
                $data['code']['display_value'] = data_get($data, 'code.display_value') ?: $this->getServiceDisplayValue($codeValue);
                $code = $this->syncIdentifier($existing, $data['code'], 'code');
                $encounter = $this->syncIdentifier($existing, $data['encounter'] ?? null, 'encounter');
                $division = $this->syncIdentifier($existing, $data['division'] ?? null, 'division');
                $conclusionCode = $this->syncCodeableConcept(
                    $existing,
                    $data['conclusion_code'] ?? null,
                    'conclusionCode'
                );
                $recordedByData = $data['recorded_by'];
                $recordedByValue = data_get($recordedByData, 'identifier.value');
                $recordedByData['display_value'] = data_get($recordedByData, 'display_value') ?: $this->getEmployeeDisplayValue($recordedByValue);
                $recordedByValue = data_get($data, 'recorded_by.identifier.value');
                $data['recorded_by']['display_value'] = data_get($data, 'recorded_by.display_value') ?: $this->getEmployeeDisplayValue($recordedByValue);
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
            $referenceValue = data_get($entityData, 'reference.identifier.value');

            $entityData['reference']['display_value'] = data_get($entityData, 'reference.display_value')
                ?: $this->getEmployeeDisplayValue($referenceValue);

            if ($existing && $existing->reference) {
                $this->updateIdentifier($existing->reference, $entityData['reference']);
                $referenceId = $existing->reference->id;
            } else {
                $reference = Repository::identifier()->store(
                    $referenceValue,
                    data_get($entityData, 'reference.display_value')
                );

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
