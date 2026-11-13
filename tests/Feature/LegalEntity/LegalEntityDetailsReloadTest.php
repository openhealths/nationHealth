<?php

declare(strict_types=1);

namespace Tests\Feature\LegalEntity;

use App\Auth\EHealth\Services\TokenStorage;
use App\Classes\eHealth\Api\LegalEntity as LegalEntityApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\JobStatus;
use App\Enums\LegalEntity\States as LegalEntityStatus;
use App\Livewire\Components\Sidebar;
use App\Livewire\LegalEntity\LegalEntityDetails;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LegalEntityDetailsReloadTest extends TestCase
{
    protected LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.default' => 'array',
            'permission.cache.store' => 'array',
            'session.driver' => 'array',
            'app.date_format' => 'd.m.Y',
        ]);
        Http::preventStrayRequests();

        foreach ([
            '2025_12_10_000012_create_legal_entity_types_table.php',
            '2025_12_10_000014_create_legal_entities_table.php',
            '2025_12_10_000016_create_permission_tables.php',
            '2025_12_10_000018_create_legal_entity_type_roles.php',
            '2025_12_10_000020_create_legal_entity_type_permissions_table.php',
            '2025_12_10_000028_create_licenses_table.php',
            '2025_12_10_000032_create_phones_table.php',
            '2025_12_10_000042_create_addresses_table.php',
            '2025_12_10_000070_create_revisions_table.php',
        ] as $migration) {
            (require database_path('migrations/install/' . $migration))->up();
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid();
            $table->string('email');
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
    }

    #[DataProvider('accessChanges')]
    public function test_sidebar_rechecks_declaration_access_without_logging_in_again(
        string $oldStatus,
        string $newStatus,
        string $newType,
        bool $hasDeclarationScope,
        bool $visibleBefore,
        bool $visibleAfter
    ): void {
        $user = $this->login($oldStatus, $hasDeclarationScope);
        $declarationsUrl = route('declaration.index', [$this->legalEntity]);
        $sidebar = Livewire::test(Sidebar::class);
        $visibleBefore ? $sidebar->assertSee($declarationsUrl) : $sidebar->assertDontSee($declarationsUrl);
        $this->fakeDetails($newStatus, $newType);

        $details = Livewire::test(ReloadedLegalEntityDetailsComponent::class)
            ->call('sync')
            ->assertRedirect(route('legal-entity.details', [$this->legalEntity]))
            ->assertSessionHas('success', __('forms.sync_successfull'))
            ->assertHasNoErrors();
        $this->assertArrayNotHasKey('redirectUsingNavigate', $details->effects);

        $this->assertDatabaseHas('legal_entities', [
            'id' => $this->legalEntity->id,
            'status' => $newStatus,
            'sync_status' => JobStatus::COMPLETED->value,
        ]);
        $this->assertAuthenticatedAs($user, 'ehealth');

        // A full page load binds the saved entity and renders the sidebar again.
        $this->app->instance('legalEntity', $this->legalEntity->fresh());
        $sidebar = Livewire::test(Sidebar::class);
        $visibleAfter ? $sidebar->assertSee($declarationsUrl) : $sidebar->assertDontSee($declarationsUrl);
        Http::assertNothingSent();
    }

    public static function accessChanges(): array
    {
        return [
            'activation shows declarations' => [
                LegalEntityStatus::SUSPENDED->value, LegalEntityStatus::ACTIVE->value, LegalEntity::TYPE_PRIMARY_CARE, true, false, true,
            ],
            'suspension hides declarations' => [
                LegalEntityStatus::ACTIVE->value, LegalEntityStatus::SUSPENDED->value, LegalEntity::TYPE_PRIMARY_CARE, true, true, false,
            ],
            'activation does not grant a missing scope' => [
                LegalEntityStatus::SUSPENDED->value, LegalEntityStatus::ACTIVE->value, LegalEntity::TYPE_PRIMARY_CARE, false, false, false,
            ],
            'type change hides declarations' => [
                LegalEntityStatus::ACTIVE->value, LegalEntityStatus::ACTIVE->value, LegalEntity::TYPE_OUTPATIENT, true, true, false,
            ],
        ];
    }

    public function test_successful_sync_reloads_the_page_even_when_access_did_not_change(): void
    {
        $this->login(LegalEntityStatus::ACTIVE->value);
        $this->fakeDetails(LegalEntityStatus::ACTIVE->value);

        Livewire::test(ReloadedLegalEntityDetailsComponent::class)
            ->call('sync')
            ->assertRedirect(route('legal-entity.details', [$this->legalEntity]))
            ->assertSessionHas('success', __('forms.sync_successfull'));
    }

    public function test_failed_sync_preserves_status_and_does_not_reload_the_page(): void
    {
        $this->login(LegalEntityStatus::SUSPENDED->value);
        $this->mock(LegalEntityApi::class)->shouldReceive('getDetails')->once()
            ->andThrow(new RuntimeException('Request failed'));

        Livewire::test(ReloadedLegalEntityDetailsComponent::class)
            ->call('sync')
            ->assertNoRedirect()
            ->assertSee(__('legal-entity.request.sync.errors.fail'));

        $this->assertSame(LegalEntityStatus::SUSPENDED->value, $this->legalEntity->fresh()->status);
        $this->assertSame(JobStatus::FAILED->value, $this->legalEntity->fresh()->sync_status);
    }

    protected function login(string $status, bool $hasDeclarationScope = true): User
    {
        $type = LegalEntityType::whereName(LegalEntity::TYPE_PRIMARY_CARE)->firstOrFail();
        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_type_id' => $type->id,
            'status' => $status,
            'is_active' => true,
            'sync_status' => JobStatus::COMPLETED->value,
        ]);
        $this->app->instance('legalEntity', $this->legalEntity);
        setPermissionsTeamId($this->legalEntity->id);
        $user = User::factory()->create();
        $this->actingAs($user, 'ehealth');
        $scopes = $hasDeclarationScope ? ['legal_entity:read', 'declaration:read'] : ['legal_entity:read'];
        $permissions = Permission::whereGuardName('ehealth')->whereIn('name', $scopes)->get();
        $role = Role::findByName('OWNER', 'ehealth');
        $role->permissions()->sync($permissions->modelKeys());
        $user->assignRole($role);
        $user->syncPermissions($scopes);
        $this->withSession(['single_role_login' => 'OWNER']);
        app(TokenStorage::class)->storeScopes($scopes);

        return $user;
    }

    protected function fakeDetails(string $status, string $type = LegalEntity::TYPE_PRIMARY_CARE): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'uuid' => $this->legalEntity->uuid,
            'type' => $type,
            'status' => $status,
            'is_active' => true,
            'license' => [
                'uuid' => (string) Str::uuid(),
                'type' => 'MSP',
                'is_active' => true,
                'is_primary' => true,
                'issued_by' => 'МОЗ',
                'issued_date' => '2025-01-01',
                'active_from_date' => '2025-01-01',
                'license_number' => 'TEST-123',
                'order_no' => 'TEST-ORDER',
            ],
            'residence_address' => [
                'type' => 'RESIDENCE', 'country' => 'UA', 'area' => 'Київська', 'settlement' => 'Київ',
            ],
            'phones' => [],
        ]);
        $this->mock(LegalEntityApi::class)->shouldReceive('getDetails')->once()->andReturn($response);
    }
}

class ReloadedLegalEntityDetailsComponent extends LegalEntityDetails
{
    // Keep the real sync action and lifecycle while omitting unrelated owner and dictionary rendering.
    public function mount(?LegalEntity $legalEntity = null): void
    {
        $this->legalEntityForm->type = $this->legalEntity->type->name;
    }

    public function render(): string
    {
        return '<div>{{ $this->statusLabel }} {{ session("success") }} {{ session("error") }}</div>';
    }
}
