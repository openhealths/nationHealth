<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Core\Arr;
use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Jobs\ObservationSync;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Observation;
use App\Repositories\MedicalEvents\Repository;
use App\Rules\InDictionary;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Throwable;

class PatientObservation extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

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
        'eHealth/vaccination_covid_groups'
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
            Repository::observation()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing observation');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('observation');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_OBSERVATION);
            Session::flash('success', __('patients.messages.observation_synced_successfully'));
        }

        $this->isSearching = false;
        $this->resetPage();
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
        $this->episodes = Episode::forPerson($this->personId)->recentlyUpdatedFirst()->get()->toArray();
        $this->encounters = Encounter::forPerson($this->personId)->recentlyUpdatedFirst()->get()->toArray();
        $reports = DiagnosticReport::forPerson($this->personId)
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
        // todo: devices, specimens
    }

    /**
     * Paginate locally stored (synced) observations straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalObservations(): LengthAwarePaginator
    {
        $paginator = Observation::forPerson($this->personId)
            ->withAllRelations()
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        $paginator->setCollection(collect(Arr::toCamelCase($paginator->getCollection()->toArray())));

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

        if ($directValue !== null) {
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

    public function render(): View
    {
        return view('livewire.person.records.observations');
    }
}
