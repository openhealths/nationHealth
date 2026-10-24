<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Core\Arr;
use App\Enums\CarePlanActivityKind;
use App\Services\MedicalEvents\FhirResource;
use InvalidArgumentException;

class CarePlanActivityMapper implements FhirMapperContract
{
    /**
     * @param  array<string, mixed>  $data  Validated form fields; dates are already ISO dateTime values.
     * @param  mixed  ...$context  [0] array{id?: string, employeeUuid?: string, carePlanUuid?: string, productType?: string, resource?: array}
     * @return array<string, mixed>
     */
    public function toFhir(array $data, mixed ...$context): array
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
            $resource['author'] = array_map(fn (mixed $author): array => $this->reference($author, 'employee'), $authors);
        } elseif (!isset($resource['author']) && isset($options['employeeUuid'])) {
            $resource['author'] = [$this->reference($options['employeeUuid'], 'employee')];
        }
        if (array_key_exists('carePlan', $data) || isset($options['carePlanUuid'])) {
            $resource['carePlan'] = $this->reference($data['carePlan'] ?? $options['carePlanUuid'], 'care_plan');
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
                $detail[$field] = $this->concept($data[$field], $system);
            }
        }
        foreach (['reasonCode' => 'eHealth/ICD10_AM/condition_codes', 'goal' => 'eHealth/care_plan_activity_goals'] as $field => $system) {
            if (array_key_exists($field, $data)) {
                $values = $data[$field] === null || $data[$field] === '' ? [] : (is_array($data[$field]) && array_is_list($data[$field]) ? $data[$field] : [$data[$field]]);
                $detail[$field] = array_map(fn (mixed $value): array => $this->concept($value, $system), $values);
            }
        }

        foreach (['productReference' => $options['productType'] ?? match ($kind) {
            CarePlanActivityKind::MEDICATION_REQUEST => 'medication',
            CarePlanActivityKind::DEVICE_REQUEST => 'device_definition',
            CarePlanActivityKind::SERVICE_REQUEST => 'service',
        }, 'location' => 'division', 'performer' => 'employee', 'program' => 'medical_program'] as $field => $type) {
            if (array_key_exists($field, $data)) {
                $detail[$field] = $data[$field] === null || $data[$field] === '' ? null : $this->reference($data[$field], $type);
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
            $detail['reasonReference'] = array_map(fn (mixed $value): array => $this->reference($value, existing: $existing), $data['reasonReference'] ?? []);
        }
        if (array_key_exists('outcomeReference', $data)) {
            $resource['outcomeReference'] = array_map(fn (mixed $value): array => $this->reference($value), $data['outcomeReference'] ?? []);
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

    /** @return array<string, mixed> */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $detail = $data['detail'];
        $form = array_intersect_key($detail, array_flip(['kind', 'status', 'doNotPerform', 'description', 'reasonCode', 'goal', 'reasonReference', 'scheduledPeriod', 'scheduledTiming', 'scheduledString', 'remainingQuantityType', 'statusReason', 'productCodeableConcept']));
        $form['uuid'] = $data['id'];
        $form['author'] = $data['author'] ?? [];
        $form['carePlan'] = $data['carePlan'] ?? null;
        foreach (['productReference', 'location', 'performer', 'program'] as $field) {
            if (array_key_exists($field, $detail)) {
                $form[$field] = $detail[$field];
            }
        }
        foreach (['quantity', 'dailyAmount', 'remainingQuantity'] as $field) {
            if (array_key_exists($field, $detail)) {
                $form[$field] = $detail[$field];
            }
        }
        foreach (['outcomeReference', 'outcomeCodeableConcept'] as $field) {
            $form[$field] = $data[$field] ?? [];
        }

        return $form;
    }

    private function concept(mixed $value, ?string $system): ?array
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

    private function reference(mixed $value, ?string $type = null, array $existing = []): array
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
        if ($type === null || !is_string($value) || $value === '') {
            throw new InvalidArgumentException('An activity reference requires its resource type and UUID.');
        }
        foreach ($existing as $reference) {
            if (data_get($reference, 'identifier.value') === $value && data_get($reference, 'identifier.type.coding.0.code') === $type) {
                return $reference;
            }
        }

        return FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($value);
    }
}
