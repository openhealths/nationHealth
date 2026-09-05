<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ReferralControllerProcessCancelUsageTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected LegalEntity $legalEntity;

    protected Employee $employee;

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

    protected function setUp(): void
    {
        parent::setUp();

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'ref_ctrl_' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Д-р Іван Петренко',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);
        $this->user->employees()->attach($this->employee->id);

        if (config('permission.teams')) {
            setPermissionsTeamId($this->legalEntity->id);
        }
    }

    public function test_process_passes_patient_uuid_and_payload_in_correct_order(): void
    {
        $referralUuid = (string) Str::uuid();
        $patientUuid = (string) Str::uuid();

        $mock = Mockery::mock(ReferralRequestLifecycleService::class);
        $mock->shouldReceive('takeIntoWork')
            ->once()
            ->withArgs(function (string $uuid, Employee $employee, ?string $patientId, array $payload) use ($referralUuid, $patientUuid): bool {
                return $uuid === $referralUuid
                    && $employee->id === $this->employee->id
                    && $patientId === $patientUuid
                    && ($payload['note'] ?? null) === 'take';
            })
            ->andReturn(['status' => 'in_progress']);

        $this->app->instance(ReferralRequestLifecycleService::class, $mock);

        $response = $this->actingAsDoctor()->postJson($this->url($referralUuid, 'process'), [
            'patient_uuid' => $patientUuid,
            'payload' => ['note' => 'take'],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_cancel_usage_requires_patient_id_and_forwards_it(): void
    {
        $referralUuid = (string) Str::uuid();
        $patientId = (string) Str::uuid();

        $mock = Mockery::mock(ReferralRequestLifecycleService::class);
        $mock->shouldReceive('cancelUsage')
            ->once()
            ->withArgs(function (string $uuid, string $passedPatientId, array $payload) use ($referralUuid, $patientId): bool {
                return $uuid === $referralUuid
                    && $passedPatientId === $patientId
                    && ($payload['explanatory_letter'] ?? null) === 'Пацієнт не зʼявився';
            })
            ->andReturn(['status' => 'active']);

        $this->app->instance(ReferralRequestLifecycleService::class, $mock);

        $response = $this->actingAsDoctor()->postJson($this->url($referralUuid, 'cancel-usage'), [
            'patient_id' => $patientId,
            'explanatory_letter' => 'Пацієнт не зʼявився',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_cancel_usage_validation_fails_without_patient_id(): void
    {
        $referralUuid = (string) Str::uuid();

        $mock = Mockery::mock(ReferralRequestLifecycleService::class);
        $mock->shouldNotReceive('cancelUsage');
        $this->app->instance(ReferralRequestLifecycleService::class, $mock);

        $response = $this->actingAsDoctor()->postJson($this->url($referralUuid, 'cancel-usage'), [
            'payload' => ['explanatory_letter' => 'x'],
        ]);

        $response->assertStatus(422);
    }

    private function actingAsDoctor(): static
    {
        return $this->actingAs($this->user, 'ehealth')->withoutMiddleware();
    }

    private function url(string $referralUuid, string $action): string
    {
        return "/dashboard/{$this->legalEntity->id}/referrals/api/{$referralUuid}/{$action}";
    }
}
