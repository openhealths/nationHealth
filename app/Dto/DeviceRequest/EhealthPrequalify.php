<?php

declare(strict_types=1);

namespace App\Dto\DeviceRequest;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\Shared\EhealthReference as EHealthReference;
use App\Mapping\Transforms\MapObject;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

final class EhealthPrequalify
{
    use PreservesEhealthDocumentValues;
    #[Map(source: 'program_id', transform: [[self::class, 'sourceObject'], new MapObject(Ehealth::class)])]
    public Ehealth $device_request;

    #[Map(source: 'program_id', if: [self::class, 'hasProgram'], transform: [[self::class, 'programRows'], new MapCollection(targetClass: EHealthReference::class)])]
    public ?array $programs = null;

    public static function sourceObject(mixed $value, object $source): object
    {
        return $source;
    }

    public static function hasProgram(mixed $value): bool
    {
        return $value !== null;
    }

    public static function programRows(string $value): array
    {
        return [(object) ['type' => 'medical_program', 'uuid' => $value]];
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        $data['device_request'] = $this->device_request->toArray();

        return $data;
    }
}
