<?php

declare(strict_types=1);

namespace App\Dto\Equipment;

use App\Core\Arr;
use App\Models\Equipment;
use App\Models\EquipmentName;
use BackedEnum;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\IsNotNull;

/**
 * Equipment model and its relations mapped to Livewire form data.
 */
#[Map(source: Equipment::class)]
class Form
{
    #[Map(source: '[names]', transform: [self::class, 'mapNames'])]
    public array $names = [];

    public ?string $uuid = null;

    public ?string $type = null;

    public ?string $serialNumber = null;

    #[Map(source: 'status', transform: [self::class, 'enumValue'])]
    public string $status = '';

    #[Map(source: 'availabilityStatus', transform: [self::class, 'enumValue'])]
    public string $availabilityStatus = '';

    #[Map(source: '[recorder?]', transform: [self::class, 'recorderUuid'])]
    public string $recorder = '';

    #[Map(source: '[division?][uuid]')]
    public ?string $divisionId = null;

    #[Map(source: '[parent?][uuid]')]
    public ?string $parentId = null;

    public ?string $deviceDefinitionId = null;

    public ?string $inventoryNumber = null;

    public ?string $manufacturer = null;

    public ?string $manufactureDate = null;

    public ?string $expirationDate = null;

    public ?string $modelNumber = null;

    public ?string $lotNumber = null;

    public ?string $note = null;

    public ?string $errorReason = null;

    #[Map(source: '[properties?]', if: new IsNotNull(), transform: [Arr::class, 'toCamelCase'])]
    public ?array $properties = null;

    /**
     * Get the backed enum value or the original string.
     *
     * @param  BackedEnum|string|null  $value
     * @return string
     */
    public static function enumValue(BackedEnum|string|null $value): string
    {
        return $value instanceof BackedEnum ? $value->value : ($value ?? '');
    }

    /**
     * Get the UUID of the equipment recorder.
     *
     * @param  mixed  $value
     * @param  Equipment  $source
     * @return string
     */
    public static function recorderUuid(mixed $value, Equipment $source): string
    {
        return $source->getRelationValue('recorder')?->uuid ?? '';
    }

    /**
     * Map equipment names to form fields.
     *
     * @param  Collection  $names
     * @return array
     */
    public static function mapNames(Collection $names): array
    {
        return $names->map(
            static fn (EquipmentName $name): array => [
                'name' => $name->name,
                'type' => $name->type->value
            ]
        )->all();
    }
}