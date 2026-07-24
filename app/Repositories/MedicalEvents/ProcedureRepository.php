<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Enums\Person\ProcedureStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property Procedure $model
 */
class ProcedureRepository extends BaseRepository
{
    public function __construct(Model $model)
    {
        parent::__construct($model);
    }

    /**
     * Store procedure in DB.
     *
     * @param  array  $data
     * @param  Person|Preperson  $patient
     * @return int
     * @throws Throwable
     */
    public function store(array $data, Person|Preperson $patient): int
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        return DB::transaction(function () use ($data, $ownerColumn, $ownerId) {
            foreach ($data as $datum) {
                $basedOn = null;
                if (isset($datum['basedOn'])) {
                    $basedOn = Repository::identifier()->store($datum['basedOn']['identifier']['value']);
                    Repository::codeableConcept()->attach($basedOn, $datum['basedOn']);
                }

                $code = Repository::identifier()->store($datum['code']['identifier']['value']);
                Repository::codeableConcept()->attach($code, $datum['code']);

                $encounter = null;
                if (isset($datum['encounter'])) {
                    $encounter = Repository::identifier()->store($datum['encounter']['identifier']['value']);
                    Repository::codeableConcept()->attach($encounter, $datum['encounter']);
                }

                $recordedBy = Repository::identifier()->store($datum['recordedBy']['identifier']['value']);
                Repository::codeableConcept()->attach($recordedBy, $datum['recordedBy']);

                $performer = null;
                if (isset($datum['performer'])) {
                    $performer = Repository::identifier()->store($datum['performer']['identifier']['value']);
                    Repository::codeableConcept()->attach($performer, $datum['performer']);
                }

                $division = null;
                if (isset($datum['division'])) {
                    $division = Repository::identifier()->store($datum['division']['identifier']['value']);
                    Repository::codeableConcept()->attach($division, $datum['division']);
                }

                $managingOrganization = Repository::identifier()
                    ->store($datum['managingOrganization']['identifier']['value']);
                Repository::codeableConcept()->attach($managingOrganization, $datum['managingOrganization']);

                $category = Repository::codeableConcept()->store($datum['category']);

                $procedure = $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    $ownerColumn => $ownerId,
                    'status' => $datum['status'],
                    'based_on_id' => $basedOn?->id,
                    'code_id' => $code->id,
                    'performed_date_time' => $datum['performedDateTime'] ?? null,
                    'encounter_id' => $encounter?->id,
                    'recorded_by_id' => $recordedBy->id,
                    'primary_source' => $datum['primarySource'],
                    'performer_id' => $performer?->id,
                    'report_origin_id' => isset($datum['reportOrigin'])
                        ? Repository::codeableConcept()->store($datum['reportOrigin'])->id
                        : null,
                    'division_id' => $division?->id,
                    'managing_organization_id' => $managingOrganization->id,
                    'outcome_id' => isset($datum['outcome'])
                        ? Repository::codeableConcept()->store($datum['outcome'])->id
                        : null,
                    'note' => $datum['note'] ?? null,
                    'category_id' => $category->id
                ]);

                if (isset($datum['performedPeriod'])) {
                    $procedure->performedPeriod()->create([
                        'start' => $datum['performedPeriod']['start'],
                        'end' => $datum['performedPeriod']['end'],
                    ]);
                }

                if (isset($datum['reasonReferences'])) {
                    foreach ($datum['reasonReferences'] as $reasonReference) {
                        $identifier = Repository::identifier()->store($reasonReference['identifier']['value']);
                        Repository::codeableConcept()->attach($identifier, $reasonReference);

                        $procedure->reasonReferences()->attach($identifier->id);
                    }
                }

                if (isset($datum['complicationDetails'])) {
                    foreach ($datum['complicationDetails'] as $complicationDetail) {
                        $identifier = Repository::identifier()->store(
                            $complicationDetail['identifier']['value']
                        );
                        Repository::codeableConcept()->attach($identifier, $complicationDetail);

                        $procedure->complicationDetails()->attach($identifier->id);
                    }
                }

                if (isset($datum['paperReferral'])) {
                    Repository::paperReferral()->store($datum['paperReferral'], $procedure);
                }

                if (!empty($datum['usedCodes'])) {
                    $usedCodeIds = [];
                    foreach ($datum['usedCodes'] as $usedCodeData) {
                        $usedCode = Repository::codeableConcept()->store($usedCodeData);

                        $usedCodeIds[] = $usedCode->id;
                    }

                    $procedure->usedCodes()->attach($usedCodeIds);
                }

                if (!empty($datum['usedReferences'])) {
                    $usedReferenceIds = [];

                    foreach ($datum['usedReferences'] as $usedReferenceData) {
                        $equipmentUuid = data_get($usedReferenceData, 'identifier.value');

                        if (!$equipmentUuid) {
                            continue;
                        }

                        $identifier = Repository::identifier()->store($equipmentUuid);
                        Repository::codeableConcept()->attach($identifier, $usedReferenceData);

                        $usedReferenceIds[] = $identifier->id;
                    }

                    $procedure->usedReferences()->attach($usedReferenceIds);
                }

                $procedureId = $procedure->id;
            }

            return $procedureId;
        });
    }

    /**
     * Get data that is related to the encounter.
     *
     * @param  string  $encounterUuid
     * @return array|null
     */
    public function get(string $encounterUuid): ?array
    {
        $results = $this->model::with([
            'basedOn.type.coding',
            'code.type.coding',
            'encounter.type.coding',
            'recordedBy.type.coding',
            'performer.type.coding',
            'reportOrigin.coding',
            'division.type.coding',
            'managingOrganization.type.coding',
            'reasonReferences.type.coding',
            'outcome.coding',
            'complicationDetails.type.coding',
            'category.coding',
            'paperReferral',
            'usedCodes.coding',
            'performedPeriod',
            'usedReferences.type.coding',
        ])
            ->whereHas('encounter', fn (Builder $query) => $query->where('value', $encounterUuid))
            ->get()
            ->toArray();

        // Hide array of relationship data, accessories are used
        return array_map(static fn (array $item) => Arr::except($item, ['performedPeriod']), $results);
    }

    /**
     * Get data that is related to the person.
     *
     * @param  int  $personId
     * @return array
     */
    public function getByPersonId(int $personId): array
    {
        return $this->model
            ->withAllRelations()
            ->where('person_id', $personId)
            ->get()
            ->toArray();
    }

    /**
     * Get procedure by id.
     *
     * @param  int  $procedureId
     * @return Procedure
     */
    public function findById(int $procedureId): Procedure
    {
        return $this->model
            ->withAllRelations()
            ->findOrFail($procedureId);
    }

    /**
     * Get procedure by eHealth uuid.
     *
     * @param  string  $uuid
     * @return Procedure
     */
    public function findByUuid(string $uuid): Procedure
    {
        return $this->model
            ->withAllRelations()
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function markAsEnteredInError(
        Procedure $procedure,
        array $statusReason,
        ?string $explanatoryLetter = null
    ): void {
        DB::transaction(static function () use ($procedure, $statusReason, $explanatoryLetter): void {
            $procedure->loadMissing(['statusReason.coding']);

            $statusReasonModel = $procedure->statusReason
                ? Repository::codeableConcept()->update($procedure->statusReason, $statusReason)
                : Repository::codeableConcept()->store($statusReason);

            $procedure->update([
                'status' => ProcedureStatus::ENTERED_IN_ERROR->value,
                'status_reason_id' => $statusReasonModel->id,
                'explanatory_letter' => $explanatoryLetter,
            ]);
        });
    }

    /**
     * Get paginated procedures related to the patient.
     *
     * @param  Person|Preperson  $patient
     * @param  int  $page
     * @param  int  $pageSize
     * @return LengthAwarePaginator
     */
    public function getPaginatedByPatient(Person|Preperson $patient, int $page, int $pageSize): LengthAwarePaginator
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        return $this->model
            ->withAllRelations()
            ->where($ownerColumn, $ownerId)
            ->latest()
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /**
     * Sync procedure data and related data by updating or creating.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(Person|Preperson $patient, array $validatedData): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($ownerColumn, $ownerId, $validatedData) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingProcedures = $this->model->whereIn('uuid', $apiUuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingProcedures->get($data['uuid']);

                $basedOn = $this->syncIdentifier($existing, $data['based_on'] ?? null, 'basedOn');
                $statusReason = $this->syncCodeableConcept($existing, $data['status_reason'] ?? null, 'statusReason');
                $code = $this->syncIdentifier($existing, $data['code'], 'code');
                $encounter = $this->syncIdentifier($existing, $data['encounter'] ?? null, 'encounter');
                $originEpisode = $this->syncIdentifier($existing, $data['origin_episode'] ?? null, 'originEpisode');
                $recordedBy = $this->syncIdentifier($existing, $data['recorded_by'], 'recordedBy');
                $performer = $this->syncIdentifier($existing, $data['performer'] ?? null, 'performer');
                $reportOrigin = $this->syncCodeableConcept($existing, $data['report_origin'] ?? null, 'reportOrigin');
                $division = $this->syncIdentifier($existing, $data['division'] ?? null, 'division');
                $managingOrganization = $this->syncIdentifier($existing, $data['managing_organization'], 'managingOrganization');
                $outcome = $this->syncCodeableConcept($existing, $data['outcome'] ?? null, 'outcome');
                $category = $this->syncCodeableConcept($existing, $data['category'], 'category');

                $procedureData = [
                    $ownerColumn => $ownerId,
                    'status' => $data['status'],
                    'status_reason_id' => $statusReason?->id,
                    'primary_source' => $data['primary_source'],
                    'note' => $data['note'] ?? null,
                    'explanatory_letter' => $data['explanatory_letter'] ?? null,
                    'based_on_id' => $basedOn?->id,
                    'code_id' => $code->id,
                    'performed_date_time' => $data['performed_date_time'] ?? null,
                    'encounter_id' => $encounter?->id,
                    'origin_episode_id' => $originEpisode?->id,
                    'recorded_by_id' => $recordedBy->id,
                    'performer_id' => $performer?->id,
                    'report_origin_id' => $reportOrigin?->id,
                    'division_id' => $division?->id,
                    'managing_organization_id' => $managingOrganization->id,
                    'outcome_id' => $outcome?->id,
                    'category_id' => $category->id
                ];

                if ($existing) {
                    $existing->update($procedureData);
                    $procedure = $existing;
                } else {
                    $procedure = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $procedureData)
                    );
                }

                if (isset($data['paper_referral'])) {
                    Repository::paperReferral()->sync($data['paper_referral'], $procedure, $existing);
                }

                Repository::period()->sync($procedure, $data['performed_period'] ?? [], 'performedPeriod');

                $this->syncPivot(
                    $procedure,
                    'reasonReferences',
                    $this->syncIdentifiers($existing, $data['reason_references'] ?? [], 'reasonReferences')
                );

                $this->syncPivot(
                    $procedure,
                    'complicationDetails',
                    $this->syncIdentifiers($existing, $data['complication_details'] ?? [], 'complicationDetails')
                );

                $this->syncPivot(
                    $procedure,
                    'usedReferences',
                    $this->syncIdentifiers($existing, $data['used_references'] ?? [], 'usedReferences')
                );

                $this->syncPivot(
                    $procedure,
                    'usedCodes',
                    $this->syncCodeableConcepts($existing, $data['used_codes'] ?? [], 'usedCodes')
                );
            }
        });
    }

    /**
     * Get the episode for the clinical impression based on the provided UUID to display the selected supporting info.
     *
     * @param  array  $uuids
     * @return array
     */
    public function getDetailsMapByUuids(array $uuids): array
    {
        return $this->model->whereIn('uuid', $uuids)
            ->with(['code', 'performedPeriod'])
            ->get()
            ->mapWithKeys(fn (Procedure $procedure) => [
                $procedure->uuid => [
                    'ehealthInsertedAt' => convertToAppDateFormat($procedure->performedDateTime ?? $procedure->performedPeriod?->start),
                    'codeCode' => $procedure->code?->value,
                    'type' => 'procedure',
                ],
            ])
            ->toArray();
    }
}
