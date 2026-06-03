<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Exceptions\EHealth\EHealthConnectionException;
use App\Repositories\MedicalEvents\Repository;
use App\Enums\Person\ClinicalImpressionStatus;
use App\Exceptions\EHealth\EHealthException;
use App\Traits\BatchLegalEntityQueries;
use App\Jobs\ClinicalImpressionSync;
use App\Classes\eHealth\EHealth;
use App\Traits\HandlesSyncBatch;
use App\Models\LegalEntity;
use App\Enums\JobStatus;
use App\Core\Arr;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;
use Throwable;

class PatientClinicalImpressions extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    public array $clinicalImpressions = [];

    public array $filterCodeOptions = [];

    public array $filterEncounterOptions = [];

    public array $filterEpisodeOptions = [];

    public string $filterCode = '';

    public string $filterEncounterId = '';

    public string $filterEpisodeId = '';

    public string $filterStatus = '';

    public string $filterEffectiveDateFrom = '';

    public string $filterEffectiveDateTo = '';

    public int $totalEntries = 0;

    public string $syncStatus = '';

    public int $pageSize = 10;

    public bool $dataFromDb = true;

    public bool $showAdditionalParams = false;

    protected array $dictionaryNames = [
        'eHealth/clinical_impression_patient_categories',
        'eHealth/clinical_impression_statuses',
        'eHealth/encounter_classes',
        'eHealth/resources',
    ];

    protected function getSyncStatus(string $entityType): ?string 
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return ClinicalImpressionSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return ClinicalImpressionSync::class;
    }

    protected function getEntityConstant(string $entityType): string 
    {
        return LegalEntity::ENTITY_CLINICAL_IMPRESSION;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void 
    {
        $this->syncStatus = $status->value;
    }

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->getClinicalImpressionsFromDb();

        $this->loadFilters();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->resetPage();

        $this->getClinicalImpressions($this->buildSearchParams());
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterCode',
            'filterEncounterId',
            'filterEpisodeId',
            'filterStatus',
            'filterEffectiveDateFrom',
            'filterEffectiveDateTo',
        ]);

        $this->resetPage();

        $this->getClinicalImpressions($this->buildSearchParams());
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('clinicalImpression')) {
            return;
        }

        if ($this->shouldResumeSync('clinicalImpression')) {
            $this->handleResumeLogic('clinicalImpression');

            return;
        }

        try {
            $response = EHealth::clinicalImpression()->getBySearchParams(
                $this->uuid,
                $this->buildSearchParams(),
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing clinical impressions');

            return;
        }
        
        try {
            $validatedData = $response->validate();
            Repository::clinicalImpression()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while syncronizing clinical impressions');
            Session::flash('error', __('patients.messages.clinical_impression_sync_database_error'));

            return;
        }

        if($response->isNotLast()) {
            $this->dispatchRemainingPages('clinicalImpression');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CLINICAL_IMPRESSION);
            Session::flash('success', __('patients.messages.clinical_impressions_synced_successfully'));
        }

        $this->clinicalImpressions = Arr::toCamelCase($validatedData);

        $this->resetPage();

        $this->getEpisodes();
        $this->getEncounters();
        $this->getClinicalImpressions($this->buildSearchParams());
    }

    public function updatedPage(): void 
    {
        if ($this->dataFromDb) {
            $this->getClinicalImpressionsFromDb();
        } else {
            $this->getClinicalImpressions($this->buildSearchParams());
        }
    }

    public function getClinicalImpressions(array $params = []): void
    {
        try {
            $this->dataFromDb = false;

            $response = EHealth::clinicalImpression()->getBySearchParams($this->uuid, $params);

            $validatedData = $response->validate();

            $paging = $response->getPaging();
            $this->totalEntries = $paging['total_entries'] ?? 0;
            $this->pageSize = $paging['page_size'] ?? 10;

            $this->clinicalImpressions = Arr::toCamelCase($validatedData);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->clinicalImpressions = [];

            $exception->handle('Error while loading clinical impressions');
        }
    }

    public function getClinicalImpressionsFromDb(): void
    {
        $clinicalImpressions = Repository::clinicalImpression()->getByPersonIdPaginated(
            $this->personId,
            $this->getPage(),
            $this->pageSize
        );

        $this->totalEntries = Repository::clinicalImpression()->countByPersonId($this->personId);

        $this->clinicalImpressions = Arr::toCamelCase($clinicalImpressions);
    }

    public function getEncounters(): void 
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

    public function getEncountersFromDb(): void 
    {
        $encounters = Arr::toCamelCase(
            Repository::encounter()->getByPersonId($this->personId)
        );

        $this->filterEncounterOptions = $this->mapEncounterOptions($encounters);
    }

    public function getEpisodes(): void 
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

    public function getEpisodesFromDb(): void
    {
        $episodes = Arr::toCamelCase(
            Repository::episode()->getByPersonId($this->personId)
        );

        $this->filterEpisodeOptions = $this->mapEpisodeOptions($episodes);
    }

    public function getCodesFromDb(): void 
    {
        $this->filterCodeOptions = collect(data_get($this->dictionaries, 'eHealth/clinical_impression_patient_categories', []))
            ->map(function ($label, string $code): array {
                $label = is_array($label) ? data_get($label, 'name') : $label;

                return [
                    'value' => $code,
                    'label' => $label,
                ];
            })
            ->take(10)
            ->values()
            ->toArray();
    }

    private function getDictionaryValue(string $dictionaryName, ?string $code): ?string
    {
        if (!$code) {
            return null;
        }

        $value = data_get($this->dictionaries, $dictionaryName . '.' . $code);

        if (is_array($value)) {
            return data_get($value, 'name');
        }

        return $value ? $value : null;
    }

    private function loadFilters(): void 
    {
        $this->getCodesFromDb();

        $this->getEpisodesFromDb();

        $this->getEncountersFromDb();
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

    private function buildPaginator(): LengthAwarePaginator 
    {
        return new LengthAwarePaginator(
            $this->clinicalImpressions,
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
            'filterEncounterId' => ['nullable', 'string', 'max:255'],
            'filterEpisodeId' => ['nullable', 'string', 'max:255'],
            'filterStatus' => ['nullable', Rule::in(ClinicalImpressionStatus::values())],
            'filterEffectiveDateFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterEffectiveDateTo' => ['nullable', 'date_format:' . config('app.date_format')],
        ];
    }

    private function buildSearchParams(): array 
    {
        return array_filter([
            'encounter_id' => $this->filterEncounterId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'code' => $this->filterCode ?: null,
            'status' => $this->filterStatus ?: null,
            'effective_date_from' => $this->filterEffectiveDateFrom ?: null,
            'effective_date_to' => $this->filterEffectiveDateTo ?: null,
            'page' => $this->getPage(),
            'page_size' => $this->pageSize,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    public function render()
    {
        return view('livewire.person.records.clinical-impressions', [
            'paginatedClinicalImpressions' => $this->buildPaginator(), 
        ]);
    }
}