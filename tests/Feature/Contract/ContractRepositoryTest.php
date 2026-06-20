<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Repositories\ContractRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for ContractRepository::saveFromEHealth().
 */
class ContractRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateFreshUsing(): array
    {
        return [
            '--path' => [
                'database/migrations/install',
                'database/migrations/update/0_1',
            ],
        ];
    }

    private ContractRepository $repository;

    private LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')
            ->where('name', 'PHARMACY')
            ->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')
                ->insertGetId(['name' => 'PHARMACY']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $this->instance('legalEntity', $this->legalEntity);

        $this->repository = app(ContractRepository::class);
    }

    public function test_save_from_ehealth_creates_contract_with_uuid(): void
    {
        $eHealthData = $this->eHealthContractPayload();

        $this->repository->saveFromEHealth($eHealthData);

        $this->assertDatabaseHas('contracts', [
            'uuid' => $eHealthData['id'],
            'status' => 'ACTIVE',
        ]);
    }

    public function test_save_from_ehealth_binds_legal_entity_id(): void
    {
        $eHealthData = $this->eHealthContractPayload();

        $contract = $this->repository->saveFromEHealth($eHealthData);

        $this->assertSame($this->legalEntity->id, $contract->legal_entity_id);
    }

    public function test_save_from_ehealth_extracts_contractor_legal_entity_id_from_nested_object(): void
    {
        $contractorEntityId = (string) Str::uuid();
        $eHealthData = array_merge($this->eHealthContractPayload(), [
            'contractor_legal_entity' => ['id' => $contractorEntityId, 'name' => 'Contractor'],
        ]);

        $contract = $this->repository->saveFromEHealth($eHealthData);

        $this->assertDatabaseHas('contracts', [
            'uuid' => $contract->uuid,
            'contractor_legal_entity_id' => $contractorEntityId,
        ]);
    }

    public function test_save_from_ehealth_falls_back_to_flat_contractor_legal_entity_id(): void
    {
        $contractorEntityId = (string) Str::uuid();
        $payload = $this->eHealthContractPayload();
        unset($payload['contractor_legal_entity']);
        $eHealthData = array_merge($payload, ['contractor_legal_entity_id' => $contractorEntityId]);

        $contract = $this->repository->saveFromEHealth($eHealthData);

        $this->assertDatabaseHas('contracts', [
            'uuid' => $contract->uuid,
            'contractor_legal_entity_id' => $contractorEntityId,
        ]);
    }

    public function test_save_from_ehealth_extracts_contractor_owner_id_from_nested_object(): void
    {
        $ownerId = (string) Str::uuid();
        $eHealthData = array_merge($this->eHealthContractPayload(), [
            'contractor_owner' => ['id' => $ownerId, 'party' => []],
        ]);

        $contract = $this->repository->saveFromEHealth($eHealthData);

        $this->assertDatabaseHas('contracts', [
            'uuid' => $contract->uuid,
            'contractor_owner_id' => $ownerId,
        ]);
    }

    public function test_save_from_ehealth_falls_back_to_owner_employee_uuid(): void
    {
        $ownerUuid = (string) Str::uuid();
        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Owner',
            'last_name' => 'Test',
            'tax_id' => '1234567890',
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
        ]);

        Employee::create([
            'uuid' => $ownerUuid,
            'full_name' => 'Owner Test',
            'employee_type' => Role::OWNER->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Owner',
            'start_date' => now()->format('Y-m-d'),
            'party_id' => $party->id,
        ]);

        $payload = $this->eHealthContractPayload();
        unset($payload['contractor_owner']);

        $contract = $this->repository->saveFromEHealth($payload);

        $this->assertSame($ownerUuid, $contract->contractor_owner_id);
    }

    public function test_save_from_ehealth_persists_full_data_field(): void
    {
        $eHealthData = $this->eHealthContractPayload();

        $contract = $this->repository->saveFromEHealth($eHealthData);

        $this->assertNotNull($contract->data);
        $this->assertSame($eHealthData['id'], $contract->data['id']);
    }

    public function test_save_from_ehealth_updates_existing_record_by_uuid(): void
    {
        $eHealthData = $this->eHealthContractPayload();

        $this->repository->saveFromEHealth($eHealthData);

        $updatedData = array_merge($eHealthData, ['status' => 'TERMINATED']);
        $this->repository->saveFromEHealth($updatedData);

        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseHas('contracts', [
            'uuid' => $eHealthData['id'],
            'status' => 'TERMINATED',
        ]);
    }

    public function test_save_from_ehealth_stores_medical_programs(): void
    {
        $programs = [['id' => 'prog-uuid-1'], ['id' => 'prog-uuid-2']];
        $eHealthData = array_merge($this->eHealthContractPayload(), [
            'medical_programs' => $programs,
        ]);

        $contract = $this->repository->saveFromEHealth($eHealthData);

        $this->assertSame($programs, $contract->medical_programs);
    }

    /**
     * @return array<string, mixed>
     */
    private function eHealthContractPayload(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'contract_number' => '0001-REI-0001',
            'status' => 'ACTIVE',
            'type' => 'REIMBURSEMENT',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'contractor_base' => 'На підставі статуту',
            'contractor_payment_details' => ['payer_account' => 'UA000000000000000000000000000'],
            'contractor_rmsp_amount' => null,
            'contractor_divisions' => [],
            'external_contractor_flag' => false,
            'external_contractors' => [],
            'contractor_legal_entity' => ['id' => (string) Str::uuid(), 'name' => 'Test Entity'],
            'contractor_owner' => ['id' => (string) Str::uuid(), 'party' => []],
            'nhs_signer_id' => null,
            'nhs_legal_entity_id' => null,
            'nhs_signer_base' => null,
            'nhs_payment_method' => null,
            'nhs_contract_price' => null,
            'nhs_signed_date' => null,
            'id_form' => 'GENERAL',
            'issue_city' => 'Київ',
            'medical_programs' => [],
            'inserted_at' => '2026-01-01T00:00:00Z',
        ];
    }
}
