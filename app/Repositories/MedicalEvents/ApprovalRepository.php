<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use Throwable;
use Carbon\Carbon;
use App\Enums\Status;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Relations\AuthenticationMethod;

/**
 * @property Approval $model
 */
class ApprovalRepository extends BaseRepository
{
    protected string $employeeUuid;

    public function __construct(Model $model)
    {
        parent::__construct($model);
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
    public function formatEHealthRequest(array $payloadData): array
    {
        $payload = [];

        // If 'authorize_with' is present but empty, remove it from the payload
        // eHealth will use the default authentication method in this case, so we don't need to send an empty value.
        if (\array_key_exists('authorize_with', $payloadData) && empty($payloadData['authorize_with'])) {
            unset($payloadData['authorize_with']);
        }

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

        if (empty($payloadData['access_level'])) {
            $payload['access_level'] = 'read';
        }

        return $payload;
    }

    /**
     * Create approval model and store its data and relations data to DB.
     *
     * @param array $data
     * @param Model $approvableModel
     *
     * @return Approval
     *
     * @throws Throwable
     */
    public function create(array $data, Model $approvableModel): ?Approval
    {
        $approval = DB::transaction(function () use ($data, $approvableModel) {
            $modelType = get_class($approvableModel);
            $modelId = $approvableModel->id;

            $grantedTo = null;
            $grantedToType = null;
            if (isset($data['granted_to'])) {
                $grantedTo = $this->resolveIdentifier(Arr::get($data, 'granted_to.identifier.value'));
                $grantedToType = Arr::get($data, 'granted_to.identifier.type.coding.0.code', null);

                Repository::codeableConcept()->attach($grantedTo, $data['granted_to']);
            }

            $createdBy = null;
            if (isset($data['created_by'])) {
                $createdBy = $this->resolveIdentifier(Arr::get($data, 'created_by.identifier.value'));

                Repository::codeableConcept()->attach($createdBy, $data['created_by']);
            }

            $reason = null;
            if (isset($data['reason'])) {
                $reason = $this->resolveIdentifier(Arr::get($data, 'reason.identifier.value'));

                Repository::codeableConcept()->attach($reason, $data['reason']);
            }

            $authMethod = AuthenticationMethod::getByModelAndUuid($approvableModel)->first();

            $approval = $this->model->create([
                'uuid' => $data['uuid'] ?? ($data['id'] ?? null),
                'approvable_id' => $modelId,
                'approvable_type' => $modelType,
                'created_by_id' => $createdBy->id,
                'granted_to_id' => $grantedTo->id,
                'granted_to_type' => $grantedToType,
                'granted_by_id' => null,
                'authorize_with' => $data['authorize_with'] ?? null,
                'authentication_method_id' => $authMethod?->id,
                'reason_id' => $reason?->id,
                'status' => $data['status'] ?? Status::NEW->value,
                'access_level' => $data['access_level'] ?? 'read',
                'is_verified' => $data['is_verified'] ?? false,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            if (isset($data['granted_resources'])) {
                foreach($data['granted_resources'] as $grantedResourceData) {
                    $identifier = $this->resolveIdentifier(Arr::get($grantedResourceData, 'identifier.value'));

                    Repository::codeableConcept()->attach($identifier, $grantedResourceData);

                    $approval->grantedResources()->create(['granted_to_id' => $identifier->id]);
                }
            }

            if (isset($data['granted_resource_types'])) {
                foreach($data['granted_resource_types'] as $grantedResourceTypeData) {
                    $grantedResourceType = Repository::coding()->store(Arr::get($grantedResourceTypeData, 'coding'));

                    $approval->grantedResourceTypes()->create(['codeable_concept_id' => $grantedResourceType->id]);
                }
            }

            return $approval;
        });

        return $approval;
    }

    /**
     * Sync approval data and related data by updating or creating.
     *
     * @param Model $approvalModel
     * @param array $modelData
     *
     * @return void
     *
     * @throws Throwable
     */
    public function sync(array $modelData, Model $approvableModel, ?Approval $approvalModel = null): void
    {
        DB::transaction(function () use ($approvalModel, $modelData, $approvableModel) {
            $approvalModelUuid = $modelData['uuid'] ?? ($modelData['id'] ?? null);

            $existing = $approvalModel::query()
                ->where('id', $approvalModel?->id)
                ->withAllRelations()
                ->first();

            $createdBy = $this->syncIdentifier($existing, $modelData['created_by'] ?? null, 'createdBy');

            $grantedTo = $this->syncIdentifier($existing, $modelData['granted_to'] ?? null, 'grantedTo');

            $reason = $this->syncIdentifier($existing, $modelData['reason'] ?? null, 'reason');

            if ($grantedTo) {
                $grantedToType = $grantedTo?->type->first()?->coding->first()?->code ?? null;
            }

            $authMethod = AuthenticationMethod::getByModelAndUuid($approvableModel)->first();

            $approvalData = [
                'uuid' => $approvalModelUuid,
                'approvable_id' => $approvableModel->id,
                'approvable_type' => get_class($approvableModel),
                'created_by_id' => $createdBy->id,
                'granted_to_id' => $grantedTo->id,
                'granted_to_type' => $grantedToType,
                'granted_by_id' => null,
                'authorize_with' => $modelData['authorize_with'] ?? null,
                'authentication_method_id' => $authMethod?->id,
                'reason_id' => $reason?->id,
                'status' => Status::APPROVED->value,
                'access_level' => $modelData['access_level'] ?? 'read',
                'is_verified' => $modelData['is_verified'] ?? false,
                'expires_at' => Carbon::parse($modelData['expires_at'])
            ];

            if ($existing) {
                $existing->update($approvalData);
                $approval = $existing;
            } else {
                $approval = $this->model->create($approvalData);
            }

            if (isset($modelData['granted_resources'])) {
                $this->syncResourceEntity(
                    $approval,
                    'grantedResources',
                    'granted_to_id',
                    $this->syncIdentifiers($existing, $modelData['granted_resources'] ?? [], 'grantedResources')
                );
            }

            if (isset($modelData['granted_resource_types'])) {
                $this->syncResourceEntity(
                    $approval,
                    'grantedResourceTypes',
                    'codeable_concept_id',
                    $this->syncCodeableConcepts($existing, $modelData['granted_resource_types'] ?? [], 'grantedResourceTypes')
                );
            }
        });
    }

    /**
     * Get data that is related to the person.
     *
     * @param  string      $entityUuid  UUID of the entity ('person', 'encounter', 'procedure' etc) (optional)
     * @param  Model|null  $approvableModel  Specific polymorphic parent model instance.
     *
     * @return array
     */
    public function get(Model $approvableModel, ?string $entityUuid = null): array
    {
        $query = $this->model::withAllRelations();

        $approvableModelId = empty($entityUuid)
            ? $approvableModel->id
            : $approvableModel->where('uuid', $entityUuid)->first()?->id;

        $query->where('approvable_type', get_class($approvableModel))
                ->where('approvable_id', $approvableModelId);

        return $query->get()->toArray();
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
                'coding' => $this->prepareCodingToRequest($codeableConceptData['coding']),
                'text' => $codeableConceptData['text'] ?? ''
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
                'system' => empty($coding['system']) ? 'eHealth/resources' : $coding['system'],
                'code' => $coding['code']
            ], $codingData);
    }

    protected function resolveIdentifier(?string $uuid = null): ?Identifier
    {
        if (!$uuid) {
            return null;
        }

        $identifier = Identifier::where('value', $uuid)->first();

        if (!$identifier) {
            $identifier = Repository::identifier()->store($uuid);
        }

        return $identifier;
    }

    /**
     * Sync a HasMany child collection by a single FK attribute.
     *
     * Compares the current values of $relationAttribute on the child rows against
     * $newIds, deletes rows whose attribute value is no longer present, and creates
     * new rows for IDs that are not yet stored.
     *
     * @param  Model   $model              The parent model that owns the HasMany relation.
     * @param  string  $relation           The HasMany relation name on $model (e.g. 'grantedResources').
     * @param  string  $relationAttribute  The FK column on the child table to compare (e.g. 'granted_to_id').
     * @param  array   $newIds             Desired set of IDs for $relationAttribute.
     *
     * @return void
     */
    protected function syncResourceEntity(Model $model, string $relation, string $relationAttribute, array $newIds): void
    {
        $currentIds = $model->{$relation}()->pluck($relationAttribute)->toArray();

        $toDelete = array_diff($currentIds, $newIds);
        $toAdd = array_diff($newIds, $currentIds);

        if ($toDelete) {
            $model->{$relation}()->whereIn($relationAttribute, $toDelete)->delete();
        }

        foreach ($toAdd as $id) {
            $model->{$relation}()->create([$relationAttribute => $id]);
        }
    }
}
