<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

use Symfony\Component\ObjectMapper\Attribute\Map;

final class EhealthDosage
{
    public int|string $sequence;

    #[Map(source: 'data[text?]', transform: [self::class, 'mapText'])]
    public string $text;

    #[Map(source: 'data[patient_instruction?]', transform: [self::class, 'mapPatientInstruction'])]
    public string $patient_instruction;

    #[Map(source: 'data[as_needed_boolean?]', transform: 'boolval')]
    public bool $as_needed_boolean;

    #[Map(source: 'data[route?]', transform: [self::class, 'mapRoute'])]
    public ?array $route = null;

    #[Map(source: 'data[dose_and_rate?]', transform: [self::class, 'mapDose'])]
    public ?array $dose_and_rate = null;

    #[Map(source: 'data[max_dose_per_administration?]', transform: [self::class, 'mapMaximum'])]
    public ?array $max_dose_per_administration = null;

    #[Map(source: 'data[max_dose_per_period?]', transform: [self::class, 'mapPeriodMaximum'])]
    public ?array $max_dose_per_period = null;

    public static function mapText(mixed $value): string
    {
        return !empty($value) ? $value : 'За призначенням лікаря';
    }

    public static function mapPatientInstruction(mixed $value, object $source): string
    {
        return !empty($value) ? $value : self::mapText($source->data['text'] ?? null);
    }

    public static function mapRoute(mixed $value): ?array
    {
        if (empty($value)) {
            return null;
        }
        $code = strtolower((string) $value) === 'oral' ? '26643006' : (string) $value;

        return self::concept('eHealth/SNOMED/route_codes', $code);
    }

    public static function mapDose(mixed $value): ?array
    {
        if (empty($value)) {
            return null;
        }
        $dose = is_array($value[0] ?? null) ? $value[0] : $value;
        if (!isset($dose['dose_quantity_value'])) {
            return null;
        }

        return [
            'type' => self::concept('eHealth/dose_and_rate', 'ordered'),
            'dose_quantity' => [
                'value' => (float) $dose['dose_quantity_value'], 'unit' => $dose['dose_quantity_unit'] ?? null,
                'system' => 'eHealth/ucum/units', 'code' => $dose['dose_quantity_code'] ?? ($dose['dose_quantity_unit'] ?? null),
            ],
        ];
    }

    public static function mapMaximum(mixed $value, object $source): ?array
    {
        if ($value === null) {
            return null;
        }
        $unit = $source->data['dose_and_rate'][0]['dose_quantity_unit'] ?? 'од.';

        return ['value' => (float) $value, 'unit' => $unit, 'system' => 'eHealth/ucum/units', 'code' => $unit];
    }

    public static function mapPeriodMaximum(mixed $value, object $source): ?array
    {
        $numerator = self::mapMaximum($value, $source);

        return $numerator === null ? null : [
            'numerator' => $numerator,
            'denominator' => ['value' => 1, 'unit' => 'd', 'system' => 'eHealth/ucum/units', 'code' => 'd'],
        ];
    }

    private static function concept(string $system, string $code): array
    {
        return ['coding' => [['system' => $system, 'code' => $code]], 'text' => ''];
    }
}
