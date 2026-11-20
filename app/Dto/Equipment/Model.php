<?php

declare(strict_types=1);

namespace App\Dto\Equipment;

use App\Classes\eHealth\Api\Responses\Collections\EquipmentDetails;
use App\Contracts\Dto\Model as ModelContract;
use App\Core\Arr;
use App\Enums\Equipment\Status;
use App\Livewire\Equipment\Forms\EquipmentForm;
use App\Models\Equipment;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

/**
 * Maps validated eHealth equipment data or an Equipment form into Eloquent attributes.
 * API UUIDs are resolved to local foreign keys by EquipmentRepository.
 */
#[Map(source: EquipmentDetails::class)]
#[Map(source: EquipmentForm::class)]
class Model implements ModelContract
{
    #[Map(source: '[id]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'uuid', if: new SourceClass(EquipmentForm::class))]
    public ?string $uuid = null;

    #[Map(source: '[legal_entity_id]', if: new SourceClass(EquipmentDetails::class))]
    public ?string $legalEntityId = null;

    #[Map(source: '[division_id]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'divisionId', if: new SourceClass(EquipmentForm::class))]
    public ?string $divisionId = null;

    #[Map(source: '[parent_id]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'parentId', if: new SourceClass(EquipmentForm::class))]
    public ?string $parentId = null;

    #[Map(source: '[recorder]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'recorder', if: new SourceClass(EquipmentForm::class))]
    public ?string $recorder = null;

    #[Map(source: '[device_definition_id]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'deviceDefinitionId', if: new SourceClass(EquipmentForm::class))]
    public ?string $deviceDefinitionId = null;

    #[Map(source: '[type]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'type', if: new SourceClass(EquipmentForm::class))]
    public ?string $type = null;

    #[Map(source: '[serial_number]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'serialNumber', if: new SourceClass(EquipmentForm::class))]
    public ?string $serialNumber = null;

    #[Map(source: '[status]', if: new SourceClass(EquipmentDetails::class))]
    public string $status = Status::DRAFT->value;

    #[Map(source: '[availability_status]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'availabilityStatus', if: new SourceClass(EquipmentForm::class))]
    public ?string $availabilityStatus = null;

    #[Map(source: '[manufacturer]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'manufacturer', if: new SourceClass(EquipmentForm::class))]
    public ?string $manufacturer = null;

    #[Map(source: '[manufacture_date]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'manufactureDate', if: new SourceClass(EquipmentForm::class))]
    public ?string $manufactureDate = null;

    #[Map(source: '[model_number]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'modelNumber', if: new SourceClass(EquipmentForm::class))]
    public ?string $modelNumber = null;

    #[Map(source: '[inventory_number]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'inventoryNumber', if: new SourceClass(EquipmentForm::class))]
    public ?string $inventoryNumber = null;

    #[Map(source: '[lot_number]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'lotNumber', if: new SourceClass(EquipmentForm::class))]
    public ?string $lotNumber = null;

    #[Map(source: '[expiration_date]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'expirationDate', if: new SourceClass(EquipmentForm::class))]
    public ?string $expirationDate = null;

    #[Map(source: '[note]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'note', if: new SourceClass(EquipmentForm::class))]
    public ?string $note = null;

    #[Map(source: '[error_reason]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'errorReason', if: new SourceClass(EquipmentForm::class))]
    public ?string $errorReason = null;

    /** @var list<array<string, mixed>>|null */
    #[Map(source: '[properties]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'properties', if: new SourceClass(EquipmentForm::class), transform: [self::class, 'snakeCaseProperties'])]
    public ?array $properties = null;

    #[Map(source: '[inserted_at]', if: new SourceClass(EquipmentDetails::class))]
    public ?string $ehealthInsertedAt = null;

    #[Map(source: '[inserted_by]', if: new SourceClass(EquipmentDetails::class))]
    public ?string $ehealthInsertedBy = null;

    #[Map(source: '[updated_at]', if: new SourceClass(EquipmentDetails::class))]
    public ?string $ehealthUpdatedAt = null;

    #[Map(source: '[updated_by]', if: new SourceClass(EquipmentDetails::class))]
    public ?string $ehealthUpdatedBy = null;

    /** @var list<array{name: string, type: string}> */
    #[Map(source: '[names]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'names', if: new SourceClass(EquipmentForm::class))]
    public array $names = [];

    /** @param list<array<string, mixed>>|null $properties */
    public static function snakeCaseProperties(?array $properties): ?array
    {
        return $properties === null ? null : Arr::toSnakeCase($properties);
    }

    public function toModel(?Equipment $equipment = null): Equipment
    {
        $equipment ??= new Equipment();

        foreach (Arr::except(get_object_vars($this), ['names', 'legalEntityId', 'divisionId', 'parentId', 'recorder']) as $key => $value) {
            $equipment->setAttribute($key, $value);
        }

        return $equipment;
    }
}
