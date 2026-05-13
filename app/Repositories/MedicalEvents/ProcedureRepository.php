<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\Api\PatientApi;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\MedicalEvents\Sql\ProcedureComplicationDetail;
use App\Models\MedicalEvents\Sql\ProcedureReasonReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * @property Procedure $model
 */
class ProcedureRepository extends BaseRepository
{
    protected string $employeeUuid;

    public function __construct(Model $model)
    {
        parent::__construct($model);

        $this->employeeUuid = Auth::user()?->getProcedureWriterEmployee()->uuid;
    }

    /**
     * Format data before request.
     *
     * @param  array  $procedure
     * @return array
     */
    public function formatEHealthRequest(array $procedure): array
    {
        if ($procedure['referralType'] === 'electronic' || $procedure['referralType'] === '') {
            unset($procedure['paperReferral']);
        }

        if ($procedure['referralType'] === 'paper' || $procedure['referralType'] === '') {
            unset($procedure['basedOn']);
        }

        // delete frontend properties
        unset($procedure['isReferralAvailable'], $procedure['referralType']);

        $procedure['id'] = Str::uuid()->toString();
        $procedure['status'] = 'completed';

        $procedure['recordedBy']['identifier']['value'] = $this->employeeUuid;

        $procedure['managingOrganization'] = [
            'identifier' => [
                'type' => [
                    'coding' => [['system' => 'eHealth/resources', 'code' => 'legal_entity']],
                    'text' => ''
                ],
                'value' => legalEntity()->uuid
            ],
        ];

        if (!empty($procedure['reasonReferences'])) {
            foreach ($procedure['reasonReferences'] as &$reasonReference) {
                $code = str_contains($reasonReference['code']['coding'][0]['system'], 'condition_codes')
                    ? 'condition'
                    : 'observation';

                $identifier = [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => $code]]
                    ],
                    'value' => $reasonReference['id']
                ];

                // Keep only the identifier key
                $reasonReference = ['identifier' => $identifier];
            }

            unset($reasonReference);
        }

        if ($procedure['outcome']['coding'][0]['code'] === '') {
            unset($procedure['outcome']);
        }

        if (!empty($procedure['usedCodes'])) {
            $procedure['usedCodes'] = collect($procedure['usedCodes'])
                ->map(fn (array $uc) => [
                    'coding' => [['system' => 'eHealth/assistive_products', 'code' => $uc['code']]]
                ])
                ->values()
                ->toArray();
        }

        $normalizedData = schemaService()
            ->setDataSchema($procedure, app(PatientApi::class))
            ->requestSchemaNormalize('schemaProcedurePackageRequest')
            ->camelCaseKeys()
            ->getNormalizedData();

        // schema service delete effectivePeriod, performer and reportOrigin, because of 'One Of', so manually add it
        if ($normalizedData['primarySource']) {
            $normalizedData['performer'] = $procedure['performer'];
            $normalizedData['performer']['identifier']['value'] = $this->employeeUuid;
        } else {
            $normalizedData['reportOrigin'] = $procedure['reportOrigin'];
        }

        $normalizedData['performedPeriod'] = [
            'start' => convertToISO8601(
                $procedure['performedPeriodStartDate'] . $procedure['performedPeriodStartTime']
            ),
            'end' => convertToISO8601(
                $procedure['performedPeriodEndDate'] . $procedure['performedPeriodEndTime']
            ),
        ];

        return $normalizedData;
    }

    /**
     * Store procedure in DB.
     *
     * @param  array  $data
     * @param  int  $personId
     * @return void
     * @throws Throwable
     */
    public function store(array $data, int $personId): void
    {
        DB::transaction(function () use ($data, $personId) {
            foreach ($data as $datum) {
                if (isset($datum['basedOn'])) {
                    $basedOn = Repository::identifier()->store($datum['basedOn']['identifier']['value']);
                    Repository::codeableConcept()->attach($basedOn, $datum['basedOn']);
                }

                $code = Repository::identifier()->store($datum['code']['identifier']['value']);
                Repository::codeableConcept()->attach($code, $datum['code']);

                if (isset($datum['encounter'])) {
                    $encounter = Repository::identifier()->store($datum['encounter']['identifier']['value']);
                    Repository::codeableConcept()->attach($encounter, $datum['encounter']);
                }

                $recordedBy = Repository::identifier()->store($datum['recordedBy']['identifier']['value']);
                Repository::codeableConcept()->attach($recordedBy, $datum['recordedBy']);

                if (isset($datum['performer'])) {
                    $performer = Repository::identifier()->store($datum['performer']['identifier']['value']);
                    Repository::codeableConcept()->attach($performer, $datum['performer']);
                }

                if (isset($datum['reportOrigin'])) {
                    $reportOrigin = Repository::codeableConcept()->store($datum['reportOrigin']);
                }

                if (isset($datum['division'])) {
                    $division = Repository::identifier()->store($datum['division']['identifier']['value']);
                    Repository::codeableConcept()->attach($division, $datum['division']);
                }

                $managingOrganization = Repository::identifier()
                    ->store($datum['managingOrganization']['identifier']['value']);
                Repository::codeableConcept()->attach($managingOrganization, $datum['managingOrganization']);

                if (isset($datum['outcome'])) {
                    $outcome = Repository::codeableConcept()->store($datum['outcome']);
                }

                $category = Repository::codeableConcept()->store($datum['category']);

                $procedure = $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    'person_id' => $personId,
                    'status' => $datum['status'],
                    'based_on_id' => $basedOn->id ?? null,
                    'code_id' => $code->id,
                    'encounter_id' => $encounter->id ?? null,
                    'recorded_by_id' => $recordedBy->id,
                    'primary_source' => $datum['primarySource'],
                    'performer_id' => $performer->id ?? null,
                    'report_origin_id' => $reportOrigin->id ?? null,
                    'division_id' => $division->id ?? null,
                    'managing_organization_id' => $managingOrganization->id,
                    'outcome_id' => $outcome->id ?? null,
                    'note' => $datum['note'] ?? null,
                    'category_id' => $category->id
                ]);

                $procedure->performedPeriod()->create([
                    'start' => $datum['performedPeriod']['start'],
                    'end' => $datum['performedPeriod']['end']
                ]);

                if (isset($datum['reasonReferences'])) {
                    foreach ($datum['reasonReferences'] as $reasonReference) {
                        $identifier = Repository::identifier()->store($reasonReference['identifier']['value']);
                        Repository::codeableConcept()->attach($identifier, $reasonReference);

                        ProcedureReasonReference::create([
                            'procedure_id' => $procedure->id,
                            'identifier_id' => $identifier->id ?? null
                        ]);
                    }
                }

                if (isset($datum['complicationDetails'])) {
                    foreach ($datum['complicationDetails'] as $complicationDetail) {
                        $identifier = Repository::identifier()->store(
                            $complicationDetail['identifier']['value']
                        );
                        Repository::codeableConcept()->attach($identifier, $complicationDetail);

                        ProcedureComplicationDetail::create([
                            'procedure_id' => $procedure->id,
                            'identifier_id' => $identifier->id ?? null
                        ]);
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
            }
        });
    }

    /**
     * Sync procedure data and related data by updating or creating.
     *
     * @param  int  $personId
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(int $personId, array $validatedData): void
    {
        DB::transaction(function () use ($personId, $validatedData) {
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
                    'person_id' => $personId,
                    'status' => $data['status'],
                    'status_reason_id' => $statusReason?->id,
                    'primary_source' => $data['primary_source'],
                    'note' => $data['note'] ?? null,
                    'explanatory_letter' => $data['explanatory_letter'] ?? null,
                    'based_on_id' => $basedOn?->id,
                    'code_id' => $code->id,
                    'encounter_id' => $encounter?->id,
                    'origin_episode_id' => $originEpisode?->id,
                    'recorded_by_id' => $recordedBy->id,
                    'performer_id' => $performer?->id,
                    'report_origin_id' => $reportOrigin?->id,
                    'division_id' => $division?->id,
                    'managing_organization_id' => $managingOrganization?->id,
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

                $reasonReferenceIds = $this->syncIdentifiers($existing, $data['reason_references'] ?? [], 'reasonReferences');
                $procedure->reasonReferences()->sync($reasonReferenceIds);

                $complicationDetailIds = $this->syncIdentifiers($existing, $data['complication_details'] ?? [], 'complicationDetails');
                $procedure->complicationDetails()->sync($complicationDetailIds);

                $usedReferenceIds = $this->syncIdentifiers($existing, $data['used_references'] ?? [], 'usedReferences');
                $procedure->usedReferences()->sync($usedReferenceIds);

                $usedCodeIds = $this->syncCodeableConcepts($existing, $data['used_codes'] ?? [], 'usedCodes');
                $procedure->usedCodes()->sync($usedCodeIds);
            }
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
            'performedPeriod'
        ])
            ->whereHas('encounter', fn ($query) => $query->where('value', $encounterUuid))
            ->get()
            ->toArray();

        // Hide array of relationship data, accessories are used
        return array_map(static fn (array $item) => Arr::except($item, ['performedPeriod']), $results);
    }

    /**
     * Get the episode for the clinical impression based on the provided UUID to display the selected supporting info.
     *
     * @param  string  $uuid
     * @return array|null
     */
    public function getForClinicalImpression(string $uuid): ?array
    {
        return Procedure::whereUuid($uuid)
            ->select(['id', 'code_id'])
            ->with('code.coding')
            ->first()
            ?->toArray();
    }
}
