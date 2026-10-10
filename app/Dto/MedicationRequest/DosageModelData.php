<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

use Symfony\Component\ObjectMapper\Attribute\Map;

/** A preloaded local dosage row to the existing repository/outbound representation. */
final class DosageModelData
{
    #[Map(source: '[sequence?]', transform: [self::class, 'mapSequence'])]
    public int|string $sequence;

    #[Map(source: '[text?]', transform: [EhealthDosage::class, 'mapText'])]
    public string $text;

    #[Map(source: '[patient_instruction?]', transform: [self::class, 'mapPatientInstruction'])]
    public string $patient_instruction;

    #[Map(source: '[as_needed_boolean?]', transform: 'boolval')]
    public bool $as_needed_boolean;

    #[Map(source: '[route?]', transform: [self::class, 'mapRoute'])]
    public string $route;

    #[Map(source: '[method?]')]
    public ?string $method = null;

    #[Map(source: '[timing?]', transform: [self::class, 'mapTiming'])]
    public mixed $timing = null;

    #[Map(source: '[dose_and_rate?]', transform: [self::class, 'mapDose'])]
    public mixed $dose_and_rate;

    #[Map(source: '[max_dose_per_administration?]', transform: [self::class, 'mapMaximum'])]
    public ?float $max_dose_per_administration = null;

    #[Map(source: '[max_dose_per_period?]', transform: [self::class, 'mapMaximum'])]
    public ?float $max_dose_per_period = null;

    #[Map(source: '[max_dose_per_lifetime?]', transform: [self::class, 'mapMaximum'])]
    public ?float $max_dose_per_lifetime = null;

    public static function mapSequence(mixed $value): int|string
    {
        return $value ?? 1;
    }

    public static function mapPatientInstruction(mixed $value, object $source): string
    {
        return !empty($value) ? $value : EhealthDosage::mapText($source['text'] ?? null);
    }

    public static function mapRoute(mixed $value): string
    {
        return $value ?? 'oral';
    }

    public static function mapTiming(mixed $value): mixed
    {
        return !empty($value) && is_string($value) ? json_decode($value, true) : ($value ?: null);
    }

    public static function mapDose(mixed $value): mixed
    {
        return !empty($value) && is_string($value) ? json_decode($value, true) : ($value ?: []);
    }

    public static function mapMaximum(mixed $value): ?float
    {
        return $value !== null ? (float) $value : null;
    }
}
