<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Encounter;

use App\Enums\Person\ServiceRequestStatus;
use App\Livewire\Encounter\EncounterCreate;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Ensures takeIntoWork runs before the redeem modal path, not inside redeemReferral.
 */
class EnsureReferralTakenIntoWorkTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;

    private User $user;

    private Employee $employee;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Тест',
            'last_name' => 'Лікар',
            'tax_id' => '1234567890',
            'birth_date' => '1985-01-01',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'ensure_referral_'.Str::random(6).'@example.com',
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
            'full_name' => 'Д-р Тест Лікар',
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

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Пацієнт',
            'last_name' => 'Тестовий',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $this->actingAs($this->user);
    }

    public function test_skips_take_into_work_when_already_in_progress(): void
    {
        $referralUuid = (string) Str::uuid();

        ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'person_id' => $this->person->id,
            'status' => ServiceRequestStatus::IN_PROGRESS->value,
            'service_id' => '59300-00',
            'quantity' => 1,
            'intent' => 'order',
            'employee_id' => $this->employee->id,
            'priority' => 'routine',
        ]);

        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('takeIntoWork');

        $this->invokeEnsure($lifecycle, $referralUuid);
    }

    public function test_calls_take_into_work_when_local_status_is_active(): void
    {
        $referralUuid = (string) Str::uuid();

        ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'person_id' => $this->person->id,
            'status' => ServiceRequestStatus::ACTIVE->value,
            'service_id' => '59300-00',
            'quantity' => 1,
            'intent' => 'order',
            'employee_id' => $this->employee->id,
            'program_id' => null,
            'priority' => 'routine',
        ]);

        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldReceive('takeIntoWork')
            ->once()
            ->withArgs(function (string $uuid, Employee $employee, ?string $patientUuid, array $payload) use ($referralUuid): bool {
                return $uuid === $referralUuid
                    && $employee->id === $this->employee->id
                    && $payload === [];
            })
            ->andReturn([]);

        $this->invokeEnsure($lifecycle, $referralUuid);
    }

    public function test_calls_take_into_work_when_local_row_is_missing(): void
    {
        $referralUuid = (string) Str::uuid();

        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldReceive('takeIntoWork')
            ->once()
            ->with($referralUuid, Mockery::type(Employee::class), null, [])
            ->andReturn([]);

        $this->invokeEnsure($lifecycle, $referralUuid);
    }

    private function invokeEnsure(ReferralRequestLifecycleService $lifecycle, string $referralUuid): void
    {
        $component = app(EncounterCreate::class);
        $component->patientUuid = null;

        $method = new ReflectionMethod(EncounterCreate::class, 'ensureReferralTakenIntoWork');
        $method->invoke($component, $lifecycle, $referralUuid);
    }
}
