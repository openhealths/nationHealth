<?php

declare(strict_types=1);

namespace Tests\Feature\LegalEntity;

use App\Classes\eHealth\EHealthResponse;
use App\Events\EHealthUserLogin;
use App\Jobs\DivisionSync;
use App\Jobs\LegalEntityDetailsSync;
use App\Listeners\FirstLoginOwnerSynchronization;
use App\Listeners\Synchronization\FirstLoginSyncChain;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\License;
use App\Models\User;
use App\Notifications\SyncNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class LegalEntityConnectionImportTest extends TestCase
{
    private LegalEntity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.default' => 'array',
            'app.date_format' => 'd.m.Y',
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
        ]);
        Http::preventStrayRequests();
        foreach ([
            '2025_12_10_000012_create_legal_entity_types_table.php',
            '2025_12_10_000014_create_legal_entities_table.php',
            '2025_12_10_000028_create_licenses_table.php',
            '2025_12_10_000032_create_phones_table.php',
            '2025_12_10_000042_create_addresses_table.php',
        ] as $migration) {
            (require database_path('migrations/install/' . $migration))->up();
        }
        $this->entity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_type_id' => LegalEntityType::whereName('PRIMARY_CARE')->value('id'),
            'status' => 'NEW',
        ]);
    }

    #[DataProvider('activities')]
    public function test_connection_import_replaces_local_license_values_with_the_received_values(?string $stored, ?string $received): void
    {
        $license = $this->entity->licenses()->create($this->licenseData($stored) + [
            'license_number' => 'OLD-123',
            'expiry_date' => '2030-01-01',
        ]);
        $incoming = array_replace($this->licenseData($received), [
            'uuid' => $license->uuid,
            'license_number' => null,
            'expiry_date' => null,
            'is_active' => true,
            'ehealth_inserted_at' => '2025-01-01T00:00:00Z',
            'ehealth_updated_at' => '2026-01-01T00:00:00Z',
        ]);
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('syncPermissions')->once()->with([])->andReturnSelf();
        $job = new LegalEntityDetailsSync($this->entity, isFirstLogin: true);
        $job->user = $user;
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'uuid' => $this->entity->uuid,
            'type' => 'PRIMARY_CARE',
            'status' => 'ACTIVE',
            'email' => 'remote@example.test',
            'beneficiary' => 'Отриманий бенефіціар',
            'accreditation' => ['category' => 'FIRST', 'order_no' => '123'],
            'archive' => [['date' => '2025-01-01', 'place' => 'Адреса архіву']],
            'license' => $incoming,
            'residence_address' => [
                'type' => 'RESIDENCE', 'country' => 'UA', 'area' => 'Київська', 'settlement' => 'Київ',
            ],
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
        ]);

        new ReflectionMethod($job, 'processResponse')->invoke($job, $response);

        $this->assertSame($received, $license->fresh()->what_licensed);
        $this->assertDatabaseHas('licenses', [
            'id' => $license->id,
            'uuid' => $incoming['uuid'],
            'license_number' => null,
            'expiry_date' => null,
            'is_primary' => true,
            'is_active' => true,
            'ehealth_inserted_at' => '2025-01-01',
            'ehealth_updated_at' => '2026-01-01',
        ]);
        $this->assertSame(1, License::count());
        $this->assertSame('ACTIVE', $this->entity->fresh()->status);
        $this->assertSame('remote@example.test', $this->entity->email);
        $this->assertSame('FIRST', $this->entity->accreditation['category']);
        $this->assertSame('Адреса архіву', $this->entity->archive[0]['place']);
        $this->assertSame('Київ', $this->entity->addresses()->sole()->settlement);
        $this->assertSame('+380501234567', $this->entity->phones()->sole()->number);
    }

    public static function activities(): array
    {
        return [
            'remote text replaces local null' => [null, 'Значення ЕСОЗ'],
            'remote zero replaces local null' => [null, '0'],
            'remote null replaces local text' => ['Старе значення', null],
            'remote text replaces local text' => ['Старе значення', 'Значення ЕСОЗ'],
            'remote null stays null' => [null, null],
        ];
    }

    #[DataProvider('launchContexts')]
    public function test_details_import_is_scheduled_only_for_an_entity_marked_by_connection_creation(
        string $status,
        ?string $syncStatus,
        bool $firstUserLogin,
        ?string $expectedJob
    ): void {
        Bus::fake();
        $this->entity->update(['status' => $status, 'sync_status' => $syncStatus]);
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('load')->once()->with('roles', 'permissions', 'party')->andReturnSelf();
        if ($expectedJob !== null) {
            $user->shouldReceive('notify')->once()->with(Mockery::type(SyncNotification::class));
            $this->expectOutputRegex('/First login synchronization started/');
        } else {
            $user->shouldNotReceive('notify');
        }
        $chain = Mockery::mock(FirstLoginSyncChain::class);
        if ($expectedJob === DivisionSync::class) {
            $chain->shouldReceive('build')->once()->with($this->entity, [], $user)->andReturn(new DivisionSync($this->entity));
        } else {
            $chain->shouldNotReceive('build');
        }
        $this->instance(FirstLoginSyncChain::class, $chain);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'test-token');
        $event = new EHealthUserLogin($user, $this->entity, (string) Str::uuid(), [], $firstUserLogin);

        new FirstLoginOwnerSynchronization()->handle($event);

        if ($expectedJob === null) {
            Bus::assertNothingBatched();
        } else {
            Bus::assertBatchCount(1);
            Bus::assertBatched(static fn ($batch): bool => $batch->jobs[0] instanceof $expectedJob);
            $this->assertSame('PROCESSING', $this->entity->fresh()->syncStatus);
        }
    }

    public static function launchContexts(): array
    {
        return [
            'new connection and new user' => ['NEW', null, true, LegalEntityDetailsSync::class],
            'new connection and existing user' => ['NEW', null, false, LegalEntityDetailsSync::class],
            'reconnection after completed synchronization' => ['NEW', 'COMPLETED', false, LegalEntityDetailsSync::class],
            'connection import already processing' => ['NEW', 'PROCESSING', false, null],
            'failed import waits for retry' => ['NEW', 'FAILED', false, null],
            'ordinary login' => ['ACTIVE', 'COMPLETED', false, null],
            'new user in an existing entity' => ['ACTIVE', 'COMPLETED', true, null],
            'first synchronization after registration' => ['ACTIVE', null, true, DivisionSync::class],
        ];
    }

    private function licenseData(?string $activity): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'type' => 'MSP',
            'is_primary' => true,
            'issued_by' => 'МОЗ',
            'issued_date' => '2025-01-01',
            'active_from_date' => '2025-01-01',
            'order_no' => '123',
            'what_licensed' => $activity,
        ];
    }
}
