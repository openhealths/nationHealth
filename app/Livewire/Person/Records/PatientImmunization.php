<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Jobs\ImmunizationSync;
use App\Models\LegalEntity;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\View\View;
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

    public array $filterVaccineOptions = [];

    public array $filterEncounterOptions = [];

    public array $filterEpisodeOptions = [];

    public string $syncStatus = '';

    public string $filterVaccine = '';

    public string $filterEpisodeId = '';

    public string $filterEncounterId = '';

    public string $filterDateFrom = '';

    public string $filterDateTo = '';

    public bool $dataFromDb = true;

    public bool $showAdditionalParams = false;

    public int $totalEntries = 0;

    public int $pageSize = 10;

    protected array $dictionaryNames = [
        'eHealth/immunization_statuses',
        'eHealth/vaccine_codes',
        'eHealth/vaccination_routes',
        'eHealth/immunization_body_sites',
        'eHealth/immunization_report_origins',
        'eHealth/reason_explanations',
        'eHealth/reason_not_given_explanations',
        'eHealth/immunization_dosage_units',
        'eHealth/vaccination_authorities',
        'eHealth/vaccination_target_diseases',
        'eHealth/encounter_classes',
    ];

    protected function initializeComponent(): void
    {
        $this->getDictionary();
        $this->getImmunizationsFromDb();
        $this->loadFilters();
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

        $this->resetPage();
        $this->getImmunizations($this->buildSearchParams());
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
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing immunizations');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::immunization()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing immunizations');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('immunization');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_IMMUNIZATION);
            Session::flash('success', __('patients.messages.immunizations_synced_successfully'));
        }

        $this->immunizations = Arr::toCamelCase($validatedData);

        $this->resetPage();

        $this->getEpisodes();
        $this->getEncounters();
        $this->getImmunizations($this->buildSearchParams());
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterVaccine',
            'filterEpisodeId',
            'filterEncounterId',
            'filterDateFrom',
            'filterDateTo',
        ]);

        $this->resetPage();
        $this->getImmunizations();
    }

    public function updatedPage(): void
    {
        if ($this->dataFromDb) {
            $this->getImmunizationsFromDb();
        } else {
            $this->getImmunizations($this->buildSearchParams());
        }
    }

    private function loadFilters(): void 
    {
        $this->getVaccineCodesFromDictionary();

        $this->getEpisodesFromDb();

        $this->getEncountersFromDb();
    }

    public function getVaccineCodesFromDictionary(): void
    {
        $this->filterVaccineOptions = collect(data_get($this->dictionaries, 'eHealth/vaccine_codes', []))
            ->map(function ($label, string $code): array {
                return [
                    'value' => $code,
                    'label' => collect([$code, $label])->filter()->implode(' | '),
                    'description' => $label,
                ];
            })
            ->sortBy('label')
            ->values()
            ->toArray();
    }

    private function getDictionaryValue(string $dictionaryName, ?string $code): ?string
    {
        if (!$code) {
            return null;
        }

        $value = data_get($this->dictionaries, $dictionaryName . '.' . $code);

        return $value;
    }

    private function getImmunizationsFromDb(): void
    {
        $this->dataFromDb = true;

        $immunizations = Repository::immunization()->getByPersonIdPaginated(
            $this->personId,
            $this->getPage(),
            $this->pageSize
        );

        $this->totalEntries = Repository::immunization()->countByPersonId($this->personId);

        $this->immunizations = Arr::toCamelCase($immunizations);
    }

    private function getImmunizations(array $params = []): void
    {
        try {
            $this->dataFromDb = false;
            
            $response = EHealth::immunization()->getBySearchParams($this->uuid, $params);

            $validatedData = $response->validate();

            $paging = $response->getPaging();
            $this->totalEntries = $paging['total_entries'] ?? 0;
            $this->pageSize = $paging['page_size'] ?? 10;

            $this->immunizations = Arr::toCamelCase($validatedData);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->immunizations = [];

            $exception->handle('Error while loading immunizations');
        }
    }

    private function getEncounters(): void 
    {
        try {
            $response = EHealth::encounter()->getBySearchParams(
                $this->uuid,
                [
                    'managing_organization_id' => legalEntity()?->uuid,
                ]
            );

            $validateData = Arr::toCamelCase($response->validate());

            $this->filterEncounterOptions = $this->mapEncounterOptions($validateData);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->filterEncounterOptions = [];

            $exception->handle('Error while loading encounters');
        }
    }

    private function getEncountersFromDb(): void 
    {
        $encounters = Arr::toCamelCase(
            Repository::encounter()->getByPersonId($this->personId)
        );

        $this->filterEncounterOptions = $this->mapEncounterOptions($encounters);
    }

    private function getEpisodes(): void 
    {
        try {
            $response = EHealth::episode()->getBySearchParams(
                $this->uuid,
                [
                    'managing_organization_id' => legalEntity()?->uuid,
                ]
            );

            $validatedData = Arr::toCamelCase($response->validate());

            $this->filterEpisodeOptions = $this->mapEpisodeOptions($validatedData);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->filterEpisodeOptions = [];

            $exception->handle('Error while loading episodes');
        }
    }

    private function getEpisodesFromDb(): void
    {
        $episodes = Arr::toCamelCase(
            Repository::episode()->getByPersonId($this->personId)
        );

        $this->filterEpisodeOptions = $this->mapEpisodeOptions($episodes);
    }

    private function mapEncounterOptions(array $encounters): array
    {
        return collect($encounters)
            ->map(function (array $encounter) {
                $encounterId = data_get($encounter, 'uuid');

                if (!$encounterId) {
                    return null;
                }

                $classCode = data_get($encounter, 'class.code') ?: data_get($encounter, 'class.coding.0.code');

                $classLabel = $this->getDictionaryValue('eHealth/encounter_classes', $classCode);

                $label = $classCode ? collect([$classCode, $classLabel])->filter()->implode(' | ') : $encounterId;

                return [
                    'value' => $encounterId,
                    'label' => $label,
                ];
            })
            ->filter()
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->toArray();
    }

    private function mapEpisodeOptions(array $episodes): array
    {
        return collect($episodes)
            ->map(function (array $episode) {
                $episodeId = data_get($episode, 'uuid');

                if (!$episodeId) {
                    return null;
                }

                return [
                    'value' => $episodeId,
                    'label' => data_get($episode, 'name'),
                ];
            })
            ->filter()
            ->unique('value')
            ->values()
            ->toArray();
    }

    private function buildSearchParams(): array
    {
        return array_filter([
            'vaccine_code' => $this->filterVaccine ?: null,
            'encounter_id' => $this->filterEncounterId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'date_from' => $this->filterDateFrom ?: null,
            'date_to' => $this->filterDateTo ?: null,
            'page' => $this->getPage(),
            'page_size' => $this->pageSize,
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
            'filterEncounterId' => ['nullable', 'string', 'max:255'],
            'filterEpisodeId' => ['nullable', 'string', 'max:255'],
            'filterDateFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterDateTo' => ['nullable', 'date_format:' . config('app.date_format')],
        ];
    }

    public function render(): View
    {
        return view('livewire.person.records.immunization', [
            'paginatedImmunizations' => $this->buildPaginator(),
        ]);
    }
}
