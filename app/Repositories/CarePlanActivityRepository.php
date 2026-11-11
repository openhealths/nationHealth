<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\SubmittedToEHealth;
use App\Core\Arr;
use App\Enums\CarePlanActivityKind;
use App\Enums\CarePlanStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;

use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;

use App\Models\MedicalEvents\Sql\ServiceRequestRequest;

use App\Repositories\MedicalEvents\BaseRepository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CarePlanActivityRepository extends BaseRepository
{
    public function __construct(CarePlanActivity $model)
    {
        parent::__construct($model);
    }

    public const array FHIR_RELATIONS = ['carePlan', 'carePlanReference.type.coding', 'authorReferences.type.coding', 'productConcept.coding', 'statusReasonConcept.coding', 'productReferenceIdentifier.type.coding', 'locationIdentifier.type.coding', 'performerIdentifier.type.coding', 'programIdentifier.type.coding', 'quantityQuantity', 'dailyAmountQuantity', 'remainingQuantityQuantity', 'scheduledPeriod', 'reasonConcepts.coding', 'goalConcepts.coding', 'outcomeConcepts.coding', 'reasonReferences.type.coding', 'outcomeReferences.type.coding'];

    public function saveForm(array $data, CarePlan $plan, ?int $localId = null): CarePlanActivity
    {
        return $this->storageTransaction(function () use ($data, $plan, $localId): CarePlanActivity {
            if ($localId !== null) {
                $record = CarePlanActivity::whereKey($localId)->lockForUpdate()->firstOrFail();
                if (Repository::carePlan()->hasPendingSubmission($record)) {
                    throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
                }
            }

            $data = Arr::toCamelCase($data);
            if (isset($data['kind'])) {
                $data['kind'] = CarePlanActivityKind::fromInput($data['kind'])->value;
            }
            $original = $localId === null ? [] : $this->getResource($localId);
            if ((isset($original['detail']['scheduledTiming']) || isset($original['detail']['scheduledString'])) && empty($data['scheduledPeriodStart']) && empty($data['scheduledPeriodEnd'])) {
                unset($data['scheduledPeriodStart'], $data['scheduledPeriodEnd']);
            }
            $data['uuid'] = $data['uuid'] ?? ($original['id'] ?? Str::uuid()->toString());
            if (isset($data['authorId'])) {
                $data['author'] = Employee::whereKey($data['authorId'])->value('uuid');
                if ($data['author'] === data_get($original, 'author.0.identifier.value')) {
                    unset($data['author']);
                }
            }
            foreach (['productReference', 'program'] as $field) {
                if (is_string($data[$field] ?? null) && $data[$field] === data_get($original, 'detail.'.$field.'.identifier.value')) {
                    $data[$field] = $original['detail'][$field];
                }
            }
            foreach (['reasonCode', 'goal'] as $field) {
                $value = $data[$field] ?? null;
                if (is_array($value) && array_is_list($value)) {
                    foreach ($value as $index => $item) {
                        if (is_string($item) && $item === data_get($original, 'detail.'.$field.'.'.$index.'.coding.0.code')) {
                            $value[$index] = $original['detail'][$field][$index];
                        }
                    }
                    $data[$field] = $value;
                }
                $code = is_array($value) && array_is_list($value) ? ($value[0] ?? null) : $value;
                if (is_string($value) && $code !== '' && $code === data_get($original, 'detail.'.$field.'.0.coding.0.code')) {
                    unset($data[$field]);
                } elseif (is_string($value) && $code !== '' && count($original['detail'][$field] ?? []) > 1) {
                    $system = $field === 'goal' ? 'eHealth/care_plan_activity_goals' : 'eHealth/ICD10_AM/condition_codes';
                    $concept = FhirResource::make()->coding($system, $code)->toCodeableConcept();
                    $data[$field] = [$concept, ...array_slice($original['detail'][$field], 1)];
                }
            }
            if (is_string($data['productCodeableConcept'] ?? null) && $data['productCodeableConcept'] === data_get($original, 'detail.productCodeableConcept.coding.0.code')) {
                unset($data['productCodeableConcept']);
            }
            if (is_string($data['statusReason'] ?? null)) {
                if ($data['statusReason'] === data_get($original, 'detail.statusReason.coding.0.code')) {
                    unset($data['statusReason']);
                } else {
                    throw new InvalidArgumentException('A status reason requires a complete CodeableConcept.');
                }
            }
            if (isset($data['scheduledPeriodStart']) || array_key_exists('scheduledPeriodEnd', $data)) {
                $existingPeriod = data_get($original, 'detail.scheduledPeriod', []);
                $planPeriod = Repository::carePlan()->getResource($plan)['period'];
                foreach (['start', 'end'] as $boundary) {
                    $field = 'scheduledPeriod'.ucfirst($boundary);
                    if (!array_key_exists($field, $data)) {
                        continue;
                    }
                    $date = $data[$field];
                    $existing = $existingPeriod[$boundary] ?? null;
                    if ($date === null || $date === '') {
                        $existingPeriod[$boundary] = null;
                    } elseif ($existing !== null && CarbonImmutable::parse($existing)->setTimezone(config('app.timezone'))->toDateString() === CarbonImmutable::parse($date)->toDateString()) {
                        $existingPeriod[$boundary] = $existing;
                    } else {
                        $parsed = CarbonImmutable::parse($date, config('app.timezone'));
                        $instant = ($boundary === 'end' ? $parsed->setTime(23, 59, 59) : $parsed->startOfDay())->utc();
                        $bound = isset($planPeriod[$boundary]) ? CarbonImmutable::parse($planPeriod[$boundary]) : null;
                        if ($bound && (($boundary === 'start' && $instant->lessThan($bound)) || ($boundary === 'end' && $instant->greaterThan($bound)))) {
                            if ($parsed->toDateString() !== $bound->setTimezone(config('app.timezone'))->toDateString()) {
                                throw new InvalidArgumentException('Activity schedule exceeds its Care Plan period.');
                            }
                            $instant = $bound;
                        }
                        $existingPeriod[$boundary] = $instant->utc()->toIso8601ZuluString();
                    }
                }
                $data['scheduledPeriod'] = $existingPeriod;
            }
            foreach (['goal', 'reasonReference'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] === null) {
                    $data[$field] = [];
                }
            }
            $options = ['resource' => $original, 'carePlanUuid' => $plan->uuid];
            if (!empty($data['productReferenceType'])) {
                if (!in_array($data['productReferenceType'], ['service', 'service_group', 'medication', 'device_definition'], true)) {
                    throw new InvalidArgumentException('Unsupported product reference type.');
                }
                $options['productType'] = $data['productReferenceType'];
            }
            $resource = Fhir::carePlanActivity()->toFhir($data, $options);

            return $this->presentForUi($this->storeResource($resource, $plan, $localId));
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

    public function presentForUi(CarePlanActivity $activity): CarePlanActivity
    {
        if ($activity->detailsSyncedAt === null) {
            return $activity;
        }

        $resource = $this->getResource($activity);
        $detail = $resource['detail'];
        $projection = [
            'productReference' => data_get($detail, 'productReference.identifier.value'),
            'productReferenceType' => data_get($detail, 'productReference.identifier.type.coding.0.code'),
            'productCodeableConcept' => data_get($detail, 'productCodeableConcept.coding.0.code'),
            'program' => data_get($detail, 'program.identifier.value'),
            'reasonCode' => data_get($detail, 'reasonCode.0.coding.0.code'),
            'goal' => array_map(static fn (array $concept): string => $concept['coding'][0]['code'], $detail['goal'] ?? []),
            'reasonReference' => array_map(static function (array $reference): string {
                $type = $reference['identifier']['type']['coding'][0]['code'];
                $type = match ($type) {
                    'condition' => 'Condition', 'observation' => 'Observation', 'diagnostic_report' => 'DiagnosticReport', default => $type
                };

                return $type.'/'.$reference['identifier']['value'];
            }, $detail['reasonReference'] ?? []),
            'scheduledPeriodStart' => data_get($detail, 'scheduledPeriod.start'),
            'scheduledPeriodEnd' => data_get($detail, 'scheduledPeriod.end'),
            'statusReason' => data_get($detail, 'statusReason.coding.0.code'),
        ];
        foreach (['quantity', 'dailyAmount', 'remainingQuantity'] as $field) {
            $projection[$field] = data_get($detail, $field.'.value');
            $projection[$field.'System'] = data_get($detail, $field.'.system');
            $projection[$field.'Code'] = data_get($detail, $field.'.code');
        }
        $activity->forceFill($projection)->syncOriginal();

        return $activity;
    }

    public function storeResources(array $resources, CarePlan $plan): void
    {
        $this->storageTransaction(function () use ($resources, $plan): void {
            foreach ($resources as $resource) {
                $this->storeResource($resource, $plan);
            }
        });
    }

    /** Only drafts can be reconstructed locally; registered activities require a full remote import. */
    public function legacyDraftResource(CarePlanActivity $activity, CarePlan $plan): array
    {
        $activity->loadMissing(['author', 'kindConcept.coding', 'quantityQuantity', 'dailyAmountQuantity', 'scheduledPeriod', 'productReferenceIdentifier.type.coding', 'productConcept.coding']);
        $data = Arr::toCamelCase($activity->getAttributes());
        $data['uuid'] = $activity->uuid ?? Str::uuid()->toString();
        unset($data['remainingQuantity'], $data['remainingQuantityType'], $data['remainingQuantitySystem'], $data['remainingQuantityCode']);
        $data['author'] = $activity->author?->uuid;
        $data['kind'] = CarePlanActivityKind::fromInput($activity->resolvedKind())->value;
        if (!$data['author']) {
            throw new InvalidArgumentException('Draft activity author cannot be resolved.');
        }
        $data['reasonReference'] = $activity->reasonReference ?? [];
        $data['goal'] = $activity->goal ?? [];
        if ($activity->productReferenceIdentifier && $activity->productReferenceIdentifier->type->isNotEmpty()) {
            $data['productReference'] = MedicalEventsRepository::identifier()->toReference($activity->productReferenceIdentifier);
        } elseif ($activity->productReference && $data['kind'] === CarePlanActivityKind::SERVICE_REQUEST->value) {
            throw new InvalidArgumentException('Untyped service product cannot distinguish a service from a service group.');
        }
        if ($activity->productConcept) {
            $data['productCodeableConcept'] = MedicalEventsRepository::codeableConcept()->toResource($activity->productConcept);
        }
        foreach (['quantity' => 'quantityQuantity', 'dailyAmount' => 'dailyAmountQuantity'] as $field => $relation) {
            if ($activity->{$relation}) {
                $data[$field] = MedicalEventsRepository::quantity()->toResource($activity->{$relation});
            }
        }
        if ($activity->scheduledPeriod) {
            $data['scheduledPeriod'] = MedicalEventsRepository::period()->toResource($activity->scheduledPeriod);
        } elseif ($activity->scheduledPeriodStart !== null) {
            $data['scheduledPeriod'] = ['start' => CarbonImmutable::parse($activity->getRawOriginal('scheduled_period_start'), config('app.timezone'))->startOfDay()->utc()->toIso8601ZuluString()];
            if ($activity->scheduledPeriodEnd !== null) {
                $data['scheduledPeriod']['end'] = CarbonImmutable::parse($activity->getRawOriginal('scheduled_period_end'), config('app.timezone'))->setTime(23, 59, 59)->utc()->toIso8601ZuluString();
            }
        }
        if ($activity->outcomeReference || $activity->outcomeCodeableConcept || $activity->statusReason) {
            throw new InvalidArgumentException('Legacy outcome or status reason requires explicit reconciliation.');
        }
        unset($data['outcomeReference'], $data['outcomeCodeableConcept'], $data['statusReason']);

        return Fhir::carePlanActivity()->toFhir($data, ['carePlanUuid' => $plan->uuid ?? 'preflight-plan']);
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

        return $activity ? $this->presentForUi($activity) : null;
    }

    public function getByCarePlanId(int $carePlanId)
    {
        return CarePlanActivity::whereCarePlanId($carePlanId)->with(self::FHIR_RELATIONS)->get()->map(fn (CarePlanActivity $activity): CarePlanActivity => $this->presentForUi($activity));
    }

    public function create(array $data): CarePlanActivity
    {
        $input = Arr::toCamelCase($data);
        $plan = CarePlan::findOrFail($input['carePlanId']);

        return $this->saveForm($input, $plan);
    }

    public function update(CarePlanActivity $activity, array $data): bool
    {
        $plan = CarePlan::findOrFail($activity->carePlanId);
        $this->saveForm($data, $plan, $activity->id);

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
        return $this->presentForUi(CarePlanActivity::whereCarePlanId($carePlanId)->with([...self::FHIR_RELATIONS, 'author.party', 'carePlan.person'])->findOrFail($id));
    }

    public function findOwnedByUuid(int $carePlanId, ?string $uuid): CarePlanActivity
    {
        if (!$uuid) {
            throw (new ModelNotFoundException())->setModel(CarePlanActivity::class);
        }

        return $this->presentForUi(CarePlanActivity::whereCarePlanId($carePlanId)->whereUuid($uuid)->with(self::FHIR_RELATIONS)->firstOrFail());
    }

    public function findForPatient(string $uuid, int $personId, ?int $legalEntityId = null): ?CarePlanActivity
    {
        $activity = CarePlanActivity::whereUuid($uuid)->whereHas('carePlan', function ($query) use ($personId, $legalEntityId): void {
            $query->wherePersonId($personId);
            if ($legalEntityId !== null) {
                $query->whereLegalEntityId($legalEntityId);
            }
        })->with(self::FHIR_RELATIONS)->first();

        return $activity ? $this->presentForUi($activity) : null;
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

            $activity = $this->presentForUi($activity);
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
}
