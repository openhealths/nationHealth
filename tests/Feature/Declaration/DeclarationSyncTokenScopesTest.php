<?php

declare(strict_types=1);

namespace Tests\Feature\Declaration;

use App\Auth\EHealth\Services\TokenStorage;
use App\Classes\eHealth\Api\Declaration as DeclarationApi;
use App\Enums\JobStatus;
use App\Enums\User\Role;
use App\Livewire\Declaration\DeclarationIndex;
use App\Models\Declaration;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class DeclarationSyncTokenScopesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Bus::fake();
        config([
            'cache.default' => 'array',
            'permission.cache.store' => 'array',
            'pagination.per_page' => 10,
        ]);
        Cache::put(BasicDictionary::KEY, [
            ['name' => 'POSITION', 'values' => [['code' => 'P1', 'description' => 'Лікар']]],
            ['name' => 'DECLARATION_STATUSES', 'values' => [['code' => 'ACTIVE', 'description' => 'Активна']]],
        ]);
        Cache::put(BasicDictionary::KEY . ':fresh', true);
    }

    public function test_doctor_role_added_after_owner_login_does_not_enable_sync_until_token_scopes_are_granted(): void
    {
        Auth::shouldUse('ehealth');
        $legalEntity = LegalEntity::factory()->ofType(LegalEntity::TYPE_PRIMARY_CARE)->create([
            'sync_status' => JobStatus::COMPLETED->value,
            'division_sync_status' => JobStatus::COMPLETED->value,
            'hcs_sync_status' => JobStatus::COMPLETED->value,
            'employee_sync_status' => JobStatus::COMPLETED->value,
        ]);
        $this->instance('legalEntity', $legalEntity);
        setPermissionsTeamId($legalEntity->id);
        $party = Party::factory()->create();
        $user = User::factory()->create(['party_id' => $party->id]);
        $user->assignRole(Role::OWNER);
        $user->syncPermissions(config('ehealth.roles.OWNER'));
        $this->actingAs($user, 'ehealth');
        session()->forget('single_role_login');
        $storage = app(TokenStorage::class);
        $storage->storeScopesFromUserPermissions($user);
        $this->withSession([config('ehealth.api.oauth.bearer_token') => 'owner-token']);

        Employee::factory()->create([
            'legal_entity_id' => $legalEntity->id,
            'division_id' => null,
            'party_id' => $party->id,
            'user_id' => $user->id,
        ]);
        $user->assignRole(Role::DOCTOR);
        $user = $user->fresh();
        $this->actingAs($user, 'ehealth');

        $this->assertTrue($user->can('person:read'));
        $this->assertFalse($user->getDirectPermissions()->pluck('name')->contains('person:read'));
        $this->assertNotContains('person:read', $storage->getTokenScopes());
        $this->assertTrue($user->can('viewAny', Declaration::class));
        $this->assertFalse($user->can('sync', Declaration::class));
        $this->mock(DeclarationApi::class)->shouldNotReceive('getMany');

        Livewire::test(DeclarationIndex::class, ['legalEntity' => $legalEntity])
            ->assertDontSee('wire:click="sync"', false)
            ->call('sync')
            ->assertDontSee('wire:click="sync"', false);

        Bus::assertBatchCount(0);
        Http::assertNothingSent();
        $this->assertNull($legalEntity->fresh()->declaration_sync_status);

        // A new login grants the scopes of both roles; adding a local role alone cannot do this.
        $user->syncPermissions(array_values(array_unique(array_merge(
            config('ehealth.roles.OWNER'),
            config('ehealth.roles.DOCTOR')
        ))));
        $storage->storeScopesFromUserPermissions($user);
        $this->withSession([config('ehealth.api.oauth.bearer_token') => 'owner-doctor-token']);
        $user = $user->fresh();
        $this->actingAs($user, 'ehealth');

        $this->assertContains('person:read', $storage->getTokenScopes());
        $this->assertTrue($user->can('sync', Declaration::class));
        Livewire::test(DeclarationIndex::class, ['legalEntity' => $legalEntity])
            ->assertSee('wire:click="sync"', false);
        Http::assertNothingSent();
    }
}
