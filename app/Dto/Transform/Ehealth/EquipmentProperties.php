<?php

declare(strict_types=1);

namespace App\Dto\Transform\Ehealth;

/**
 * Equipment properties transform for #[Map].
 * Converts form values into the format expected by the eHealth API.
 */
class EquipmentProperties
{
    /**
     * Build equipment properties with snake_case keys,
     * excluding null and empty string values.
     *
     * @param  array|null  $properties
     * @return array|null
     */
    public static function map(?array $properties): ?array
    {
        if ($properties === null || $properties === []) {
            return null;
        }

        return collect($properties)
            ->map(static fn (array $property): array => array_filter(
                [
                    'type' => $property['type'],
                    'value_integer' => $property['valueInteger'] ?? null,
                    'value_decimal' => $property['valueDecimal'] ?? null,
                    'value_boolean' => $property['valueBoolean'] ?? null,
                    'value_string' => $property['valueString'] ?? null
                ],
                static fn (mixed $value): bool => $value !== null && $value !== ''
            ))
            ->values()
            ->all();
    }
}