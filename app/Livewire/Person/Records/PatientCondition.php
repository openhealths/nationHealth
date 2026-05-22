<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Jobs\ConditionSync;
use App\Classes\eHealth\EHealth;
use App\Traits\HandlesSyncBatch;
use App\Models\LegalEntity;
use App\Enums\JobStatus;
use App\Core\Arr;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;
use Throwable;

class PatientCondition extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    public array $conditions = [];

    public array $filterCodeOptions = [];

    public array $filterEncounterOptions = [];

    public array $filterEpisodeOptions = [];

    public string $filterCode = '';

    public string $filterEncounterId = '';

    public string $filterEpisodeId = '';

    public string $filterOnsetDateFrom = '';

    public string $filterOnsetDateTo = '';

    public bool $showAdditionalParams = false;

    public int $totalEntries = 0;

    public string $syncStatus = '';

    public int $pageSize = 10;

    public bool $dataFromDb = true;

    protected array $dictionaryNames = [
        'eHealth/ICPC2/condition_codes',
        'eHealth/ICD10/condition_codes',
        'eHealth/encounter_classes',
        'eHealth/body_sites',
        'eHealth/condition_severities',
        'eHealth/condition_clinical_statuses',
        'eHealth/condition_verification_statuses',
        'eHealth/ICPC2/reasons',
        'eHealth/report_origins',
        'eHealth/resources',
    ];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return ConditionSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string 
    {
        return ConditionSync::class;
    }

    protected function getEntityConstant(string $entityType): string 
    {
        return LegalEntity::ENTITY_CONDITION;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void 
    {
        $this->syncStatus = $status->value;
    }

    public function initializeComponent(): void 
    {
        $this->getDictionary();

        $this->loadConditionsFromDb();

        $this->loadFilters();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->resetPage();

        $this->loadConditions($this->buildSearchParams());
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('condition')) {
            return;
        }

        if ($this->shouldResumeSync('condition')) {
            $this->handleResumeLogic('condition');
            return;
        }

        try {
            $response = EHealth::condition()->getBySearchParams(
                $this->uuid,
                $this->buildSearchParams(),
            );
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing condition');
            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::condition()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing condition');
            Session::flash('error', __('patients.messages.condition_sync_database_error'));
            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('condition');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CONDITION);
            Session::flash('success', __('patients.messages.condition_synced_successfully'));
        }

        $this->conditions = Arr::toCamelCase($validatedData);

        $this->resetPage();

        $this->loadEpisodes();
        $this->loadEncounters();
        $this->loadConditions($this->buildSearchParams());
    }

    public function updatedPage(): void
    {
        if ($this->dataFromDb) {
            $this->loadConditionsFromDb();
        } else {
            $this->loadConditions($this->buildSearchParams());
        }
        
    }

    public function searchCodes(string $search = ''): void
    {
        $this->loadCodesFromDb($search);
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterCode',
            'filterEncounterId',
            'filterEpisodeId',
            'filterOnsetDateFrom',
            'filterOnsetDateTo',
        ]);

        $this->resetPage();

        $this->loadConditions($this->buildSearchParams());
    }

    private function loadFilters(): void 
    {
        $this->loadCodesFromDb();

        $this->loadEpisodesFromDb();

        $this->loadEncountersFromDb();
    }

    private function loadCodesFromDb(string $search = ''): void
    {
        $search = trim($search);

        if ($search === '') {
            $this->filterCodeOptions = [];

            return;
        }

        $this->filterCodeOptions = collect()
            ->merge($this->searchCodesInDictionary('eHealth/ICPC2/condition_codes', $search))
            ->merge($this->searchCodesInDictionary('eHealth/ICD10/condition_codes', $search))
            ->merge($this->searchIcd10AmCodesInDb($search))
            ->unique(fn (array $option) => $option['description'] . ':' . $option['value'])
            ->sortBy(fn (array $option) => $this->codeSortPriority($option, $search) . mb_strtolower($option['label']))
            ->take(10)
            ->values()
            ->toArray();
    }

    private function searchCodesInDictionary(string $system, string $search): array
    {
        $search = mb_strtolower($search);
        $options = [];

        foreach (data_get($this->dictionaries, $system, []) as $code => $label) {
            $code = (string) $code;

            $label = is_array($label)
                ? (
                    data_get($label, 'name')
                    ?: data_get($label, 'text')
                    ?: data_get($label, 'description')
                    ?: $code
                )
                : (string) $label;

            $codeLower = mb_strtolower($code);
            $labelLower = mb_strtolower($label);

            if (!str_contains($codeLower, $search) && !str_contains($labelLower, $search)) {
                continue;
            }

            $options[] = [
                'value' => $code,
                'label' => $code . ' | ' . $label,
                'description' => $system,
            ];

            if (count($options) >= 10) {
                break;
            }
        }

        return $options;
    }

    private function searchIcd10AmCodesInDb(string $search): array
    {
        return DB::table('icd_10')
            ->select(['code', 'description'])
            ->where('is_active', true)
            ->where(function ($query) use ($search) {
                $query->where('code', 'ILIKE', '%' . $search . '%')
                    ->orWhere('description', 'ILIKE', '%' . $search . '%');
            })
            ->orderByRaw('CASE WHEN code ILIKE ? THEN 0 ELSE 1 END', [$search . '%'])
            ->orderBy('code')
            ->limit(10)
            ->get()
            ->map(static fn ($item) => [
                'value' => (string) $item->code,
                'label' => $item->code . ' | ' . $item->description,
                'description' => 'eHealth/ICD10_AM/condition_codes',
            ])
            ->toArray();
    }

    private function codeSortPriority(array $option, string $search): string
    {
        $code = mb_strtolower((string) $option['value']);
        $label = mb_strtolower((string) $option['label']);
        $search = mb_strtolower($search);

        if (str_starts_with($code, $search)) {
            return '0';
        }

        if (str_contains($code, $search)) {
            return '1';
        }

        if (str_starts_with($label, $search)) {
            return '2';
        }

        return '3';
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

            $validatedData = Arr::toCamelCase($response->validate());

            $this->filterEncounterOptions = $this->mapEncounterOptions($validatedData);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->filterEncounterOptions = [];

            $this->handleEHealthExceptions($exception, 'Error while loading encounters');
        }
    }

    private function loadEncountersFromDb(): void
    {
        $encounters = Arr::toCamelCase(
            Repository::encounter()->getByPersonId($this->personId)
        );

        $this->filterEncounterOptions = $this->mapEncounterOptions($encounters);
    }

    private function getDictionaryValue(string $dictionaryName, ?string $code): ?string
    {
        if (!$code) {
            return null;
        }

        $value = data_get($this->dictionaries, $dictionaryName . '.' . $code);

        if (is_array($value)) {
            return data_get($value, 'name')
                ?: data_get($value, 'text')
                ?: data_get($value, 'description')
                ?: data_get($value, 'displayValue');
        }

        return $value ? (string) $value : null;
    }

    private function mapEncounterOptions(array $encounters): array
    {
        return collect($encounters)
            ->map(function (array $encounter) {
                $encounterId = data_get($encounter, 'uuid');

                if (!$encounterId) {
                    return null;
                }

                $classCode = data_get($encounter, 'class.code')
                    ?: data_get($encounter, 'class.coding.0.code');

                $classLabel = $this->getDictionaryValue(
                    'eHealth/encounter_classes',
                    $classCode
                );

                $label = $classCode
                    ? collect([$classCode, $classLabel])->filter()->implode(' | ')
                    : $encounterId;

                return [
                    'value' => $encounterId,
                    'label' => $label,
                    'description' => $encounterId,
                ];
            })
            ->filter()
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->toArray();
    }

    private function getDictionaryLabel(?string $system, ?string $code, ?string $fallback = null): ?string
    {
        if (!$code) {
            return $fallback;
        }

        $dictionaryValue = $system
            ? data_get($this->dictionaries, $system . '.' . $code)
            : null;

        if (is_array($dictionaryValue)) {
            $dictionaryValue = data_get($dictionaryValue, 'name')
                ?: data_get($dictionaryValue, 'text')
                ?: data_get($dictionaryValue, 'description')
                ?: data_get($dictionaryValue, 'displayValue');
        }

        $label = $dictionaryValue ?: $fallback ?: $code;

        return $code . ' | ' . $label;
    }

    private function loadConditions(array $params = []): void
    {
        try {
            $this->dataFromDb = false;

            $response = EHealth::condition()->getBySearchParams($this->uuid, $params);

            $validateData = $response->validate();

            $paging = $response->getPaging();
            $this->totalEntries = $paging['total_entries'] ?? 0;
            $this->pageSize = $paging['page_size'] ?? 10;

            $this->conditions = Arr::toCamelCase($validateData);
        } catch(ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->conditions = [];

            $this->handleEHealthExceptions($exception, 'Error while loading conditions');
        }
    }

    private function loadConditionsFromDb(): void 
    {
        $conditions = Repository::condition()->getByPersonIdPaginated(
            $this->personId,
            $this->getPage(),
            $this->pageSize
        );

        $this->totalEntries = Repository::condition()->countByPersonId($this->personId);

        $this->conditions = Arr::toCamelCase($conditions);
    }

    private function buildSearchParams(): array 
    {
        return array_filter([
            'code' => $this->filterCode ?: null,
            'encounter_id' => $this->filterEncounterId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'onset_date_from' => $this->filterOnsetDateFrom ?: null,
            'onset_date_to' => $this->filterOnsetDateTo ?: null,
            'managing_organization_id' => legalEntity()?->uuid,
            'page' => $this->getPage(),
            'page_size' => $this->pageSize,
        ], static fn ($value) => $value !== '' && $value !== null);
    }

    private function buildPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $this->conditions,
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
            'filterOnsetDateFrom' => ['nullable', 'date_format:d.m.Y'],
            'filterOnsetDateTo' => ['nullable', 'date_format:d.m.Y'],
        ];
    }

    public function render()
    {
        return view('livewire.person.records.condition', [
            'paginatedConditions' => $this->buildPaginator(),
        ]);
    }
}
