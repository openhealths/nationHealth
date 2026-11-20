<?php

declare(strict_types=1);

namespace App\Dto\Equipment;

use App\Core\Arr;
use App\Models\Equipment;
use BackedEnum;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Equipment model and its relations mapped to Livewire form data.
 */
#[Map(source: Equipment::class)]
class Form
{
    /** @var list<array{name: string, type: string}> */
    #[Map(source: '[names]', transform: [self::class, 'namesToArray'])]
    public array $names = [];

    public ?string $uuid = null;
    public ?string $type = null;
    public ?string $serialNumber = null;

    #[Map(source: 'status', transform: [self::class, 'enumValue'])]
    public string $status = '';

    #[Map(source: 'availabilityStatus', transform: [self::class, 'enumValue'])]
    public string $availabilityStatus = '';

    #[Map(source: 'attributes', transform: [self::class, 'recorderUuid'])]
    public string $recorder = '';

    #[Map(source: 'attributes', transform: [self::class, 'divisionUuid'])]
    public ?string $divisionId = null;

    #[Map(source: 'attributes', transform: [self::class, 'parentUuid'])]
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

    #[Map(source: 'attributes', transform: [self::class, 'propertiesToForm'])]
    public ?array $properties = null;

    public static function enumValue(BackedEnum|string|null $value): string
    {
        return $value instanceof BackedEnum ? $value->value : ($value ?? '');
    }

    public static function recorderUuid(array $attributes, Equipment $source): string
    {
        return $source->getRelation('recorder')?->uuid ?? '';
    }

    public static function divisionUuid(array $attributes, Equipment $source): ?string
    {
        return $source->getRelation('division')?->uuid;
    }

    public static function parentUuid(array $attributes, Equipment $source): ?string
    {
        return $source->getRelation('parent')?->uuid;
    }

    /** @return list<array{name: string, type: string}> */
    public static function namesToArray(Collection $names): array
    {
        return $names->map(static fn (EloquentModel $name): array => [
            'name' => $name->name,
            'type' => self::enumValue($name->type),
        ])->values()->all();
    }

    public static function propertiesToForm(array $attributes, Equipment $source): ?array
    {
        $properties = $source->getAttribute('properties');

        return $properties === null ? null : Arr::toCamelCase($properties);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
