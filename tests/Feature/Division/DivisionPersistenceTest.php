<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Dto\Phone\Model as PhoneData;
use App\Enums\Status;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\Relations\Phone;
use App\Repositories\DivisionRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DivisionPersistenceTest extends TestCase
{
    use DatabaseTransactions;
    use DivisionMappingFixtures;

    private function legalEntity(): LegalEntity
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PHARMACY')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PHARMACY']);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED', 'legal_entity_type_id' => $typeId,
        ]);
    }

    public function test_saves_form_as_draft_with_local_relations(): void
    {
        $entity = $this->legalEntity();
        $this->app->instance('legalEntity', $entity);
        $component = $this->formComponent();

        $division = $component->saveForm();

        $this->assertModelExists($division);
        $this->assertSame(Status::DRAFT, $division->status);
        $this->assertSame($entity->id, $division->legalEntityId);
        $this->assertCount(1, $division->addresses);
        $this->assertCount(1, $division->phones);
        $this->assertSame($division->id, $division->addresses[0]->addressable_id);
        $this->assertSame($division->id, $division->phones[0]->phoneable_id);
    }

    public function test_form_saving_updates_existing_draft_by_local_id(): void
    {
        $entity = $this->legalEntity();
        $this->app->instance('legalEntity', $entity);
        $draft = Division::create([
            'name' => 'Old draft', 'email' => 'old@example.com',
            'legal_entity_id' => $entity->id, 'status' => 'DRAFT',
        ]);
        $draft->phones()->create(['type' => 'MOBILE', 'number' => '+380501111111']);
        $component = $this->formComponent(['id' => $draft->id]);

        $saved = $component->saveForm();

        $this->assertSame($draft->id, $saved->id);
        $this->assertSame(Status::DRAFT, $saved->status);
        $this->assertSame('Draft division', $saved->name);
        $this->assertCount(1, $saved->phones);
        $this->assertSame('+380501234567', $saved->phones[0]->number);
    }

    public function test_form_saving_updates_synchronized_division_by_uuid(): void
    {
        $entity = $this->legalEntity();
        $this->app->instance('legalEntity', $entity);
        $division = Division::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Original division', 'email' => 'old@example.com',
            'legal_entity_id' => $entity->id, 'status' => 'ACTIVE', 'dls_id' => 'original-license',
        ]);

        $saved = $this->formComponent(['uuid' => $division->uuid])->saveForm();

        $this->assertSame($division->id, $saved->id);
        $this->assertSame(Status::UNSYNCED, $saved->status);
        $this->assertSame('original-license', $saved->dlsId);
    }

    public function test_form_saving_rejects_missing_existing_division(): void
    {
        $this->app->instance('legalEntity', $this->legalEntity());
        $this->expectException(ModelNotFoundException::class);

        $this->formComponent(['id' => PHP_INT_MAX])->saveForm();
    }

    public function test_saves_response_into_existing_draft_and_replaces_relations(): void
    {
        $entity = $this->legalEntity();
        $division = Division::create([
            'name' => 'Draft division', 'email' => 'draft@example.com',
            'legal_entity_id' => $entity->id, 'status' => 'DRAFT',
        ]);
        $id = $division->id;
        $response = $this->response();
        $division->addresses()->create($response['addresses'][0]);
        $division->phones()->create(['type' => 'MOBILE', 'number' => '+380501111111']);

        $data = $this->mapResponse($response);
        $saved = app(DivisionRepository::class)->saveMappedDivision($data, $division, $entity);

        $this->assertModelExists($saved);
        $this->assertSame($id, $saved->id);
        $this->assertSame($response['id'], $saved->uuid);
        $this->assertSame($entity->id, $saved->legalEntity->id);
        $this->assertCount(1, $saved->addresses);
        $this->assertCount(1, $saved->phones);
        $this->assertSame($response['phones'][0]['number'], $saved->phones[0]->number);
        $this->assertSame($id, $saved->addresses[0]->addressable_id);
        $this->assertSame(Division::class, $saved->addresses[0]->addressable_type);
        $this->assertSame($id, $saved->phones[0]->phoneable_id);
        $this->assertSame(Division::class, $saved->phones[0]->phoneable_type);
    }

    public function test_rolls_back_division_and_relations_when_related_save_fails(): void
    {
        $entity = $this->legalEntity();
        $division = Division::create([
            'name' => 'Draft division', 'email' => 'draft@example.com',
            'legal_entity_id' => $entity->id, 'status' => 'DRAFT',
        ]);
        $oldPhone = $division->phones()->create(['type' => 'MOBILE', 'number' => '+380501111111']);
        $data = $this->mapResponse($this->response());
        $data->phones = [new class extends PhoneData
        {
            public function toModel(): Phone
            {
                return new class extends Phone
                {
                    public function save(array $options = []): bool
                    {
                        return false;
                    }
                };
            }
        }];

        try {
            app(DivisionRepository::class)->saveMappedDivision($data, $division, $entity);
            $this->fail('Expected a related model save failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot save division phone.', $exception->getMessage());
        }

        $division->refresh();
        $this->assertSame('Draft division', $division->name);
        $this->assertSame(Status::DRAFT, $division->status);
        $this->assertNull($division->uuid);
        $this->assertModelExists($oldPhone);
        $this->assertCount(0, $division->addresses);
        $this->assertCount(1, $division->phones);
    }
}
