<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Arr;
use App\Enums\CarePlanActivityKind;
use App\Enums\CarePlanStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Throwable;

class BackfillCarePlanFhir extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'care-plans:backfill-fhir {--legal-entity= : Required local legal entity ID} {--write : Apply conversion after a successful preflight}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect or convert legacy Care Plan drafts; registered resources require a full ESOZ sync';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $legalEntityId = filter_var($this->option('legal-entity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($legalEntityId === false) {
            $this->error('Specify a positive local --legal-entity ID.');

            return self::INVALID;
        }
        $report = $this->backfillResources($legalEntityId, (bool) $this->option('write'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $report['errors'] === [] && $report['needsRemoteSync'] === [] ? self::SUCCESS : self::FAILURE;
    }
    public function backfillResources(int $legalEntityId, bool $write = false): array
    {
        $report = ['plans' => 0, 'activities' => 0, 'converted' => 0, 'needsRemoteSync' => [], 'errors' => []];
        CarePlan::whereLegalEntityId($legalEntityId)->with('activities')->chunkById(100, function ($plans) use (&$report): void {
            foreach ($plans as $plan) {
                $report['plans']++;
                $report['activities'] += $plan->activities->count();
                if (CarePlanActivity::whereCarePlanId($plan->id)->whereNotNull('uuid')->select('uuid')->groupBy('uuid')->havingRaw('COUNT(*) > 1')->exists()) {
                    $report['errors'][] = ['type' => 'duplicateActivity', 'localId' => $plan->id, 'reason' => 'Duplicate activity UUIDs must be reconciled before schema migration.'];
                }
                if ($plan->detailsSyncedAt === null && $plan->uuid !== null && $plan->status !== CarePlanStatus::DRAFT->value) {
                    $report['needsRemoteSync'][] = ['type' => 'carePlan', 'localId' => $plan->id];
                    continue;
                }
                try {
                    if ($plan->detailsSyncedAt === null) {
                        $this->legacyDraftResource($plan);
                    }
                    foreach ($plan->activities as $activity) {
                        if ($activity->detailsSyncedAt !== null) {
                            continue;
                        }
                        if ($activity->uuid !== null && $activity->status !== 'draft') {
                            $report['needsRemoteSync'][] = ['type' => 'activity', 'localId' => $activity->id];
                        } else {
                            $this->legacyActivityDraftResource($activity, $plan);
                        }
                    }
                } catch (Throwable $exception) {
                    $report['errors'][] = ['type' => 'carePlan', 'localId' => $plan->id, 'reason' => $exception->getMessage()];
                }
            }
        });
        if (CarePlan::whereLegalEntityId($legalEntityId)->whereNotNull('uuid')->select('person_id', 'uuid')->groupBy('person_id', 'uuid')->havingRaw('COUNT(*) > 1')->exists()) {
            $report['errors'][] = ['type' => 'duplicateCarePlan', 'reason' => 'Duplicate resource UUIDs must be reconciled before schema migration.'];
        }
        if (!$write || $report['errors'] !== [] || $report['needsRemoteSync'] !== []) {
            return $report;
        }
        CarePlan::whereLegalEntityId($legalEntityId)->chunkById(100, function ($plans) use (&$report, $legalEntityId): void {
            foreach ($plans as $plan) {
                DB::transaction(function () use ($plan, $legalEntityId, &$report): void {
                    $plan = CarePlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                    if ($plan->detailsSyncedAt === null) {
                        $plan = Repository::carePlan()->storeResource($this->legacyDraftResource($plan), $plan->personId, $legalEntityId, $plan->id);
                        $report['converted']++;
                    }
                    $repository = Repository::carePlanActivity();
                    foreach ($plan->activities()->get() as $activity) {
                        if ($activity->detailsSyncedAt === null) {
                            $repository->storeResource($this->legacyActivityDraftResource($activity, $plan), $plan, $activity->id);
                            $report['converted']++;
                        }
                    }
                });
            }
        });

        return $report;
    }

    private function legacyDraftResource(CarePlan $plan): array
    {
        $plan->loadMissing(['author', 'encounter', 'effectivePeriod', 'categoryConcept.coding', 'basedOn.type.coding', 'partOf.type.coding', 'encounterIdentifier.type.coding', 'supportingInfoReferences.type.coding']);
        $data = Arr::toCamelCase($plan->getAttributes());
        $data['uuid'] = $plan->uuid ?? Uuid::uuid1()->toString();
        $data['category'] = $plan->categoryConcept ? MedicalEventsRepository::codeableConcept()->toResource($plan->categoryConcept) : $plan->category;
        $data['author'] = $plan->author?->uuid;
        if (!$data['author']) {
            throw new InvalidArgumentException('Draft author cannot be resolved.');
        }
        $data['addresses'] = $plan->addresses ?? [];
        $data['basedOn'] = MedicalEventsRepository::identifier()->toReference($plan->basedOn);
        $data['partOf'] = MedicalEventsRepository::identifier()->toReference($plan->partOf);
        $data['supportingInfo'] = $plan->supportingInfoReferences->map(fn ($reference): array => MedicalEventsRepository::identifier()->toReference($reference))->all();
        if ($data['supportingInfo'] === [] && !empty($plan->supportingInfo)) {
            throw new InvalidArgumentException('Legacy supporting info requires explicit reconciliation.');
        }
        if ($plan->encounterIdentifier) {
            $data['encounter'] = MedicalEventsRepository::identifier()->toReference($plan->encounterIdentifier);
        } elseif ($plan->encounter?->uuid) {
            $data['encounter'] = $plan->encounter->uuid;
        } elseif ($plan->encounterId !== null) {
            throw new InvalidArgumentException('Draft encounter cannot be resolved.');
        }
        if ($plan->effectivePeriod) {
            $data['period'] = MedicalEventsRepository::period()->toResource($plan->effectivePeriod);
        }

        return $this->carePlanResourceFromFields($data, ['timezone' => config('app.timezone')]);
    }

    protected function legacyActivityDraftResource(CarePlanActivity $activity, CarePlan $plan): array
    {
        $activity->loadMissing(['author', 'kindConcept.coding', 'quantityQuantity', 'dailyAmountQuantity', 'scheduledPeriod', 'productReferenceIdentifier.type.coding', 'productConcept.coding']);
        $data = Arr::toCamelCase($activity->getAttributes());
        $data['uuid'] = $activity->uuid ?? Uuid::uuid1()->toString();
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

        return $this->carePlanActivityResourceFromFields($data, ['carePlanUuid' => $plan->uuid ?? 'preflight-plan']);
    }

    protected function carePlanResourceFromFields(array $data, mixed ...$context): array
    {
        $data = Arr::toCamelCase($data);
        $options = $context[0] ?? [];
        $resource = $options['resource'] ?? [];
        $resource['id'] = $data['uuid'] ?? ($options['id'] ?? ($resource['id'] ?? null));

        if (!is_string($resource['id']) || $resource['id'] === '') {
            throw new InvalidArgumentException('Care Plan mapping requires an allocated resource UUID.');
        }

        foreach (['title', 'description', 'note', 'intent', 'status', 'requisition', 'context', 'statusReason'] as $field) {
            if (array_key_exists($field, $data)) {
                $resource[$field] = $data[$field];
            }
        }

        foreach (['category' => 'eHealth/care_plan_categories', 'termsOfService' => 'PROVIDING_CONDITION'] as $field => $system) {
            if (array_key_exists($field, $data)) {
                if (!is_string($data[$field]) || $data[$field] !== data_get($resource, $field.'.coding.0.code')) {
                    $resource[$field] = $this->carePlanConcept($data[$field], $system);
                }
            }
        }

        if (array_key_exists('author', $data) || !array_key_exists('author', $resource)) {
            $value = $data['author'] ?? ($options['authorUuid'] ?? ($options['employeeUuid'] ?? null));
            if ($value === null || $value === '') {
                unset($resource['author']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'author.identifier.value')) {
                $resource['author'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($value);
            }
        }
        if (array_key_exists('encounter', $data) || !array_key_exists('encounter', $resource)) {
            $value = $data['encounter'] ?? ($options['encounterUuid'] ?? null);
            if ($value === null || $value === '') {
                unset($resource['encounter']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'encounter.identifier.value')) {
                $resource['encounter'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'encounter')
                    ->toIdentifier($value);
            }
        }
        if (array_key_exists('careManager', $data) || !array_key_exists('careManager', $resource)) {
            $value = $data['careManager'] ?? ($options['careManagerUuid'] ?? null);
            if ($value === null || $value === '') {
                unset($resource['careManager']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'careManager.identifier.value')) {
                $resource['careManager'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($value);
            }
        }
        if (array_key_exists('managingOrganization', $data) || !array_key_exists('managingOrganization', $resource)) {
            $value = $data['managingOrganization'] ?? ($options['managingOrganizationUuid'] ?? null);
            if ($value === null || $value === '') {
                unset($resource['managingOrganization']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'managingOrganization.identifier.value')) {
                $resource['managingOrganization'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'legal_entity')
                    ->toIdentifier($value);
            }
        }

        foreach (['addresses', 'supportingInfo', 'contributor'] as $field) {
            if (array_key_exists($field, $data)) {
                $resource[$field] = $data[$field];
            } elseif (array_key_exists($field, $options) && !array_key_exists($field, $resource)) {
                $resource[$field] = $options[$field];
            }
        }

        foreach (['basedOn', 'partOf'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($value === null || $value === '') {
                unset($resource[$field]);
            } else {
                // Preserve the original display metadata when an unchanged link is reopened.
                $resource[$field] = $this->carePlanExistingReference($value, 'care_plan', isset($resource[$field]) ? [$resource[$field]] : []);
            }
        }

        if (array_key_exists('coAuthors', $data)) {
            $existing = $resource['contributor'] ?? [];
            $resource['contributor'] = array_map(fn (mixed $value): array => $this->carePlanExistingReference($value, 'employee', $existing), $data['coAuthors']);
        }

        if (array_key_exists('episodes', $data)) {
            $otherInfo = array_values(array_filter($resource['supportingInfo'] ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') !== 'episode_of_care'));
            $existing = $resource['supportingInfo'] ?? [];
            $episodes = array_map(fn (mixed $episode): array => $this->carePlanExistingReference(is_array($episode) && !isset($episode['identifier']) ? ($episode['uuid'] ?? ($episode['id'] ?? '')) : $episode, 'episode_of_care', $existing), $data['episodes']);
            $resource['supportingInfo'] = [...$otherInfo, ...$episodes];
        }

        if (array_key_exists('medicalRecords', $data)) {
            $episodes = array_values(array_filter($resource['supportingInfo'] ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') === 'episode_of_care'));
            $resource['supportingInfo'] = [...$episodes, ...$data['medicalRecords']];
        }

        if (array_key_exists('period', $data)) {
            $resource['period'] = $data['period'];
        } else {
            foreach (['start', 'end'] as $boundary) {
                $field = 'period'.ucfirst($boundary);
                if (array_key_exists($field, $data)) {
                    $resource['period'][$boundary] = $this->carePlanDateTime($data[$field], $data[$field.'Time'] ?? null, $options['timezone'] ?? 'UTC', $resource['period'][$boundary] ?? null, $boundary === 'end', $options['dateFormat'] ?? 'Y-m-d');
                }
            }
        }

        if (array_key_exists('informWith', $data)) {
            $resource['informWith'] = is_array($data['informWith']) || $data['informWith'] === null
                ? $data['informWith']
                : ($data['informWith'] === '' ? null : ['authMethodId' => $data['informWith']]);
        }

        return $resource;
    }

    protected function carePlanConcept(mixed $value, string $system): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_array($value) ? $value : FhirResource::make()->coding($system, (string) $value)->toCodeableConcept();
    }

    protected function carePlanReference(mixed $value, string $type): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('A reference requires a resource UUID.');
        }

        return FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($value);
    }

    protected function carePlanExistingReference(mixed $value, string $type, array $existing): array
    {
        foreach ($existing as $reference) {
            if (is_string($value) && data_get($reference, 'identifier.value') === $value && data_get($reference, 'identifier.type.coding.0.code') === $type) {
                return $reference;
            }
        }

        return $this->carePlanReference($value, $type);
    }

    protected function carePlanDateTime(?string $date, ?string $time, string $timezone, ?string $original, bool $end, string $dateFormat): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $hasTime = str_contains($date, 'T') || preg_match('/\d{2}:\d{2}/', $date) === 1;
        $parsed = CarbonImmutable::hasFormat($date, $dateFormat)
            ? CarbonImmutable::createFromFormat('!'.$dateFormat, $date, $timezone)
            : CarbonImmutable::parse($date, $timezone);
        if ($original !== null) {
            $previous = CarbonImmutable::parse($original)->setTimezone($timezone);
            if (($hasTime && $previous->equalTo($parsed)) || (!$hasTime && $previous->toDateString() === $parsed->toDateString() && ($time === null || $time === '' || $previous->format(strlen($time) === 5 ? 'H:i' : 'H:i:s') === $time))) {
                return $original;
            }
        }
        if (!$hasTime) {
            $parsed = $parsed->setTimeFromTimeString($time ?: ($end ? '23:59:59' : '00:00:00'));
        }

        return rtrim(rtrim($parsed->utc()->format('Y-m-d\TH:i:s.u'), '0'), '.').'Z';
    }

    protected function carePlanActivityResourceFromFields(array $data, mixed ...$context): array
    {
        $data = Arr::toCamelCase($data);
        $options = $context[0] ?? [];
        $resource = $options['resource'] ?? [];
        $resource['id'] = $data['uuid'] ?? $options['id'] ?? $resource['id'] ?? null;
        if (!is_string($resource['id']) || $resource['id'] === '') {
            throw new InvalidArgumentException('Care Plan Activity mapping requires an allocated resource UUID.');
        }

        if (array_key_exists('author', $data)) {
            $authors = is_array($data['author']) && array_is_list($data['author']) ? $data['author'] : [$data['author']];
            $resource['author'] = array_map(fn (mixed $author): array => $this->carePlanActivityReference($author, 'employee'), $authors);
        } elseif (!isset($resource['author']) && isset($options['employeeUuid'])) {
            $resource['author'] = [$this->carePlanActivityReference($options['employeeUuid'], 'employee')];
        }
        if (array_key_exists('carePlan', $data) || isset($options['carePlanUuid'])) {
            $resource['carePlan'] = $this->carePlanActivityReference($data['carePlan'] ?? $options['carePlanUuid'], 'care_plan');
        }

        $detail = array_replace($resource['detail'] ?? [], $data['detail'] ?? []);
        foreach (['kind', 'status', 'doNotPerform', 'description', 'scheduledTiming', 'scheduledString', 'remainingQuantityType'] as $field) {
            if (array_key_exists($field, $data)) {
                $detail[$field] = $data[$field];
            }
        }
        $kind = CarePlanActivityKind::tryFrom($detail['kind'] ?? '')
            ?? throw new InvalidArgumentException('An activity requires a supported kind.');

        foreach (['productCodeableConcept' => 'device_definition_classification_type', 'statusReason' => null] as $field => $system) {
            if (array_key_exists($field, $data)) {
                $detail[$field] = $this->carePlanActivityConcept($data[$field], $system);
            }
        }
        foreach (['reasonCode' => 'eHealth/ICD10_AM/condition_codes', 'goal' => 'eHealth/care_plan_activity_goals'] as $field => $system) {
            if (array_key_exists($field, $data)) {
                $values = $data[$field] === null || $data[$field] === '' ? [] : (is_array($data[$field]) && array_is_list($data[$field]) ? $data[$field] : [$data[$field]]);
                $detail[$field] = array_map(fn (mixed $value): array => $this->carePlanActivityConcept($value, $system), $values);
            }
        }

        foreach (['productReference' => $options['productType'] ?? match ($kind) {
            CarePlanActivityKind::MEDICATION_REQUEST => 'medication',
            CarePlanActivityKind::DEVICE_REQUEST => 'device_definition',
            CarePlanActivityKind::SERVICE_REQUEST => 'service',
        }, 'location' => 'division', 'performer' => 'employee', 'program' => 'medical_program'] as $field => $type) {
            if (array_key_exists($field, $data)) {
                $detail[$field] = $data[$field] === null || $data[$field] === '' ? null : $this->carePlanActivityReference($data[$field], $type);
            }
        }
        if (isset($detail['productReference'], $detail['productCodeableConcept'])) {
            throw new InvalidArgumentException('An activity cannot contain both product variants.');
        }

        foreach (['quantity', 'dailyAmount', 'remainingQuantity'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $detail[$field] = is_array($value) || $value === null ? $value : ($value === '' ? null : array_replace($detail[$field] ?? [], ['value' => is_numeric($value) ? $value + 0 : $value]));
                if (is_array($detail[$field])) {
                    foreach (['system', 'code', 'unit', 'comparator'] as $part) {
                        if (array_key_exists($field.ucfirst($part), $data)) {
                            $detail[$field][$part] = $data[$field.ucfirst($part)];
                        }
                    }
                }
            }
        }
        if (array_key_exists('reasonReference', $data)) {
            $existing = $detail['reasonReference'] ?? [];
            $detail['reasonReference'] = array_map(fn (mixed $value): array => $this->carePlanActivityReference($value, existing: $existing), $data['reasonReference'] ?? []);
        }
        if (array_key_exists('outcomeReference', $data)) {
            $resource['outcomeReference'] = array_map(fn (mixed $value): array => $this->carePlanActivityReference($value), $data['outcomeReference'] ?? []);
        }
        if (array_key_exists('outcomeCodeableConcept', $data)) {
            $resource['outcomeCodeableConcept'] = $data['outcomeCodeableConcept'] ?? [];
        }
        if (array_key_exists('scheduledPeriod', $data)) {
            $detail['scheduledPeriod'] = $data['scheduledPeriod'];
        } else {
            foreach (['start', 'end'] as $boundary) {
                $field = 'scheduledPeriod'.ucfirst($boundary);
                if (array_key_exists($field, $data)) {
                    $detail['scheduledPeriod'][$boundary] = $data[$field];
                }
            }
        }
        $scheduled = array_filter(['scheduledPeriod', 'scheduledTiming', 'scheduledString'], static fn (string $field): bool => isset($detail[$field]));
        if (count($scheduled) > 1) {
            throw new InvalidArgumentException('An activity must use a single scheduled variant.');
        }
        $resource['detail'] = $detail;

        return $resource;
    }

    protected function carePlanActivityConcept(mixed $value, ?string $system): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if ($system === null) {
            throw new InvalidArgumentException('A status reason requires an explicit coding system.');
        }

        return FhirResource::make()->coding($system, (string) $value)->toCodeableConcept();
    }

    protected function carePlanActivityReference(mixed $value, ?string $type = null, array $existing = []): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && str_contains($value, '/')) {
            [$type, $value] = explode('/', $value, 2);
            $type = match (strtolower($type)) {
                'diagnosticreport' => 'diagnostic_report',
                'clinicalimpression' => 'clinical_impression',
                default => strtolower($type),
            };
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('An activity reference requires its resource type and UUID.');
        }
        $matches = array_values(array_filter(
            $existing,
            static fn (array $reference): bool =>
            data_get($reference, 'identifier.value') === $value
            && ($type === null || data_get($reference, 'identifier.type.coding.0.code') === $type)
        ));
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($type === null) {
            throw new InvalidArgumentException('An activity reference requires an unambiguous existing reference or an explicit resource type.');
        }

        return FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($value);
    }
}
