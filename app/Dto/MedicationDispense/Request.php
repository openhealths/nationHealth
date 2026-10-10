<?php

declare(strict_types=1);

namespace App\Dto\MedicationDispense;

use App\Mapping\Transforms\FallbackValue;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class Request
{
    #[Map(source: '[id?]', transform: [new FallbackValue('uuid'), [self::class, 'text']])]
    public string $id;

    #[Map(source: '[medication_info?][medication_id?]', transform: [new FallbackValue('medication_id', 'medication.id', 'medication.identifier.value', 'medication_info.id', 'dispense_request.medication_info.id'), [self::class, 'text']])]
    public string $medicationId;

    #[Map(source: '[medical_program_id?]', transform: [new FallbackValue('medical_program.id', 'medical_program.identifier.value', 'program.id'), [self::class, 'text']])]
    public string $programId;

    public static function text(mixed $value): string
    {
        return (string) $value;
    }

    public static function formatNumber(string $number): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));

        return trim((string) preg_replace('/(.{4})/', '$1-', $clean), '-');
    }
}
