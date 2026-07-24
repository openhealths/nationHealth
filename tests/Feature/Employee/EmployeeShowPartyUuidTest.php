<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Employee\RequestStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\Employee\EmployeeRequestShow;
use App\Livewire\Employee\EmployeeShow;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeShowPartyUuidTest extends TestCase
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

    #[Test]
    public function employee_show_displays_party_uuid(): void
    {
        [$legalEntity, $employee, $partyUuid] = $this->createEmployeeWithPartyUuid();

        Livewire::test(EmployeeShow::class, [
            'legalEntity' => $legalEntity,
            'employee' => $employee,
        ])
            ->assertOk()
            ->assertSee(__('employees.ehealth_id'))
            ->assertSee($partyUuid);
    }

    #[Test]
    public function employee_show_displays_empty_ehealth_id_when_party_uuid_missing(): void
    {
        [$legalEntity, $employee] = $this->createEmployeeWithPartyUuid(withPartyUuid: false);

        Livewire::test(EmployeeShow::class, [
            'legalEntity' => $legalEntity,
            'employee' => $employee,
        ])
            ->assertOk()
            ->assertSee(__('employees.ehealth_id'))
            ->assertSeeHtml('id="partyUuid"');
    }

    #[Test]
    public function employee_request_show_displays_party_uuid(): void
    {
        [$legalEntity, , $partyUuid, $employeeRequest] = $this->createEmployeeWithPartyUuid(withRequest: true);

        Livewire::test(EmployeeRequestShow::class, [
            'legalEntity' => $legalEntity,
            'employee_request' => $employeeRequest,
        ])
            ->assertOk()
            ->assertSee(__('employees.ehealth_id'))
            ->assertSee($partyUuid);
    }

    /**
     * @return array{0: LegalEntity, 1: Employee, 2: string, 3?: EmployeeRequest}
     */
    private function createEmployeeWithPartyUuid(bool $withPartyUuid = true, bool $withRequest = false): array
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
        $this->instance('legalEntity', $legalEntity);

        $partyUuid = (string) Str::uuid();

        $party = Party::create([
            'uuid' => $withPartyUuid ? $partyUuid : null,
            'first_name' => 'Тарас',
            'last_name' => 'Шевченко',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'hr-uuid@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Шевченко Тарас',
            'employee_type' => Role::HR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $this->actingAs($user);

        if (!$withRequest) {
            return [$legalEntity, $employee, $withPartyUuid ? $partyUuid : ''];
        }

        $employeeRequest = EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::NEW->value,
            'position' => 'P1',
            'start_date' => now()->format('Y-m-d'),
            'employee_type' => Role::HR->value,
            'user_id' => $user->id,
            'party_id' => $party->id,
            'employee_id' => $employee->id,
            'email' => $user->email,
        ]);

        return [$legalEntity, $employee, $partyUuid, $employeeRequest];
    }
}
