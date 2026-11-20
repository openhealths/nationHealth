<?php

declare(strict_types=1);

namespace App\Dto\Equipment;

use App\Core\Arr;
use App\Dto\EhealthMapping;
use App\Livewire\Equipment\Forms\EquipmentForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Equipment request body in the format expected by the eHealth API.
 */
#[Map(source: EquipmentForm::class)]
class Ehealth
{
    use EhealthMapping;

    /** @var list<array{name: string, type: string}> */
    public array $names = [];

    public ?string $type = null;

    public ?string $serialNumber = null;

    public ?string $status = null;

    public ?string $recorder = null;

    public ?string $divisionId = null;

    public ?string $availabilityStatus = null;

    public ?string $inventoryNumber = null;

    public ?string $manufacturer = null;

    #[Map(source: 'manufactureDate', transform: [self::class, 'formatDate'])]
    public ?string $manufactureDate = null;

    #[Map(source: 'expirationDate', transform: [self::class, 'formatDate'])]
    public ?string $expirationDate = null;

    public ?string $modelNumber = null;

    public ?string $lotNumber = null;

    public ?string $note = null;

    public ?string $parentId = null;

    public ?string $deviceDefinitionId = null;

    /** @var list<array<string, mixed>>|null */
    #[Map(source: 'properties', transform: [self::class, 'prepareProperties'])]
    public ?array $properties = null;

    public static function prepareProperties(?array $properties): ?array
    {
        if ($properties === null || $properties === []) {
            return null;
        }

        return array_map(
            static fn (array $property): array => array_filter(
                Arr::only($property, [
                    'type',
                    'valueInteger',
                    'valueDecimal',
                    'valueBoolean',
                    'valueString'
                ]),
                static fn (mixed $value): bool => $value !== null && $value !== ''
            ),
            $properties
        );
    }

    public static function formatDate(?string $date): ?string
    {
        return filled($date) ? convertToYmd($date) : null;
    }

    /**
     * Keep property values such as integer 0 and boolean false intact.
     * EhealthMapping still converts their keys to snake_case.
     *
     * @return list<string>
     */
    protected function keepWhenSet(): array
    {
        return ['properties'];
    }
}
