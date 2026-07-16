<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Get approvals must send granted_resource_type + granted_resources filters.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/2115600961/Get+approvals
 */
class CarePlanApprovalSyncFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases()
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

    public function test_sync_for_care_plan_passes_get_approvals_resource_filters(): void
    {
        $carePlan = $this->makeCarePlan();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn([]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('getPatientApprovals')
            ->once()
            ->with($carePlan->person->uuid, [
                'granted_resource_type' => 'care_plan',
                'granted_resources' => $carePlan->uuid,
            ])
            ->andReturn($response);
        $this->instance(ApprovalApi::class, $api);

        app(CarePlanApprovalService::class)->syncForCarePlan($carePlan->fresh(['person']));
    }

    public function test_sync_approvals_passes_get_approvals_resource_filters(): void
    {
        $carePlan = $this->makeCarePlan();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn([]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('getPatientApprovals')
            ->once()
            ->with($carePlan->person->uuid, [
                'granted_resource_type' => 'care_plan',
                'granted_resources' => $carePlan->uuid,
            ])
            ->andReturn($response);
        $this->instance(ApprovalApi::class, $api);

        Repository::approval()->syncApprovals($carePlan->fresh(['person']), 'care_plan');
    }

    private function makeCarePlan(): CarePlan
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
            'first_name' => 'Doctor',
            'last_name' => 'Who',
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'doctor-'.Str::random(8).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Test',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        return CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employee->id,
            'legal_entity_id' => $legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Test Care Plan',
            'status' => 'draft',
        ]);
    }
}
