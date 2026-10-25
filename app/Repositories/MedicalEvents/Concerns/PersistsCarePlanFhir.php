<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents\Concerns;

use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Quantity;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

trait PersistsCarePlanFhir
{
    private function replaceFhirConcept(?CodeableConcept $existing, ?array $data): ?int
    {
        return $data === null ? null : Repository::codeableConcept()->replaceComplete($existing, $data)->id;
    }

    private function replaceFhirReference(?Identifier $existing, ?array $data): ?int
    {
        return $data === null ? null : Repository::identifier()->replaceReference($existing, $data)->id;
    }

    /** Synchronize owned primitives by position so retries do not allocate duplicate rows. */
    private function syncFhirCollection(Model $owner, string $name, array $items, bool $concepts = false, bool $positioned = false): void
    {
        $relation = $owner->{$name}();
        $existing = $relation->get();
        $ids = [];
        foreach (array_values($items) as $position => $data) {
            $id = $concepts
                ? $this->replaceFhirConcept($existing->get($position), $data)
                : $this->replaceFhirReference($existing->get($position), $data);
            if ($positioned) {
                $ids[$id] = ['position' => $position];
            } else {
                $ids[] = $id;
            }
        }
        $relation->sync($ids);
    }

    private function fhirConcept(?CodeableConcept $concept): ?array
    {
        return $concept === null ? null : Repository::codeableConcept()->toResource($concept);
    }

    private function fhirReference(?Identifier $identifier): ?array
    {
        return $identifier === null ? null : Repository::identifier()->toReference($identifier);
    }

    private function replaceFhirQuantity(?Quantity $existing, ?array $data): ?int
    {
        if ($data === null) {
            return null;
        }
        $value = $data['value'] ?? null;
        if (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) >= 1e14 || ((float) $value !== 0.0 && abs((float) $value) < 1e-10)) {
            throw new InvalidArgumentException('Quantity is outside the supported numeric range.');
        }
        $decimal = rtrim(str_contains((string) $value, 'e') || str_contains((string) $value, 'E') ? sprintf('%.14F', (float) $value) : (string) $value, '0');
        if (str_contains($decimal, '.') && strlen(explode('.', $decimal, 2)[1]) > 10) {
            throw new InvalidArgumentException('Quantity exceeds the supported decimal scale; rounding is not permitted.');
        }
        $quantity = $existing ?? new Quantity();
        $quantity->fill(array_intersect_key($data, array_flip(['value', 'comparator', 'unit', 'system', 'code'])));
        foreach (['comparator', 'unit', 'system', 'code'] as $field) {
            if (!array_key_exists($field, $data)) {
                $quantity->{$field} = null;
            }
        }
        $quantity->save();

        return $quantity->id;
    }

    private function fhirQuantity(?Quantity $quantity): ?array
    {
        if ($quantity === null) {
            return null;
        }
        $data = ['value' => $quantity->value];
        foreach (['comparator', 'unit', 'system', 'code'] as $field) {
            if ($quantity->{$field} !== null) {
                $data[$field] = $quantity->{$field};
            }
        }

        return $data;
    }
}
