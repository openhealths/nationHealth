<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Enums\JobStatus;
use App\Models\Contracts\ContractRequest;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractRequestModelTest extends TestCase
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

    public function test_inserted_at_is_cast_to_carbon(): void
    {
        $contractRequest = ContractRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'contractor_legal_entity_id' => (string) Str::uuid(),
            'contractor_owner_id' => (string) Str::uuid(),
            'type' => 'REIMBURSEMENT',
            'status' => 'NEW',
            'inserted_at' => '2025-06-17 12:00:00',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->assertInstanceOf(CarbonInterface::class, $contractRequest->fresh()->inserted_at);
    }

    public function test_sync_status_is_cast_to_job_status_enum(): void
    {
        $contractRequest = ContractRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'contractor_legal_entity_id' => (string) Str::uuid(),
            'contractor_owner_id' => (string) Str::uuid(),
            'type' => 'CAPITATION',
            'status' => 'NEW',
            'sync_status' => 'PARTIAL',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->assertSame(JobStatus::PARTIAL, $contractRequest->fresh()->sync_status);
    }
}
