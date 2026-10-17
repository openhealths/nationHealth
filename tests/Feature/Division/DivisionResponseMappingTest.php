<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Responses\Collections\DivisionCreate;
use App\Classes\eHealth\Api\Responses\Collections\AddressCreate;
use App\Classes\eHealth\Api\Responses\Collections\PhoneCreate;
use App\Contracts\Dto\Model as ModelContract;
use App\Dto\Address\Model as AddressData;
use App\Dto\Phone\Model as PhoneData;
use App\Enums\Status;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\Relations\Phone;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Tests\TestCase;

class DivisionResponseMappingTest extends TestCase
{
    use DivisionMappingFixtures;

    public function test_maps_validated_response_to_unsaved_models(): void
    {
        $response = $this->response(['legal_entity_id' => (string) Str::uuid()]);
        $data = $this->mapResponse($response);
        $division = new Division();
        $division->id = 42;
        $division->legal_entity_id = 7;

        $this->assertSame($division, $data->toModel($division));
        $this->assertSame(42, $division->id);
        $this->assertSame(7, $division->legal_entity_id);
        $this->assertSame($response['id'], $division->uuid);
        $this->assertSame(Status::ACTIVE, $division->status);
        $this->assertSame('123', $division->externalId);
        $this->assertFalse($division->dlsVerified);
        $this->assertSame($response['inserted_at'], $division->ehealthInsertedAt);
        $this->assertSame([['08:00', '17:30']], $division->workingHours['mon']);
        $this->assertSame([['-1', '-1']], $division->workingHours['sun']);
        $this->assertEquals($response['location'], $division->location);
        $this->assertInstanceOf(AddressData::class, $data->addresses[0]);
        $this->assertSame('0', $data->addresses[0]->building);
        $this->assertFalse($data->addresses[0]->toModel()->exists);
        $this->assertInstanceOf(PhoneData::class, $data->phones[0]);
        $this->assertFalse($data->phones[0]->toModel()->exists);
        $this->assertArrayNotHasKey('note', $data->phones[0]->toModel()->getAttributes());
    }

    public function test_maps_missing_optional_response_fields(): void
    {
        $response = $this->response();
        foreach (['external_id', 'location', 'working_hours', 'mountain_group', 'dls_id', 'dls_verified',
            'inserted_at', 'updated_at', 'inserted_by', 'updated_by'] as $key) {
            unset($response[$key]);
        }

        $data = $this->mapResponse($response);

        $this->assertNull($data->externalId);
        $this->assertNull($data->location);
        $this->assertNull($data->workingHours);
        $this->assertNull($data->dlsVerified);
        $this->assertNull($data->ehealthInsertedAt);
        $this->assertFalse($data->mountainGroup);
    }

    public function test_model_dtos_implement_the_shared_conversion_contract(): void
    {
        $response = $this->response();
        $data = $this->mapResponse($response);
        $dtos = [$data, $data->addresses[0], $data->phones[0]];

        $convert = fn (ModelContract $dto): \Illuminate\Database\Eloquent\Model => $dto->toModel();

        foreach ($dtos as $dto) {
            $this->assertInstanceOf(ModelContract::class, $dto);
            $this->assertFalse($convert($dto)->exists);
        }

        $division = $convert($data);
        $this->assertInstanceOf(Division::class, $division);
        $this->assertSame($response['id'], $division->uuid);
        $this->assertArrayNotHasKey('addresses', $division->getAttributes());
        $this->assertArrayNotHasKey('phones', $division->getAttributes());
        $this->assertInstanceOf(\App\Models\Relations\Address::class, $convert($data->addresses[0]));
        $this->assertInstanceOf(Phone::class, $convert($data->phones[0]));
    }

    public function test_passes_camel_case_attributes_to_the_model(): void
    {
        $data = $this->mapResponse($this->response());
        $division = new class extends Division
        {
            /** @var list<string> */
            public array $receivedKeys = [];

            public function setAttribute($key, $value): mixed
            {
                $this->receivedKeys[] = $key;

                return parent::setAttribute($key, $value);
            }
        };

        $data->toModel($division);

        $this->assertContains('externalId', $division->receivedKeys);
        $this->assertContains('workingHours', $division->receivedKeys);
        $this->assertContains('ehealthInsertedAt', $division->receivedKeys);
        $this->assertNotContains('external_id', $division->receivedKeys);
        $this->assertNotContains('addresses', $division->receivedKeys);
        $this->assertNotContains('phones', $division->receivedKeys);
        $this->assertSame('123', $division->getAttributes()['external_id']);
    }

    public function test_invalid_response_is_rejected_before_mapping(): void
    {
        $this->expectException(ValidationException::class);

        $this->mapResponse($this->response(['phones' => [['type' => 'MOBILE']]]));
    }

    public function test_maps_multiple_addresses_to_independent_nested_dtos(): void
    {
        $response = $this->response();
        $residence = $response['addresses'][0];
        $reception = array_replace($residence, ['type' => 'RECEPTION', 'building' => '12']);
        $data = $this->mapResponse(array_replace($response, [
            'addresses' => [2 => $residence, 5 => $reception],
        ]));

        $this->assertSame([0, 1], array_keys($data->addresses));
        $this->assertInstanceOf(AddressData::class, $data->addresses[1]);
        $this->assertSame('RESIDENCE', $data->addresses[0]->type);
        $this->assertSame('RECEPTION', $data->addresses[1]->type);
        $this->assertSame('12', $data->addresses[1]->toModel()->building);
        $this->assertSame($residence['settlement_id'], $data->addresses[0]->settlementId);
        $this->assertNull($data->addresses[0]->region);
        $this->assertSame([], (new DivisionCreate(['addresses' => []]))->get('addresses'));
    }

    public function test_source_wraps_addresses_without_mutating_input_or_rewrapping_objects(): void
    {
        $attributes = ['type' => 'RESIDENCE', 'building' => '0'];
        $existing = new AddressCreate(['type' => 'RECEPTION']);
        $collection = new Collection(['type' => 'RESIDENCE', 'building' => '12']);
        $input = ['addresses' => [2 => $attributes, 5 => $existing, 7 => $collection]];

        $source = new DivisionCreate($input);

        $this->assertSame([0, 1, 2], array_keys($source->get('addresses')));
        $this->assertInstanceOf(AddressCreate::class, $source->get('addresses')[0]);
        $this->assertSame($attributes, $source->get('addresses')[0]->all());
        $this->assertSame($existing, $source->get('addresses')[1]);
        $this->assertInstanceOf(AddressCreate::class, $source->get('addresses')[2]);
        $this->assertSame($collection->all(), $source->get('addresses')[2]->all());
        $this->assertSame($collection, $input['addresses'][7]);
        $this->assertSame($attributes, $input['addresses'][2]);
        $this->assertFalse((new DivisionCreate())->has('addresses'));
    }

    public function test_related_models_preserve_allowed_attributes_without_case_conversion(): void
    {
        $source = new DivisionCreate(['addresses' => [5 => [
            'type' => 'RESIDENCE',
            'settlement_id' => 'settlement-id',
            'settlement_type' => 'CITY',
            'street_type' => 'STREET',
            'building' => '0',
            'apartment' => null,
            'addressable_id' => 999,
        ]]]);
        $wrappedAddresses = $source->get('addresses');
        $this->assertInstanceOf(AddressCreate::class, $wrappedAddresses[0]);
        $addressData = (new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor()))
            ->map($wrappedAddresses[0], AddressData::class);
        $addresses = [$addressData->toModel()];
        $phoneSource = new DivisionCreate(['phones' => [5 => [
            'type' => 'MOBILE',
            'number' => '+380501234567',
            'note' => 'Excluded',
            'phoneable_id' => 999,
        ]]]);
        $phoneData = (new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor()))
            ->map($phoneSource->get('phones')[0], PhoneData::class);
        $phones = [$phoneData->toModel()];

        $this->assertSame([0], array_keys($addresses));
        $this->assertSame([0], array_keys($phones));
        $this->assertSame([
            'type' => 'RESIDENCE',
            'country' => null,
            'area' => null,
            'region' => null,
            'settlement' => null,
            'settlement_id' => 'settlement-id',
            'settlement_type' => 'CITY',
            'street_type' => 'STREET',
            'street' => null,
            'building' => '0',
            'apartment' => null,
            'zip' => null,
        ], $addresses[0]->getAttributes());
        $this->assertSame([
            'type' => 'MOBILE',
            'number' => '+380501234567',
        ], $phones[0]->getAttributes());
        $this->assertSame('settlement-id', $addresses[0]->settlementId);
        $this->assertFalse($addresses[0]->exists);
        $this->assertFalse($phones[0]->exists);
    }

    public function test_source_wraps_phones_without_mutating_input_or_rewrapping_objects(): void
    {
        $attributes = ['type' => 'MOBILE', 'number' => '+380501234567'];
        $existing = new PhoneCreate($attributes);
        $collection = new Collection($attributes);
        $input = ['phones' => [2 => $attributes, 5 => $existing, 7 => $collection]];

        $source = new DivisionCreate($input);

        $this->assertSame([0, 1, 2], array_keys($source->get('phones')));
        $this->assertInstanceOf(PhoneCreate::class, $source->get('phones')[0]);
        $this->assertSame($attributes, $source->get('phones')[0]->all());
        $this->assertSame($existing, $source->get('phones')[1]);
        $this->assertInstanceOf(PhoneCreate::class, $source->get('phones')[2]);
        $this->assertSame($attributes, $source->get('phones')[2]->all());
        $this->assertSame($collection, $input['phones'][7]);
        $this->assertSame($attributes, $input['phones'][2]);
        $this->assertFalse((new DivisionCreate())->has('phones'));
        $this->assertSame([], (new DivisionCreate(['phones' => []]))->get('phones'));
    }

    public function test_maps_multiple_phones_to_independent_nested_dtos(): void
    {
        $data = $this->mapResponse($this->response(['phones' => [
            2 => ['type' => 'MOBILE', 'number' => '+380501234567'],
            5 => ['type' => 'LAND_LINE', 'number' => '+380441234567'],
        ]]));

        $this->assertSame([0, 1], array_keys($data->phones));
        $this->assertInstanceOf(PhoneData::class, $data->phones[0]);
        $this->assertInstanceOf(PhoneData::class, $data->phones[1]);
        $this->assertSame('MOBILE', $data->phones[0]->type);
        $this->assertSame('LAND_LINE', $data->phones[1]->type);
        $this->assertSame('+380441234567', $data->phones[1]->toModel()->number);
        $this->assertNotSame($data->phones[0], $data->phones[1]);
    }

    public function test_preserves_explicit_null_and_false_optional_values(): void
    {
        $data = $this->mapResponse($this->response([
            'external_id' => null, 'location' => null, 'working_hours' => null,
            'dls_id' => null, 'dls_verified' => false,
        ]));

        $this->assertNull($data->externalId);
        $this->assertNull($data->location);
        $this->assertNull($data->workingHours);
        $this->assertNull($data->dlsId);
        $this->assertFalse($data->dlsVerified);
    }

    public function test_reads_null_and_legacy_fallback_working_hours(): void
    {
        $data = $this->mapResponse($this->response(['working_hours' => null]));
        $division = $data->toModel();

        $this->assertSame(Division::getWorkingDaysTemplate(), $division->workingHours);

        $response = $this->response();
        unset($response['working_hours']);

        $this->assertSame(Division::getWorkingDaysTemplate(), $this->mapResponse($response)->toModel()->workingHours);

        $division->setRawAttributes(['working_hours' => '[["-1","-1"]]']);

        $this->assertSame(Division::getWorkingDaysTemplate(), $division->workingHours);
    }

    public function test_does_not_map_local_identifiers_from_nested_response_arrays(): void
    {
        $response = $this->response();
        $response['addresses'][0]['addressable_id'] = 999;
        $response['addresses'][0]['addressable_type'] = LegalEntity::class;
        $response['phones'][0]['phoneable_id'] = 999;
        $response['phones'][0]['phoneable_type'] = LegalEntity::class;

        $data = $this->mapResponse($response);

        $this->assertArrayNotHasKey('addressable_id', $data->addresses[0]->toModel()->getAttributes());
        $this->assertArrayNotHasKey('addressable_type', $data->addresses[0]->toModel()->getAttributes());
        $this->assertArrayNotHasKey('phoneable_id', $data->phones[0]->toModel()->getAttributes());
        $this->assertArrayNotHasKey('phoneable_type', $data->phones[0]->toModel()->getAttributes());
    }

}
