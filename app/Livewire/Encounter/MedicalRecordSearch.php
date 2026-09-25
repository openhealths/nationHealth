<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\eHealth\EHealth;
use App\Enums\MedicalEvents\RecordType;
use App\Enums\Person\ObservationStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\Icd10;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Rules\InDictionary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Search the patient's conditions or observations and hand the picked one over to the encounter package.
 */
class MedicalRecordSearch extends Component
{
    /**
     * Patient UUID for API requests. Null for a preperson that is not yet registered in eHealth.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $patientUuid = null;

    /**
     * Browser event the picked record is dispatched with.
     *
     * @var string
     */
    #[Locked]
    public string $selectionEvent = '';

    /**
     * Name of the Alpine function of the including view that tells whether a record is already added.
     *
     * @var string
     */
    #[Locked]
    public string $isAddedCheck = '';

    /**
     * Active episodes of the patient offered as a filter.
     *
     * @var array
     */
    #[Locked]
    public array $episodes = [];

    /**
     * Record type the search is limited to, leaving the type out of the filters. Empty lets the user choose.
     *
     * @var string
     */
    #[Locked]
    public string $fixedRecordType = '';

    /**
     * Whether the conditions are searched by code and onset date as well, on demand rather than on every change.
     *
     * @var bool
     */
    #[Locked]
    public bool $withConditionFilters = false;

    /**
     * Type of the records to search for, a condition or an observation.
     *
     * @var string
     */
    public string $recordType = '';

    /**
     * Episode UUID to search the records by.
     *
     * @var string
     */
    public string $filterEpisodeId = '';

    /**
     * Condition code to search the records by.
     *
     * @var string
     */
    public string $filterCode = '';

    /**
     * Start of the onset date range to search the records by.
     *
     * @var string
     */
    public string $filterOnsetDateFrom = '';

    /**
     * End of the onset date range to search the records by.
     *
     * @var string
     */
    public string $filterOnsetDateTo = '';

    /**
     * Whether a search has run, so that an empty list reads as nothing found rather than nothing asked for.
     *
     * @var bool
     */
    public bool $hasSearched = false;

    /**
     * Found records.
     *
     * @var array
     */
    public array $records = [];

    /**
     * ICD-10 AM descriptions of the found condition codes, keyed by code.
     *
     * @var array
     */
    public array $icd10Descriptions = [];

    /**
     * @param  string|null  $patientUuid
     * @param  string  $selectionEvent
     * @param  string  $isAddedCheck
     * @param  array  $episodes
     * @param  string  $fixedRecordType
     * @param  bool  $withConditionFilters
     * @return void
     */
    public function mount(
        ?string $patientUuid,
        string $selectionEvent,
        string $isAddedCheck,
        array $episodes = [],
        string $fixedRecordType = '',
        bool $withConditionFilters = false
    ): void {
        $this->patientUuid = $patientUuid;
        $this->selectionEvent = $selectionEvent;
        $this->isAddedCheck = $isAddedCheck;
        $this->episodes = $episodes;
        $this->fixedRecordType = $fixedRecordType;
        $this->recordType = $fixedRecordType;
        $this->withConditionFilters = $withConditionFilters;
    }

    /**
     * Search again once the record type changes.
     *
     * @return void
     */
    public function updatedRecordType(): void
    {
        $this->search();
    }

    /**
     * Search again once the episode filter changes, unless the search runs on demand.
     *
     * @return void
     */
    public function updatedFilterEpisodeId(): void
    {
        if ($this->withConditionFilters) {
            return;
        }

        $this->search();
    }

    /**
     * Clear the filters of the on demand search, leaving the found records as they are.
     *
     * @return void
     */
    public function resetFilters(): void
    {
        $this->reset(['filterCode', 'filterEpisodeId', 'filterOnsetDateFrom', 'filterOnsetDateTo']);
    }

    /**
     * Search the patient's records of the chosen type managed by the current legal entity.
     *
     * @return void
     */
    public function search(): void
    {
        $this->validate([
            'recordType' => [
                'nullable',
                Rule::in([RecordType::CONDITION->value, RecordType::OBSERVATION->value])
            ],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterCode' => [
                'nullable',
                'string',
                new InDictionary(['eHealth/ICPC2/condition_codes', 'eHealth/ICD10_AM/condition_codes'])
            ],
            'filterOnsetDateFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterOnsetDateTo' => ['nullable', 'date_format:' . config('app.date_format')]
        ]);

        $this->records = [];
        $this->hasSearched = true;

        $recordType = RecordType::tryFrom($this->fixedRecordType ?: $this->recordType);

        if ($recordType === null || $this->patientUuid === null) {
            return;
        }

        $params = array_filter([
            'managing_organization_id' => legalEntity()->uuid,
            'episode_id' => $this->filterEpisodeId ?: null,
            ...($this->withConditionFilters ? [
                'code' => $this->filterCode ?: null,
                'onset_date_from' => $this->filterOnsetDateFrom ?: null,
                'onset_date_to' => $this->filterOnsetDateTo ?: null
            ] : [])
        ]);

        try {
            $records = $recordType === RecordType::CONDITION
                ? EHealth::condition()->getBySearchParams($this->patientUuid, $params)->validate()
                : collect(EHealth::observation()->getBySearchParams($this->patientUuid, $params)->validate())
                    ->reject(static fn (array $observation): bool => data_get($observation, 'status') === ObservationStatus::ENTERED_IN_ERROR->value)
                    ->all();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while searching for medical records');

            return;
        }

        $episodeNames = $this->withConditionFilters ? $this->episodeNamesByEncounter($records) : collect();

        $this->records = collect($records)
            ->map(static fn (array $record): array => [
                'id' => data_get($record, 'uuid'),
                'ehealthInsertedAt' => convertToAppDateFormat(data_get($record, 'ehealth_inserted_at')),
                'onsetDate' => convertToAppDateFormat(data_get($record, 'onset_date')),
                'codeCode' => data_get($record, 'code.coding.0.code'),
                'codeSystem' => data_get($record, 'code.coding.0.system'),
                'clinicalStatus' => data_get($record, 'clinical_status'),
                'verificationStatus' => data_get($record, 'verification_status'),
                'episodeName' => $episodeNames->get(data_get($record, 'context.identifier.value'), ''),
                'type' => $recordType->value
            ])
            ->values()
            ->all();

        $this->icd10Descriptions = Icd10::whereIn(
            'code',
            collect($this->records)
                ->where('codeSystem', 'eHealth/ICD10_AM/condition_codes')
                ->pluck('codeCode')
                ->filter()
                ->unique()
        )
            ->pluck('description', 'code')
            ->all();
    }

    /**
     * Names of the episodes the records were registered in, keyed by the encounter UUID.
     * A record names only its encounter, so the episode is resolved through the encounters stored locally.
     *
     * @param  array  $records
     * @return Collection
     */
    private function episodeNamesByEncounter(array $records): Collection
    {
        $episodeNames = collect($this->episodes)->pluck('name', 'uuid');

        return Encounter::whereIn('uuid', collect($records)->pluck('context.identifier.value')->filter()->unique())
            ->with('episode')
            ->get(['uuid', 'episode_id'])
            ->mapWithKeys(static fn (Encounter $encounter): array => [
                $encounter->uuid => $episodeNames->get($encounter->episode?->value, '')
            ]);
    }

    /**
     * Hand the picked record over to the including view, along with its ICD-10 AM description,
     * since the including view does not load the descriptions of the codes found here.
     *
     * @param  string  $recordId
     * @return void
     */
    public function select(string $recordId): void
    {
        $record = collect($this->records)->firstWhere('id', $recordId);

        if ($record === null) {
            return;
        }

        $this->dispatch(
            $this->selectionEvent,
            record: [...$record, 'description' => $this->icd10Descriptions[$record['codeCode']] ?? '']
        );
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('livewire.encounter.medical-record-search');
    }
}
