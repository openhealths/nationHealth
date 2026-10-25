<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Dto\FormCollection;
use App\Dto\CarePlanActivity\Model as ActivityData;
use App\Dto\CarePlanActivity\Form as ActivityFormData;

use App\Core\Arr;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use Illuminate\Support\Facades\DB;
use App\Repositories\MedicalEvents\Concerns\PersistsCarePlanFhir;
use App\Models\Employee\Employee;
use App\Enums\CarePlanActivityKind;
use InvalidArgumentException;
use Illuminate\Support\Str;

class CarePlanActivityRepository
{
    use PersistsCarePlanFhir;

    public const array FHIR_RELATIONS = ['carePlan', 'authorReferences.type.coding', 'productConcept.coding', 'statusReasonConcept.coding', 'productReferenceIdentifier.type.coding', 'locationIdentifier.type.coding', 'performerIdentifier.type.coding', 'programIdentifier.type.coding', 'quantityQuantity', 'dailyAmountQuantity', 'remainingQuantityQuantity', 'scheduledPeriod', 'reasonConcepts.coding', 'goalConcepts.coding', 'outcomeConcepts.coding', 'reasonReferences.type.coding', 'outcomeReferences.type.coding'];

    public function findOpenDocuments(CarePlanActivity $activity): array
    {
        $documents = [];
        foreach (MedicalEventsRepository::medicationRequest()->findOpenForActivity($activity) as $request) {
            $status = strtolower((string) $request->status);
            $documents[] = [
                'type' => in_array($status, ['new', 'draft', 'signed'], true) ? 'medication_request_request' : 'medication_request',
                'status' => $status,
                'uuid' => $request->uuid,
            ];
        }
        foreach ([
            'service_request' => MedicalEventsRepository::serviceRequest(),
            'device_request' => MedicalEventsRepository::deviceRequest(),
        ] as $type => $repository) {
            foreach ($repository->findOpenForActivity($activity) as $request) {
                $documents[] = ['type' => $type, 'status' => strtolower((string) $request->status), 'uuid' => $request->uuid];
            }
        }

        return $documents;
    }

    public function saveForm(array $data, CarePlan $plan, ?int $localId = null): CarePlanActivity
    {
        $data = Arr::toCamelCase($data);
        if (isset($data['kind'])) {
            $data['kind'] = CarePlanActivityKind::fromInput($data['kind'])->value;
        }
        $original = $localId === null ? [] : $this->getResource($localId);
        if ((isset($original['detail']['scheduledTiming']) || isset($original['detail']['scheduledString'])) && empty($data['scheduledPeriodStart']) && empty($data['scheduledPeriodEnd'])) {
            unset($data['scheduledPeriodStart'], $data['scheduledPeriodEnd']);
        }
        $data['uuid'] = $data['uuid'] ?? $original['id'] ?? Str::uuid()->toString();
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
            $code = is_array($value) && array_is_list($value) ? ($value[0] ?? null) : $value;
            if (is_string($code) && $code !== '' && $code === data_get($original, 'detail.'.$field.'.0.coding.0.code')) {
                unset($data[$field]);
            } elseif (is_string($code) && $code !== '' && (!is_array($value) || count($value) === 1) && count($original['detail'][$field] ?? []) > 1) {
                $system = $field === 'goal' ? 'eHealth/care_plan_activity_goals' : 'eHealth/ICD10_AM/condition_codes';
                $concept = \App\Services\MedicalEvents\FhirResource::make()->coding($system, $code)->toCodeableConcept();
                $data[$field] = [$concept, ...array_slice($original['detail'][$field], 1)];
            }
        }
        if (is_string($data['productCodeableConcept'] ?? null) && $data['productCodeableConcept'] === data_get($original, 'detail.productCodeableConcept.coding.0.code')) {
            unset($data['productCodeableConcept']);
        }
        if (is_string($data['statusReason'] ?? null)) {
            throw new InvalidArgumentException('A status reason requires a complete CodeableConcept.');
        }
        if (isset($data['scheduledPeriodStart']) || array_key_exists('scheduledPeriodEnd', $data)) {
            $existingPeriod = data_get($original, 'detail.scheduledPeriod', []);
            $planPeriod = app(CarePlanRepository::class)->getResource($plan)['period'];
            foreach (['start', 'end'] as $boundary) {
                $field = 'scheduledPeriod'.ucfirst($boundary);
                if (!array_key_exists($field, $data)) {
                    continue;
                }
                $date = $data[$field];
                $existing = $existingPeriod[$boundary] ?? null;
                if ($date === null || $date === '') {
                    $existingPeriod[$boundary] = null;
                } elseif ($existing !== null && \Carbon\CarbonImmutable::parse($existing)->setTimezone(config('app.timezone'))->toDateString() === \Carbon\CarbonImmutable::parse($date)->toDateString()) {
                    $existingPeriod[$boundary] = $existing;
                } else {
                    $parsed = \Carbon\CarbonImmutable::parse($date, config('app.timezone'));
                    $instant = ($boundary === 'end' ? $parsed->setTime(23, 59, 59) : $parsed->startOfDay())->utc();
                    $bound = isset($planPeriod[$boundary]) ? \Carbon\CarbonImmutable::parse($planPeriod[$boundary]) : null;
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
        $resource = ActivityData::fromSource(new FormCollection($data), $options)->toArray();

        return $this->presentForUi($this->storeResource($resource, $plan, $localId));
    }

    public function presentForUi(CarePlanActivity $activity): CarePlanActivity
    {
        $resource = $this->getResource($activity);
        $projection = ActivityFormData::fromSource(new FormCollection($resource))->toUiArray();
        $activity->forceFill($projection)->syncOriginal();

        return $activity;
    }

    public function storeResources(array $resources, CarePlan $plan): void
    {
        DB::transaction(function () use ($resources, $plan): void {
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
            $data['productReference'] = $this->fhirReference($activity->productReferenceIdentifier);
        } elseif ($activity->productReference && $data['kind'] === CarePlanActivityKind::SERVICE_REQUEST->value) {
            throw new InvalidArgumentException('Untyped service product cannot distinguish a service from a service group.');
        }
        if ($activity->productConcept) {
            $data['productCodeableConcept'] = $this->fhirConcept($activity->productConcept);
        }
        foreach (['quantity' => 'quantityQuantity', 'dailyAmount' => 'dailyAmountQuantity'] as $field => $relation) {
            if ($activity->{$relation}) {
                $data[$field] = $this->fhirQuantity($activity->{$relation});
            }
        }
        if ($activity->scheduledPeriod) {
            $data['scheduledPeriod'] = MedicalEventsRepository::period()->toResource($activity->scheduledPeriod);
        } elseif ($activity->scheduledPeriodStart !== null) {
            $data['scheduledPeriod'] = ['start' => \Carbon\CarbonImmutable::parse($activity->getRawOriginal('scheduled_period_start'), config('app.timezone'))->startOfDay()->utc()->toIso8601ZuluString()];
            if ($activity->scheduledPeriodEnd !== null) {
                $data['scheduledPeriod']['end'] = \Carbon\CarbonImmutable::parse($activity->getRawOriginal('scheduled_period_end'), config('app.timezone'))->setTime(23, 59, 59)->utc()->toIso8601ZuluString();
            }
        }
        if ($activity->outcomeReference || $activity->outcomeCodeableConcept || $activity->statusReason) {
            throw new InvalidArgumentException('Legacy outcome or status reason requires explicit reconciliation.');
        }
        unset($data['outcomeReference'], $data['outcomeCodeableConcept'], $data['statusReason']);

        return ActivityData::fromSource(new FormCollection($data), ['carePlanUuid' => $plan->uuid ?? 'preflight-plan'])->toArray();
    }

    /** Store a complete activity; remainingQuantity is supplied by the source, never recalculated. */
    public function storeResource(array $resource, CarePlan $plan, ?int $localId = null): CarePlanActivity
    {
        $detail = $resource['detail'] ?? [];
        if (!isset($resource['id'], $detail['status']) || CarePlanActivityKind::tryFrom($detail['kind'] ?? '') === null || data_get($resource, 'carePlan.identifier.value') !== $plan->uuid) {
            throw new InvalidArgumentException('A complete activity requires identity, kind, status and its owning Care Plan reference.');
        }

        return DB::transaction(function () use ($resource, $detail, $plan, $localId): CarePlanActivity {
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
                'fhirVersion' => 1,
            ];
            foreach (['productCodeableConcept' => ['productConcept', 'productCodeableConceptId'], 'statusReason' => ['statusReasonConcept', 'statusReasonConceptId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = $this->replaceFhirConcept($activity->exists ? $activity->{$relation} : null, $detail[$field] ?? null);
            }
            foreach (['productReference' => ['productReferenceIdentifier', 'productReferenceId'], 'location' => ['locationIdentifier', 'locationIdentifierId'], 'performer' => ['performerIdentifier', 'performerIdentifierId'], 'program' => ['programIdentifier', 'programIdentifierId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = $this->replaceFhirReference($activity->exists ? $activity->{$relation} : null, $detail[$field] ?? null);
            }
            foreach (['quantity' => ['quantityQuantity', 'quantityId'], 'dailyAmount' => ['dailyAmountQuantity', 'dailyAmountId'], 'remainingQuantity' => ['remainingQuantityQuantity', 'remainingQuantityId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = $this->replaceFhirQuantity($activity->exists ? $activity->{$relation} : null, $detail[$field] ?? null);
            }
            $knownDetail = ['kind', 'status', 'description', 'doNotPerform', 'remainingQuantityType', 'scheduledTiming', 'scheduledString', 'productCodeableConcept', 'statusReason', 'productReference', 'location', 'performer', 'program', 'quantity', 'dailyAmount', 'remainingQuantity', 'scheduledPeriod', 'reasonCode', 'reasonReference', 'goal'];
            $attributes['fhirMetadata'] = array_diff_key($resource, array_flip(['id', 'author', 'detail', 'outcomeReference', 'outcomeCodeableConcept']));
            $attributes['fhirMetadata']['detail'] = array_diff_key($detail, array_flip($knownDetail));
            $activity->forceFill($attributes)->save();
            MedicalEventsRepository::period()->syncExact($activity, $detail['scheduledPeriod'] ?? null);
            $this->syncFhirCollection($activity, 'authorReferences', $authors, positioned: true);
            $this->syncFhirCollection($activity, 'reasonReferences', $detail['reasonReference'] ?? []);
            $this->syncFhirCollection($activity, 'reasonConcepts', $detail['reasonCode'] ?? [], concepts: true, positioned: true);
            $this->syncFhirCollection($activity, 'goalConcepts', $detail['goal'] ?? [], concepts: true, positioned: true);
            $this->syncFhirCollection($activity, 'outcomeReferences', $resource['outcomeReference'] ?? []);
            $this->syncFhirCollection($activity, 'outcomeConcepts', $resource['outcomeCodeableConcept'] ?? [], concepts: true, positioned: true);

            return $activity;
        });
    }

    public function getResource(int|CarePlanActivity $id): array
    {
        $activity = $id instanceof CarePlanActivity ? $id->loadMissing(self::FHIR_RELATIONS) : CarePlanActivity::with(self::FHIR_RELATIONS)->findOrFail($id);
        if ($activity->fhirVersion !== 1) {
            throw new InvalidArgumentException('Activity must pass the FHIR backfill before use.');
        }
        $resource = $activity->fhirMetadata ?? [];
        $resource['id'] = $activity->uuid;
        $resource['carePlan'] ??= ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']]]]];
        $resource['carePlan']['identifier']['value'] = $activity->carePlan->uuid;
        $resource['author'] = $activity->authorReferences->map(fn ($reference): array => $this->fhirReference($reference))->all();
        $detail = array_replace($resource['detail'] ?? [], [
            'kind' => $activity->kind,
            'status' => $activity->status,
            'description' => $activity->description,
            'doNotPerform' => $activity->doNotPerform,
            'remainingQuantityType' => $activity->remainingQuantityType,
            'scheduledPeriod' => $activity->scheduledPeriod ? MedicalEventsRepository::period()->toResource($activity->scheduledPeriod) : null,
            'scheduledTiming' => $activity->scheduledTiming,
            'scheduledString' => $activity->scheduledString,
        ]);
        foreach (['productCodeableConcept' => 'productConcept', 'statusReason' => 'statusReasonConcept'] as $field => $relation) {
            $detail[$field] = $this->fhirConcept($activity->{$relation});
        }
        foreach (['productReference' => 'productReferenceIdentifier', 'location' => 'locationIdentifier', 'performer' => 'performerIdentifier', 'program' => 'programIdentifier'] as $field => $relation) {
            $detail[$field] = $this->fhirReference($activity->{$relation});
        }
        foreach (['quantity' => 'quantityQuantity', 'dailyAmount' => 'dailyAmountQuantity', 'remainingQuantity' => 'remainingQuantityQuantity'] as $field => $relation) {
            $detail[$field] = $this->fhirQuantity($activity->{$relation});
        }
        foreach (['reasonCode' => 'reasonConcepts', 'goal' => 'goalConcepts'] as $field => $relation) {
            $detail[$field] = $activity->{$relation}->map(fn ($concept): array => $this->fhirConcept($concept))->all();
        }
        $detail['reasonReference'] = $activity->reasonReferences->map(fn ($reference): array => $this->fhirReference($reference))->all();
        $resource['detail'] = array_filter($detail, static fn (mixed $value): bool => $value !== null);
        $resource['outcomeReference'] = $activity->outcomeReferences->map(fn ($reference): array => $this->fhirReference($reference))->all();
        $resource['outcomeCodeableConcept'] = $activity->outcomeConcepts->map(fn ($concept): array => $this->fhirConcept($concept))->all();

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
        return DB::transaction(function () use ($id): bool {
            $activity = CarePlanActivity::whereKey($id)->lockForUpdate()->first();
            if (!$activity || $activity->status !== \App\Enums\CarePlanStatus::DRAFT->value) {
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
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(CarePlanActivity::class);
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

    public function linkedDocuments(array $activityUuids, string $kind): \Illuminate\Database\Eloquent\Collection
    {
        $model = match ($kind) {
            'medication_request' => \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::class,
            'service_request' => \App\Models\MedicalEvents\Sql\ServiceRequestRequest::class,
            'device_request' => \App\Models\MedicalEvents\Sql\DeviceRequestRequest::class,
            default => throw new InvalidArgumentException('Unsupported linked document kind.'),
        };
        $relations = $kind === 'medication_request' ? ['basedOn'] : ['employee', 'basedOn', 'category', 'priority'];

        return $model::with($relations)->whereHas('basedOn', fn ($query) => $query->whereIn('value', $activityUuids))->get();
    }

    /**
     * @param  callable(int): float  $issuedSum
     */
    public function assertCanIssue(int $activityId, float $qty, callable $issuedSum): CarePlanActivity
    {
        return DB::transaction(function () use ($activityId, $qty, $issuedSum): CarePlanActivity {
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
                throw new \InvalidArgumentException(__('care-plan.activity_issue_exceeds_remaining', [
                    'remaining' => $remaining,
                ]));
            }

            return $activity;
        });
    }
}
