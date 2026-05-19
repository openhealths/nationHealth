<?php

namespace App\Livewire\Person\Records;

use App\Livewire\Person\Records\BasePatientComponent;
use App\Core\Arr;
use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\ObservationSync;
use App\Models\LegalEntity;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Session;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;
use Throwable;

class PatientObservation extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;
    
    public array $observations = [];

    public array $episodes = [];

    public array $encounters = [];

    public array $diagnosticReports = [];

    public array $devices = [];

    public array $specimens = [];

    public array $filterCodeOptions = [];

    public array $filterEncounterOptions = [];

    public array $filterDiagnosticReportOptions = [];

    public array $filterEpisodeOptions = [];

    public array $filterDeviceOptions = [];

    public array $filterSpecimenOptions = [];

    public string $filterCode = '';

    public string $filterEncounterId = '';

    public string $filterDiagnosticReportId = '';

    public string $filterEpisodeId = '';

    public string $filterIssuedFrom = '';
    
    public string $filterIssuedTo = '';

    public string $filterDeviceId = '';

    public string $filterSpecimenId = '';

    public bool $showAdditionalParams = false;

    public string $syncStatus = '';

    public int $totalEntries = 0;

    public int $pageSize = 10;

    protected array $dictionaryNames = [
        'eHealth/observation_categories',
        'eHealth/ICF/observation_categories',
        'eHealth/LOINC/observation_codes',
        'eHealth/custom/observation_codes',
        'eHealth/ICF/classifiers',
        'eHealth/observation_methods',
        'eHealth/observation_interpretations',
        'eHealth/body_sites',
        'eHealth/ucum/units',
        'eHealth/report_origins',
        'eHealth/eye_colour',
        'eHealth/hair_color',
        'eHealth/hair_length',
        'GENDER',
        'eHealth/rankin_scale',
        'eHealth/vaccination_covid_groups',
    ];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return ObservationSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string 
    {
        return ObservationSync::class;
    }

    protected function getEntityConstant(string $entityType): string 
    {
        return LegalEntity::ENTITY_OBSERVATION;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void 
    {
        $this->syncStatus = $status->value;
    }

    public function initializeComponent(): void 
    {
        $this->getDictionary();

        $this->dictionaries['eHealth/ICF/classifiers'] = dictionary()
            ->basics()
            ->byName('eHealth/ICF/classifiers')
            ->flattenedChildValues()
            ->toArray();

        $this->loadObservationsFromDb();

        $this->loadFilters();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->resetPage();

        $this->loadObservations($this->buildSearchParams());
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('observation')) {
            return;
        }

        if ($this->shouldResumeSync('observation')) {
            $this->handleResumeLogic('observation');

            return;
        }

        try {
            $response = EHealth::observation()->getBySearchParams(
                $this->uuid,
                $this->buildSearchParams(),
            );
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing observation');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::observation()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing observation');
            Session::flash('error', __('patients.messages.observation_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('observation');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_OBSERVATION);
            Session::flash('success', __('patients.messages.observation_synced_successfully'));
        }

        $this->observations = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function updatedPage(): void
    {
        $this->loadObservations($this->buildSearchParams());
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterCode',
            'filterEncounterId',
            'filterDiagnosticReportId',
            'filterEpisodeId',
            'filterIssuedFrom',
            'filterIssuedTo',
            'filterDeviceId',
            'filterSpecimenId',
        ]);

        $this->resetPage();

        $this->loadObservations($this->buildSearchParams());
    }

    private function loadFilters(): void
    {
        $this->loadObservationCodesFromDb();

        $this->loadEpisodesFromDb();

        $this->loadEncountersFromDb();

        $this->loadDiagnosticReportsFromDb();
    }

    private function loadObservationCodesFromDb(): void
    {
        $observations = Arr::toCamelCase(Repository::observation()->getByPersonId($this->personId));

        $this->filterCodeOptions = collect($observations)
            ->map(function (array $observation) {
                $code = data_get($observation, 'code.coding.0.code');
                $system = data_get($observation, 'code.coding.0.system', 'eHealth/LOINC/observation_codes');

                if (!$code) {
                    return null;
                }

                $label = data_get(
                    $this->dictionaries,
                    $system . '.' . $code,
                    data_get($observation, 'code.text', $code)
                );

                return [
                    'value' => $code,
                    'label' => $code . ' | ' . $label,
                    'description' => $system,
                ];
            })
            ->filter()
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->toArray();
    }

    private function loadEpisodesFromDb(): void 
    {
        $filterEpisodeOptions = Repository::episode()->getByPersonId($this->personId);

        $this->filterEpisodeOptions = collect($filterEpisodeOptions)
                ->map(function (array $episode) {
                    $episodeId = data_get($episode, 'uuid');

                    if (!$episodeId) {
                        return null;
                    }

                    return [
                        'value' => $episodeId,
                        'label' => data_get($episode, 'name') ?: $episodeId,
                        'description' => $episodeId,
                    ];
                })
                ->filter()
                ->unique('value')
                ->values()
                ->toArray();
    }

    private function loadEncountersFromDb(): void
    {
        $encounters = Repository::encounter()->getByPersonId($this->personId);

        $this->filterEncounterOptions = collect(Arr::toCamelCase($encounters))
            ->map(function (array $encounter) {
                $encounterId = data_get($encounter, 'uuid');

                if (!$encounterId) {
                    return null;
                }

                $typeCode = data_get($encounter, 'type.coding.0.code');
                $typeSystem = data_get($encounter, 'type.coding.0.system');

                $typeLabel = $typeCode
                    ? data_get(
                        $this->dictionaries,
                        $typeSystem . '.' . $typeCode,
                        data_get($encounter, 'type.text', $typeCode)
                    )
                    : null;

                $classCode = data_get($encounter, 'class.code', data_get($encounter, 'class.coding.0.code'));
                $classSystem = data_get($encounter, 'class.system', data_get($encounter, 'class.coding.0.system'));

                $classLabel = $classCode
                    ? data_get(
                        $this->dictionaries,
                        $classSystem . '.' . $classCode,
                        data_get($encounter, 'class.text', $classCode)
                    )
                    : null;

                $episodeLabel = data_get(
                    $encounter,
                    'episode.displayValue',
                    data_get($encounter, 'episode.identifier.value')
                );

                $periodStart = collect([
                    data_get($encounter, 'period.startDate'),
                    data_get($encounter, 'period.startTime'),
                ])->filter()->implode(' ');

                $performer = data_get($encounter, 'performer.displayValue');

                $label = collect([
                    $typeLabel,
                    $classLabel,
                    $episodeLabel,
                    $periodStart,
                    $performer,
                ])->filter()->implode(' | ');

                return [
                    'value' => $encounterId,
                    'label' => $label ?: $encounterId,
                    'description' => $encounterId,
                ];
            })
            ->filter()
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->toArray();
    }

    private function loadDiagnosticReportsFromDb(): void
    {
        $diagnosticReports = Repository::diagnosticReport()->getByPersonId($this->personId);

        $this->filterDiagnosticReportOptions = collect(Arr::toCamelCase($diagnosticReports))
            ->map(function (array $diagnosticReport) {
                $diagnosticReportId = data_get($diagnosticReport, 'uuid');

                if (!$diagnosticReportId) {
                    return null;
                }

                $code = data_get(
                    $diagnosticReport,
                    'code.identifier.value',
                    data_get($diagnosticReport, 'code.coding.0.code')
                );

                $codeSystem = data_get($diagnosticReport, 'code.coding.0.system');

                $codeLabel = $code
                    ? data_get(
                        $this->dictionaries,
                        $codeSystem . '.' . $code,
                        data_get(
                            $diagnosticReport,
                            'code.displayValue',
                            data_get($diagnosticReport, 'code.text', $code)
                        )
                    )
                    : null;

                $categoryCode = data_get($diagnosticReport, 'category.0.coding.0.code');
                $categorySystem = data_get(
                    $diagnosticReport,
                    'category.0.coding.0.system',
                    'eHealth/diagnostic_report_categories'
                );

                $categoryLabel = $categoryCode
                    ? data_get(
                        $this->dictionaries,
                        $categorySystem . '.' . $categoryCode,
                        data_get($diagnosticReport, 'category.0.text', $categoryCode)
                    )
                    : null;

                $issued = collect([
                    data_get($diagnosticReport, 'issuedDate'),
                    data_get($diagnosticReport, 'issuedTime'),
                ])->filter()->implode(' ');

                $performer = data_get($diagnosticReport, 'performer.displayValue');

                $label = collect([
                    $codeLabel,
                    $categoryLabel,
                    $issued,
                    $performer,
                ])->filter()->implode(' | ');

                return [
                    'value' => $diagnosticReportId,
                    'label' => $label ?: $diagnosticReportId,
                    'description' => $diagnosticReportId,
                ];
            })
            ->filter()
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->toArray();
    }

    private function loadObservations(array $params = []): void
    {
        try {
            $response = EHealth::observation()->getBySearchParams(
                $this->uuid,
                $params
            );

            $validatedData = $response->validate();

            $paging = $response->getPaging();
            $this->totalEntries = $paging['total_entries'] ?? 0;
            $this->pageSize = $paging['page_size'] ?? 10;

            $this->observations = Arr::toCamelCase($validatedData);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->observations = [];

            $this->handleEHealthExceptions($exception, 'Error while loading observations');
        }
    }

    private function loadObservationsFromDb(): void
    {
        $observations = Repository::observation()->getByPersonId($this->personId);

        $this->totalEntries = count($observations);

        $this->observations = Arr::toCamelCase($observations);
    }

    private function buildSearchParams(): array
    {
        return array_filter([
            'code' => $this->filterCode ?: null,
            'encounter_id' => $this->filterEncounterId ?: null,
            'diagnostic_report_id' => $this->filterDiagnosticReportId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'issued_from' => $this->filterIssuedFrom ?: null,
            'issued_to' => $this->filterIssuedTo ?: null,
            'device_id' => $this->filterDeviceId ?: null,
            'managing_organization_id' => legalEntity()?->uuid,
            'specimen_id' => $this->filterSpecimenId ?: null,
            'page' => $this->getPage(),
            'page_size' => $this->pageSize,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function buildPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $this->observations,
            $this->totalEntries,
            $this->pageSize,
            $this->getPage(),
            ['path' => request()->url()]
        );
    }

    private function filterValidationRules(): array
    {
        return [
            'filterCode' => ['nullable', 'string', 'max:255'],
            'filterEncounterId' => ['nullable', 'uuid'],
            'filterDiagnosticReportId' => ['nullable', 'uuid'],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterIssuedFrom' => ['nullable', 'date_format:d.m.Y'],
            'filterIssuedTo' => ['nullable', 'date_format:d.m.Y'],
            'filterDeviceId' => ['nullable', 'uuid'],
            'filterSpecimenId' => ['nullable', 'uuid'],
        ];
    }

    public function render()
    {
        return view('livewire.person.records.observations', [
            'paginatedObservations' => $this->buildPaginator(),
        ]);
    }
}
