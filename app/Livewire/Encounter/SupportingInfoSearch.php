<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\eHealth\EHealth;
use App\Enums\MedicalEvents\RecordType;
use App\Enums\Person\ObservationStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\Icd10;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Search the patient's records the encounter package can refer to as supporting info
 * and hand the picked one over to the including view.
 */
class SupportingInfoSearch extends Component
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
     * Record types offered for the search, in the order they are listed.
     *
     * @var array
     */
    #[Locked]
    public array $recordTypes = [];

    /**
     * Active episodes of the patient, offered both as records and as a filter.
     *
     * @var array
     */
    #[Locked]
    public array $episodes = [];

    /**
     * Whether the records can be narrowed to an episode.
     *
     * @var bool
     */
    #[Locked]
    public bool $withEpisodeFilter = false;

    /**
     * Type of the records to search for.
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
     * @param  array  $recordTypes
     * @param  array  $episodes
     * @param  bool  $withEpisodeFilter
     * @return void
     */
    public function mount(
        ?string $patientUuid,
        string $selectionEvent,
        string $isAddedCheck,
        array $recordTypes,
        array $episodes = [],
        bool $withEpisodeFilter = false
    ): void {
        $this->patientUuid = $patientUuid;
        $this->selectionEvent = $selectionEvent;
        $this->isAddedCheck = $isAddedCheck;
        $this->recordTypes = $recordTypes;
        $this->episodes = $episodes;
        $this->withEpisodeFilter = $withEpisodeFilter;
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
     * Search again once the episode filter changes.
     *
     * @return void
     */
    public function updatedFilterEpisodeId(): void
    {
        $this->search();
    }

    /**
     * Search the patient's records of the chosen type managed by the current legal entity.
     *
     * @return void
     */
    public function search(): void
    {
        $this->validate([
            'recordType' => ['nullable', Rule::in($this->recordTypes)],
            'filterEpisodeId' => ['nullable', 'uuid']
        ]);

        $this->records = [];

        $recordType = RecordType::tryFrom($this->recordType);

        if ($recordType === null) {
            return;
        }

        $episodeId = $this->withEpisodeFilter ? ($this->filterEpisodeId ?: null) : null;

        if ($recordType === RecordType::EPISODE) {
            $this->records = $this->episodeRecords($episodeId);

            return;
        }

        if ($this->patientUuid === null) {
            return;
        }

        // Diagnostic reports name the episode filter after the encounter context they were registered in
        $params = array_filter([
            'managing_organization_id' => legalEntity()->uuid,
            $recordType === RecordType::DIAGNOSTIC_REPORT ? 'context_episode_id' : 'episode_id' => $episodeId
        ]);

        try {
            $this->records = match ($recordType) {
                RecordType::ENCOUNTER => $this->encounterRecords($params),
                RecordType::PROCEDURE => $this->procedureRecords($params),
                RecordType::DIAGNOSTIC_REPORT => $this->diagnosticReportRecords($params),
                RecordType::CONDITION => $this->conditionRecords($params),
                RecordType::OBSERVATION => $this->observationRecords($params)
            };
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle("Error while searching for {$recordType->value} as supporting info");

            return;
        }

        $this->icd10Descriptions = $recordType === RecordType::CONDITION
            ? Icd10::whereIn('code', collect($this->records)->pluck('code')->filter()->unique())
                ->pluck('description', 'code')
                ->all()
            : [];
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
        $record = collect($this->records)->firstWhere('uuid', $recordId);

        if ($record === null) {
            return;
        }

        $this->dispatch(
            $this->selectionEvent,
            record: [...$record, 'description' => $this->icd10Descriptions[$record['code']] ?? '']
        );
    }

    /**
     * Active episodes of the patient, narrowed to the given episode.
     *
     * @param  string|null  $episodeId
     * @return array
     */
    private function episodeRecords(?string $episodeId): array
    {
        return collect($this->episodes)
            ->when($episodeId, static fn (Collection $episodes): Collection => $episodes->where('uuid', $episodeId))
            ->map(static fn (array $episode): array => [
                'uuid' => data_get($episode, 'uuid'),
                'ehealthInsertedAt' => convertToAppDateFormat(data_get($episode, 'ehealthInsertedAt')),
                'code' => data_get($episode, 'name'),
                'type' => 'episode'
            ])
            ->values()
            ->all();
    }

    /**
     * Encounters of the patient, named by their primary diagnosis.
     *
     * @param  array  $params
     * @return array
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    private function encounterRecords(array $params): array
    {
        return collect(EHealth::encounter()->getBySearchParams($this->patientUuid, $params)->validate())
            ->map(static function (array $encounter): array {
                $primaryDiagnosis = collect(data_get($encounter, 'diagnoses', []))
                    ->first(static fn (array $diagnosis): bool => data_get($diagnosis, 'role.coding.0.code') === 'primary');

                return [
                    'uuid' => data_get($encounter, 'uuid'),
                    'ehealthInsertedAt' => convertToAppDateFormat(data_get($encounter, 'ehealth_inserted_at')),
                    'code' => data_get($primaryDiagnosis, 'code.coding.0.code'),
                    'type' => 'encounter'
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Procedures of the patient.
     *
     * @param  array  $params
     * @return array
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    private function procedureRecords(array $params): array
    {
        return collect(EHealth::procedure()->getBySearchParams($this->patientUuid, $params)->validate())
            ->map(static fn (array $procedure): array => [
                'uuid' => data_get($procedure, 'uuid'),
                'ehealthInsertedAt' => convertToAppDateFormat(data_get($procedure, 'ehealth_inserted_at')),
                'code' => data_get($procedure, 'code.identifier.value'),
                'type' => 'procedure'
            ])
            ->values()
            ->all();
    }

    /**
     * Diagnostic reports of the patient.
     *
     * @param  array  $params
     * @return array
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    private function diagnosticReportRecords(array $params): array
    {
        return collect(EHealth::diagnosticReport()->getBySearchParams($this->patientUuid, $params)->validate())
            ->map(static fn (array $report): array => [
                'uuid' => data_get($report, 'uuid'),
                'ehealthInsertedAt' => convertToAppDateFormat(data_get($report, 'ehealth_inserted_at')),
                'code' => data_get($report, 'code.identifier.value'),
                'type' => 'diagnostic_report'
            ])
            ->values()
            ->all();
    }

    /**
     * Conditions of the patient.
     *
     * @param  array  $params
     * @return array
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    private function conditionRecords(array $params): array
    {
        return collect(EHealth::condition()->getBySearchParams($this->patientUuid, $params)->validate())
            ->map(static fn (array $condition): array => [
                'uuid' => data_get($condition, 'uuid'),
                'ehealthInsertedAt' => convertToAppDateFormat(data_get($condition, 'ehealth_inserted_at')),
                'code' => data_get($condition, 'code.coding.0.code'),
                'type' => 'condition'
            ])
            ->values()
            ->all();
    }

    /**
     * Observations of the patient, except those entered in error.
     *
     * @param  array  $params
     * @return array
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    private function observationRecords(array $params): array
    {
        return collect(EHealth::observation()->getBySearchParams($this->patientUuid, $params)->validate())
            ->reject(static fn (array $observation): bool => data_get($observation, 'status') === ObservationStatus::ENTERED_IN_ERROR->value)
            ->map(static fn (array $observation): array => [
                'uuid' => data_get($observation, 'uuid'),
                'ehealthInsertedAt' => convertToAppDateFormat(data_get($observation, 'ehealth_inserted_at')),
                'code' => data_get($observation, 'code.coding.0.code'),
                'type' => 'observation'
            ])
            ->values()
            ->all();
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('livewire.encounter.supporting-info-search');
    }
}
