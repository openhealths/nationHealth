<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Jobs\DivisionSync;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Permission;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * When a job fails with 401/403/429 it has to continue under a user whose session may serve it.
 */
class EHealthJobUserSelectionTest extends TestCase
{
    use DatabaseTransactions;

    private const string SCOPE = DivisionSync::SCOPE_REQUIRED;

    private LegalEntity $legalEntity;

    private LegalEntity $otherLegalEntity;

    private User $initiator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legalEntity = $this->createLegalEntity();
        $this->otherLegalEntity = $this->createLegalEntity();

        // Permissions are limited to those that the type of the legal entity allows
        Permission::findOrCreate(self::SCOPE, 'web')
            ->legalEntityTypes()
            ->syncWithoutDetaching([$this->legalEntity->legal_entity_type_id]);

        $this->initiator = $this->createUser($this->legalEntity);
    }

    public function test_another_user_of_the_same_legal_entity_continues_the_sync(): void
    {
        $colleague = $this->createUser($this->legalEntity);
        $this->openSession($colleague, $this->legalEntity->uuid, 'colleague-token');

        $this->assertTrue($colleague->is($this->job()->pick($this->initiator)));
        $this->assertSame('colleague-token', $this->job()->tokenOf($colleague));
    }

    public function test_user_without_the_required_scope_in_the_session_is_skipped(): void
    {
        $colleague = $this->createUser($this->legalEntity);
        $this->openSession($colleague, $this->legalEntity->uuid, 'colleague-token', ['employee:read']);

        $this->assertNull($this->job()->pick($this->initiator));
    }

    public function test_user_without_the_required_permission_is_skipped(): void
    {
        $colleague = $this->createUser($this->legalEntity, false);
        $this->openSession($colleague, $this->legalEntity->uuid, 'colleague-token');

        $this->assertNull($this->job()->pick($this->initiator));
    }

    public function test_session_issued_for_another_legal_entity_is_skipped(): void
    {
        $colleague = $this->createUser($this->legalEntity);
        $this->openSession($colleague, $this->otherLegalEntity->uuid, 'other-entity-token');

        $this->assertNull($this->job()->pick($this->initiator));
        $this->assertNull($this->job()->pick($this->initiator, 'revoked-token'));
    }

    public function test_expired_session_is_skipped(): void
    {
        $colleague = $this->createUser($this->legalEntity);
        $this->openSession($colleague, $this->legalEntity->uuid, 'expired-token', null, now()->subMinute()->timestamp);

        $this->assertNull($this->job()->pick($this->initiator));
    }

    public function test_session_that_is_not_bound_to_the_user_is_skipped(): void
    {
        $colleague = $this->createUser($this->legalEntity);
        $this->openSession($colleague, $this->legalEntity->uuid, 'stale-token', null, null, false);

        $this->assertNull($this->job()->pick($this->initiator));
    }

    public function test_same_user_is_not_reused_unless_the_token_was_revoked(): void
    {
        $this->openSession($this->initiator, $this->legalEntity->uuid, 'new-token');

        $this->assertNull($this->job()->pick($this->initiator));
        $this->assertTrue($this->initiator->is($this->job()->pick($this->initiator, 'old-token')));
    }

    public function test_same_user_with_the_revoked_token_is_not_reused(): void
    {
        $this->openSession($this->initiator, $this->legalEntity->uuid, 'old-token');

        $this->assertNull($this->job()->pick($this->initiator, 'old-token'));
    }

    public function test_the_bound_session_of_the_same_user_wins_over_a_stale_one(): void
    {
        $this->openSession($this->initiator, $this->legalEntity->uuid, 'old-token', null, null, false);
        $this->openSession($this->initiator, $this->legalEntity->uuid, 'new-token');

        $this->assertTrue($this->initiator->is($this->job()->pick($this->initiator, 'old-token')));
        $this->assertSame('new-token', $this->job()->tokenOf($this->initiator->refresh()));
    }

    public function test_colleague_is_chosen_when_the_initiator_session_is_not_usable(): void
    {
        $this->openSession($this->initiator, $this->legalEntity->uuid, 'old-token');
        $colleague = $this->createUser($this->legalEntity);
        $this->openSession($colleague, $this->legalEntity->uuid, 'colleague-token');

        $this->assertTrue($colleague->is($this->job()->pick($this->initiator, 'old-token')));
    }

    public function test_token_rejected_earlier_in_the_chain_is_not_taken_again(): void
    {
        $this->openSession($this->initiator, $this->legalEntity->uuid, 'token-a');
        $colleague = $this->createUser($this->legalEntity);
        $this->openSession($colleague, $this->legalEntity->uuid, 'token-b');

        // The chain A -> B -> A: B has just been rejected and A was rejected before
        $this->assertNull($this->job()->pick($colleague, 'token-b', [hash('sha256', 'token-a')]));
        $this->assertTrue($this->initiator->is($this->job()->pick($colleague, 'token-b')));
    }

    private function job(): DivisionSync
    {
        return new class($this->legalEntity) extends DivisionSync {
            public function pick(User $user, ?string $revokedToken = null, array $rejectedTokenHashes = []): ?User
            {
                return $this->getUserWithActiveSession($user, $revokedToken, $rejectedTokenHashes);
            }

            public function tokenOf(User $user): string
            {
                return $this->getSessionToken($user);
            }
        };
    }

    private function createLegalEntity(): LegalEntity
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        return LegalEntity::create([
            'uuid' => (string)Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
    }

    private function createUser(LegalEntity $legalEntity, bool $withPermission = true): User
    {
        $party = Party::create([
            'uuid' => (string)Str::uuid(),
            'first_name' => 'Sync',
            'last_name' => 'Tester',
            'tax_id' => (string)random_int(1000000000, 9999999999),
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string)Str::uuid(),
            'email' => 'sync-' . Str::random(8) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string)Str::uuid(),
            'full_name' => 'Sync Tester',
            'employee_type' => Role::OWNER->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Owner',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $user->employees()->attach($employee->id);

        if ($withPermission) {
            setPermissionsTeamId($legalEntity->id);
            $user->givePermissionToParent(
                Permission::findOrCreate(self::SCOPE, 'web'),
                Permission::findOrCreate(self::SCOPE, 'ehealth')
            );
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $user;
    }

    /**
     * @param  list<string>|null  $scopes
     */
    private function openSession(
        User $user,
        string $legalEntityUuid,
        string $token,
        ?array $scopes = null,
        ?int $expiresAt = null,
        bool $bind = true
    ): void {
        $sessionId = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => base64_encode(serialize([
                config('ehealth.api.oauth.bearer_token') => $token,
                config('ehealth.api.oauth.token_scopes') => $scopes ?? [self::SCOPE],
                'auth_token_expires_at' => $expiresAt ?? now()->addHour()->timestamp,
                'ehealth_legal_entity_uuid' => $legalEntityUuid,
            ])),
            'last_activity' => time(),
        ]);

        if ($bind) {
            $user->forceFill(['session_id' => $sessionId])->save();
        }
    }
}
