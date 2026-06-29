<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Enums\Contract\ContractStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Contracts\Contract;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Services\MedicalEvents\DeviceProgramParticipationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceProgramParticipationGuardTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_blocks_sign_when_program_missing_from_active_contracts(): void
    {
        $legalEntity = $this->createLegalEntity();
        $programId = (string) Str::uuid();
        $otherProgramId = (string) Str::uuid();

        Contract::query()->create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'contractor_legal_entity_id' => $legalEntity->uuid,
            'contractor_owner_id' => (string) Str::uuid(),
            'status' => ContractStatus::ACTIVE->value,
            'contract_number' => 'TEST-001',
            'medical_programs' => [$otherProgramId],
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $employee = \App\Models\Employee\Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Test Doctor',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
        ]);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employee->id,
            'legal_entity_id' => $legalEntity->id,
            'status' => 'active',
            'title' => 'Test plan',
            'period_start' => now()->format('Y-m-d'),
            'period_end' => now()->addMonth()->format('Y-m-d'),
        ]);

        $activity = CarePlanActivity::create([
            'care_plan_id' => $carePlan->id,
            'author_id' => $employee->id,
            'kind' => 'device_request',
            'status' => 'draft',
            'program' => $programId,
            'product_reference' => (string) Str::uuid(),
        ]);

        $guard = app(DeviceProgramParticipationGuard::class);
        $participating = $guard->resolveParticipatingProgramIds($legalEntity, false);

        $this->assertSame([$otherProgramId], $participating);

        $assessment = $guard->assess($carePlan, $activity, $legalEntity);
        $this->assertNotNull($assessment->blockingMessage());
    }

    public function test_extracts_program_ids_from_contract_medical_program_objects(): void
    {
        $legalEntity = $this->createLegalEntity();
        $programId = (string) Str::uuid();

        Contract::query()->create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'contractor_legal_entity_id' => $legalEntity->uuid,
            'contractor_owner_id' => (string) Str::uuid(),
            'status' => ContractStatus::ACTIVE->value,
            'contract_number' => 'TEST-002',
            'medical_programs' => [
                ['id' => $programId],
            ],
        ]);

        $participating = app(DeviceProgramParticipationGuard::class)
            ->resolveParticipatingProgramIds($legalEntity, false);

        $this->assertSame([$programId], $participating);
    }

    private function createLegalEntity(): LegalEntity
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
    }
}
