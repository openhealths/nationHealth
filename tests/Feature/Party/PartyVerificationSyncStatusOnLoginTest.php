<?php

declare(strict_types=1);

namespace Tests\Feature\Party;

use App\Enums\JobStatus;
use App\Events\EHealthUserLogin;
use App\Jobs\DivisionSync;
use App\Jobs\EmployeeRequestsSyncAll;
use App\Jobs\EmployeeSync;
use App\Jobs\PartyVerificationSync;
use App\Listeners\FirstLoginOwnerSynchronization;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\Party\PartyVerificationBulkAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

class PartyVerificationSyncStatusOnLoginTest extends TestCase
{
    use DatabaseTransactions;

    private function createFixture(string $roleType, ?string $syncStatus = 'COMPLETED'): array
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => $syncStatus,
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Test',
            'last_name' => 'User',
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'verification_status' => 'NOT_VERIFIED',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => strtolower($roleType) . '_' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => "Test {$roleType}",
            'employee_type' => $roleType,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => "{$roleType} Position",
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $user->employees()->attach($employee->id);

        $spatieRole = SpatieRole::firstOrCreate(
            ['name' => $roleType, 'guard_name' => 'web'],
            ['team_id' => config('permission.teams') ? $legalEntity->id : null]
        );
        $user->assignRole($spatieRole);

        $this->withSession([
            config('ehealth.api.oauth.bearer_token') => 'test-ehealth-token',
        ]);

        return compact('legalEntity', 'party', 'user', 'employee');
    }

    private function assertPartyVerificationSyncNotQueued(): void
    {
        $this->assertTrue(
            Bus::batched(fn ($batch) => $batch->name === 'Party Verification Status Sync')->isEmpty(),
            'Party Verification Status Sync batch was dispatched unexpectedly.'
        );
    }

    #[Test]
    public function first_login_listener_skips_standalone_party_verification_batch(): void
    {
        Bus::fake();

        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createFixture('HR');

        $event = new EHealthUserLogin(
            user: $user,
            legalEntity: $legalEntity,
            authUserUUID: $user->uuid,
            scopes: [PartyVerificationSync::SCOPE_REQUIRED],
            isFirstLogin: true
        );

        event($event);

        $this->assertPartyVerificationSyncNotQueued();
    }

    #[Test]
    public function missing_read_scope_skips_party_verification_sync(): void
    {
        Bus::fake();

        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createFixture('HR');

        $event = new EHealthUserLogin(
            user: $user,
            legalEntity: $legalEntity,
            authUserUUID: $user->uuid,
            scopes: ['party_verification:details', 'employee:read'],
            isFirstLogin: false
        );

        event($event);

        $this->assertPartyVerificationSyncNotQueued();
    }

    #[Test]
    public function login_without_read_scope_skips_even_for_admin_fixture(): void
    {
        Bus::fake();

        // ADMIN in config has no party_verification:read — must not queue bulk list sync.
        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createFixture('ADMIN');

        Cache::forget(PartyVerificationBulkAccess::cacheKey($legalEntity->id));

        $event = new EHealthUserLogin(
            user: $user,
            legalEntity: $legalEntity,
            authUserUUID: $user->uuid,
            scopes: ['party_verification:details', 'party_verification:write', 'declaration:read'],
            isFirstLogin: false
        );

        event($event);

        $this->assertPartyVerificationSyncNotQueued();
    }

    #[Test]
    public function login_with_read_scope_queues_regardless_of_role_label(): void
    {
        Bus::fake();

        // Scope presence alone decides; fixture role is incidental.
        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createFixture('OWNER');

        Cache::forget(PartyVerificationBulkAccess::cacheKey($legalEntity->id));

        $event = new EHealthUserLogin(
            user: $user,
            legalEntity: $legalEntity,
            authUserUUID: $user->uuid,
            scopes: [PartyVerificationSync::SCOPE_REQUIRED, 'declaration:read'],
            isFirstLogin: false
        );

        event($event);

        Bus::assertBatched(function ($batch) {
            return $batch->name === 'Party Verification Status Sync'
                && count($batch->jobs) === 1
                && $batch->jobs[0] instanceof PartyVerificationSync
                && ($batch->options['sync_entity'] ?? null) === LegalEntity::ENTITY_PARTY_VERIFICATION;
        });

        $this->assertTrue(PartyVerificationBulkAccess::wasSyncedRecently($legalEntity));
    }

    #[Test]
    public function hr_login_with_read_scope_queues_party_verification_sync(): void
    {
        Bus::fake();

        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createFixture('HR');

        Cache::forget(PartyVerificationBulkAccess::cacheKey($legalEntity->id));

        $event = new EHealthUserLogin(
            user: $user,
            legalEntity: $legalEntity,
            authUserUUID: $user->uuid,
            scopes: [PartyVerificationSync::SCOPE_REQUIRED, 'employee:read'],
            isFirstLogin: false
        );

        event($event);

        Bus::assertBatched(function ($batch) {
            return $batch->name === 'Party Verification Status Sync'
                && count($batch->jobs) === 1
                && $batch->jobs[0] instanceof PartyVerificationSync
                && ($batch->options['sync_entity'] ?? null) === LegalEntity::ENTITY_PARTY_VERIFICATION;
        });

        $this->assertTrue(Cache::has(PartyVerificationBulkAccess::cacheKey($legalEntity->id)));
    }

    #[Test]
    public function login_within_24_hours_skips_due_to_cache(): void
    {
        Bus::fake();

        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createFixture('HR');

        PartyVerificationBulkAccess::markSynced($legalEntity);

        $event = new EHealthUserLogin(
            user: $user,
            legalEntity: $legalEntity,
            authUserUUID: $user->uuid,
            scopes: [PartyVerificationSync::SCOPE_REQUIRED],
            isFirstLogin: false
        );

        event($event);

        $this->assertPartyVerificationSyncNotQueued();
    }

    #[Test]
    public function login_skips_when_party_verification_already_processing(): void
    {
        Bus::fake();

        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createFixture('HR');

        Cache::forget(PartyVerificationBulkAccess::cacheKey($legalEntity->id));
        $legalEntity->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_PARTY_VERIFICATION);

        $event = new EHealthUserLogin(
            user: $user,
            legalEntity: $legalEntity,
            authUserUUID: $user->uuid,
            scopes: [PartyVerificationSync::SCOPE_REQUIRED],
            isFirstLogin: false
        );

        event($event);

        $this->assertPartyVerificationSyncNotQueued();
    }

    #[Test]
    public function first_login_chain_includes_party_verification_when_token_has_read(): void
    {
        Bus::fake();

        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createFixture('OWNER', syncStatus: null);

        Cache::forget(PartyVerificationBulkAccess::cacheKey($legalEntity->id));

        $event = new EHealthUserLogin(
            user: $user,
            legalEntity: $legalEntity,
            authUserUUID: $user->uuid,
            scopes: [PartyVerificationSync::SCOPE_REQUIRED, 'employee:read'],
            isFirstLogin: true
        );

        (new FirstLoginOwnerSynchronization())->handle($event);

        Bus::assertBatched(function ($batch) {
            if ($batch->name !== 'FirstLoginSync' || count($batch->jobs) !== 1) {
                return false;
            }

            $job = $batch->jobs[0];
            if (!$job instanceof DivisionSync) {
                return false;
            }

            $employeeRequests = (function () {
                return $this->nextEntity;
            })->call($job);

            if (!$employeeRequests instanceof EmployeeRequestsSyncAll) {
                return false;
            }

            $employee = (function () {
                return $this->nextEntity;
            })->call($employeeRequests);

            if (!$employee instanceof EmployeeSync) {
                return false;
            }

            $partyVerification = (function () {
                return $this->nextEntity;
            })->call($employee);

            return $partyVerification instanceof PartyVerificationSync;
        });

        $this->assertTrue(PartyVerificationBulkAccess::wasSyncedRecently($legalEntity));
    }

    #[Test]
    public function first_login_chain_skips_party_verification_without_read_scope(): void
    {
        Bus::fake();

        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createFixture('OWNER', syncStatus: null);

        $event = new EHealthUserLogin(
            user: $user,
            legalEntity: $legalEntity,
            authUserUUID: $user->uuid,
            scopes: ['party_verification:details', 'employee:read'],
            isFirstLogin: true
        );

        (new FirstLoginOwnerSynchronization())->handle($event);

        Bus::assertBatched(function ($batch) {
            if ($batch->name !== 'FirstLoginSync' || count($batch->jobs) !== 1) {
                return false;
            }

            $job = $batch->jobs[0];
            $employeeRequests = (function () {
                return $this->nextEntity;
            })->call($job);
            $employee = (function () {
                return $this->nextEntity;
            })->call($employeeRequests);

            $afterEmployee = (function () {
                return $this->nextEntity;
            })->call($employee);

            return !$afterEmployee instanceof PartyVerificationSync;
        });
    }
}
