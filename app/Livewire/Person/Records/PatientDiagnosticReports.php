<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Jobs\DiagnosticReportSync;
use App\Traits\HandlesSyncBatch;
use App\Models\LegalEntity;
use App\Enums\JobStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\WithPagination;
use Throwable;

class PatientDiagnosticReports extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    public array $diagnosticReports = [];

    public array $filterCodeOptions = [];

    public array $filterEncounterOptions = [];

    public array $filterEpisodeOptions = [];

    public array $filterBasedOnOptions = [];

    public array $filterSpecimenOptions = [];

    public string $filterCategory = '';

    public string $filterCode = '';

    public string $filterEncounterId = '';

    public string $filterContextEpisodeId = '';

    public string $filterOriginEpisodeId = '';

    public string $filterBasedOn = '';

    public string $filterSpecimenId = '';

    public string $filterIssuedFrom = '';

    public string $filterIssuedTo = '';

    public bool $showAdditionalParams = false;

    public int $totalEntries = 0;

    public string $syncStatus = '';

    public int $pageSize = 10;

    protected array $dictionaryNames = [
        'eHealth/diagnostic_report_categories',
    ];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return DiagnosticReportSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string 
    {
        return DiagnosticReportSync::class;
    }

    protected function getEntityConstant(string $entityType): string 
    {
        return LegalEntity::ENTITY_DIAGNOSTIC_REPORT;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void 
    {
        $this->syncStatus = $status->value;
    }

    public function initializeComponent(): void 
    {
        $this->getDictionary();

        $this->dictionaries['custom/services'] = dictionary()->services()->flattened()->toArray();

        $this->loadFilters();

        $this->loadDiagnosticReports($this->buildSearchParams());
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->resetPage();
        
        $this->loadDiagnosticReports($this->buildSearchParams());
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterCategory',
            'filterCode',
            'filterEncounterId',
            'filterContextEpisodeId',
            'filterOriginEpisodeId',
            'filterIssuedFrom',
            'filterIssuedTo',
            'filterBasedOn',
            'filterSpecimenId',
        ]);

        $this->resetPage();

        $this->loadDiagnosticReports($this->buildSearchParams());
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('diagnostic_report')) {
            return;
        }

        if ($this->shouldResumeSync('diagnostic_report')) {
            $this->handleResumeLogic('diagnostic_report');
            return;
        }

        try {
            $response = EHealth::diagnosticReport()->getBySearchParams(
                $this->uuid,
                $this->buildSearchParams(),
            );
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing diagnostic report');
            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::diagnosticReport()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing diagnostic report');
            Session::flash('error', __('patients.messages.diagnostic_report_sync_database_error'));
            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('diagnostic_report');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_DIAGNOSTIC_REPORT);
            Session::flash('success', __('patients.messages.diagnostic_reports_synced_successfully'));
        }

        $this->diagnosticReports = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function updatedPage(): void
    {
        $this->loadDiagnosticReports($this->buildSearchParams());
    }

    private function loadFilters(): void 
    {
        $this->loadServices();

        $this->loadEpisodes();

        $this->loadEncounters();
    }

    private function loadDiagnosticReports(array $params = []): void
    {
        try
        {
            $response = EHealth::diagnosticReport()->getBySearchParams($this->uuid, $params);

            $validateData = $response->validate();

            $paging = $response->getPaging();
            $this->totalEntries = $paging['total_entries'] ?? 0;
            $this->pageSize = $paging['page_size'] ?? 10;

            $this->diagnosticReports = Arr::toCamelCase($validateData);
        }
        catch(ConnectionException|EHealthValidationException|EHealthResponseException $exception)
        {
            $this->diagnosticReports = [];

            $this->handleEHealthExceptions($exception, 'Error while loading diagnostic reports');
        }
    }

    private function loadEpisodes(): void
    {
        try {
            $response = EHealth::episode()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()?->uuid]
            );

            $validatedData = $response->validate();

            $this->filterEpisodeOptions = collect($validatedData)
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
                ->sortBy('label')
                ->values()
                ->toArray();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->filterEpisodeOptions = [];

            $this->handleEHealthExceptions($exception, 'Error while loading episodes');
        }
    }

    private function loadEncounters(): void
    {
        try {
            $response = EHealth::encounter()->getBySearchParams(
                $this->uuid,
                [
                    'managing_organization_id' => legalEntity()?->uuid,
                    'page_size' => 100,
                ]
            );

            $validatedData = $response->validate();

            $this->filterEncounterOptions = Arr::toCamelCase($validatedData);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->filterEncounterOptions = [];

            $this->handleEHealthExceptions($exception, 'Error while loading encounters');
        }
    }

    private function loadServices(): void
    {
        $this->filterCodeOptions = collect(dictionary()->services()->flattened()->toArray())
            ->map(function (array $service) {
                $serviceId = data_get($service, 'id');

                if (!$serviceId) {
                    return null;
                }

                $serviceCode = data_get($service, 'code');
                $serviceName = data_get($service, 'name') ?: $serviceId;

                return [
                    'value' => $serviceId,
                    'label' => $serviceCode
                        ? $serviceCode . ' | ' . $serviceName
                        : $serviceName,
                    'description' => $serviceId,
                ];
            })
            ->filter()
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->toArray();
    }

    private function buildSearchParams(): array
    {
        return array_filter([
            'code' => $this->filterCode ?: null,
            'encounter_id' => $this->filterEncounterId ?: null,
            'context_episode_id' => $this->filterContextEpisodeId ?: null,
            'origin_episode_id' => $this->filterOriginEpisodeId ?: null,
            'issued_from' => $this->filterIssuedFrom ?: null,
            'issued_to' => $this->filterIssuedTo ?: null,
            'based_on' => $this->filterBasedOn ?: null,
            'managing_organization_id' => legalEntity()?->uuid,
            'specimen_id' => $this->filterSpecimenId ?: null,
            'page' => $this->getPage(),
            'page_size' => $this->pageSize,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function buildPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $this->diagnosticReports,
            $this->totalEntries,
            $this->pageSize,
            $this->getPage(),
            ['path' => request()->url()]
        );
    }

    private function filterValidationRules(): array 
    {
        return [
            'filterCategory' => ['nullable', 'string', 'max:255'],
            'filterCode' => ['nullable', 'uuid'],
            'filterEncounterId' => ['nullable', 'uuid'],
            'filterContextEpisodeId' => ['nullable', 'uuid'],
            'filterOriginEpisodeId' => ['nullable', 'uuid'],
            'filterIssuedFrom' => ['nullable', 'date_format:d.m.Y'],
            'filterIssuedTo' => ['nullable', 'date_format:d.m.Y'],
            'filterBasedOn' => ['nullable', 'uuid'],
            'filterSpecimenId' => ['nullable', 'uuid'],
        ];
    }

    public function render(): View
    {
        return view('livewire.person.records.diagnostic-reports', [
            'paginatedDiagnosticReports' => $this->buildPaginator(),
        ]);
    }
}
