<?php

declare(strict_types=1);

namespace Tests\Feature\Party;

use App\Classes\eHealth\Api\Party as PartyApi;
use App\Classes\eHealth\EHealthResponse;
use App\Jobs\PartyVerificationDetailsUpsert;
use App\Jobs\PartyVerificationSync;
use App\Livewire\Party\PartyVerificationIndex;
use App\Livewire\Party\PartyVerify;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\Party\PartyVerificationCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PartyVerificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

    private function grantPartyVerificationPermissions(User $user, LegalEntity $legalEntity): void
    {
        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $user->givePermissionToParent(
            Permission::findOrCreate('party_verification:details', 'web'),
            Permission::findOrCreate('party_verification:write', 'web'),
        );
    }

    /**
     * @return array{legalEntity: LegalEntity, party: Party, user: User}
     */
    private function createVerificationFixture(string $verificationStatus = 'NOT_VERIFIED'): array
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'verification_status' => $verificationStatus,
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'John Doe',
            'employee_type' => \App\Enums\User\Role::HR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'HR Manager',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);

        $this->grantPartyVerificationPermissions($user, $legalEntity);
        $this->actingAs($user);
        $this->instance('legalEntity', $legalEntity);
        $this->withSession([
            config('ehealth.api.oauth.token_scopes') => [
                'party_verification:details',
                'party_verification:write',
            ],
            config('ehealth.api.oauth.bearer_token') => 'test-token',
        ]);

        return compact('legalEntity', 'party', 'user');
    }

    public function test_verification_index_renders_local_data_without_api_calls(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party] = $this->createVerificationFixture('NOT_VERIFIED');

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $mockPartyApi->shouldNotReceive('getDetails');
        $this->instance(PartyApi::class, $mockPartyApi);

        Livewire::test(PartyVerificationIndex::class, ['legalEntity' => $legalEntity])
            ->assertSee($party->fullName)
            ->assertSeeHtml('NOT_VERIFIED')
            ->set('dracsDeathStatus', 'NOT_VERIFIED')
            ->assertHasNoErrors();
    }

    public function test_verification_index_sync_via_bulk_list_when_read_scope_present(): void
    {
        Bus::fake();

        ['legalEntity' => $legalEntity, 'party' => $party] = $this->createVerificationFixture('VERIFICATION_NEEDED');

        $tokenKey = config('ehealth.api.oauth.bearer_token');
        $scopesKey = config('ehealth.api.oauth.token_scopes');

        $this->withSession([
            $tokenKey => 'test-token',
            $scopesKey => ['party_verification:details', 'party_verification:write', PartyVerificationSync::SCOPE_REQUIRED],
        ]);

        $listItem = [
            'party_id' => $party->uuid,
            'verification_status' => 'VERIFIED',
            'details' => [
                'drfo' => ['verification_status' => 'VERIFIED'],
                'dracs_death' => ['verification_status' => 'VERIFIED'],
                'dms_passport' => ['verification_status' => 'VERIFIED'],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('validate')->andReturn([$listItem]);
        $mockResponse->shouldReceive('map')->andReturn([$party->uuid => $listItem]);
        $mockResponse->shouldReceive('isNotLast')->andReturn(false);

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $mockPartyApi->shouldReceive('getMany')
            ->once()
            ->with([], 1)
            ->andReturn($mockResponse);
        $this->instance(PartyApi::class, $mockPartyApi);

        Livewire::test(PartyVerificationIndex::class, ['legalEntity' => $legalEntity])
            ->call('sync')
            ->assertHasNoErrors()
            ->assertDispatched('flashMessage', function (string $eventName, array $params): bool {
                $payload = isset($params['message']) ? $params : ($params[0] ?? []);

                return ($payload['message'] ?? null) === __('party_verification.messages.sync_success')
                    && ($payload['type'] ?? null) === 'success';
            });

        Bus::assertNothingBatched();
        $this->assertSame('VERIFIED', PartyVerificationCache::get($party->uuid)['verification_status'] ?? null);
    }

    public function test_verification_index_sync_queues_bulk_remaining_pages_when_read_scope_present(): void
    {
        Bus::fake();

        ['legalEntity' => $legalEntity, 'party' => $party] = $this->createVerificationFixture('VERIFICATION_NEEDED');

        $tokenKey = config('ehealth.api.oauth.bearer_token');
        $scopesKey = config('ehealth.api.oauth.token_scopes');

        $this->withSession([
            $tokenKey => 'test-token',
            $scopesKey => [PartyVerificationSync::SCOPE_REQUIRED],
        ]);

        $listItem = [
            'party_id' => $party->uuid,
            'verification_status' => 'NOT_VERIFIED',
            'details' => [
                'drfo' => ['verification_status' => 'NOT_VERIFIED'],
                'dracs_death' => ['verification_status' => 'NOT_VERIFIED'],
                'dms_passport' => ['verification_status' => 'NOT_VERIFIED'],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('validate')->andReturn([$listItem]);
        $mockResponse->shouldReceive('map')->andReturn([$party->uuid => $listItem]);
        $mockResponse->shouldReceive('isNotLast')->andReturn(true);

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $mockPartyApi->shouldReceive('getMany')
            ->once()
            ->andReturn($mockResponse);
        $this->instance(PartyApi::class, $mockPartyApi);

        Livewire::test(PartyVerificationIndex::class, ['legalEntity' => $legalEntity])
            ->call('sync')
            ->assertHasNoErrors()
            ->assertDispatched('flashMessage', function (string $eventName, array $params): bool {
                $payload = isset($params['message']) ? $params : ($params[0] ?? []);

                return ($payload['message'] ?? null) === __('party_verification.messages.sync_page_done')
                    && ($payload['type'] ?? null) === 'success';
            });

        Bus::assertBatched(function ($batch) {
            return $batch->name === 'Party Verification Status Sync'
                && count($batch->jobs) === 1
                && $batch->jobs[0] instanceof PartyVerificationSync
                && ($batch->options['sync_entity'] ?? null) === LegalEntity::ENTITY_PARTY_VERIFICATION;
        });
    }

    public function test_verification_index_sync_via_details_when_only_details_scope_present(): void
    {
        Bus::fake();
        config(['ehealth.party_verification.details_sync_page_size' => 1]);

        ['legalEntity' => $legalEntity, 'party' => $party, 'user' => $user] = $this->createVerificationFixture('NOT_VERIFIED');

        $secondParty = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Jane',
            'last_name' => 'Roe',
            'tax_id' => '0987654321',
            'birth_date' => '1991-02-02',
            'gender' => 'FEMALE',
            'verification_status' => 'NOT_VERIFIED',
        ]);

        Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Jane Roe',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $secondParty->id,
        ]);

        $tokenKey = config('ehealth.api.oauth.bearer_token');
        $scopesKey = config('ehealth.api.oauth.token_scopes');

        $this->withSession([
            $tokenKey => 'test-token',
            $scopesKey => ['party_verification:details', 'party_verification:write'],
        ]);

        $detailPayload = [
            'verification_status' => 'VERIFIED',
            'details' => [
                'drfo' => ['verification_status' => 'VERIFIED'],
                'dracs_death' => ['verification_status' => 'VERIFIED'],
                'dms_passport' => ['verification_status' => 'VERIFIED'],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailPayload);

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $mockPartyApi->shouldReceive('getDetails')
            ->once()
            ->andReturn($mockResponse);
        $this->instance(PartyApi::class, $mockPartyApi);

        Livewire::test(PartyVerificationIndex::class, ['legalEntity' => $legalEntity])
            ->call('sync')
            ->assertHasNoErrors();

        Bus::assertBatched(function ($batch) {
            return $batch->name === 'Party Verification Details Sync'
                && count($batch->jobs) === 1
                && $batch->jobs[0] instanceof PartyVerificationDetailsUpsert
                && ($batch->options['sync_entity'] ?? null) === LegalEntity::ENTITY_PARTY_VERIFICATION;
        });
    }

    public function test_verification_index_sync_requires_details_or_read_scope(): void
    {
        Bus::fake();

        ['legalEntity' => $legalEntity] = $this->createVerificationFixture('VERIFICATION_NEEDED');

        $this->withSession([
            config('ehealth.api.oauth.bearer_token') => 'test-token',
            config('ehealth.api.oauth.token_scopes') => ['employee:read'],
        ]);

        Livewire::test(PartyVerificationIndex::class, ['legalEntity' => $legalEntity])
            ->call('sync')
            ->assertHasNoErrors();

        Bus::assertNothingBatched();
    }

    public function test_party_verify_allows_updating_status(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party] = $this->createVerificationFixture('NOT_VERIFIED');

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $this->instance(PartyApi::class, $mockPartyApi);

        $detailResponse = [
            'verification_status' => 'NOT_VERIFIED',
            'details' => [
                'drfo' => [
                    'verification_status' => 'VERIFIED',
                    'verification_reason' => 'RULES_PASSED',
                    'result' => 100,
                ],
                'dracs_death' => [
                    'verification_status' => 'NOT_VERIFIED',
                    'verification_reason' => 'RULES_TRIGGERED',
                    'verification_comment' => 'Triggered',
                ],
                'mvs_passport' => [
                    'verification_status' => 'VERIFIED',
                ],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailResponse);
        $mockResponse->shouldReceive('getData')->andReturn($detailResponse);

        $mockPartyApi->shouldReceive('getDetails')
            ->with($party->uuid)
            ->andReturn($mockResponse);

        $updateResponse = Mockery::mock(EHealthResponse::class);
        $mockPartyApi->shouldReceive('update')
            ->with($party->uuid, [
                'dracs_death' => [
                    'verification_status' => 'VERIFIED',
                    'verification_reason' => 'MANUAL_DECEASED',
                    'verification_comment' => 'Employee death confirmed',
                ],
            ])
            ->once()
            ->andReturn($updateResponse);

        Livewire::test(PartyVerify::class, ['legalEntity' => $legalEntity, 'party' => $party])
            ->assertSet('canUpdateVerification', true)
            ->call('checkAndOpenModal')
            ->assertSet('showUpdateModal', true)
            ->assertSet('reason', '')
            ->set('reason', 'MANUAL_DECEASED')
            ->set('comment', 'Employee death confirmed')
            ->call('updateStatus')
            ->assertHasNoErrors()
            ->assertDispatched('flashMessage', function (string $eventName, array $params): bool {
                $payload = isset($params['message']) ? $params : ($params[0] ?? []);

                return ($payload['message'] ?? null) === __('party_verification.messages.update_success')
                    && ($payload['type'] ?? null) === 'success';
            });
    }

    public function test_party_verify_sync_one_fetches_details_and_updates_cache(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party] = $this->createVerificationFixture('NOT_VERIFIED');

        $detailResponse = [
            'verification_status' => 'VERIFIED',
            'details' => [
                'drfo' => ['verification_status' => 'VERIFIED'],
                'dracs_death' => ['verification_status' => 'VERIFIED'],
                'dms_passport' => ['verification_status' => 'VERIFIED'],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailResponse);
        $mockResponse->shouldReceive('getData')->andReturn($detailResponse);

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $mockPartyApi->shouldReceive('getDetails')
            ->with($party->uuid)
            ->times(3)
            ->andReturn($mockResponse);
        $this->instance(PartyApi::class, $mockPartyApi);

        Livewire::test(PartyVerify::class, ['legalEntity' => $legalEntity, 'party' => $party])
            ->call('syncOne')
            ->assertHasNoErrors()
            ->assertSet('verificationDetails.verification_status', 'VERIFIED')
            ->assertDispatched('flashMessage', function (string $eventName, array $params): bool {
                $payload = isset($params['message']) ? $params : ($params[0] ?? []);

                return ($payload['message'] ?? null) === __('party_verification.messages.sync_one_success')
                    && ($payload['type'] ?? null) === 'success';
            });

        $this->assertSame('VERIFIED', $party->fresh()->verification_status);
        $this->assertSame('VERIFIED', PartyVerificationCache::get($party->uuid)['verification_status'] ?? null);
    }

    public function test_party_verify_normalizes_narrative_reason_codes_to_live_schema(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party] = $this->createVerificationFixture('NOT_VERIFIED');

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $this->instance(PartyApi::class, $mockPartyApi);

        $detailResponse = [
            'verification_status' => 'NOT_VERIFIED',
            'details' => [
                'dracs_death' => [
                    'verification_status' => 'NOT_VERIFIED',
                    'verification_reason' => 'RULES_TRIGGERED',
                ],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailResponse);
        $mockResponse->shouldReceive('getData')->andReturn($detailResponse);

        $mockPartyApi->shouldReceive('getDetails')
            ->with($party->uuid)
            ->andReturn($mockResponse);

        $updateResponse = Mockery::mock(EHealthResponse::class);
        $mockPartyApi->shouldReceive('update')
            ->with($party->uuid, [
                'dracs_death' => [
                    'verification_status' => 'VERIFIED',
                    'verification_reason' => 'MANUAL_DECEASED',
                    'verification_comment' => 'Confirmed via legacy spelling',
                ],
            ])
            ->once()
            ->andReturn($updateResponse);

        Livewire::test(PartyVerify::class, ['legalEntity' => $legalEntity, 'party' => $party])
            ->call('checkAndOpenModal')
            ->set('reason', 'MANUAL_CONFIRMED')
            ->assertSet('reason', 'MANUAL_DECEASED')
            ->set('comment', 'Confirmed via legacy spelling')
            ->call('updateStatus')
            ->assertHasNoErrors();
    }

    public function test_party_verify_rejects_reason_outside_api_enum(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party] = $this->createVerificationFixture('NOT_VERIFIED');

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $this->instance(PartyApi::class, $mockPartyApi);

        $detailResponse = [
            'verification_status' => 'NOT_VERIFIED',
            'details' => [
                'dracs_death' => [
                    'verification_status' => 'NOT_VERIFIED',
                ],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailResponse);
        $mockResponse->shouldReceive('getData')->andReturn($detailResponse);

        $mockPartyApi->shouldReceive('getDetails')
            ->with($party->uuid)
            ->andReturn($mockResponse);

        $mockPartyApi->shouldNotReceive('update');

        Livewire::test(PartyVerify::class, ['legalEntity' => $legalEntity, 'party' => $party])
            ->call('checkAndOpenModal')
            ->set('reason', 'AUTO_ONLINE')
            ->set('comment', 'Should fail local validation')
            ->call('updateStatus')
            ->assertHasErrors(['reason']);
    }

    public function test_party_verify_shows_dms_passport_warning_when_not_verified(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party] = $this->createVerificationFixture();

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $this->instance(PartyApi::class, $mockPartyApi);

        $detailResponse = [
            'verification_status' => 'NOT_VERIFIED',
            'details' => [
                'drfo' => [
                    'verification_status' => 'VERIFIED',
                ],
                'dracs_death' => [
                    'verification_status' => 'VERIFIED',
                ],
                'dms_passport' => [
                    'verification_status' => 'NOT_VERIFIED',
                    'verification_reason' => 'AUTO_NOT_VALID',
                ],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailResponse);
        $mockResponse->shouldReceive('getData')->andReturn($detailResponse);

        $mockPartyApi->shouldReceive('getDetails')
            ->with($party->uuid)
            ->andReturn($mockResponse);

        Livewire::test(PartyVerify::class, ['legalEntity' => $legalEntity, 'party' => $party])
            ->assertSee(__('party_verification.warning.header'), false)
            ->assertSee(__('party_verification.warning.dms_passport'), false)
            ->assertSee(__('party_verification.warning.footer'), false)
            ->assertDontSee(__('party_verification.warning.drfo'), false)
            ->assertDontSee(__('party_verification.warning.dracs_death'), false);
    }

    public function test_party_verify_allows_empty_comment(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party] = $this->createVerificationFixture('NOT_VERIFIED');

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $this->instance(PartyApi::class, $mockPartyApi);

        $detailResponse = [
            'verification_status' => 'NOT_VERIFIED',
            'details' => [
                'dracs_death' => [
                    'verification_status' => 'NOT_VERIFIED',
                    'verification_reason' => 'RULES_TRIGGERED',
                ],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailResponse);
        $mockResponse->shouldReceive('getData')->andReturn($detailResponse);
        $mockPartyApi->shouldReceive('getDetails')->with($party->uuid)->andReturn($mockResponse);

        $updateResponse = Mockery::mock(EHealthResponse::class);
        $mockPartyApi->shouldReceive('update')
            ->with($party->uuid, [
                'dracs_death' => [
                    'verification_status' => 'VERIFIED',
                    'verification_reason' => 'MANUAL_DECEASED',
                ],
            ])
            ->once()
            ->andReturn($updateResponse);

        Livewire::test(PartyVerify::class, ['legalEntity' => $legalEntity, 'party' => $party])
            ->call('checkAndOpenModal')
            ->set('reason', 'MANUAL_DECEASED')
            ->set('comment', '')
            ->call('updateStatus')
            ->assertHasNoErrors();
    }

    public function test_party_verification_cache_stores_and_retrieves_payload(): void
    {
        $partyUuid = (string) Str::uuid();
        $apiData = [
            'verification_status' => 'VERIFIED',
            'details' => [
                'drfo' => ['verification_status' => 'VERIFIED'],
                'dracs_death' => ['verification_status' => 'NOT_VERIFIED'],
                'dms_passport' => ['verification_status' => 'VERIFIED'],
            ],
        ];

        PartyVerificationCache::put($partyUuid, $apiData);

        $cached = PartyVerificationCache::get($partyUuid);

        $this->assertNotNull($cached);
        $this->assertSame('VERIFIED', $cached['verification_status']);
        $this->assertSame('VERIFIED', $cached['details']['drfo']['verification_status']);
        $this->assertSame('NOT_VERIFIED', $cached['details']['dracs_death']['verification_status']);
        $this->assertSame('VERIFIED', $cached['details']['dms_passport']['verification_status']);
    }
}
