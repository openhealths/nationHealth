<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Enums\Contract\Status;
use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Policies\ContractRequestPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractRequestPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function migrateFreshUsing(): array
    {
        return [
            '--path' => [
                'database/migrations/install',
                'database/migrations/update/0_1',
            ],
        ];
    }

    public function test_owner_can_approve_when_permission_is_granted(): void
    {
        Gate::before(static fn () => true);

        $legalEntity = $this->createLegalEntity();
        $this->instance('legalEntity', $legalEntity);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Test',
            'last_name' => 'User',
            'tax_id' => '1234567890',
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'party_id' => $party->id,
        ]);
        $contractRequestApprove = $this->createContractRequest($legalEntity, Status::APPROVED);
        $contractRequestSign = $this->createContractRequest($legalEntity, Status::NHS_SIGNED);

        $policy = new ContractRequestPolicy();

        $this->assertTrue($policy->approve($user, $contractRequestApprove)->allowed());
        $this->assertTrue($policy->sign($user, $contractRequestSign)->allowed());
    }

    public function test_policy_denies_access_for_foreign_legal_entity(): void
    {
        $legalEntity = $this->createLegalEntity();
        $this->instance('legalEntity', $legalEntity);

        $foreignEntity = $this->createLegalEntity();
        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Test',
            'last_name' => 'User',
            'tax_id' => '1234567890',
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'party_id' => $party->id,
        ]);
        $contractRequest = $this->createContractRequest($foreignEntity, Status::APPROVED);

        $policy = new ContractRequestPolicy();

        $this->assertTrue($policy->approve($user, $contractRequest)->denied());
    }

    public function test_create_capitation_is_denied_for_all_legal_entity_types(): void
    {
        Gate::before(static fn () => true);

        foreach (['PRIMARY_CARE', 'OUTPATIENT', 'PHARMACY'] as $typeName) {
            $legalEntity = $this->createLegalEntity($typeName);
            $this->instance('legalEntity', $legalEntity);

            $user = $this->createUser();
            $policy = new ContractRequestPolicy();

            $this->assertTrue(
                $policy->createCapitation($user)->denied(),
                "createCapitation should be denied for {$typeName}"
            );
        }
    }

    public function test_create_reimbursement_allowed_only_for_pharmacy(): void
    {
        Gate::before(static fn () => true);

        $pharmacy = $this->createLegalEntity('PHARMACY');
        $this->instance('legalEntity', $pharmacy);
        $user = $this->createUser();
        $policy = new ContractRequestPolicy();

        $this->assertTrue($policy->createReimbursement($user)->allowed());

        foreach (['PRIMARY_CARE', 'OUTPATIENT'] as $typeName) {
            $legalEntity = $this->createLegalEntity($typeName);
            $this->instance('legalEntity', $legalEntity);

            $this->assertTrue(
                $policy->createReimbursement($user)->denied(),
                "createReimbursement should be denied for {$typeName}"
            );
        }
    }

    private function createUser(): User
    {
        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Test',
            'last_name' => 'User',
            'tax_id' => '1234567890',
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
        ]);

        return User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'test-'.Str::random(8).'@example.com',
            'password' => bcrypt('password'),
            'party_id' => $party->id,
        ]);
    }

    private function createLegalEntity(string $typeName = 'PHARMACY'): LegalEntity
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')
            ->where('name', $typeName)
            ->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')
                ->insertGetId(['name' => $typeName]);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
    }

    private function createContractRequest(LegalEntity $legalEntity, Status $status): ContractRequest
    {
        return ContractRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'contractor_legal_entity_id' => $legalEntity->uuid,
            'contractor_owner_id' => (string) Str::uuid(),
            'type' => 'REIMBURSEMENT',
            'status' => $status->value,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
    }
}
