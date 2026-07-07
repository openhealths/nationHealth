<?php
    
declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\Person\ProcedureStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\ProcedureSync;
use App\Models\Equipment;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\WithPagination;
use Throwable;

class PatientProcedures extends BasePatientComponent 
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    public bool $showAdditionalParams = false;

    public bool $fromDb = true;

    public array $procedures = [];

    public array $filterEpisodeOptions = [];

    public array $filterUsedReferenceOptions = [];

    public array $filterBasedOnOptions = [];

    public array $filterCodeOptions = [];

    public array $filterEncounterOptions = [];

    public array $filterOriginEpisodeOptions = [];

    public array $filterDeviceOptions = [];

    public string $filterCategory = '';

    public string $filterStatus = '';

    public string $filterEpisodeId = '';

    public string $filterUsedReferenceId = '';

    public string $filterBasedOn = '';

    public string $filterCode = '';

    public string $filterEncounterId = '';

    public string $filterOriginEpisodeId = '';

    public string $filterDeviceId = '';

    public int $totalEntries = 0;

    public string $syncStatus = '';

    public int $pageSize = 10;

    protected array $dictionaryNames = [
        'eHealth/procedure_categories',
        'eHealth/procedure_outcomes',
        'eHealth/report_origins',
        'eHealth/assistive_products',
    ];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return ProcedureSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return ProcedureSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_PROCEDURE;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
        $this->syncStatus = $status->value;
    }

    public function initializeComponent(): void 
    {
        $this->getDictionary();

        $this->dictionaries['custom/services'] = dictionary()->services()->flattened()->toArray();

        $this->getFilters();

        $this->getProceduresFromDb();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->fromDb = false;

        $this->resetPage();

        $this->getProcedures($this->buildSearchParams());
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterCategory',
            'filterStatus',
            'filterEpisodeId',
            'filterUsedReferenceId',
            'filterBasedOn',
            'filterCode',
            'filterEncounterId',
            'filterOriginEpisodeId',
            'filterDeviceId',
        ]);

        $this->resetPage();

        $this->fromDb = false;

        $this->getProcedures($this->buildSearchParams());
    }

    public function sync(): void 
    {
        if ($this->cannotStartSync('procedure')) {
            return;
        }

        $this->fromDb = false;

        if ($this->shouldResumeSync('procedure')) {
            $this->handleResumeLogic('procedure');

            return;
        }

        try {
            $response = EHealth::procedure()->getBySearchParams(
                $this->uuid,
                $this->buildSearchParams(),
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing procedure');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::procedure()->sync($this->patient(), $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing procedure');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('procedure');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_PROCEDURE);
            Session::flash('success', __('patients.messages.procedure_synced_successfully'));
        }

        $this->getProcedures($this->buildSearchParams());

        $this->getFilters();
    }

    public function getProcedures(array $params = []): void
    {
        try {
            $response = EHealth::procedure()->getBySearchParams($this->uuid, $params);

            $validatedData = $response->validate();

            $paging = $response->getPaging();
            $this->totalEntries = $paging['total_entries'] ?? 0;
            $this->pageSize = $paging['page_size'] ?? 10;

            $this->procedures = Arr::toCamelCase($validatedData);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->procedures = [];

            $exception->handle('Error while loading procedures');
        }
    }

    public function getProceduresFromDb(): void
    {
        $paginator = Repository::procedure()->getPaginatedByPatient(
            $this->patient(),
            $this->getPage(),
            $this->pageSize
        );

        $this->totalEntries = $paginator->total();
        $this->pageSize = $paginator->perPage();

        $this->procedures = $this->formatDatesForDisplay(
            $paginator
                ->getCollection()
                ->map(function (Procedure $procedure) {
                    $data = Arr::toCamelCase($procedure->toArray());
                    $data['id'] = $procedure->id;

                    return $data;
                })
                ->toArray()
        );
    }

    public function updatedPage(): void
    {
        if ($this->fromDb) {
            $this->getProceduresFromDb();

            return;
        }

        $this->getProcedures($this->buildSearchParams());
    }

    private function getFilters(): void
    {
        $this->getServices();

        $this->getEpisodesFromDb();

        $this->getEncountersFromDb();

        $this->getUsedReferencesFromDb();

        $this->getBasedOnFromDb();

        $this->getDevicesFromDb();
    }

    private function getEpisodes(): void
    {
        try {
            $response = EHealth::episode()->getBySearchParams(
                $this->uuid,
                [
                    'managing_organization_id' => legalEntity()?->uuid,
                    'page_size' => 100,
                ]
            );

            $validatedData = $response->validate();

            $options = collect($validatedData)
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

            $this->filterEpisodeOptions = $options;
            $this->filterOriginEpisodeOptions = $options;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->filterEpisodeOptions = [];
            $this->filterOriginEpisodeOptions = [];

            $exception->handle('Error while loading episodes');
        }
    }

    private function getEpisodesFromDb(): void
    {
        $options = Episode::forPatient($this->patient())
            ->get()
            ->map(function (Episode $episode) {
                if (!$episode->uuid) {
                    return null;
                }

                return [
                    'value' => $episode->uuid,
                    'label' => $episode->name ?: $episode->uuid,
                    'description' => $episode->uuid,
                ];
            })
            ->filter()
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->toArray();

        $this->filterEpisodeOptions = $options;
        $this->filterOriginEpisodeOptions = $options;
    }

    private function getEncounters(): void
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
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->filterEncounterOptions = [];

            $exception->handle('Error while loading encounters');
        }
    }

    private function getEncountersFromDb(): void
    {
        $this->filterEncounterOptions = Arr::toCamelCase(
            $this->formatDatesForDisplay(
                Repository::encounter()->getByPersonId($this->patient())
            )
        );
    }

    private function getServices(): void
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

    private function getUsedReferencesFromDb(): void
    {
        $usedReferences = Procedure::with('usedReferences')
            ->forPatient($this->patient())
            ->get()
            ->flatMap(fn (Procedure $procedure) => $procedure->usedReferences);

        $equipmentNames = Equipment::with('names')
            ->whereIn('uuid', $usedReferences->pluck('value'))
            ->get()
            ->pluck('names.0.name', 'uuid');

        $this->filterUsedReferenceOptions = $usedReferences
            ->filter(fn (?Identifier $identifier) => $identifier?->value)
            ->map(fn (Identifier $identifier) => [
                'value' => $identifier->value,
                'label' => ($equipmentNames[$identifier->value] ?? $identifier->display_value ?? '-') . ' | ' . $identifier->value,
                'description' => $identifier->value,
            ])
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->toArray();
    }

    private function getBasedOnFromDb(): void
    {
        $procedures = Procedure::with('basedOn.type.coding')
            ->forPatient($this->patient())
            ->get();

        $this->filterBasedOnOptions = $this->getIdentifierOptions(
            $procedures->pluck('basedOn')->filter()
        );
    }

    private function getDevicesFromDb(): void
    {
        //TODO implement device_id filter options
    }

    private function getIdentifierOptions(iterable $identifiers): array
    {
        return collect($identifiers)
            ->filter(fn (?Identifier $identifier) => $identifier?->value)
            ->map(function (Identifier $identifier) {
                return [
                    'value' => $identifier->value,
                    'label' => $identifier->display_value ?: $identifier->value,
                    'description' => $identifier->value,
                ];
            })
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->toArray();
    }

    private function buildSearchParams(): array
    {
        return array_filter([
            'episode_id' => $this->filterEpisodeId ?: null,
            'status' => $this->filterStatus ?: null,
            'used_reference_id' => $this->filterUsedReferenceId ?: null,
            'based_on' => $this->filterBasedOn ?: null,
            'code' => $this->filterCode ?: null,
            'managing_organization_id' => legalEntity()?->uuid,
            'encounter_id' => $this->filterEncounterId ?: null,
            'origin_episode_id' => $this->filterOriginEpisodeId ?: null,
            'device_id' => $this->filterDeviceId ?: null,
            'page' => $this->getPage(),
            'page_size' => $this->pageSize,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function buildPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $this->procedures,
            $this->totalEntries,
            $this->pageSize,
            $this->getPage(),
            ['path' => request()->url()]
        );
    }

    protected function filterValidationRules(): array 
    {
        return [
            'filterCategory' => ['nullable', 'string', 'max:255'],
            'filterStatus' => ['nullable', Rule::in(ProcedureStatus::values())],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterUsedReferenceId' => ['nullable', 'uuid'],
            'filterBasedOn' => ['nullable', 'uuid'],
            'filterCode' => ['nullable', 'uuid'],
            'filterEncounterId' => ['nullable', 'uuid'],
            'filterOriginEpisodeId' => ['nullable', 'uuid'],
            'filterDeviceId' => ['nullable', 'uuid'],
        ];
    }

    public function render(): View 
    {
        return view('livewire.person.records.procedures', [
            'paginatedProcedures' => $this->buildPaginator(),
        ]);
    }
}