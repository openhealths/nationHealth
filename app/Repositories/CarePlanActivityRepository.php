<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\SubmittedToEHealth;
use App\Enums\CarePlanActivityKind;
use App\Enums\CarePlanStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\BaseRepository as MedicalEventsBaseRepository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CarePlanActivityRepository extends MedicalEventsBaseRepository
{
    public function __construct(CarePlanActivity $model)
    {
        parent::__construct($model);
    }

    public const array FHIR_RELATIONS = ['carePlan', 'carePlanReference.type.coding', 'authorReferences.type.coding', 'productConcept.coding', 'statusReasonConcept.coding', 'productReferenceIdentifier.type.coding', 'locationIdentifier.type.coding', 'performerIdentifier.type.coding', 'programIdentifier.type.coding', 'quantityQuantity', 'dailyAmountQuantity', 'remainingQuantityQuantity', 'scheduledPeriod', 'reasonConcepts.coding', 'goalConcepts.coding', 'outcomeConcepts.coding', 'reasonReferences.type.coding', 'outcomeReferences.type.coding'];

    public function saveDraft(array $resource, CarePlan $plan, ?int $localId = null): CarePlanActivity
    {
        return $this->storageTransaction(function () use ($resource, $plan, $localId): CarePlanActivity {
            // Serializing on the plan prevents two drafts from consuming the final service slot.
            $plan = CarePlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($localId !== null) {
                $record = CarePlanActivity::whereCarePlanId($plan->id)->whereKey($localId)->lockForUpdate()->firstOrFail();
                if (Repository::carePlan()->hasPendingSubmission($record)) {
                    throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
                }
            }

            if (data_get($resource, 'detail.kind') === CarePlanActivityKind::SERVICE_REQUEST->value) {
                // Recheck these persistence invariants while the plan is locked.
                if (data_get($resource, 'detail.quantity.code') === 'MINUTE' && !$plan->allowsServiceMinutes()) {
                    throw ValidationException::withMessages(['activityForm.quantity_code' => __('care-plan.service_minutes_unavailable')]);
                }
                if ($plan->termsOfServiceCode() === 'OUTPATIENT') {
                    $errors = [];
                    foreach (['start', 'end'] as $boundary) {
                        if (!filled(data_get($resource, 'detail.scheduledPeriod.'.$boundary))) {
                            $errors['activityForm.scheduled_period_'.$boundary] = __('care-plan.service_activity_period_required');
                        }
                    }
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                }
                $productUuid = data_get($resource, 'detail.productReference.identifier.value');
                $productType = data_get($resource, 'detail.productReference.identifier.type.coding.0.code', 'service');
                if (is_string($productUuid) && $productUuid !== '' && $this->serviceCreationCapacity($plan, $productUuid, $productType, $localId)['remaining'] < 1) {
                    throw ValidationException::withMessages(['activityForm.product_reference' => __('care-plan.service_activity_limit_reached')]);
                }
            }

            return $this->storeResource($resource, $plan, $localId);
        });
    }

    /** BA02 counts created activities and distinct issued referrals, independently of service quantity. */
    public function serviceCreationCapacity(CarePlan $plan, string $productUuid, string $productType = 'service', ?int $exceptId = null): array
    {
        $activities = CarePlanActivity::whereCarePlanId($plan->id)->whereKind(CarePlanActivityKind::SERVICE_REQUEST->value)
            ->whereHas('productReferenceIdentifier', static fn ($query) => $query->whereValue($productUuid)
                ->whereHas('type.coding', static fn ($coding) => $coding->whereCode($productType)))
            ->get(['id', 'uuid']);

        // These are clinical states of a full native resource, never async job states.
        // Recalled/completed referrals retain their credit; duplicate imports of a UUID do not add another.
        $confirmed = ServiceRequestRequest::wherePersonId($plan->personId)->whereServiceId($productUuid)
            ->whereHas('codeReference', static fn ($query) => $query->whereValue($productUuid)
                ->whereHas('type.coding', static fn ($coding) => $coding->whereCode($productType)))
            ->whereNotNull('details_synced_at')->whereNotNull('request_number')->where('request_number', '<>', '')
            ->whereIn('clinical_status', [ServiceRequestStatus::ACTIVE->value, ServiceRequestStatus::IN_QUEUE->value,
                ServiceRequestStatus::IN_PROGRESS->value, ServiceRequestStatus::COMPLETED->value, ServiceRequestStatus::USED->value,
                ServiceRequestStatus::CANCELLED->value, ServiceRequestStatus::RECALLED->value,
                ServiceRequestStatus::REVOKED->value, ServiceRequestStatus::ENTERED_IN_ERROR->value])
            ->whereHas('basedOn', static fn ($query) => $query->whereIn('value', $activities->pluck('uuid'))
                ->whereHas('type.coding', static fn ($coding) => $coding->whereCode('care_plan_activity')))
            ->whereHas('requesterLegalEntity', static fn ($query) => $query->whereValue($plan->legalEntity->uuid))
            ->pluck('uuid')->unique()->count();
        $created = $activities->when($exceptId !== null, static fn ($records) => $records->where('id', '!=', $exceptId))->count();
        $limit = 5 + $confirmed;

        return ['limit' => $limit, 'created' => $created, 'confirmedReferrals' => $confirmed, 'remaining' => max(0, $limit - $created)];
    }

    public function storeResources(array $resources, CarePlan $plan): void
    {
        $this->storageTransaction(function () use ($resources, $plan): void {
            foreach ($resources as $resource) {
                $this->storeResource($resource, $plan);
            }
        });
    }

    /** Store a complete activity; remainingQuantity is supplied by the source, never recalculated. */
    public function storeResource(array $resource, CarePlan $plan, ?int $localId = null): CarePlanActivity
    {
        $detail = $resource['detail'] ?? [];
        if (!isset($resource['id'], $detail['status']) || CarePlanActivityKind::tryFrom($detail['kind'] ?? '') === null || data_get($resource, 'carePlan.identifier.value') !== $plan->uuid) {
            throw new InvalidArgumentException('A complete activity requires identity, kind, status and its owning Care Plan reference.');
        }

        return $this->storageTransaction(function () use ($resource, $detail, $plan, $localId): CarePlanActivity {
            $query = CarePlanActivity::whereCarePlanId($plan->id)->lockForUpdate();
            $activity = $localId === null ? $query->whereUuid($resource['id'])->first() : $query->findOrFail($localId);
            if ($activity?->uuid !== null && $activity->uuid !== $resource['id']) {
                throw new InvalidArgumentException('Cannot replace an existing activity identity.');
            }
            $activity ??= new CarePlanActivity();
            $activity->carePlanId = $plan->id;
            $authors = $resource['author'] ?? [];
            $authorUuid = data_get($authors, '0.identifier.value');
            $attributes = [
                'uuid' => $resource['id'],
                'kind' => $detail['kind'],
                'status' => $detail['status'],
                'description' => $detail['description'] ?? null,
                'doNotPerform' => $detail['doNotPerform'] ?? false,
                'authorId' => $authorUuid ? Employee::whereUuid($authorUuid)->whereLegalEntityId($plan->legalEntityId)->value('id') : null,
                'remainingQuantityType' => $detail['remainingQuantityType'] ?? null,
                'scheduledTiming' => $detail['scheduledTiming'] ?? null,
                'scheduledString' => $detail['scheduledString'] ?? null,
                'detailsSyncedAt' => now(),
                'carePlanReferenceId' => $this->syncIdentifier($activity->exists ? $activity : null, $resource['carePlan'], 'carePlanReference')->id,
            ];
            foreach (['productCodeableConcept' => ['productConcept', 'productCodeableConceptId'], 'statusReason' => ['statusReasonConcept', 'statusReasonConceptId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = isset($detail[$field]) ? $this->syncCodeableConcept($activity->exists ? $activity : null, $detail[$field], $relation)?->id : null;
            }
            foreach (['productReference' => ['productReferenceIdentifier', 'productReferenceId'], 'location' => ['locationIdentifier', 'locationIdentifierId'], 'performer' => ['performerIdentifier', 'performerIdentifierId'], 'program' => ['programIdentifier', 'programIdentifierId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = isset($detail[$field]) ? $this->syncIdentifier($activity->exists ? $activity : null, $detail[$field], $relation)?->id : null;
            }
            foreach (['quantity' => ['quantityQuantity', 'quantityId'], 'dailyAmount' => ['dailyAmountQuantity', 'dailyAmountId'], 'remainingQuantity' => ['remainingQuantityQuantity', 'remainingQuantityId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = MedicalEventsRepository::quantity()->replaceComplete($activity->exists ? $activity->{$relation} : null, $detail[$field] ?? null)?->id;
            }
            $activity->forceFill($attributes)->save();
            MedicalEventsRepository::period()->sync($activity, !empty($detail['scheduledPeriod']) ? $detail['scheduledPeriod'] + ['start' => null] : null, 'scheduledPeriod');
            $this->syncPivot($activity, 'authorReferences', $this->syncIdentifiers($activity, $authors, 'authorReferences'));
            $this->syncPivot($activity, 'reasonReferences', $this->syncIdentifiers($activity, $detail['reasonReference'] ?? [], 'reasonReferences'));
            $this->syncPivot($activity, 'reasonConcepts', $this->syncCodeableConcepts($activity, $detail['reasonCode'] ?? [], 'reasonConcepts'));
            $this->syncPivot($activity, 'goalConcepts', $this->syncCodeableConcepts($activity, $detail['goal'] ?? [], 'goalConcepts'));
            $this->syncPivot($activity, 'outcomeReferences', $this->syncIdentifiers($activity, $resource['outcomeReference'] ?? [], 'outcomeReferences'));
            $this->syncPivot($activity, 'outcomeConcepts', $this->syncCodeableConcepts($activity, $resource['outcomeCodeableConcept'] ?? [], 'outcomeConcepts'));

            return $activity->unsetRelations();
        });
    }

    public function getResource(int|CarePlanActivity $id): array
    {
        $activity = $id instanceof CarePlanActivity ? $id->loadMissing(self::FHIR_RELATIONS) : CarePlanActivity::with(self::FHIR_RELATIONS)->findOrFail($id);
        if ($activity->detailsSyncedAt === null) {
            throw new InvalidArgumentException('Activity must pass the FHIR backfill before use.');
        }
        $resource = [];
        $resource['id'] = $activity->uuid;
        $resource['carePlan'] = MedicalEventsRepository::identifier()->toReference($activity->carePlanReference);
        $resource['author'] = $activity->authorReferences->map(fn ($reference): array => MedicalEventsRepository::identifier()->toReference($reference))->all();
        $detail = [
            'kind' => $activity->kind,
            'status' => $activity->status,
            'description' => $activity->description,
            'doNotPerform' => $activity->doNotPerform,
            'remainingQuantityType' => $activity->remainingQuantityType,
            'scheduledPeriod' => $activity->scheduledPeriod ? MedicalEventsRepository::period()->toResource($activity->scheduledPeriod) : null,
            'scheduledTiming' => $activity->scheduledTiming,
            'scheduledString' => $activity->scheduledString,
        ];
        foreach (['productCodeableConcept' => 'productConcept', 'statusReason' => 'statusReasonConcept'] as $field => $relation) {
            $detail[$field] = MedicalEventsRepository::codeableConcept()->toResource($activity->{$relation});
        }
        foreach (['productReference' => 'productReferenceIdentifier', 'location' => 'locationIdentifier', 'performer' => 'performerIdentifier', 'program' => 'programIdentifier'] as $field => $relation) {
            $detail[$field] = MedicalEventsRepository::identifier()->toReference($activity->{$relation});
        }
        foreach (['quantity' => 'quantityQuantity', 'dailyAmount' => 'dailyAmountQuantity', 'remainingQuantity' => 'remainingQuantityQuantity'] as $field => $relation) {
            $detail[$field] = MedicalEventsRepository::quantity()->toResource($activity->{$relation});
        }
        foreach (['reasonCode' => 'reasonConcepts', 'goal' => 'goalConcepts'] as $field => $relation) {
            $detail[$field] = $activity->{$relation}->map(fn ($concept): array => MedicalEventsRepository::codeableConcept()->toResource($concept))->all();
        }
        $detail['reasonReference'] = $activity->reasonReferences->map(fn ($reference): array => MedicalEventsRepository::identifier()->toReference($reference))->all();
        $resource['detail'] = array_filter($detail, static fn (mixed $value): bool => $value !== null);
        $resource['outcomeReference'] = $activity->outcomeReferences->map(fn ($reference): array => MedicalEventsRepository::identifier()->toReference($reference))->all();
        $resource['outcomeCodeableConcept'] = $activity->outcomeConcepts->map(fn ($concept): array => MedicalEventsRepository::codeableConcept()->toResource($concept))->all();

        return $resource;
    }

    public function findById(int $id): ?CarePlanActivity
    {
        $activity = CarePlanActivity::with([...self::FHIR_RELATIONS, 'author.party', 'carePlan.person'])->find($id);

        return $activity;
    }

    public function getByCarePlanId(int $carePlanId)
    {
        return CarePlanActivity::whereCarePlanId($carePlanId)->with(self::FHIR_RELATIONS)->get();
    }

    public function create(array $resource, CarePlan $plan): CarePlanActivity
    {
        return $this->saveDraft($resource, $plan);
    }

    public function update(CarePlanActivity $activity, array $data): bool
    {
        $plan = CarePlan::findOrFail($activity->carePlanId);
        $this->saveDraft($data, $plan, $activity->id);

        return true;
    }

    public function updateById(int $id, array $data): bool
    {
        $activity = CarePlanActivity::find($id);
        if (!$activity) {
            return false;
        }

        return $this->update($activity, $data);
    }

    public function deleteById(int $id): bool
    {
        return $this->storageTransaction(function () use ($id): bool {
            $activity = CarePlanActivity::whereKey($id)->lockForUpdate()->first();
            if (!$activity || $activity->status !== CarePlanStatus::DRAFT->value || Repository::carePlan()->hasPendingSubmission($activity)) {
                return false;
            }
            foreach (CarePlanActivityKind::cases() as $kind) {
                if ($this->linkedDocuments([$activity->uuid], $kind->value)->isNotEmpty()) {
                    return false;
                }
            }
            $activity->quantityQuantity?->delete();
            $activity->dailyAmountQuantity?->delete();

            return (bool) $activity->delete();
        });
    }

    public function findOwned(int $carePlanId, int $id): CarePlanActivity
    {
        return CarePlanActivity::whereCarePlanId($carePlanId)->with([...self::FHIR_RELATIONS, 'author.party', 'carePlan.person'])->findOrFail($id);
    }

    public function findOwnedByUuid(int $carePlanId, ?string $uuid): CarePlanActivity
    {
        if (!$uuid) {
            throw (new ModelNotFoundException())->setModel(CarePlanActivity::class);
        }

        return CarePlanActivity::whereCarePlanId($carePlanId)->whereUuid($uuid)->with(self::FHIR_RELATIONS)->firstOrFail();
    }

    public function findForPatient(string $uuid, int $personId, ?int $legalEntityId = null): ?CarePlanActivity
    {
        $activity = CarePlanActivity::whereUuid($uuid)->whereHas('carePlan', function ($query) use ($personId, $legalEntityId): void {
            $query->wherePersonId($personId);
            if ($legalEntityId !== null) {
                $query->whereLegalEntityId($legalEntityId);
            }
        })->with(self::FHIR_RELATIONS)->first();

        return $activity;
    }

    public function loadActivities(CarePlan $plan): void
    {
        $plan->loadMissing('activities');
    }

    public function submissionStates(CarePlanActivity $activity): array
    {
        $states = [];
        $records = collect([$activity])->concat($this->linkedDocuments([$activity->uuid], $activity->resolvedKind()));
        foreach ($records as $record) {
            if (!$record instanceof SubmittedToEHealth) {
                continue;
            }
            $state = $this->submissionState($record);
            if ($state['id'] === null) {
                continue;
            }
            $states[$record->uuid] = $state + [
                'document' => $record instanceof CarePlanActivity ? __('care-plan.prescriptions') : __('care-plan.document_type_service_referral'),
                'number' => $record instanceof CarePlanActivity ? null : $record->requestNumber,
            ];
        }

        return $states;
    }

    public function linkedDocuments(array $activityUuids, string $kind, bool $forDisplay = false): Collection
    {
        $model = match ($kind) {
            'medication_request' => MedicationRequestRequest::class,
            'service_request' => ServiceRequestRequest::class,
            'device_request' => DeviceRequestRequest::class,
            default => throw new InvalidArgumentException('Unsupported linked document kind.'),
        };
        $relations = $kind === 'medication_request' ? ['basedOn.type.coding'] : ['employee', 'basedOn.type.coding', 'category', 'priority'];

        $records = $model::with($relations)->whereHas('basedOn', fn ($query) => $query->whereIn('value', $activityUuids))->get();

        return $forDisplay && $kind === 'medication_request'
            ? MedicalEventsRepository::medicationRequest()->carePlanDocumentsForDisplay($records)
            : $records;
    }

    /**
     * @param  callable(int): float  $issuedSum
     */
    public function assertCanIssue(int $activityId, float $qty, callable $issuedSum): CarePlanActivity
    {
        return $this->storageTransaction(function () use ($activityId, $qty, $issuedSum): CarePlanActivity {
            $activity = CarePlanActivity::with(self::FHIR_RELATIONS)
                ->whereKey($activityId)
                ->lockForUpdate()
                ->firstOrFail();

            $cap = $activity->quantity;
            if ($cap === null || $cap === '') {
                return $activity;
            }

            $issued = max(0.0, (float) $issuedSum($activity->id));
            $remaining = max(0.0, (float) $cap - $issued);

            if ($qty > $remaining + 0.0001) {
                throw new InvalidArgumentException(__('care-plan.activity_issue_exceeds_remaining', [
                    'remaining' => $remaining,
                ]));
            }

            return $activity->unsetRelations();
        });
    }

    public const CLOSED_DOCUMENT_STATUSES = [
        'completed',
        'cancelled',
        'canceled',
        'rejected',
        'expired',
        'entered-in-error',
        'entered_in_error',
        'recalled',
        'revoked',
        'stopped',
    ];

    public const PLAN_COMPLETE_FINAL_ACTIVITY_STATUSES = [
        'completed',
        'cancelled',
        'canceled',
        'entered-in-error',
        'entered_in_error',
    ];
    public function findOpenDocumentsForActivity(CarePlanActivity $activity): array
    {
        $open = [];

        foreach ($this->openMedicationDocuments($activity) as $row) {
            $open[] = $row;
        }

        foreach ($this->openServiceDocuments($activity) as $row) {
            $open[] = $row;
        }

        foreach ($this->openDeviceDocuments($activity) as $row) {
            $open[] = $row;
        }

        return $open;
    }

    public function hasOpenDocumentsForActivity(CarePlanActivity $activity): bool
    {
        return $this->findOpenDocumentsForActivity($activity) !== [];
    }

    public function findActivitiesBlockingPlanCancel(CarePlan $carePlan): SupportCollection
    {
        Repository::carePlanActivity()->loadActivities($carePlan);

        return $carePlan->activities
            ->filter(function (CarePlanActivity $activity): bool {
                $status = strtolower((string) $activity->status);

                return in_array($status, ['scheduled', 'in_progress', 'completed'], true) || !$this->isFinalActivityStatus($status);
            })
            ->values();
    }

    public function isFinalActivityStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::PLAN_COMPLETE_FINAL_ACTIVITY_STATUSES, true);
    }

    private function openMedicationDocuments(CarePlanActivity $activity): array
    {
        return Repository::carePlanActivity()->linkedDocuments([$activity->uuid], 'medication_request')
            ->filter(fn (MedicationRequestRequest $row): bool => $this->isOpenDocumentStatus((string) $row->status))
            ->map(fn (MedicationRequestRequest $row): array => [
                'type' => $this->medicationDocumentType((string) $row->status),
                'status' => strtolower((string) $row->status),
                'uuid' => $row->uuid,
            ])
            ->values()
            ->all();
    }

    private function openServiceDocuments(CarePlanActivity $activity): array
    {
        return Repository::carePlanActivity()->linkedDocuments([$activity->uuid], 'service_request')
            ->filter(fn (ServiceRequestRequest $row): bool => $this->isOpenDocumentStatus((string) $row->status))
            ->map(fn (ServiceRequestRequest $row): array => [
                'type' => 'service_request',
                'status' => strtolower((string) $row->status),
                'uuid' => $row->uuid,
            ])
            ->values()
            ->all();
    }

    private function openDeviceDocuments(CarePlanActivity $activity): array
    {
        return Repository::carePlanActivity()->linkedDocuments([$activity->uuid], 'device_request')
            ->filter(fn (DeviceRequestRequest $row): bool => $this->isOpenDocumentStatus((string) $row->status))
            ->map(fn (DeviceRequestRequest $row): array => [
                'type' => 'device_request',
                'status' => strtolower((string) $row->status),
                'uuid' => $row->uuid,
            ])
            ->values()
            ->all();
    }

    private function isOpenDocumentStatus(string $status): bool
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '') {
            return true;
        }

        return !in_array($normalized, self::CLOSED_DOCUMENT_STATUSES, true);
    }

    private function medicationDocumentType(string $status): string
    {
        $normalized = strtolower($status);

        // Local MRR before sign; after sign the same table holds ACTIVE MR.
        if (in_array($normalized, ['new', 'draft', 'signed'], true)) {
            return 'medication_request_request';
        }

        return 'medication_request';
    }

    public function findActivitiesBlockingPlanComplete(CarePlan $carePlan): SupportCollection
    {
        $this->loadActivities($carePlan);

        return $carePlan->activities
            ->filter(fn (CarePlanActivity $activity): bool => !$this->isFinalActivityStatus((string) $activity->status))
            ->values();
    }

}
