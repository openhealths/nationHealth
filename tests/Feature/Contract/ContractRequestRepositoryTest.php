<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Enums\Contract\Type;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
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

    public function test_save_from_ehealth_uses_type_from_payload_when_present(): void
    {
        $payload = $this->eHealthContractRequestPayload([
            'type' => 'CAPITATION',
        ]);

        $contractRequest = $this->repository->saveFromEHealth($payload, 'REIMBURSEMENT');

        $this->assertSame('CAPITATION', $contractRequest->type);
    }

    public function test_contract_type_enum_values_match_ehealth(): void
    {
        $this->assertSame('REIMBURSEMENT', Type::REIMBURSEMENT->value);
        $this->assertSame('CAPITATION', Type::CAPITATION->value);
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
