<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Enums\Contract\Type;
use App\Enums\JobStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Repositories\ContractRequestRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractRequestRepositoryTest extends TestCase
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

    private ContractRequestRepository $repository;

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

        $this->repository = app(ContractRequestRepository::class);
    }

    public function test_save_from_ehealth_persists_sync_status(): void
    {
        $payload = $this->eHealthContractRequestPayload([
            'sync_status' => JobStatus::PARTIAL->value,
        ]);

        $contractRequest = $this->repository->saveFromEHealth($payload, 'REIMBURSEMENT');

        $this->assertSame(JobStatus::PARTIAL, $contractRequest->sync_status);
        $this->assertDatabaseHas('contract_requests', [
            'uuid' => $payload['id'],
            'sync_status' => JobStatus::PARTIAL->value,
        ]);
    }

    public function test_save_from_ehealth_prefers_sync_endpoint_type_over_payload(): void
    {
        // GET /api/contract_requests/{contract_type} — path type is authoritative for list sync.
        $payload = $this->eHealthContractRequestPayload([
            'type' => 'CAPITATION',
        ]);

        $contractRequest = $this->repository->saveFromEHealth($payload, 'REIMBURSEMENT');

        $this->assertSame('REIMBURSEMENT', $contractRequest->type);
    }

    public function test_save_from_ehealth_persists_id_form_and_period_dates(): void
    {
        $payload = $this->eHealthContractRequestPayload([
            'id_form' => 'GENERAL',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'inserted_at' => '2026-07-15T09:25:01.067981Z',
            'status_reason' => null,
        ]);

        $contractRequest = $this->repository->saveFromEHealth($payload, 'REIMBURSEMENT');

        $this->assertSame('GENERAL', $contractRequest->idForm);
        $this->assertSame('REIMBURSEMENT', $contractRequest->type);
        $this->assertSame('2026-01-01', $contractRequest->startDate?->format('Y-m-d'));
        $this->assertSame('2026-12-31', $contractRequest->endDate?->format('Y-m-d'));
        $this->assertSame('2026-07-15', $contractRequest->insertedAt?->format('Y-m-d'));
        $this->assertNull($contractRequest->statusReason);
    }

    public function test_contract_type_enum_values_match_ehealth(): void
    {
        $this->assertSame('REIMBURSEMENT', Type::REIMBURSEMENT->value);
        $this->assertSame('CAPITATION', Type::CAPITATION->value);
    }

    public function test_save_from_ehealth_falls_back_to_active_owner_uuid(): void
    {
        $ownerUuid = (string) Str::uuid();
        $this->createActiveOwner($ownerUuid);

        $payload = $this->eHealthContractRequestPayload();
        unset($payload['contractor_owner_id']);

        $contractRequest = $this->repository->saveFromEHealth($payload, 'REIMBURSEMENT');

        $this->assertSame($ownerUuid, $contractRequest->contractor_owner_id);
    }

    public function test_save_from_ehealth_extracts_contractor_owner_uuid_from_nested_object(): void
    {
        $ownerUuid = (string) Str::uuid();
        $payload = $this->eHealthContractRequestPayload();
        unset($payload['contractor_owner_id']);
        $payload['contractor_owner'] = ['uuid' => $ownerUuid];

        $contractRequest = $this->repository->saveFromEHealth($payload, 'REIMBURSEMENT');

        $this->assertSame($ownerUuid, $contractRequest->contractor_owner_id);
    }

    private function createActiveOwner(string $uuid): Employee
    {
        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Owner',
            'last_name' => 'Test',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        return Employee::create([
            'uuid' => $uuid,
            'employee_type' => Role::OWNER->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Owner',
            'start_date' => now()->format('Y-m-d'),
            'party_id' => $party->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eHealthContractRequestPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'contract_number' => '0001-CAP-0001',
            'status' => 'NEW',
            'type' => 'REIMBURSEMENT',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'contractor_owner_id' => (string) Str::uuid(),
            'contractor_legal_entity_id' => $this->legalEntity->uuid,
            'medical_programs' => [],
            'inserted_at' => '2026-01-01T00:00:00Z',
        ], $overrides);
    }
}
