<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Mapping\Transforms\FhirIdentifier;
use Symfony\Component\ObjectMapper\Attribute\Map;

final class EhealthProcess
{
    #[Map(source: 'employeeUuid', transform: [self::class, 'mapEmployee'])]
    public array $used_by_employee;

    #[Map(source: 'divisionUuid', transform: [self::class, 'mapDivision'])]
    public ?array $used_by_division = null;

    #[Map(source: 'legalEntityUuid', transform: [self::class, 'mapLegalEntity'])]
    public ?array $used_by_legal_entity = null;

    #[Map(source: 'programId', transform: [self::class, 'mapProgram'])]
    public ?array $program = null;

    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn (mixed $value): bool => $value !== null);
    }

    public static function mapEmployee(mixed $value, object $source): array
    {
        return self::reference($value, 'employee', $source);
    }

    public static function mapDivision(mixed $value, object $source): ?array
    {
        return $value ? self::reference($value, 'division', $source) : null;
    }

    public static function mapLegalEntity(mixed $value, object $source): ?array
    {
        return $value ? self::reference($value, 'legal_entity', $source) : null;
    }

    public static function mapProgram(mixed $value, object $source): ?array
    {
        return $value ? self::reference($value, 'medical_program', $source) : null;
    }

    private static function reference(mixed $value, string $type, object $source): array
    {
        return ['identifier' => (new FhirIdentifier($type))($value, $source, null)];
    }
}
