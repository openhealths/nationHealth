<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Dto\Address\Model as AddressData;
use App\Dto\Division\Model as DivisionData;
use App\Dto\Input\FormCollection;
use App\Dto\Phone\Model as PhoneData;
use App\Enums\Status;
use App\Models\Division;
use Illuminate\Support\Str;
use Tests\TestCase;

class DivisionFormToModelMappingTest extends TestCase
{
    use DivisionMappingFixtures;

    public function test_wraps_nested_form_items_in_dedicated_form_collections(): void
    {
        $items = ['residence' => ['type' => 'RESIDENCE', 'settlementId' => 'settlement-uuid']];

        $sources = DivisionData::wrapFormItems($items);

        $this->assertSame([0], array_keys($sources));
        $this->assertInstanceOf(FormCollection::class, $sources[0]);
        $this->assertSame($items['residence'], $sources[0]->all());
        $this->assertSame(['residence'], array_keys($items));
        $this->assertSame([], DivisionData::wrapFormItems(null));
        $this->assertSame([], DivisionData::wrapFormItems([]));
    }

    public function test_maps_form_directly_to_division_and_nested_model_dtos(): void
    {
        $component = $this->formComponent();
        $input = $component->divisionForm->division;
        $data = $this->mapForm($component->divisionForm);
        $division = $data->toModel();

        $this->assertSame(Status::DRAFT, $division->status);
        $this->assertNull($division->uuid);
        $this->assertSame('123', $division->externalId);
        $this->assertFalse($division->mountainGroup);
        $this->assertSame([['08:00', '17:30']], $division->workingHours['mon']);
        $this->assertInstanceOf(AddressData::class, $data->addresses[0]);
        $this->assertSame($input['addresses']['residence']['settlementId'], $data->addresses[0]->settlementId);
        $this->assertSame('CITY', $data->addresses[0]->toModel()->settlementType);
        $this->assertSame('STREET', $data->addresses[0]->streetType);
        $this->assertInstanceOf(PhoneData::class, $data->phones[0]);
        $this->assertSame($input, $component->divisionForm->division);
        $this->assertArrayNotHasKey('isForm', $division->getAttributes());
    }

    public function test_form_mapping_preserves_api_metadata_and_excludes_ownership_fields(): void
    {
        $component = $this->formComponent([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE',
            'externalId' => '', 'legalEntityId' => 999, 'dlsId' => 'untrusted-license',
        ]);
        $component->divisionForm->division['addresses']['residence']['addressable_id'] = 999;
        $component->divisionForm->division['phones'][0]['phoneable_id'] = 999;
        $data = $this->mapForm($component->divisionForm);
        $division = new Division();
        $division->dlsId = 'original-license';
        $division->dlsVerified = true;
        $division->ehealthInsertedAt = '2026-10-04';
        $division->legalEntityId = 7;

        $data->toModel($division);

        $this->assertSame(Status::UNSYNCED, $division->status);
        $this->assertNull($division->externalId);
        $this->assertSame('original-license', $division->dlsId);
        $this->assertTrue($division->dlsVerified);
        $this->assertSame('2026-10-04', $division->ehealthInsertedAt);
        $this->assertSame(7, $division->legalEntityId);
        $this->assertArrayNotHasKey('addressable_id', $data->addresses[0]->toModel()->getAttributes());
        $this->assertArrayNotHasKey('phoneable_id', $data->phones[0]->toModel()->getAttributes());
    }
}
