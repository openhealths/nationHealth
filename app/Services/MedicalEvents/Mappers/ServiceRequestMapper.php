<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Core\Arr;
use App\Services\MedicalEvents\FhirResource;
use App\Services\MedicalEvents\InformWith;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ServiceRequestMapper implements FhirMapperContract
{
    private const CATEGORY_SYSTEM = 'eHealth/SNOMED/service_request_categories';

    public const CREATE_FIELDS = ['id', 'status', 'intent', 'priority', 'basedOn', 'category', 'code', 'program', 'context', 'requesterEmployee', 'requesterLegalEntity', 'reasonReference', 'supportingInfo', 'note', 'patientInstruction', 'permittedResources', 'performer', 'locationReference', 'performerType', 'quantity', 'specimens', 'focus', 'informWith', 'occurrencePeriod', 'occurrenceDateTime'];

    /** Pure form projection over the original ESOZ resource; no generated identity or clock. */
    public function toFhir(array $data, mixed ...$context): array
    {
        $input = Arr::toCamelCase($data);
        $options = Arr::toCamelCase($context[0] ?? []);
        $resource = $options['resource'] ?? [];
        if (array_key_exists('uuid', $input)) {
            $this->set($resource, 'id', $input['uuid']);
        }
        if (array_key_exists('status', $input)) {
            $this->set($resource, 'status', $input['status']);
        }
        if (array_key_exists('intent', $input)) {
            $this->set($resource, 'intent', $input['intent']);
        }
        if (array_key_exists('priority', $input)) {
            $this->set($resource, 'priority', $input['priority']);
        }
        if (array_key_exists('requestNumber', $input)) {
            $this->set($resource, 'requisition', $input['requestNumber']);
        }
        if (array_key_exists('note', $input)) {
            $this->set($resource, 'note', $input['note']);
        }
        if (array_key_exists('patientInstruction', $input)) {
            $this->set($resource, 'patientInstruction', $input['patientInstruction']);
        }
        if (!empty($options['employeeUuid']) && data_get($resource, 'requesterEmployee.identifier.value') !== $options['employeeUuid']) {
            $resource['requesterEmployee'] = FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($options['employeeUuid']);
        }
        if (!empty($options['legalEntityUuid']) && data_get($resource, 'requesterLegalEntity.identifier.value') !== $options['legalEntityUuid']) {
            $resource['requesterLegalEntity'] = FhirResource::make()
                ->coding('eHealth/resources', 'legal_entity')
                ->toIdentifier($options['legalEntityUuid']);
        }
        if (!empty($options['personUuid']) && data_get($resource, 'subject.identifier.value') !== $options['personUuid']) {
            $resource['subject'] = FhirResource::make()
                ->coding('eHealth/resources', 'patient')
                ->toIdentifier($options['personUuid']);
        }
        if (empty($resource['id']) && !($options['allowMissingId'] ?? false)) {
            throw new InvalidArgumentException('A Service Request UUID must be allocated before mapping.');
        }
        $resource['status'] ??= 'draft';
        $resource['intent'] ??= 'order';
        foreach (['code' => ['serviceId', 'service', 'serviceType'], 'program' => ['programId', 'medical_program', null], 'performer' => ['performer', 'legal_entity', null], 'locationReference' => ['locationReference', 'division', null]] as $field => [$key, $defaultType, $typeKey]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (is_array($value)) {
                $resource[$field] = $value;
            } elseif ($value === null || $value === '') {
                unset($resource[$field]);
            } else {
                $existing = $resource[$field] ?? null;
                $type = $typeKey !== null ? ($input[$typeKey] ?? null) : null;
                $sameValue = data_get($existing, 'identifier.value') === (string) $value;
                $type ??= $sameValue ? data_get($existing, 'identifier.type.coding.0.code', $defaultType) : $defaultType;
                $resource[$field] = $this->reference((string) $value, $type, $sameValue ? $existing : null);
            }
        }
        foreach (['category' => self::CATEGORY_SYSTEM, 'performerType' => 'SPECIALITY_TYPE'] as $field => $system) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                if (is_array($value)) {
                    $resource[$field] = $value;
                } elseif ($value === null || $value === '') {
                    unset($resource[$field]);
                } elseif (data_get($resource, "$field.coding.0.code") !== $value) {
                    $resource[$field] = ['coding' => [['system' => $system, 'code' => $value]]];
                }
            }
        }
        if (array_key_exists('contextUuid', $input) || isset($options['encounterUuid']) || isset($options['episodeUuid'])) {
            $value = $input['contextUuid'] ?? ($options['encounterUuid'] ?? ($options['episodeUuid'] ?? null));
            $type = $input['contextType'] ?? (isset($options['encounterUuid']) ? 'encounter' : (isset($options['episodeUuid']) ? 'episode_of_care' : data_get($resource, 'context.identifier.type.coding.0.code')));
            $this->set($resource, 'context', $value ? $this->reference($value, $type, $resource['context'] ?? null) : null);
        }
        if (array_key_exists('basedOnUuid', $input)) {
            $items = $resource['basedOn'] ?? [];
            $previousActivity = collect($items)->first(fn (array $ref): bool => in_array(data_get($ref, 'identifier.type.coding.0.code'), ['activity', 'care_plan_activity'], true));
            $items = array_values(array_filter($items, static fn (array $ref): bool => !in_array(data_get($ref, 'identifier.type.coding.0.code'), ['activity', 'care_plan_activity'], true)));
            if (!empty($input['basedOnUuid'])) {
                $old = collect($resource['basedOn'] ?? [])->first(fn (array $ref): bool => data_get($ref, 'identifier.value') === $input['basedOnUuid']);
                $items[] = $this->reference($input['basedOnUuid'], 'activity', $old);
            }
            if (data_get($previousActivity, 'identifier.value') !== ($input['basedOnUuid'] ?: null)) {
                $resource['basedOn'] = $items;
            }
        }
        $previousCarePlan = collect($resource['basedOn'] ?? [])->first(fn (array $ref): bool => data_get($ref, 'identifier.type.coding.0.code') === 'care_plan');
        if (array_key_exists('carePlanUuid', $options) && data_get($previousCarePlan, 'identifier.value') !== $options['carePlanUuid']) {
            $items = array_values(array_filter($resource['basedOn'] ?? [], static fn (array $ref): bool => data_get($ref, 'identifier.type.coding.0.code') !== 'care_plan'));
            if (!empty($options['carePlanUuid'])) {
                array_unshift($items, $this->reference($options['carePlanUuid'], 'care_plan', $previousCarePlan));
            }
            $resource['basedOn'] = $items;
        }
        if (array_key_exists('quantity', $input)) {
            if ($input['quantity'] === null || $input['quantity'] === '') {
                unset($resource['quantity']);
            } elseif (is_array($input['quantity'])) {
                $resource['quantity'] = $input['quantity'];
            } else {
                if (!is_numeric($input['quantity']) || !is_finite((float) $input['quantity'])) {
                    throw new InvalidArgumentException('Quantity must be a finite number.');
                }
                if (!BigDecimal::of((string) $input['quantity'])->isEqualTo((string) (float) $input['quantity'])) {
                    throw new InvalidArgumentException('Quantity cannot be represented without loss in the JSON numeric contract.');
                }
                $resource['quantity'] = array_replace($resource['quantity'] ?? [], ['value' => (float) $input['quantity']]);
                $resource['quantity']['system'] ??= 'SERVICE_UNIT';
                $resource['quantity']['code'] ??= 'PIECE';
            }
        }
        foreach (['system', 'code', 'unit', 'comparator'] as $key) {
            if (array_key_exists('quantity'.ucfirst($key), $input) && isset($resource['quantity'])) {
                $this->set($resource['quantity'], $key, $input['quantity'.ucfirst($key)]);
            }
        }
        foreach (['startedAt' => 'start', 'endedAt' => 'end'] as $source => $target) {
            if (!array_key_exists($source, $input)) {
                continue;
            }
            $value = $input[$source];
            if ($value === null || $value === '') {
                if (isset($resource['occurrencePeriod'])) {
                    unset($resource['occurrencePeriod'][$target]);
                }
            } else {
                $existing = $resource['occurrencePeriod'][$target] ?? null;
                if ($existing !== $value) {
                    $resource['occurrencePeriod'][$target] = $this->dateTime((string) $value, $options['timezone'] ?? 'UTC');
                }
            }
        }
        foreach (['supportingInfo', 'reasonReference', 'permittedResources', 'specimens', 'focus', 'basedOn'] as $field) {
            if (array_key_exists($field, $input)) {
                $items = $input[$field];
                $items = is_string($items) ? json_decode($items, true, flags: JSON_THROW_ON_ERROR) : ($items ?? []);
                $resource[$field] = array_map(function (array $item) use ($resource, $field): array {
                    if (isset($item['identifier'])) {
                        return $item;
                    }
                    $old = collect($resource[$field] ?? [])->first(fn (array $ref): bool => data_get($ref, 'identifier.value') === ($item['uuid'] ?? null));

                    return $this->reference($item['uuid'] ?? '', isset($item['type']) ? (strtoupper($item['type']) === $item['type'] ? strtolower($item['type']) : Str::snake($item['type'])) : null, $old);
                }, $items);
            }
        }
        if (array_key_exists('informWith', $input)) {
            $id = is_array($input['informWith']) ? ($input['informWith']['authMethodId'] ?? InformWith::authMethodId($input['informWith'])) : InformWith::authMethodId($input['informWith']);
            $this->set($resource, 'informWith', $id === null ? null : ['authMethodId' => $id]);
        }
        foreach (['occurrenceDateTime', 'occurrencePeriod'] as $field) {
            if (array_key_exists($field, $input)) {
                $this->set($resource, $field, $input[$field]);
            }
        }
        if (!empty($resource['occurrencePeriod']) && !empty($resource['occurrenceDateTime'])) {
            throw new InvalidArgumentException('Only one occurrence variant is allowed.');
        }

        return $resource;
    }

    public function normalize(array $resource): array
    {
        $resource = Arr::toCamelCase($resource);
        if (isset($resource['category']) && array_is_list($resource['category']) && count($resource['category']) === 1) {
            $resource['category'] = $resource['category'][0];
        }
        if (isset($resource['quantityInteger']) && !isset($resource['quantity'])) {
            $resource['quantity'] = ['value' => $resource['quantityInteger'], 'system' => 'SERVICE_UNIT', 'code' => 'PIECE'];
            unset($resource['quantityInteger']);
        }
        if (isset($resource['code']['coding'][0]['code']) && !isset($resource['code']['identifier'])) {
            $resource['code'] = $this->reference((string) $resource['code']['coding'][0]['code'], 'service');
        }
        if (isset($resource['note']) && is_array($resource['note'])) {
            $resource['note'] = implode("\n", array_column($resource['note'], 'text'));
        }
        if (isset($resource['uuid']) && !isset($resource['id'])) {
            $resource['id'] = $resource['uuid'];
            unset($resource['uuid']);
        }

        if (isset($resource['requestNumber']) && !isset($resource['requisition'])) {
            $resource['requisition'] = $resource['requestNumber'];
            unset($resource['requestNumber']);
        }

        return $resource;
    }

    public function toPrequalifyPayload(array $data, array $uuids, ?string $carePlanUuid = null, ?string $activityUuid = null): array
    {
        if ($activityUuid !== null) {
            $data['based_on_uuid'] = $activityUuid;
        }
        $resource = $this->toFhir($data, $uuids + ['allowMissingId' => true, 'carePlanUuid' => $carePlanUuid]);
        $content = $this->createContent($resource);
        unset($content['id'], $content['program']);

        return ['service_request' => $content, 'programs' => isset($resource['program']) ? [Arr::toSnakeCase($resource['program'])] : []];
    }

    /** Compatibility for callers that still inspect the prequalify-shaped wrapper. */
    public function toCreateSignedPayload(array $data, array $uuids, ?string $carePlanUuid = null, ?string $activityUuid = null): array
    {
        $payload = $this->toPrequalifyPayload($data, $uuids, $carePlanUuid, $activityUuid);
        if (empty($data['uuid'])) {
            throw new InvalidArgumentException('A Service Request UUID must be allocated before signing.');
        }
        $payload['service_request']['id'] = $data['uuid'];

        return $payload;
    }

    public function toCreateSignedContent(array $data, array $uuids, ?string $carePlanUuid = null, ?string $activityUuid = null): array
    {
        if ($activityUuid !== null) {
            $data['based_on_uuid'] = $activityUuid;
        }

        return $this->createContent($this->toFhir($data, $uuids + ['carePlanUuid' => $carePlanUuid]));
    }

    public function createContent(array $resource): array
    {
        $content = array_intersect_key($resource, array_flip(self::CREATE_FIELDS));
        $content['status'] = 'active';
        $content['intent'] ??= 'order';
        $content['priority'] ??= 'routine';
        // Hospitalization also needs a specialty; optional performer references must survive signing.

        return Arr::toSnakeCase(array_filter($content, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []));
    }

    public function fromFhir(array $data, mixed ...$context): array
    {
        $resource = $this->normalize($data);
        $activity = collect($resource['basedOn'] ?? [])->first(fn (array $ref): bool => in_array(data_get($ref, 'identifier.type.coding.0.code'), ['activity', 'care_plan_activity'], true));
        $result = [];
        foreach (['uuid' => 'id', 'status' => 'status', 'request_number' => 'requisition', 'started_at' => 'occurrencePeriod.start', 'ended_at' => 'occurrencePeriod.end', 'service_id' => 'code.identifier.value', 'service_type' => 'code.identifier.type.coding.0.code', 'quantity' => 'quantity.value', 'quantity_system' => 'quantity.system', 'quantity_code' => 'quantity.code', 'quantity_unit' => 'quantity.unit', 'quantity_comparator' => 'quantity.comparator', 'program_id' => 'program.identifier.value', 'intent' => 'intent', 'category' => 'category.coding.0.code', 'context_uuid' => 'context.identifier.value', 'context_type' => 'context.identifier.type.coding.0.code', 'priority' => 'priority', 'note' => 'note', 'patient_instruction' => 'patientInstruction', 'inform_with' => 'informWith', 'performer' => 'performer.identifier.value', 'location_reference' => 'locationReference.identifier.value', 'performer_type' => 'performerType.coding.0.code'] as $key => $path) {
            $result[$key] = data_get($resource, $path);
        }
        $result['based_on_uuid'] = data_get($activity, 'identifier.value');
        foreach (['supportingInfo' => 'supporting_info', 'reasonReference' => 'reason_reference', 'permittedResources' => 'permitted_resources', 'specimens' => 'specimens', 'focus' => 'focus'] as $field => $key) {
            $result[$key] = array_map(static fn (array $ref): array => ['uuid' => data_get($ref, 'identifier.value'), 'type' => data_get($ref, 'identifier.type.coding.0.code')], $resource[$field] ?? []);
        }

        return $result;
    }

    private function reference(string $value, ?string $type, ?array $original = null): array
    {
        if ($value === '' || $type === null || $type === '') {
            throw new InvalidArgumentException('A reference requires an explicit resource type and value.');
        }
        if (data_get($original, 'identifier.value') === $value && data_get($original, 'identifier.type.coding.0.code') === $type) {
            return $original;
        }

        return FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($value);
    }

    private function dateTime(string $value, string $timezone): string
    {
        return CarbonImmutable::parse($value, $timezone)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function set(array &$resource, string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            unset($resource[$field]);
        } else {
            $resource[$field] = $value;
        }
    }
}
