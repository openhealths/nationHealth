<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\DeviceProperty;
use App\Models\MedicalEvents\Sql\Quantity;
use App\Models\MedicalEvents\Sql\Range;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property Device $model
 */
class DeviceRepository extends BaseRepository
{
    /**
     * Store devices in DB.
     *
     * @param  array  $data
     * @param  Person|Preperson  $patient
     * @return void
     * @throws Throwable
     */
    public function store(array $data, Person|Preperson $patient): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($data, $ownerColumn, $ownerId) {
            foreach ($data as $datum) {
                $context = Repository::identifier()->store($datum['context']['identifier']['value']);
                Repository::codeableConcept()->attach($context, $datum['context']);

                $definition = null;
                if (isset($datum['definition'])) {
                    $definition = Repository::identifier()->store($datum['definition']['identifier']['value']);
                    Repository::codeableConcept()->attach($definition, $datum['definition']);
                }

                $recorder = Repository::identifier()->store($datum['recorder']['identifier']['value']);
                Repository::codeableConcept()->attach($recorder, $datum['recorder']);

                $parent = null;
                if (isset($datum['parent'])) {
                    $parent = Repository::identifier()->store($datum['parent']['identifier']['value']);
                    Repository::codeableConcept()->attach($parent, $datum['parent']);
                }

                $type = Repository::codeableConcept()->store($datum['type']);

                $device = $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    $ownerColumn => $ownerId,
                    'status' => $datum['status'],
                    'type_id' => $type->id,
                    'model_number' => $datum['modelNumber'] ?? null,
                    'lot_number' => $datum['lotNumber'] ?? null,
                    'manufacturer' => $datum['manufacturer'] ?? null,
                    'serial_number' => $datum['serialNumber'] ?? null,
                    'manufacture_date' => $datum['manufactureDate'] ?? null,
                    'expiration_date' => $datum['expirationDate'] ?? null,
                    'note' => $datum['note'] ?? null,
                    'primary_source' => $datum['primarySource'],
                    'report_origin_id' => isset($datum['reportOrigin'])
                        ? Repository::codeableConcept()->store($datum['reportOrigin'])->id
                        : null,
                    'context_id' => $context->id,
                    'recorder_id' => $recorder->id,
                    'definition_id' => $definition?->id,
                    'parent_id' => $parent?->id
                ]);

                $device->names()->createMany($datum['name']);

                if (isset($datum['property'])) {
                    foreach ($datum['property'] as $propertyData) {
                        $this->storeProperty($device, $propertyData);
                    }
                }

                if (isset($datum['identifier'])) {
                    foreach ($datum['identifier'] as $identifierData) {
                        $identifier = Repository::identifier()->store($identifierData['value']);
                        Repository::codeableConcept()->attach($identifier, ['identifier' => $identifierData]);

                        $device->identifiers()->attach($identifier->id);
                    }
                }
            }
        });
    }

    /**
     * Get devices that are related to the encounter.
     *
     * @param  string  $encounterUuid
     * @return array
     */
    public function get(string $encounterUuid): array
    {
        return $this->model
            ->withAllRelations()
            ->forEncounter($encounterUuid)
            ->get()
            ->toArray();
    }

    /**
     * Sync devices and their related data.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(Person|Preperson $patient, array $validatedData): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($ownerColumn, $ownerId, $validatedData) {
            $existingDevices = $this->model
                ->whereIn('uuid', collect($validatedData)->pluck('uuid')->toArray())
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingDevices->get($data['uuid']);

                $type = $this->syncCodeableConcept($existing, $data['type'], 'type');
                $reportOrigin = $this->syncCodeableConcept($existing, $data['report_origin'] ?? null, 'reportOrigin');
                $context = $this->syncIdentifier($existing, $data['context'], 'context');
                $recorder = $this->syncIdentifier($existing, $data['recorder'], 'recorder');
                $definition = $this->syncIdentifier($existing, $data['definition'] ?? null, 'definition');
                $parent = $this->syncIdentifier($existing, $data['parent'] ?? null, 'parent');

                $deviceData = [
                    $ownerColumn => $ownerId,
                    'status' => $data['status'],
                    'type_id' => $type->id,
                    'model_number' => $data['model_number'] ?? null,
                    'lot_number' => $data['lot_number'] ?? null,
                    'manufacturer' => $data['manufacturer'] ?? null,
                    'serial_number' => $data['serial_number'] ?? null,
                    'manufacture_date' => $data['manufacture_date'] ?? null,
                    'expiration_date' => $data['expiration_date'] ?? null,
                    'note' => $data['note'] ?? null,
                    'primary_source' => $data['primary_source'],
                    'report_origin_id' => $reportOrigin?->id,
                    'context_id' => $context->id,
                    'recorder_id' => $recorder->id,
                    'definition_id' => $definition?->id,
                    'parent_id' => $parent?->id
                ];

                if ($existing) {
                    $existing->update($deviceData);
                    $device = $existing;
                } else {
                    $device = $this->model->create(array_merge(['uuid' => $data['uuid']], $deviceData));
                }

                $identifiers = array_map(
                    static fn (array $identifier): array => ['identifier' => $identifier],
                    $data['identifier'] ?? []
                );

                $this->syncPivot(
                    $device,
                    'identifiers',
                    $this->syncIdentifiers($existing, $identifiers, 'identifiers')
                );

                $this->syncNames($device, $data['name']);
                $this->syncProperties($device, Arr::toCamelCase($data['property'] ?? []));
            }
        });
    }

    /**
     * Sync device names.
     *
     * @param  Device  $device
     * @param  array  $names
     * @return void
     */
    private function syncNames(Device $device, array $names): void
    {
        $existingNames = $device->relationLoaded('names') ? $device->names : collect();

        foreach ($names as $index => $name) {
            $existingName = $existingNames[$index] ?? null;

            if ($existingName) {
                $existingName->update($name);

                continue;
            }

            $device->names()->create($name);
        }

        foreach ($existingNames->slice(count($names)) as $extra) {
            $extra->delete();
        }
    }

    /**
     * Store a device property with the single value it carries.
     *
     * @param  Device  $device
     * @param  array  $propertyData
     * @return void
     */
    private function storeProperty(Device $device, array $propertyData): void
    {
        $property = $device->properties()->create([
            'code_id' => Repository::codeableConcept()->store($propertyData['code'])->id
        ]);

        $this->syncPropertyValue($property, $propertyData);
    }

    /**
     * Sync device properties.
     *
     * @param  Device  $device
     * @param  array  $properties
     * @return void
     */
    private function syncProperties(Device $device, array $properties): void
    {
        $existingProperties = $device->relationLoaded('properties') ? $device->properties : collect();

        foreach ($properties as $index => $propertyData) {
            $existingProperty = $existingProperties[$index] ?? null;

            if ($existingProperty) {
                $this->updateCodeableConcept($existingProperty->code, $propertyData['code']);
                $this->syncPropertyValue($existingProperty, $propertyData);

                continue;
            }

            $this->storeProperty($device, $propertyData);
        }

        foreach ($existingProperties->slice(count($properties)) as $extra) {
            $extra->delete();
        }
    }

    /**
     * Sync the value of a property. The value type may change between saves, so every column is written and the
     * ones the property no longer carries are cleared.
     *
     * @param  DeviceProperty  $property
     * @param  array  $propertyData
     * @return void
     */
    private function syncPropertyValue(DeviceProperty $property, array $propertyData): void
    {
        $value = $property->value;

        $valueData = [
            'value_codeable_concept_id' => null,
            'value_quantity_id' => null,
            'value_range_id' => null,
            'value_boolean' => $propertyData['valueBoolean'] ?? null,
            'value_integer' => $propertyData['valueInteger'] ?? null,
            'value_string' => $propertyData['valueString'] ?? null
        ];

        if (isset($propertyData['valueCodeableConcept'])) {
            $valueData['value_codeable_concept_id'] = $this->syncCodeableConcept(
                $value,
                $propertyData['valueCodeableConcept'],
                'valueCodeableConcept'
            )->id;
        }

        if (isset($propertyData['valueQuantity'])) {
            $valueData['value_quantity_id'] = $this
                ->syncQuantity($value?->valueQuantity, $propertyData['valueQuantity'])
                ->id;
        }

        if (isset($propertyData['valueRange'])) {
            $range = $value?->valueRange;
            $rangeData = [
                'low_id' => $this->syncQuantity($range?->low, $propertyData['valueRange']['low'])->id,
                'high_id' => $this->syncQuantity($range?->high, $propertyData['valueRange']['high'])->id
            ];

            if ($range) {
                $range->update($rangeData);
            } else {
                $range = Range::create($rangeData);
            }

            $valueData['value_range_id'] = $range->id;
        }

        if ($value) {
            $value->update($valueData);

            return;
        }

        $property->value()->create($valueData);
    }

    /**
     * Update the given quantity, or create one when the property did not carry it before.
     *
     * @param  Quantity|null  $quantity
     * @param  array  $quantityData
     * @return Quantity
     */
    private function syncQuantity(?Quantity $quantity, array $quantityData): Quantity
    {
        if ($quantity) {
            $quantity->update($quantityData);

            return $quantity;
        }

        return Quantity::create($quantityData);
    }
}
