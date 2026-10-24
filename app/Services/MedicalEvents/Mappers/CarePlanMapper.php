<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Core\Arr;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class CarePlanMapper implements FhirMapperContract
{
    /**
     * Context contains id, employeeUuid, encounterUuid, timezone, and the original resource.
     * UUID allocation and validation belong to the caller. Unedited fields survive a form round trip.
     *
     * @param  array<string, mixed>  $data
     * @param  mixed  ...$context  [0] array<string, mixed>
     * @return array<string, mixed>
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        $data = Arr::toCamelCase($data);
        $options = $context[0] ?? [];
        $resource = $options['resource'] ?? [];
        $resource['id'] = $data['uuid'] ?? $options['id'] ?? $resource['id'] ?? null;

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
                    $resource[$field] = $this->concept($data[$field], $system);
                }
            }
        }

        foreach (['author' => 'employee', 'encounter' => 'encounter', 'careManager' => 'employee', 'managingOrganization' => 'legal_entity'] as $field => $type) {
            $value = $data[$field] ?? $options[$field.'Uuid'] ?? null;
            if ($field === 'author') {
                $value ??= $options['employeeUuid'] ?? null;
            }
            if ($value !== null && $value !== '') {
                if (!is_string($value) || $value !== data_get($resource, $field.'.identifier.value')) {
                    $resource[$field] = $this->reference($value, $type);
                }
            }
        }

        foreach (['addresses', 'supportingInfo', 'contributor', 'basedOn', 'partOf'] as $field) {
            if (array_key_exists($field, $data)) {
                $resource[$field] = $data[$field];
            } elseif (array_key_exists($field, $options) && !array_key_exists($field, $resource)) {
                $resource[$field] = $options[$field];
            }
        }

        if (array_key_exists('coAuthors', $data)) {
            $existing = $resource['contributor'] ?? [];
            $resource['contributor'] = array_map(fn (mixed $value): array => $this->existingReference($value, 'employee', $existing), $data['coAuthors']);
        }

        if (array_key_exists('episodes', $data)) {
            $otherInfo = array_values(array_filter($resource['supportingInfo'] ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') !== 'episode_of_care'));
            $existing = $resource['supportingInfo'] ?? [];
            $episodes = array_map(fn (mixed $episode): array => $this->existingReference(is_array($episode) && !isset($episode['identifier']) ? ($episode['uuid'] ?? $episode['id'] ?? '') : $episode, 'episode_of_care', $existing), $data['episodes']);
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
                    $resource['period'][$boundary] = $this->dateTime($data[$field], $data[$field.'Time'] ?? null, $options['timezone'] ?? 'UTC', $resource['period'][$boundary] ?? null, $boundary === 'end', $options['dateFormat'] ?? 'Y-m-d');
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

    /** @return array<string, mixed> */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $options = $context[0] ?? [];
        $form = array_intersect_key($data, array_flip(['title', 'description', 'note', 'intent', 'status', 'requisition', 'addresses', 'supportingInfo', 'basedOn', 'partOf', 'context']));
        $form['uuid'] = $data['id'];
        foreach (['category', 'termsOfService'] as $field) {
            $form[$field] = data_get($data, $field.'.coding.0.code', '');
        }
        foreach (['author', 'encounter', 'careManager', 'managingOrganization'] as $field) {
            $form[$field] = data_get($data, $field.'.identifier.value', '');
        }
        $form['coAuthors'] = array_map(static fn (array $reference): string => $reference['identifier']['value'], $data['contributor'] ?? []);
        $form['episodes'] = [];
        $form['medicalRecords'] = [];
        foreach ($data['supportingInfo'] ?? [] as $reference) {
            if (data_get($reference, 'identifier.type.coding.0.code') === 'episode_of_care') {
                $form['episodes'][] = ['uuid' => $reference['identifier']['value']];
            } else {
                $form['medicalRecords'][] = $reference;
            }
        }
        foreach (['start', 'end'] as $boundary) {
            $field = 'period'.ucfirst($boundary);
            $value = data_get($data, 'period.'.$boundary);
            $date = $value ? CarbonImmutable::parse($value)->setTimezone($options['timezone'] ?? 'UTC') : null;
            $form[$field] = $date?->format($options['dateFormat'] ?? 'Y-m-d') ?? '';
            $form[$field.'Time'] = $date?->format('H:i:s') ?? '';
        }
        if (array_key_exists('informWith', $data)) {
            $form['informWith'] = data_get($data, 'informWith.authMethodId', '');
        }

        return $form;
    }

    private function concept(mixed $value, string $system): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_array($value) ? $value : FhirResource::make()->coding($system, (string) $value)->toCodeableConcept();
    }

    private function reference(mixed $value, string $type): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('A reference requires a resource UUID.');
        }

        return FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($value);
    }

    private function existingReference(mixed $value, string $type, array $existing): array
    {
        foreach ($existing as $reference) {
            if (is_string($value) && data_get($reference, 'identifier.value') === $value && data_get($reference, 'identifier.type.coding.0.code') === $type) {
                return $reference;
            }
        }

        return $this->reference($value, $type);
    }

    private function dateTime(?string $date, ?string $time, string $timezone, ?string $original, bool $end, string $dateFormat): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $parsed = CarbonImmutable::hasFormat($date, $dateFormat)
            ? CarbonImmutable::createFromFormat('!'.$dateFormat, $date, $timezone)
            : CarbonImmutable::parse($date, $timezone);
        if ($original !== null) {
            $previous = CarbonImmutable::parse($original)->setTimezone($timezone);
            if ($previous->toDateString() === $parsed->toDateString() && ($time === null || $time === '' || $previous->format(strlen($time) === 5 ? 'H:i' : 'H:i:s') === $time)) {
                return $original;
            }
        }
        if (!str_contains($date, 'T') && !preg_match('/\d{2}:\d{2}/', $date)) {
            $parsed = $parsed->setTimeFromTimeString($time ?: ($end ? '23:59:59' : '00:00:00'));
        }

        return $parsed->utc()->toIso8601ZuluString();
    }
}
