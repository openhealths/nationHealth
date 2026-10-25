<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\AuthMethodId;
use App\Mapping\Transforms\FhirIdentifier;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

/** Outgoing request body; the accepted raw draft is signed separately, without remapping. */
final class Ehealth
{
    use PreservesEhealthDocumentValues;

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    #[Map(source: 'uuids[person_uuid]')]
    public string $person_id;

    #[Map(source: 'uuids[employee_uuid]')]
    public string $employee_id;

    #[Map(source: 'uuids[division_uuid?]')]
    public ?string $division_id = null;

    #[Map(source: 'data[created_at?]', transform: [self::class, 'mapCreatedDate'])]
    public string $created_at;

    #[Map(source: 'data[started_at?]', transform: [self::class, 'mapDate'])]
    public ?string $started_at = null;

    #[Map(source: 'data[ended_at?]', transform: [self::class, 'mapDate'])]
    public ?string $ended_at = null;

    #[Map(source: 'data[medication_id]')]
    public string $medication_id;

    #[Map(source: 'data[medication_qty]', transform: 'floatval')]
    public float $medication_qty;

    #[Map(source: 'data[intent?]', transform: [self::class, 'mapIntent'])]
    public string $intent;

    #[Map(source: 'data[category?]', transform: [self::class, 'mapCategory'])]
    public string $category;

    #[Map(source: 'data[medication_program_id?]', transform: [self::class, 'mapOptional'])]
    public mixed $medical_program_id = null;

    #[Map(source: 'data[based_on_uuid?]', transform: [[self::class, 'basedOnRows'], new MapCollection(targetClass: EhealthReference::class), [self::class, 'nonempty']])]
    public ?array $based_on = null;

    #[Map(source: 'uuids[encounter_uuid?]', transform: [self::class, 'mapEncounter'])]
    public ?array $context = null;

    #[Map(source: 'data[dosage_instructions?]', transform: [[self::class, 'instructionRows'], new MapCollection(targetClass: EhealthDosage::class), [self::class, 'nonempty']])]
    public ?array $dosage_instruction = null;

    #[Map(source: 'data[inform_with?]', transform: [AuthMethodId::class, 'extract'])]
    public ?string $inform_with = null;

    #[Map(source: 'data[container_dosage?]', transform: [self::class, 'mapContainer'])]
    public mixed $container_dosage = null;

    #[Map(source: 'data[note?]', transform: [self::class, 'mapOptional'])]
    public mixed $note = null;

    public static function source(array $data, array $uuids, CarbonImmutable $mappedAt, ?string $carePlanUuid = null): object
    {
        return (object) ['data' => $data, 'uuids' => $uuids, 'mappedAt' => $mappedAt, 'carePlanUuid' => $carePlanUuid];
    }

    public static function basedOnRows(mixed $value, object $source): array
    {
        return !empty($source->carePlanUuid) && !empty($value) ? [
            (object) ['type' => 'care_plan', 'uuid' => $source->carePlanUuid],
            (object) ['type' => 'activity', 'uuid' => $value],
        ] : [];
    }

    public static function instructionRows(?array $value): array
    {
        $rows = [];
        foreach ($value ?? [] as $index => $instruction) {
            $rows[] = (object) ['data' => $instruction, 'sequence' => $instruction['sequence'] ?? ($index + 1)];
        }

        return $rows;
    }

    public static function nonempty(array $value): ?array
    {
        return $value ?: null;
    }

    public static function mapCreatedDate(mixed $value, object $source): string
    {
        return self::mapDate($value) ?? $source->mappedAt->format('Y-m-d');
    }

    public static function mapDate(mixed $value): ?string
    {
        return !empty($value) ? CarbonImmutable::parse($value)->format('Y-m-d') : null;
    }

    public static function mapIntent(mixed $value): string
    {
        return $value ?? 'order';
    }

    public static function mapCategory(mixed $value): string
    {
        return $value ?? 'community';
    }

    public static function mapOptional(mixed $value): mixed
    {
        return !empty($value) ? $value : null;
    }

    public static function mapEncounter(mixed $value, object $source): ?array
    {
        return $value ? ['identifier' => (new FhirIdentifier('encounter', includeText: true))($value, $source, null)] : null;
    }

    public static function mapContainer(mixed $value): mixed
    {
        if (empty($value)) {
            return null;
        }
        if (is_string($value) && str_contains($value, '|')) {
            [$amount, $unit, $code] = array_pad(explode('|', $value), 3, '');

            return ['system' => 'MEDICATION_UNIT', 'code' => $code ?: ($unit ?: 'PIECE'), 'value' => (float) $amount];
        }

        return $value;
    }
}
