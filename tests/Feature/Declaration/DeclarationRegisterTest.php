<?php

declare(strict_types=1);

namespace Tests\Feature\Declaration;

use App\Classes\eHealth\Api\Declaration as DeclarationApi;
use App\Jobs\CompleteSync;
use App\Enums\Declaration\Status;
use App\Jobs\DeclarationRegisterSync;
use App\Jobs\LegalEntitySync;
use App\Models\Declaration;
use App\Models\LegalEntity;
use App\Models\User;
use App\Policies\DeclarationPolicy;
use App\Models\Role;
use App\Repositories\DeclarationRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class DeclarationRegisterTest extends TestCase
{
    public function test_register_needs_only_declaration_read_and_skips_patient_jobs(): void
    {
        $user = new User();
        $user->setRelation('roles', collect([(new Role())->forceFill(['name' => 'OWNER', 'guard_name' => auth()->getDefaultDriver()])]));
        $entity = new LegalEntity();
        $policy = new DeclarationPolicy();
        $this->assertTrue($policy->sync($user, $entity, ['declaration:read'])->allowed());
        $this->assertFalse($policy->sync($user, $entity, ['person:read'])->allowed());

        $next = new ReflectionMethod(DeclarationRegisterSync::class, 'getNextEntityJob');
        $this->assertInstanceOf(CompleteSync::class, $next->invoke(new DeclarationRegisterSync($entity, status: Status::TERMINATED)));
        $continuation = new LegalEntitySync($entity);
        $this->assertSame($continuation, $next->invoke(new DeclarationRegisterSync($entity, $continuation, true, status: Status::TERMINATED)));
    }

    public function test_register_imports_a_terminated_declaration_without_a_patient_and_preserves_enrichment(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false]);
        foreach (['employees', 'divisions'] as $table) {
            Schema::create($table, static function ($table): void {
                $table->id();
                $table->uuid();
                $table->foreignId('legal_entity_id');
            });
        }
        (require database_path('migrations/install/2025_12_10_000098_create_declarations_table.php'))->up();

        $facility = (new LegalEntity())->forceFill(['id' => 1, 'uuid' => (string) Str::uuid()]);
        $employee = (string) Str::uuid();
        DB::table('employees')->insert(['uuid' => $employee, 'legal_entity_id' => $facility->id]);
        $item = ['id' => (string) Str::uuid(), 'employee' => ['id' => $employee], 'declaration_number' => '1234-5678',
            'status' => 'terminated', 'start_date' => '2025-10-01', 'end_date' => '2026-01-01', 'inserted_at' => '2025-10-01T10:00:00Z'];

        $api = (new DeclarationApi())->stub(function (Request $request) use ($item, $facility) {
            $this->assertSame('/api/declarations', parse_url($request->url(), PHP_URL_PATH));
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame($facility->uuid, $query['legal_entity_id']);
            $this->assertSame('terminated', $query['status']);
            $this->assertArrayNotHasKey('person_id', $query);

            return Http::response(['data' => [$item]]);
        });
        $page = $api->getRegisterPage($facility->uuid, 1, Status::TERMINATED)->validate();
        $repository = new DeclarationRepository();
        $repository->storeRegisterPage($page, $facility);
        $declaration = Declaration::sole();
        $this->assertNull($declaration->personId);
        $this->assertSame($item['id'], $declaration->uuid);

        $declaration->update(['person_id' => 42, 'declaration_request_id' => 21, 'signed_at' => '2025-10-01',
            'status' => Status::ACTIVE, 'is_active' => true]);
        $page[0]['reason_description'] = 'Updated reason';
        $repository->storeRegisterPage($page, $facility);
        $declaration = Declaration::sole();
        $this->assertEquals(42, $declaration->personId);
        $this->assertEquals(21, $declaration->declarationRequestId);
        $this->assertSame('2025-10-01', $declaration->signedAt);
        $this->assertSame('Updated reason', $declaration->reasonDescription);
        $this->assertSame(Status::TERMINATED, $declaration->status);
        $this->assertFalse((bool) $declaration->isActive);

        $foreign = (new LegalEntity())->forceFill(['id' => 2]);
        $this->assertSame($item['id'], Declaration::facilityRegister($facility, [$employee], ['terminated'], '1234-5678')->sole()->uuid);
        $this->assertFalse(Declaration::facilityRegister($foreign)->exists());
        $this->assertFalse(Declaration::facilityRegister($facility, [(string) Str::uuid()])->exists());
        $this->assertFalse(Declaration::facilityRegister($facility, [], ['active'])->exists());
        $this->assertFalse(Declaration::facilityRegister($facility, [], [], 'other-number')->exists());
    }
}
