<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\View\View;

class PatientImmunization extends BasePatientComponent
{
    public array $immunizations = [];

    public array $episodes = [];

    public string $filterVaccine = '';

    public string $filterEpisodeId = '';

    public string $filterDateFrom = '';

    public string $filterDateTo = '';

    public bool $showAdditionalParams = false;

    protected function initializeComponent(): void
    {
        $this->getDictionary();
        $this->loadEpisodesFromDb();
        $this->loadImmunizaionsFromDb();
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

        $this->loadEpisodes();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterVaccine',
            'filterEpisodeId',
            'filterDateFrom',
            'filterDateTo',
        ]);

        $this->loadImmunizations();
    }

    public function updatedPage(): void
    {
        $this->loadImmunizations($this->buildSearchParams());
    }

    private function loadImmunizaionsFromDb(): void
    {
        $immunizations = Repository::immunization()->getByPersonId($this->personId);

        $this->totalEntries = count($immunizations);

        $this->immunizations = Arr::toCamelCase(
            $this->formatDatesForDisplay($immunizations)
        );
    }

    private function loadEpisodesFromDb(): void 
    {
        $episodes = Repository::episode()->getByPersonId($this->personId);

        $this->totalEntries = count($episodes);

        $this->episodes = Arr::toCamelCase(
            $this->formatDatesForDisplay($episodes)
        );
    }   

    private function loadImmunizations(array $params = []): void
    {
        try {
            $response = EHealth::immunization()->getBySearchParams($this->uuid, $params);

            $validatedData = $response->validate();

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
        ], static fn ($value) => $value !== null && $value !== '');
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
        return view('livewire.person.records.immunization');
    }
}