<?php

declare(strict_types=1);

namespace App\Livewire\Observation;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\ObservationSync;
use App\Livewire\Encounter\Forms\EncounterCancellationForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Observation;
use App\Repositories\MedicalEvents\Repository;
use App\Rules\InDictionary;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesEncounterCancellation;
use App\Traits\HandlesSyncBatch;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class ObservationIndex extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesEncounterCancellation;
    use HandlesSyncBatch;
    use WithPagination;

    public EncounterCancellationForm $form;

    /**
     * Filter dropdown options the user can pick from to narrow the observations search.
     *
     * @var array
     */
    public array $episodes = [];

    public array $encounters = [];

    public array $diagnosticReports = [];

    public array $devices = [];

    public array $specimens = [];

    /**
     * Bound search filter values applied when querying observations.
     *
     * @var string
     */
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

    protected array $dictionaryNames = [
        'eHealth/cancellation_reasons',
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
        'eHealth/occupation_type'
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

        $this->dictionaries['eHealth/ICF/classifiers'] = dictionary()->basics()
            ->byName('eHealth/ICF/classifiers')
            ->flattenedChildValues()
            ->toArray();

        $this->loadFilterOptions();
    }

    #[Computed]
    public function paginatedObservations(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchObservationsFromEHealth()
            : $this->paginateLocalObservations();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->isSearching = true;
        $this->resetPage();
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
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing observation');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::observation()->sync($this->patient(), $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing observation');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('observation');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_OBSERVATION);
            Session::flash('success', __('observations.messages.synced_successfully'));
        }

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    /**
     * Open the page of an observation found through the eHealth search, storing it first when it is not in the database yet.
     *
     * @param  string  $observationId
     * @return void
     */
    public function view(string $observationId): void
    {
        $observation = Observation::forPatient($this->patient())->whereUuid($observationId)->first()
            ?? $this->storeSearchedObservation($observationId);

        if ($observation === null) {
            return;
        }

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.observations.view',
                [legalEntity(), 'preperson' => $this->prepersonId, 'observation' => $observation->id],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'persons.observations.view',
            [legalEntity(), 'person' => $this->personId, 'observation' => $observation->id],
            navigate: true
        );
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
            'isSearching'
        ]);

        $this->resetPage();
    }

    protected function loadFilterOptions(): void
    {
        $this->episodes = Repository::episode()->getByPersonId($this->patient());
        $this->encounters = Repository::encounter()->getByPersonId($this->patient());

        $reports = DiagnosticReport::forPatient($this->patient())
            ->final()
            ->with(['effectivePeriod', 'code.type.coding'])
            ->recentlyUpdatedFirst()
            ->get();

        // Load the services dictionary only if some report misses a name.
        $serviceNames = $reports->contains(static fn (DiagnosticReport $report): bool => !$report->code->displayValue)
            ? dictionary()->services()->flattened()->pluck('name', 'id')
            : collect();

        // Name from the record or from the dictionary.
        $this->diagnosticReports = $reports
            ->map(static fn (DiagnosticReport $report): array => [
                'uuid' => $report->uuid,
                'displayValue' => $report->code->displayValue ?? $serviceNames->get($report->code->value)
            ])
            ->toArray();

        $this->devices = Device::forPatient($this->patient())
            ->with('names')
            ->recentlyUpdatedFirst()
            ->get(['id', 'uuid'])
            ->map(static fn (Device $device): array => [
                'uuid' => $device->uuid,
                'name' => $device->names->first()?->value ?? $device->uuid
            ])
            ->toArray();
        // todo: specimens
    }

    /**
     * Store an observation found through the eHealth search, so that it has a page to open.
     *
     * @param  string  $observationId
     * @return Observation|null
     */
    protected function storeSearchedObservation(string $observationId): ?Observation
    {
        try {
            $response = EHealth::observation()->getById($this->uuid, $observationId);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading the observation');

            return null;
        }

        try {
            Repository::observation()->sync($this->patient(), [$response->validate()]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while storing the observation');

            return null;
        }

        return Observation::forPatient($this->patient())->whereUuid($observationId)->first();
    }

    /**
     * Paginate locally stored (synced) observations straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalObservations(): LengthAwarePaginator
    {
        $paginator = Observation::forPatient($this->patient())
            ->withAllRelations()
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        // The id is hidden on the model but the list links to the observation page by it
        $paginator->setCollection(
            collect(Arr::toCamelCase($paginator->getCollection()->makeVisible('id')->toArray()))
        );

        return $paginator;
    }

    /**
     * Fetch a single page of observations from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchObservationsFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        $params = array_filter([
            'code' => $this->filterCode ?: null,
            'encounter_id' => $this->filterEncounterId ?: null,
            'diagnostic_report_id' => $this->filterDiagnosticReportId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'issued_from' => $this->filterIssuedFrom ?: null,
            'issued_to' => $this->filterIssuedTo ?: null,
            'device_id' => $this->filterDeviceId ?: null,
            'managing_organization_id' => legalEntity()->uuid,
            'specimen_id' => $this->filterSpecimenId ?: null,
            'page' => $this->getPage(),
            'page_size' => config('pagination.per_page')
        ]);

        try {
            $response = EHealth::observation()->getBySearchParams($this->uuid, $params);
            $observations = Arr::toCamelCase($this->formatDatesForDisplay($response->validate()));
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading observations');
            $observations = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($observations), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    public function displayObservationValue(array $observation): string
    {
        $directValue = $this->resolveObservationValue($observation);

        if (filled($directValue)) {
            return $directValue;
        }

        $componentValues = collect(data_get($observation, 'components', []))
            ->map(function (array $component): ?string {
                $value = $this->resolveObservationValue($component);

                if ($value === null) {
                    return null;
                }

                $code = $this->displayCodeableConcept(data_get($component, 'code'));

                return $code ? $code . ': ' . $value : $value;
            })
            ->filter()
            ->values()
            ->toArray();

        return $componentValues ? implode('; ', $componentValues) : '-';
    }

    private function resolveObservationValue(array $item): ?string
    {
        $quantity = data_get($item, 'valueQuantity') ?? data_get($item, 'value.valueQuantity');

        if (is_array($quantity)) {
            return collect([
                data_get($quantity, 'value'),
                data_get($quantity, 'unit') ?: data_get($quantity, 'code'),
            ])->filter(static fn ($value) => $value !== null && $value !== '')->implode(' ') ?: null;
        }

        $codeableConcept = data_get($item, 'valueCodeableConcept') ?? data_get($item, 'value.valueCodeableConcept');

        if (is_array($codeableConcept)) {
            return $this->displayCodeableConcept($codeableConcept);
        }

        foreach (['valueString', 'value.valueString'] as $path) {
            $value = data_get($item, $path);

            if ($value !== null && $value !== '') {
                return (string)$value;
            }
        }

        $missing = '__missing_observation_value__';
        foreach (['valueBoolean', 'value.valueBoolean'] as $path) {
            $value = data_get($item, $path, $missing);

            if ($value !== $missing && $value !== null && $value !== '') {
                return (bool)$value ? 'Так' : 'Ні';
            }
        }

        foreach (['valueDateTime', 'value.valueDateTime', 'valueTime', 'value.valueTime'] as $path) {
            $value = data_get($item, $path);

            if ($value !== null && $value !== '') {
                return $this->formatObservationValueDate((string)$value);
            }
        }

        return null;
    }

    private function displayCodeableConcept(?array $codeableConcept): ?string
    {
        if (!$codeableConcept) {
            return null;
        }

        $code = data_get($codeableConcept, 'coding.0.code');
        $system = data_get($codeableConcept, 'coding.0.system');

        if (!$code) {
            return data_get($codeableConcept, 'text');
        }

        return data_get(
            $this->dictionaries,
            $system . '.' . $code,
            data_get($codeableConcept, 'text', $code)
        );
    }

    private function formatObservationValueDate(string $value): string
    {
        try {
            if (preg_match('/^\d{2}:\d{2}/', $value)) {
                return substr($value, 0, 5);
            }

            return CarbonImmutable::parse($value)->format(str_contains($value, ':') ? 'd.m.Y H:i' : 'd.m.Y');
        } catch (Throwable) {
            return $value;
        }
    }

    protected function filterValidationRules(): array
    {
        return [
            'filterCode' => [
                'nullable',
                'string',
                new InDictionary(
                    ['eHealth/LOINC/observation_codes', 'eHealth/custom/observation_codes', 'eHealth/ICF/classifiers']
                )
            ],
            'filterEncounterId' => ['nullable', 'uuid'],
            'filterDiagnosticReportId' => ['nullable', 'uuid'],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterIssuedFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterIssuedTo' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterDeviceId' => ['nullable', 'uuid'],
            'filterSpecimenId' => ['nullable', 'uuid']
        ];
    }

    /**
     * @inheritDoc
     */
    protected function encounterCancellationForm(): EncounterCancellationForm
    {
        return $this->form;
    }

    /**
     * @inheritDoc
     */
    protected function afterEncounterCancelled(): void
    {
        $this->isSearching = false;
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.observation.observations');
    }
}
