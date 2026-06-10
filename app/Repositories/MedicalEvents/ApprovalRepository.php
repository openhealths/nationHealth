<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\Api\PatientApi;
use App\Enums\Status;
use App\Models\Approval;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * @property Approval $model
 */
class ApprovalRepository extends BaseRepository
{
    protected string $employeeUuid;

    public function __construct(Model $model)
    {
        parent::__construct($model);

        // $this->employeeUuid = Auth::user()?->getProcedureWriterEmployee()->uuid;
    }

    public function create(?int $personId, ?array $data = null): Approval
    {
        $data ??= [
                    'uuid' => null,
                    'approvable_id' => $personId,
                    'approvable_type' => Person::class,
                    'granted_to_id' => null,
                    'granted_by_id' => null,
                    'status' => Status::NEW->value,
                    'reason_id' => null,
                ];

        return Approval::create($data);
    }


    /**
     * Build a formatted eHealth API request payload for an approval.
     *
     * Iterates over the provided payload data and transforms each entry according
     * to its entity type. Identifier-based entities are wrapped via
     * {@see prepareIdentifierToRequest()}. Array entities (`resources`,
     * `resource_types`) are mapped element-by-element. `access_level` defaults
     * to `'read'` when not provided. `authorize_with` is omitted from the
     * returned payload when empty.
     *
     * Supported entity keys: `resources`, `resource_types`, `service_request`,
     * `forbidden_group`, `diagnoses_group`, `service_group`, `patient`,
     * `composition`, `child_resource`, `granted_to`, `created_by`, `person`,
     * `access_level`, `authorize_with`. Unknown keys are silently ignored.
     *
     * @param array<string, mixed> $payloadData Associative array of entity key → raw data.
     *
     * @return array<string, mixed> Formatted payload ready for the eHealth API.
     *
     * @see https://ehealthmedicaleventsapi.docs.apiary.io/#reference/approvals/create-approval/create-approval
     */
    public function formatApprovalEHealthRequest(array $payloadData): array
    {
        $payload = [];

        foreach ($payloadData as $entity => $entityData) {
             match ($entity) {
                'resources' => $payload[$entity] = array_map(fn (array $identifier) => $this->prepareIdentifierToRequest($identifier), $entityData),
                'resource_types' => $payload[$entity] =array_map(fn (array $codeableConcept) => $this->prepareCodeableConceptToRequest($codeableConcept)['type'], $entityData),
                'service_request', 'forbidden_group', 'diagnoses_group', 'service_group', 'patient', 'composition',
                'child_resource', 'granted_to', 'created_by', 'person' => $payload[$entity] = $this->prepareIdentifierToRequest($entityData),
                'access_level' => $payload[$entity] = $entityData ?: 'read',
                'authorize_with' => $payload[$entity] = $entityData ?: null,
                default => null,
            };
        }

        // If 'authorize_with' is present but empty, remove it from the payload
        // eHealth will use the default authentication method in this case, so we don't need to send an empty value.
        if (\array_key_exists('authorize_with', $payload) && empty($payload['authorize_with'])) {
            unset($payload['authorize_with']);
        }

        return $payload;
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
                    'person_id' => $personId,
                    'status' => $datum['status'],
                    'based_on_id' => $basedOn?->id,
                    'code_id' => $code->id,
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

                $procedure->performedPeriod()->create([
                    'start' => $datum['performedPeriod']['start'],
                    'end' => $datum['performedPeriod']['end']
                ]);

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
            ->whereHas('encounter', fn (Builder $query) => $query->where('value', $encounterUuid))
            ->get()
            ->toArray();

        // Hide array of relationship data, accessories are used
        return array_map(static fn (array $item) => Arr::except($item, ['performedPeriod']), $results);
    }

    /**
     * Wrap a raw identifier array into the eHealth FHIR identifier request structure.
     *
     * @param array{type: array, value: string} $identifier  Raw identifier with `type` (codeable concept) and `value` (UUID).
     *
     * @return array{identifier: array{type: array, value: string}}
     */
    protected function prepareIdentifierToRequest(array $identifier): array
    {
        return [
            'identifier' => [
                'type' => $this->prepareCodeableConceptToRequest($identifier['type']),
                'value' => $identifier['value']
            ]
        ];
    }

    /**
     * Format a codeable concept array into the eHealth API `type` structure.
     *
     * @param array{coding: array, text?: string} $codeableConceptData
     *
     * @return array{type: array{coding: array, text: string}}
     */
    protected function prepareCodeableConceptToRequest(array $codeableConceptData): array
    {
        return [
                'type' => [
                    'coding' => $this->prepareCodingToRequest($codeableConceptData['coding']),
                    'text' => $codeableConceptData['text'] ?? ''
                ],
        ];
    }

    /**
     * Normalize a coding array for the eHealth API.
     *
     * Falls back to `'eHealth/resources'` as the system when `code` is empty.
     *
     * @param array<int, array{system: string, code: string}> $codingData
     *
     * @return array<int, array{system: string, code: string}>
     */
    protected function prepareCodingToRequest(array $codingData): array
    {
        return array_map(static fn (array $coding) => [
                'system' => empty($coding['code']) ? 'eHealth/resources' : $coding['system'],
                'code' => $coding['code']
            ], $codingData);
    }
}
