<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use Throwable;
use Carbon\Carbon;
use App\Enums\JobStatus;
use App\Models\EhealthJob;
use App\Models\EhealthLink;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use App\Models\Person\Person;
use App\Classes\eHealth\EHealth;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Enums\Person\ApprovalStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Relations\AuthenticationMethod;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\MedicalEvents\Mongo\Approval as MongoApproval;

/**
 * @property Approval $model
 */
class ApprovalRepository extends BaseRepository
{
    public function reusableForCarePlan(\App\Models\CarePlan $plan, string $employeeUuid, string $accessLevel): ?Approval
    {
        return $plan->approvals()->with(['grantedTo', 'ehealthLinks.job'])
            ->whereHas('grantedTo', fn ($query) => $query->whereValue($employeeUuid))
            ->whereAccessLevel($accessLevel)
            ->where(function ($query): void {
                $query->whereIn('status', [ApprovalStatus::ACTIVE->value, ApprovalStatus::APPROVED->value, ApprovalStatus::PENDING->value, ApprovalStatus::NEW->value])
                    ->orWhereHas('ehealthLinks', fn ($links) => $links->whereStatus(\App\Enums\JobStatus::PENDING->value));
            })
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('is_verified')->latest('id')->first();
    }

    public function beginCarePlanRequest(\App\Models\CarePlan $plan, array $payload, bool $confirmWithoutOtp = false): EhealthLink
    {
        return $this->storageTransaction(function () use ($plan, $payload, $confirmWithoutOtp): EhealthLink {
            $identifier = $this->resolveIdentifier(data_get($payload, 'granted_to.identifier.value'));
            $approval = Approval::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'approvable_type' => $plan::class,
                'approvable_id' => $plan->id,
                'granted_to_id' => $identifier?->id,
                'granted_to_type' => 'employee',
                'access_level' => $payload['access_level'],
                'authorize_with' => $payload['authorize_with'] ?? null,
                'status' => ApprovalStatus::PENDING->value,
                'is_verified' => false,
            ]);
            $link = $this->attachEhealthLink($approval, ['href' => null]);
            $link->job()->update(['request_data' => $payload + ['confirmWithoutOtp' => $confirmWithoutOtp]]);

            return $link->load('linkable');
        });
    }

    public function requestLink(int $id): ?EhealthLink
    {
        return EhealthLink::with(['job', 'linkable.approvable', 'processingData'])->find($id);
    }

    public function creationLinkFor(Approval $approval): ?EhealthLink
    {
        return $approval->ehealthLinks()->with('job')->whereHas('job', fn ($query) => $query
            ->where(fn ($operation) => $operation->whereNull('request_data->operation')->orWhere('request_data->operation', '!=', 'resendSms')))
            ->latest('id')->first();
    }

    public function carePlanApproval(\App\Models\CarePlan $plan, string $approvalUuid): Approval
    {
        return $plan->approvals()->whereUuid($approvalUuid)->firstOrFail();
    }

    public function beginCarePlanSmsResend(\App\Models\CarePlan $plan, string $approvalUuid): EhealthLink
    {
        return $this->storageTransaction(function () use ($plan, $approvalUuid): EhealthLink {
            $approval = $this->carePlanApproval($plan, $approvalUuid);
            $link = $this->attachEhealthLink($approval, ['href' => null]);
            $link->job()->update(['request_data' => ['operation' => 'resendSms']]);

            return $link->load('linkable');
        });
    }

    public function finishCarePlanSmsResend(EhealthLink $link, array $data): void
    {
        $this->storageTransaction(function () use ($link, $data): void {
            $link = EhealthLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            $status = strtolower((string) ($data['status'] ?? '')) === 'processed'
                ? \App\Enums\JobStatus::PROCESSED->value : \App\Enums\JobStatus::FAILED->value;
            $link->job()->update(['status' => $status, 'response_data' => $data]);
            $link->update(['status' => $status, 'error' => $status === 'FAILED' ? ['message' => __('care-plan.sms_resend_error')] : null]);
        });
    }

    public function carePlanSmsResendState(\App\Models\CarePlan $plan, string $approvalUuid, int $linkId): array
    {
        $approval = $plan->approvals()->whereUuid($approvalUuid)->firstOrFail();
        $link = $approval->ehealthLinks()->whereKey($linkId)->with('job')->firstOrFail();
        if (data_get($link->job->request_data, 'operation') !== 'resendSms') {
            throw new \RuntimeException(__('care-plan.sms_resend_error'));
        }

        if ($approval->getAttribute('is_verified') && ApprovalStatus::resolve($approval->status)?->isGranted()
            && ($approval->expiresAt === null || $approval->expiresAt->isFuture())) {
            return ['status' => 'GRANTED'];
        }

        return ['status' => $link->status];
    }

    public function trackRequest(EhealthLink $link, string $href, array $data): void
    {
        $this->storageTransaction(function () use ($link, $href, $data): void {
            $link->update(['href' => $href]);
            $link->job()->update(['response_data' => $data]);
        });
    }

    public function completeCarePlanRequest(EhealthLink $link, array $data): void
    {
        $this->storageTransaction(function () use ($link, $data): void {
            $approval = $link->linkable;
            $resolved = Approval::whereUuid($data['id'])->first();
            if ($resolved !== null && $resolved->id !== $approval->id) {
                if ($resolved->approvableId !== $approval->approvableId || $resolved->approvableType !== $approval->approvableType) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['approval' => __('care-plan.approval_result_unavailable')]);
                }
                $approval->ehealthLinks()->update(['linkable_id' => $resolved->id]);
                $approval->delete();
                $approval = $resolved;
            }
            if (($data['is_verified'] ?? null) === false && ApprovalStatus::resolve($data['status'] ?? null) !== ApprovalStatus::INACTIVE) {
                $data['status'] = ApprovalStatus::PENDING->value;
            }
            if ($approval->getAttribute('is_verified') && ApprovalStatus::resolve($approval->status)?->isGranted()) {
                $data['is_verified'] = true;
                $data['status'] = ApprovalStatus::ACTIVE->value;
            }
            $this->sync($data, $approval->approvable, $approval);
            $link->job()->update(['status' => \App\Enums\JobStatus::PROCESSED->value, 'response_data' => $data]);
            $link->update(['status' => \App\Enums\JobStatus::PROCESSED->value]);
            $link->processingData()->create(['response_data' => $data]);
        });
    }

    public function localForCarePlan(\App\Models\CarePlan $plan): array
    {
        return $plan->approvals()->withAllRelations()->with('ehealthLinks.job')->latest('id')->get()
            ->map(function (Approval $approval): array {
                $row = $approval->toArray();
                $link = $approval->ehealthLinks->sortByDesc('id')->first();
                $row['requestPending'] = $link !== null && !in_array($link->status, [\App\Enums\JobStatus::PROCESSED->value, \App\Enums\JobStatus::FAILED->value], true);

                return $row;
            })->all();
    }

    public function recordConfirmationError(EhealthLink $link): void
    {
        $link->load('job');
        $link->job()->update(['response_data' => array_replace($link->job->response_data ?? [], ['confirmationError' => true])]);
    }

    public function storePatientResponse(string $patientUuid, string $approvalUuid, array $data, string $confirmedStatus): void
    {
        $approval = Approval::whereUuid($approvalUuid)->whereHasMorph(
            'approvable',
            [\App\Models\CarePlan::class],
            fn ($query) => $query->whereHas('person', fn ($person) => $person->whereUuid($patientUuid))
        )->with('approvable')->firstOrFail();
        if (isset($data['id']) && $data['id'] !== $approvalUuid) {
            throw \Illuminate\Validation\ValidationException::withMessages(['approval' => __('care-plan.approval_result_unavailable')]);
        }
        if ($confirmedStatus === ApprovalStatus::ACTIVE->value && array_key_exists('is_verified', $data) && $data['is_verified'] !== true) {
            throw \Illuminate\Validation\ValidationException::withMessages(['approval' => __('care-plan.approval_verify_error')]);
        }
        if (isset($data['id'], $data['is_verified'])) {
            $this->sync($data, $approval->approvable, $approval);
        } else {
            $changes = ['status' => $confirmedStatus];
            if ($confirmedStatus === ApprovalStatus::ACTIVE->value) {
                $changes['isVerified'] = true;
            }
            // These server-owned fields are explicit; camelCase keys are not in this model's $fillable.
            $approval->forceFill($changes)->save();
        }
    }
    protected const int SMS_CODE_ALIVE_MINUTES = 14;

    protected string $employeeUuid;

    public function __construct(Model $model)
    {
        parent::__construct($model);
    }

    /**
     * Resolve an approval for confirmation, selecting by current employee when given an approvable model.
     * Return the error when the approval is unavailable.
     *
     * @return array{approval: Approval, error: null}|array{approval: null, error: string}
     */
    public function findApprovalForConfirmation(Model $model): array
    {
        if ($model instanceof Approval) {
            $approval = $model;
        } else {
            $approvals = Approval::getByModel($model)
                ->whereHas('grantedTo', fn ($query) => $query->whereValue(Auth::user()->activeDoctorEmployee()?->uuid))
                ->latest('id');

            $approval = (clone $approvals)
                ->whereNotNull('uuid')
                ->whereFuture('expires_at')
                ->whereIn('status', [ApprovalStatus::PENDING->value, ApprovalStatus::NEW->value, ApprovalStatus::ACTIVE->value, ApprovalStatus::APPROVED->value])
                ->where('is_verified', false)
                ->first() ?? $approvals->first();
        }

        $error = match (true) {
            $approval === null => __('patients.errors.approval_not_found'),
            $approval->status === JobStatus::FAILED->value => __('patients.errors.approval_not_created'),
            !$approval->uuid => __('patients.errors.approval_creation_pending'),
            ApprovalStatus::resolve($approval->status) === null,
            ApprovalStatus::resolve($approval->status) === ApprovalStatus::INACTIVE => __('patients.errors.approval_unavailable', ['status' => $approval->status]),
            $approval->expiresAt?->isPast() === true => __('patients.errors.approval_expired'),
            default => null,
        };

        if ($error === null) {
            return ['approval' => $approval, 'error' => null];
        }

        if ($approval?->status === JobStatus::FAILED->value) {
            $link = $approval->ehealthLinks()->where('status', JobStatus::FAILED->value)->latest('id')->first();

            if ($link?->error) {
                $error .= ' '.(new EHealthValidationException(['error' => $link->error]))->getFormattedMessage();
            }
        }

        return ['approval' => null, 'error' => $error];
    }

    /**
     * Fetch approvals from eHealth and sync them locally for a given polymorphic entity.
     *
     * - Prefers Get approvals filters (`granted_resource_type`, `granted_resources`) when patient uuid is known.
     * - Still applies a client-side filter as a safety net if the API ignores filters.
     * - Stores the full raw eHealth JSON in MongoDB (Mongo\Approval).
     * - Extracts reason_id (FK → identifiers) from the reason object — never writes a raw string.
     * - Extracts granted_to_id (FK → identifiers) from the granted_to identifier value.
     *
     * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/2115600961/Get+approvals
     */
    public function syncApprovals(Model $entity, string $resourceType, array $additionalFilters = [], ?string $token = null): void
    {
        if (empty($entity->uuid)) {
            return;
        }

        try {
            $api = EHealth::approval();
            if ($token !== null) {
                $api->withToken($token);
            }
            $patientUuid = null;

            if (method_exists($entity, 'person') && $entity->person) {
                $patientUuid = $entity->person->uuid;
            } elseif (isset($entity->person_id)) {
                $person = \App\Models\Person\Person::find($entity->person_id);
                $patientUuid = $person?->uuid;
            }

            $filters = array_filter(array_merge([
                'granted_resource_type' => $resourceType,
                'granted_resources' => $entity->uuid,
            ], $additionalFilters), static fn ($value) => $value !== null && $value !== '');

            if ($patientUuid) {
                $response = $api->getPatientApprovals($patientUuid, $filters);
                $data = $response->validate();

                // Safety net: keep only approvals that reference this specific resource
                if (!empty($data) && is_array($data)) {
                    $filteredData = [];

                    foreach ($data as $approvalData) {
                        $grantedResources = $approvalData['granted_resources'] ?? [];

                        foreach ($grantedResources as $resource) {
                            $typeCode = $resource['identifier']['type']['coding'][0]['code'] ?? null;
                            $value = $resource['identifier']['value'] ?? null;

                            if ($typeCode === $resourceType && $value === $entity->uuid) {
                                $filteredData[] = $approvalData;
                                break;
                            }
                        }
                    }

                    $data = $filteredData;
                }
            } else {
                $response = $api->getMany($filters);
                $data = $response->validate();
            }

            if (empty($data)) {
                return;
            }

            $syncedUuids = [];
            foreach ($data as $approvalData) {
                // Persist full raw eHealth JSON to MongoDB
                try {
                    MongoApproval::updateOrCreate(
                        ['id' => $approvalData['id']],
                        $approvalData
                    );
                } catch (\Throwable $e) {
                    Log::warning('MedicalEvents\\ApprovalRepository Mongo sync failed: '.$e->getMessage());
                }

                $syncedUuids[] = $approvalData['id'];

                // Resolve granted_to → Identifier FK
                $grantedToValue = $approvalData['granted_to']['identifier']['value'] ?? null;
                $grantedToCode = $approvalData['granted_to']['identifier']['type']['coding'][0]['code'] ?? 'legal_entity';

                // Resolve reason → Identifier FK (never a raw string)
                $reasonValue = $approvalData['reason']['value'] ?? null;

                // Resolve created_by → Identifier FK
                $createdByValue = $approvalData['created_by']['identifier']['value'] ?? null;

                Approval::updateOrCreate(
                    ['uuid' => $approvalData['id']],
                    [
                        'approvable_type' => get_class($entity),
                        'approvable_id' => $entity->id,
                        'granted_to_id' => $this->resolveIdentifier($grantedToValue)?->id,
                        'granted_to_type' => $grantedToCode,
                        'reason_id' => $this->resolveIdentifier($reasonValue)?->id,
                        'created_by_id' => $this->resolveIdentifier($createdByValue)?->id,
                        'status' => ApprovalStatus::resolve($approvalData['status'] ?? null) === ApprovalStatus::INACTIVE
                            ? ApprovalStatus::INACTIVE->value
                            : (!empty($approvalData['is_verified']) ? ApprovalStatus::ACTIVE->value : ApprovalStatus::PENDING->value),
                        'access_level' => $approvalData['access_level'] ?? 'read',
                        'is_verified' => (bool) ($approvalData['is_verified'] ?? false),
                        'expires_at' => $approvalData['expires_at'] ?? null,
                    ]
                );
            }

            $inactiveQuery = Approval::where('approvable_type', get_class($entity))
                ->where('approvable_id', $entity->id)
                ->whereDoesntHave('ehealthLinks', fn ($links) => $links->whereHas('job', fn ($jobs) => $jobs->whereIn('status', ['PENDING', 'PROCESSING'])))
                ->whereNotIn('uuid', $syncedUuids);

            if (isset($additionalFilters['granted_to.identifier.value'])) {
                $identifier = \App\Models\MedicalEvents\Sql\Identifier::where('value', $additionalFilters['granted_to.identifier.value'])->first();
                if ($identifier) {
                    $inactiveQuery->where('granted_to_id', $identifier->id);
                } else {
                    $inactiveQuery->whereRaw('1 = 0');
                }
            }

            $inactiveQuery->update(['status' => ApprovalStatus::INACTIVE->value]);
        } catch (\App\Exceptions\EHealth\EHealthValidationException $e) {
            if ($entity instanceof \App\Models\CarePlan) {
                throw $e;
            }
            \Illuminate\Support\Facades\Log::error('MedicalEvents\ApprovalRepository syncing failed: ' . $e->getFormattedMessage());
        } catch (\Exception $e) {
            if ($entity instanceof \App\Models\CarePlan) {
                throw $e;
            }
            \Illuminate\Support\Facades\Log::error('MedicalEvents\ApprovalRepository syncing failed: ' . $e->getMessage());
        }
    }

    /**
     * Attach an EhealthLink to an Approval after a 202 async response.
     *
     * @param  array{href: string}  $link  The link object from the eHealth 202 response.
     */
    public function attachEhealthLink(Approval $approval, array $link): EhealthLink
    {
        $job = EhealthJob::create([
            'processing_method' => 'ASYNC',
            'status' => 'PENDING',
        ]);

        return EhealthLink::create([
            'linkable_type' => Approval::class,
            'linkable_id' => $approval->id,
            'ehealth_job_id' => $job->id,
            'entity' => 'job',
            'href' => $link['href'],
            'status' => 'PENDING',
        ]);
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
     * @param  array<string, mixed>  $payloadData  Associative array of entity key → raw data.
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
                'resource_types' => $payload[$entity] = array_map(fn (array $codeableConcept) => $this->prepareCodeableConceptToRequest($codeableConcept)['type'], $entityData),
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
    
    public function storePending(Person $person, array $payload, Employee $creator): Approval
    {
        return DB::transaction(function () use ($person, $payload, $creator): Approval {
            $creatorUuid = $payload['created_by']['identifier']['value'];
            $recipientUuid = $payload['granted_to']['identifier']['value'];
            $recipientType = $payload['granted_to']['identifier']['type']['coding'][0]['code'];

            $createdBy = Repository::identifier()->store(
                $creatorUuid,
                $creator->fullName
            );

            $grantedTo = Repository::identifier()->store(
                $recipientUuid,
                $recipientType === 'employee' ? $creator->fullName : legalEntity()->name
            );

            return $this->model->create([
                'uuid' => (string) Str::uuid(),
                'approvable_type' => Person::class,
                'approvable_id' => $person->id,
                'created_by_id' => $createdBy->id,
                'granted_to_id' => $grantedTo->id,
                'granted_to_type' => $recipientType,
                'authorize_with' => $payload['authorize_with'],
                'status' => ApprovalStatus::PENDING->value,
                'access_level' => $payload['access_level'],
                'is_verified' => false,
            ]);
        });
    }

    /**
     * Create approval model and store its data and relations data to DB.
     *
     * @param  array  $data
     * @param  Model  $approvableModel
     * @return Approval
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
                'status' => ApprovalStatus::forStorage($data['status'] ?? null),
                'access_level' => $data['access_level'] ?? 'read',
                'is_verified' => $data['is_verified'] ?? false,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            if (isset($data['granted_resources'])) {
                foreach ($data['granted_resources'] as $grantedResourceData) {
                    $identifier = $this->resolveIdentifier(Arr::get($grantedResourceData, 'identifier.value'));

                    Repository::codeableConcept()->attach($identifier, $grantedResourceData);

                    $approval->grantedResources()->create(['granted_to_id' => $identifier->id]);
                }
            }

            if (isset($data['granted_resource_types'])) {
                foreach ($data['granted_resource_types'] as $grantedResourceTypeData) {
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
     * @param  Model  $approvalModel
     * @param  array  $modelData
     * @return void
     * @throws Throwable
     */
    public function sync(array $modelData, Model $approvableModel, ?Approval $approvalModel = null): void
    {
        DB::transaction(function () use ($approvalModel, $modelData, $approvableModel) {
            $approvalModelUuid = $modelData['uuid'] ?? ($modelData['id'] ?? null);

            $approvalQuery = $approvalModel?->newQuery() ?? Approval::query();
            $existing = $approvalQuery
                ->when(
                    $approvalModel?->id !== null,
                    static fn ($query) => $query->where('id', $approvalModel->id),
                    static fn ($query) => $query->where('uuid', $approvalModelUuid)
                )
                ->withAllRelations()
                ->with('grantedResourceIdentifiers.type.coding')
                ->first();

            $createdBy = $this->syncIdentifier($existing, $modelData['created_by'] ?? null, 'createdBy');

            $grantedTo = $this->syncIdentifier($existing, $modelData['granted_to'] ?? null, 'grantedTo');

            $reason = $this->syncIdentifier($existing, $modelData['reason'] ?? null, 'reason');

            $grantedToType = Arr::get($modelData, 'granted_to.identifier.type.coding.0.code')
                ?? $existing?->grantedToType
                ?? 'legal_entity';

            $grantedToId = $grantedTo?->id
                ?? $existing?->grantedToId;

            $authMethod = AuthenticationMethod::getByModelAndUuid($approvableModel)->first();

            $approvalData = [
                'uuid' => $approvalModelUuid,
                'approvable_id' => $approvableModel->id,
                'approvable_type' => get_class($approvableModel),
                'created_by_id' => $createdBy?->id,
                'granted_to_id' => $grantedToId,
                'granted_to_type' => $grantedToType,
                'granted_by_id' => null,
                'authorize_with' => $modelData['authorize_with'] ?? $existing?->authorizeWith,
                'authentication_method_id' => $authMethod?->id,
                'reason_id' => $reason?->id,
                'status' => ApprovalStatus::forStorage($modelData['status'] ?? (!empty($modelData['is_verified']) ? ApprovalStatus::ACTIVE->value : ApprovalStatus::PENDING->value)),
                'access_level' => $modelData['access_level'] ?? $existing?->accessLevel ?? 'read',
                'is_verified' => $modelData['is_verified'] ?? false,
                'expires_at' => isset($modelData['expires_at']) ? convertToLocalTimezone($modelData['expires_at']) : $existing?->expiresAt,
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
                    $this->syncIdentifiers($existing, $modelData['granted_resources'] ?? [], 'grantedResourceIdentifiers')
                );
            }

            if (isset($modelData['granted_resource_types'])) {
                $this->syncResourceEntity(
                    $approval,
                    'grantedResourceTypes',
                    'codeable_concept_id',
                    $this->syncCodeableConcepts($existing, $modelData['granted_resource_types'] ?? [], 'grantedResourceTypesIdentifiers')
                );
            }

            $approval->refresh();
        });
    }

    /**
     * Sync approvals data and related data by updating or creating.
     *
     * @param  Person  $patient
     * @param  array  $approvals
     * @return void
     */
    public function syncMany(Person $patient, array $approvals): void
    {
        DB::transaction(function () use ($patient, $approvals): void {
            $existing = Approval::query()
                ->with('approvable')
                ->whereIn('uuid', array_column($approvals, 'id'))
                ->get()
                ->keyBy('uuid');

            foreach ($approvals as $data) {
                $approval = $existing->get($data['id']);

                $this->sync(
                    $data,
                    $approval?->approvable ?? $patient,
                    $approval
                );
            }
        });
    }

    /**
     * Get data that is related to the person.
     *
     * @param  string  $entityUuid  UUID of the entity ('person', 'encounter', 'procedure' etc) (optional)
     * @param  Model|null  $approvableModel  Specific polymorphic parent model instance.
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
     * @param  array{type: array, value: string}  $identifier  Raw identifier with `type` (codeable concept) and `value` (UUID).
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
     * @param  array{coding: array, text?: string}  $codeableConceptData
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
     * @param  array<int, array{system: string, code: string}>  $codingData
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
     * @param  Model  $model  The parent model that owns the HasMany relation.
     * @param  string  $relation  The HasMany relation name on $model (e.g. 'grantedResources').
     * @param  string  $relationAttribute  The FK column on the child table to compare (e.g. 'granted_to_id').
     * @param  array  $newIds  Desired set of IDs for $relationAttribute.
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

    /**
     * Determine whether the SMS verification code for the given approval is still valid.
     *
     * A code is considered alive if fewer than {@see SMS_CODE_ALIVE_MINUTES} minutes
     * have elapsed since the approval was last updated.
     *
     * @param  Approval  $approval
     * @return bool `true` if the code has not yet expired, `false` otherwise.
     */
    public function isSmsCodeAlive(Approval $approval): bool
    {
        $timestamp = $approval->updated_at;

        return !Carbon::parse($timestamp)->addMinutes(self::SMS_CODE_ALIVE_MINUTES)->isPast();
    }

    public function replaceResolvedUuid(Approval $approval, string $uuid): void
    {
        $approval->uuid = $uuid;
        $approval->save();
    }
}
