<?php

declare(strict_types=1);

namespace App\Dto\Equipment;

use App\Classes\eHealth\Api\Responses\Collections\EquipmentDetails;
use App\Enums\Equipment\Status;
use App\Livewire\Equipment\Forms\EquipmentForm;
use App\Models\Equipment;
use App\Dto\Transform\Ehealth\EquipmentProperties;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

/**
 * Maps equipment data from eHealth responses or Livewire forms
 * to Eloquent model attributes.
 */
#[Map(source: EquipmentDetails::class)]
#[Map(source: EquipmentForm::class)]
#[Map(target: Equipment::class)]
class Model
{
    #[Map(source: '[id]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $uuid = null;

    #[Map(source: '[division_id]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'divisionId', if: new SourceClass(EquipmentForm::class))]
    public ?string $divisionUuid = null;

    #[Map(source: '[parent_id]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'parentId', if: new SourceClass(EquipmentForm::class))]
    public ?string $parentUuid = null;

    #[Map(source: '[recorder]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'recorder', if: new SourceClass(EquipmentForm::class))]
    public ?string $recorderUuid = null;

    #[Map(source: '[device_definition_id]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'deviceDefinitionId', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $deviceDefinitionId = null;

    #[Map(source: '[type]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'type', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $type = null;

    #[Map(source: '[serial_number]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'serialNumber', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $serialNumber = null;

    #[Map(source: '[status]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public string $status = Status::DRAFT->value;

    #[Map(source: '[availability_status]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'availabilityStatus', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $availabilityStatus = null;

    #[Map(source: '[manufacturer]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'manufacturer', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $manufacturer = null;

    #[Map(source: '[manufacture_date]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'manufactureDate', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $manufactureDate = null;

    #[Map(source: '[model_number]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'modelNumber', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $modelNumber = null;

    #[Map(source: '[inventory_number]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'inventoryNumber', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $inventoryNumber = null;

    #[Map(source: '[lot_number]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'lotNumber', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $lotNumber = null;

    #[Map(source: '[expiration_date]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'expirationDate', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $expirationDate = null;

    #[Map(source: '[note]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'note', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $note = null;

    #[Map(source: '[error_reason]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'errorReason', if: new SourceClass(EquipmentForm::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $errorReason = null;

    /** @var list<array<string, mixed>>|null */
    #[Map(source: '[properties]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'properties', if: new SourceClass(EquipmentForm::class), transform: [EquipmentProperties::class, 'map'])]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?array $properties = null;

    #[Map(source: '[inserted_at]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $ehealthInsertedAt = null;

    #[Map(source: '[inserted_by]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $ehealthInsertedBy = null;

    #[Map(source: '[updated_at]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $ehealthUpdatedAt = null;

    #[Map(source: '[updated_by]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(if: new TargetClass(Equipment::class))]
    public ?string $ehealthUpdatedBy = null;

    /** @var list<array{name: string, type: string}> */
    #[Map(source: '[names]', if: new SourceClass(EquipmentDetails::class))]
    #[Map(source: 'names', if: new SourceClass(EquipmentForm::class))]
    public array $names = [];
}
