<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Division as DivisionApi;
use App\Enums\Status;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\Division\Model as DivisionData;
use App\Jobs\DivisionSync;
use App\Livewire\Division\DivisionEdit;
use App\Livewire\Division\Forms\DivisionForm;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Repositories\DivisionRepository;
use App\Repositories\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DivisionSyncPersistenceTest extends TestCase
{
    use DatabaseTransactions;
    use DivisionMappingFixtures;

    protected function legalEntity(): LegalEntity
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PHARMACY')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PHARMACY']);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED', 'legal_entity_type_id' => $typeId,
        ]);
    }

    protected function syncData(array $overrides = []): array
    {
        $response = $this->response($overrides);
        $response['uuid'] = $response['id'];
        $response['legal_entity_uuid'] = (string) Str::uuid();
        $response['ehealth_inserted_at'] = $response['inserted_at'];
        $response['ehealth_updated_at'] = $response['updated_at'];
        unset($response['id'], $response['inserted_at'], $response['updated_at']);

        return $response;
    }

    public function test_exact_numeric_coordinates_survive_sync_and_an_edit_of_the_name(): void
    {
        $entity = $this->legalEntity();
        $response = $this->response([
            'name' => 'Clinic "No. 12.5"',
            'legal_entity_id' => $entity->uuid,
            'location' => ['latitude' => 45, 'longitude' => 'EXACT_LONGITUDE'],
        ]);
        $json = static fn (array $data): string => str_replace(
            '"EXACT_LONGITUDE"',
            '179.99999999999999',
            json_encode(['data' => $data], JSON_THROW_ON_ERROR)
        );
        $listBody = $json([$response]);
        $detailsBody = $json($response);

        config(['ehealth.api.domain' => 'https://ehealth.invalid']);
        $api = new DivisionApi(Http::getFacadeRoot());
        $api->preventStrayRequests();
        $api->stub(fn (Request $request) => Http::response(
            parse_url($request->url(), PHP_URL_PATH) === '/api/divisions' ? $listBody : $detailsBody
        ));

        $repository = Repository::division();
        $repository->saveDivisionsList($this->mapList($api->getMany()->validate()), $entity);
        $division = $entity->divisions()->where('uuid', $response['id'])->firstOrFail();
        $location = ['latitude' => 45, 'longitude' => '179.99999999999999'];
        $this->assertSame($location, $division->location);
        $this->assertSame($response['name'], $division->name);

        $details = propertyObjectMapper()->map($api->getDetails($division->uuid)->validate(), DivisionData::class);
        $saved = $repository->saveMappedDivision($details, $division, $entity);
        $this->assertSame($location, $saved->location);

        $edit = new class extends DivisionEdit
        {
            public function submitNameChange(Division $division, DivisionApi $api): void
            {
                $this->setDivisionData($division);
                $this->divisionForm->division['name'] = 'Renamed clinic';
                $api->update($division->uuid, $this->prepareRequestData($division))->validate();
            }
        };
        $edit->divisionForm = new DivisionForm($edit, 'divisionForm');
        Http::record();
        $edit->submitNameChange($saved, $api);

        Http::assertSentCount(1);
        [$request] = Http::recorded()->first();
        $this->assertSame('PATCH', $request->method());
        $this->assertSame('https://ehealth.invalid/api/divisions/' . $saved->uuid, Str::before($request->url(), '?'));
        $this->assertSame('Renamed clinic', $request['name']);
        $this->assertMatchesRegularExpression('/"longitude"\s*:\s*179\.99999999999999\s*[,}]/', $request->body());
    }

    public function test_bulk_sync_maps_rows_and_replaces_only_the_synced_divisions_relations(): void
    {
        $entity = $this->legalEntity();
        $otherEntity = $this->legalEntity();
        $existing = Division::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Old division', 'email' => 'old@example.com',
            'legal_entity_id' => $entity->id, 'status' => 'UNSYNCED', 'is_active' => true,
        ]);
        $oldPhone = $existing->phones()->create(['type' => 'MOBILE', 'number' => '+380501111111']);
        $other = Division::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Other division', 'email' => 'other@example.com',
            'legal_entity_id' => $otherEntity->id, 'status' => 'ACTIVE',
        ]);
        $otherPhone = $other->phones()->create(['type' => 'MOBILE', 'number' => '+380502222222']);
        $location = ['latitude' => '50.12345678901234', 'longitude' => '0'];
        $rows = [
            $this->syncData(['id' => $existing->uuid, 'name' => 'Updated division', 'external_id' => '00123', 'location' => $location]),
            $this->syncData(['name' => 'New division', 'location' => null, 'working_hours' => null, 'addresses' => [], 'phones' => []]),
        ];
        $input = $rows;

        $divisions = $this->mapList($rows);
        Repository::division()->saveDivisionsList($divisions, $entity);
        Repository::division()->saveDivisionsList($divisions, $entity);

        $existing->refresh()->load(['addresses', 'phones']);
        $this->assertSame('Updated division', $existing->name);
        $this->assertSame(Status::ACTIVE, $existing->status);
        $this->assertTrue($existing->is_active);
        $this->assertSame('00123', $existing->externalId);
        $this->assertSame($location, $existing->location);
        $this->assertCount(1, $existing->addresses);
        $this->assertCount(1, $existing->phones);
        $this->assertSame($rows[0]['addresses'][0]['settlement_id'], $existing->addresses[0]->settlementId);
        $this->assertSame($existing->id, $existing->addresses[0]->addressable_id);
        $this->assertSame(Division::class, $existing->addresses[0]->addressable_type);
        $this->assertSame($rows[0]['phones'][0]['number'], $existing->phones[0]->number);
        $this->assertSame($existing->id, $existing->phones[0]->phoneable_id);
        $this->assertSame(Division::class, $existing->phones[0]->phoneable_type);
        $this->assertModelMissing($oldPhone);
        $this->assertModelExists($otherPhone);
        $this->assertSame('Other division', $other->fresh()->name);
        $this->assertSame(2, $entity->divisions()->count());
        $this->assertSame($input, $rows);
        $new = $entity->divisions()->where('uuid', $rows[1]['uuid'])->firstOrFail();
        $this->assertFalse($new->is_active);
        $this->assertNull($new->getRawOriginal('location'));
        $this->assertNull($new->getRawOriginal('working_hours'));
        $this->assertCount(0, $new->addresses);
        $this->assertCount(0, $new->phones);
    }

    public function test_bulk_sync_rolls_back_rows_and_relationship_deletions_when_persistence_fails(): void
    {
        $entity = $this->legalEntity();
        $existing = Division::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Old division', 'email' => 'old@example.com',
            'legal_entity_id' => $entity->id, 'status' => 'UNSYNCED',
        ]);
        $oldPhone = $existing->phones()->create(['type' => 'MOBILE', 'number' => '+380501111111']);
        $rows = [$this->syncData(['id' => $existing->uuid]), $this->syncData()];
        $repository = new class extends DivisionRepository
        {
            public function replaceDivisionRelations(array $divisionIds, array $addressesData, array $phonesData): void
            {
                parent::replaceDivisionRelations($divisionIds, $addressesData, $phonesData);

                throw new RuntimeException('Relationship persistence failed.');
            }
        };
        try {
            $repository->saveDivisionsList($this->mapList($rows), $entity);
            $this->fail('Expected a relationship persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Relationship persistence failed.', $exception->getMessage());
        }

        $this->assertSame('Old division', $existing->fresh()->name);
        $this->assertSame(Status::UNSYNCED, $existing->fresh()->status);
        $this->assertModelExists($oldPhone);
        $this->assertCount(0, $existing->fresh()->addresses);
        $this->assertSame(1, $entity->divisions()->count());
        $this->assertDatabaseMissing('divisions', ['uuid' => $rows[1]['uuid']]);
    }

    public function test_empty_bulk_sync_preserves_existing_divisions_and_relationships(): void
    {
        $entity = $this->legalEntity();
        $division = Division::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Existing division', 'email' => 'division@example.com',
            'legal_entity_id' => $entity->id, 'status' => 'ACTIVE',
        ]);
        $phone = $division->phones()->create(['type' => 'MOBILE', 'number' => '+380501111111']);

        Repository::division()->saveDivisionsList($this->mapList([]), $entity);

        $this->assertModelExists($division);
        $this->assertModelExists($phone);
        $this->assertSame(1, $entity->divisions()->count());
    }

    public function test_first_login_job_persists_mapped_rows_with_missing_optional_fields(): void
    {
        $entity = $this->legalEntity();
        $full = $this->syncData([
            'external_id' => '00123',
            'location' => ['latitude' => '50.1234567890123456789', 'longitude' => '0'],
        ]);
        $minimal = $this->syncData();
        foreach (['external_id', 'mountain_group', 'location', 'working_hours', 'dls_id',
            'dls_verified', 'ehealth_inserted_at', 'ehealth_updated_at', 'inserted_by', 'updated_by'] as $field) {
            unset($minimal[$field]);
        }
        $response = $this->mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([$full, $minimal]);
        $job = unserialize(serialize(new DivisionSync(legalEntity: $entity, isFirstLogin: true)));

        (function (EHealthResponse $response): void {
            $this->processResponse($response);
        })->call($job, $response);

        $saved = $entity->divisions()->where('uuid', $full['uuid'])->firstOrFail();
        $this->assertSame('00123', $saved->externalId);
        $this->assertSame($full['location'], $saved->location);
        $this->assertCount(1, $saved->addresses);
        $this->assertCount(1, $saved->phones);
        $this->assertSame($saved->id, $saved->addresses[0]->addressable_id);
        $this->assertSame($saved->id, $saved->phones[0]->phoneable_id);
        $savedMinimal = $entity->divisions()->where('uuid', $minimal['uuid'])->firstOrFail();
        $this->assertFalse((bool) $savedMinimal->mountainGroup);
        $this->assertNull($savedMinimal->externalId);
        $this->assertNull($savedMinimal->dlsVerified);
        $this->assertNull($savedMinimal->getRawOriginal('location'));
        $this->assertNull($savedMinimal->getRawOriginal('working_hours'));
        $this->assertSame(2, $entity->divisions()->count());
    }
}
