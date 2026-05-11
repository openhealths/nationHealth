<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\ImmunizationSync;
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

class PatientImmunization extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    public array $immunizations = [];

    public array $episodes = [];

    public string $syncStatus = '';

    public string $filterVaccine = '';

    public string $filterEpisodeId = '';

    public string $filterDateFrom = '';

    public string $filterDateTo = '';

    public bool $showAdditionalParams = false;

    public int $totalEntries = 0;

    public int $pageSize = 10;

    protected array $dictionaryNames = [
        'eHealth/vaccine_codes',
        'eHealth/vaccination_routes',
        'eHealth/immunization_body_sites',
        'eHealth/reason_explanations',
        'eHealth/immunization_dosage_units',
        'eHealth/vaccination_authorities',
        'eHealth/vaccination_target_diseases',
    ];

    protected function initializeComponent(): void
    {
        $this->getDictionary();
        $this->loadEpisodes();
        $this->loadImmunizations($this->buildSearchParams());
    }

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return ImmunizationSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string 
    {
        return ImmunizationSync::class;
    }

    protected function getEntityConstant(string $entityType): string 
    {
        return LegalEntity::ENTITY_IMMUNIZATION;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void 
    {
        $this->syncStatus = $status->value;
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->loadImmunizations($this->buildSearchParams());
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('immunization')) {
            return;
        }

        if ($this->shouldResumeSync('immunization')) {
            $this->handleResumeLogic('immunization');

            return;
        }

        try {
            $response = EHealth::immunization()->getBySearchParams(
                $this->uuid,
                $this->buildSearchParams(),
            );
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing immunizations');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::immunization()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing immunizations');
            Session::flash('error', __('patients.messages.immunization_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('immunization');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_IMMUNIZATION);
            Session::flash('success', __('patients.messages.immunization_synced_successfully'));
        }

        $this->immunizations = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterVaccine',
            'filterEpisodeId',
            'filterDateFrom',
            'filterDateTo',
        ]);

        $this->resetPage();
        $this->loadImmunizations();
    }

    public function updatedPage(): void
    {
        $this->loadImmunizations($this->buildSearchParams());
    }

    private function loadImmunizations(array $params = []): void
    {
        try {
            $response = EHealth::immunization()->getBySearchParams($this->uuid, $params);

            $validatedData = $response->validate();

            $paging = $response->getPaging();
            $this->totalEntries = $paging['total_entries'] ?? 0;
            $this->pageSize = $paging['page_size'] ?? 10;

            $this->immunizations = Arr::toCamelCase($validatedData);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->immunizations = [];

            $this->handleEHealthExceptions($exception, 'Error while loading immunizations');
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

            $this->episodes = Arr::toCamelCase($validatedData);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->episodes = [];

            $this->handleEHealthExceptions($exception, 'Error while loading episodes');
        }
    }

    private function buildSearchParams(): array
    {
        return array_filter([
            'vaccine_code' => $this->filterVaccine ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'date_from' => $this->filterDateFrom ?: null,
            'date_to' => $this->filterDateTo ?: null,
            'page' => $this->getPage(),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function buildPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $this->immunizations,
            $this->totalEntries,
            $this->pageSize,
            $this->getPage(),
            ['path' => request()->url()]
        );
    }

    private function filterValidationRules(): array
    {
        return [
            'filterVaccine' => ['nullable', 'string', 'max:255'],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterDateFrom' => ['nullable', 'string', 'max:255'],
            'filterDateTo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function render(): View
    {
        return view('livewire.person.records.immunization', [
            'paginatedImmunizations' => $this->buildPaginator(),
        ]);
    }
}