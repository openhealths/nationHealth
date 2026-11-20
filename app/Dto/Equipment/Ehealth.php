<?php

declare(strict_types=1);

namespace App\Dto\Equipment;

use App\Dto\EhealthMapping;
use App\Livewire\Equipment\Forms\EquipmentForm;
use App\Dto\Transform\Ehealth\EquipmentProperties;
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
    #[Map(source: 'properties', transform: [EquipmentProperties::class, 'map'])]
    public ?array $properties = null;

    /**
     * Convert the date to Y-m-d format if present.
     *
     * @param  string|null  $date
     * @return string|null
     */
    public static function formatDate(?string $date): ?string
    {
        return filled($date) ? convertToYmd($date) : null;
    }

    /**
     * Keep property values such as integer 0 and boolean false intact.
     *
     * @return list<string>
     */
    protected function keepWhenSet(): array
    {
        return ['properties'];
    }
}
