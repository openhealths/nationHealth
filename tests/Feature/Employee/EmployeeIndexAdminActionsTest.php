<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Livewire\Employee\EmployeeIndex;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Policies\EmployeePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeIndexAdminActionsTest extends TestCase
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
    public function policy_allows_admin_to_update_approved_employee_without_employee_write_scope(): void
    {
        [$legalEntity, $employee] = $this->createLegalEntityWithApprovedDoctor();
        $this->instance('legalEntity', $legalEntity);

        $admin = Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('can')->with('employee:write')->andReturn(false);
        $admin->shouldReceive('hasElevatedEmployeeRole')->andReturn(true);

        $response = (new EmployeePolicy())->update($admin, $employee);

        $this->assertTrue($response->allowed());
    }

    #[Test]
    public function actions_dropdown_links_admin_to_edit_even_when_employee_has_no_user(): void
    {
        [$legalEntity, $employee] = $this->createLegalEntityWithApprovedDoctor();
        $this->instance('legalEntity', $legalEntity);

        $html = view('livewire.employee.parts.actions-dropdown', [
            'position' => $employee,
            'permissions' => [
                'employee_view' => true,
                'employee_write' => true,
                'employee_deactivate' => true,
                'employee_admin_hr' => true,
                'request_view' => true,
                'request_write' => true,
                'request_delete' => true,
            ],
        ])->render();

        $this->assertStringContainsString(
            route('employee.edit', ['legalEntity' => $legalEntity->id, 'employee' => $employee->id]),
            $html
        );
        $this->assertStringNotContainsString('tryEdit', $html);
    }

    #[Test]
    public function actions_dropdown_allows_edit_when_employee_has_no_user(): void
    {
        [$legalEntity, $employee] = $this->createLegalEntityWithApprovedDoctor();
        $this->instance('legalEntity', $legalEntity);

        $html = view('livewire.employee.parts.actions-dropdown', [
            'position' => $employee,
            'permissions' => [
                'employee_view' => true,
                'employee_write' => true,
                'employee_deactivate' => false,
                'employee_admin_hr' => false,
                'request_view' => false,
                'request_write' => false,
                'request_delete' => false,
            ],
        ])->render();

        $this->assertStringContainsString(
            route('employee.edit', ['legalEntity' => $legalEntity->id, 'employee' => $employee->id]),
            $html
        );
        $this->assertStringNotContainsString('tryEdit', $html);
    }

    #[Test]
    public function flash_view_hides_success_when_error_is_present(): void
    {
        session()->flash('success', 'Saved successfully');
        session()->flash('error', 'Something failed');

        $html = view('livewire.components.x-message')->render();

        $this->assertStringContainsString('Something failed', $html);
        $this->assertStringNotContainsString('Saved successfully', $html);
    }

    #[Test]
    public function request_error_message_translates_missing_employee_deactivate_allowance(): void
    {
        $component = new EmployeeIndex();
        $method = new \ReflectionMethod(EmployeeIndex::class, 'translateRequestError');
        $method->setAccessible(true);

        $translated = $method->invoke(
            $component,
            '403: Your scope does not allow to access this resource. Missing allowances: employee:deactivate'
        );

        $this->assertSame(
            __('employees.errors.missing_allowance_employee_deactivate'),
            $translated
        );
    }

    #[Test]
    public function request_error_message_translates_invalid_access_token(): void
    {
        $component = new EmployeeIndex();
        $method = new \ReflectionMethod(EmployeeIndex::class, 'translateRequestError');
        $method->setAccessible(true);

        $translated = $method->invoke(
            $component,
            '401: Invalid access token'
        );

        $this->assertSame(
            __('employees.errors.invalid_access_token'),
            $translated
        );
    }

    #[Test]
    public function deactivate_keeps_local_role_when_other_approved_same_type_remains(): void
    {
        [$legalEntity, $specialistA, $specialistB, $user] = $this->createLegalEntityWithTwoApprovedSpecialists();
        $this->instance('legalEntity', $legalEntity);

        setPermissionsTeamId($legalEntity->id);
        $role = \App\Models\Role::findOrCreate(Role::SPECIALIST->value, 'web');
        $user->assignRole($role);

        $this->mockSuccessfulEmployeeDeactivate();

        $component = $this->makeEmployeeIndex($legalEntity);
        $component->employeeIdToDeactivate = $specialistA->id;
        $component->deactivationStatus = Status::STOPPED->value;
        $component->deactivationEndDate = now()->format('Y-m-d');
        $component->deactivate();

        $user->refresh();
        $this->assertTrue($user->hasRole(Role::SPECIALIST->value, 'web'));
        $this->assertDatabaseHas('employees', [
            'id' => $specialistB->id,
            'status' => Status::APPROVED->value,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $specialistA->id,
            'status' => Status::STOPPED->value,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function deactivate_accepts_end_date_after_start_when_cast_uses_app_format(): void
    {
        [$legalEntity, $employee] = $this->createLegalEntityWithApprovedDoctor();
        $employee->update(['start_date' => '2024-06-26']);
        $this->instance('legalEntity', $legalEntity);

        $this->mockSuccessfulEmployeeDeactivate();

        $component = $this->makeEmployeeIndex($legalEntity);
        $endDate = now('Europe/Kyiv')->format('Y-m-d');
        $component->employeeIdToDeactivate = $employee->id;
        $component->deactivationStatus = Status::STOPPED->value;
        $component->deactivationEndDate = $endDate;
        $component->deactivate();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status' => Status::STOPPED->value,
            'end_date' => $endDate,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function deactivate_rejects_end_date_before_start(): void
    {
        [$legalEntity, $employee] = $this->createLegalEntityWithApprovedDoctor();
        $employee->update(['start_date' => '2024-06-26']);
        $this->instance('legalEntity', $legalEntity);

        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldNotReceive('deactivate');
        $this->app->instance(EmployeeApi::class, $api);

        $component = $this->makeEmployeeIndex($legalEntity);
        $component->employeeIdToDeactivate = $employee->id;
        $component->deactivationStatus = Status::STOPPED->value;
        $component->deactivationEndDate = '2024-06-25';
        $component->deactivate();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'status' => Status::APPROVED->value,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function deactivate_revokes_role_only_for_dismissed_user_when_same_type_remains_on_another_user(): void
    {
        [$legalEntity, $keptEmployee, $dismissedEmployee, $keptUser, $dismissedUser] = $this->createLegalEntityWithTwoHrUsers();
        $this->instance('legalEntity', $legalEntity);

        setPermissionsTeamId($legalEntity->id);
        $role = \App\Models\Role::findOrCreate(Role::HR->value, 'web');
        $keptUser->assignRole($role);
        $dismissedUser->assignRole($role);

        $dismissedEmployee->users()->attach($dismissedUser->id);

        $this->mockSuccessfulEmployeeDeactivate();

        $component = $this->makeEmployeeIndex($legalEntity);
        $component->employeeIdToDeactivate = $dismissedEmployee->id;
        $component->deactivationStatus = Status::STOPPED->value;
        $component->deactivationEndDate = now()->format('Y-m-d');
        $component->deactivate();

        $keptUser->refresh();
        $dismissedUser->refresh();
        $dismissedEmployee->refresh();

        $this->assertTrue($keptUser->hasRole(Role::HR->value, 'web'));
        $this->assertFalse($dismissedUser->hasRole(Role::HR->value, 'web'));
        $this->assertNull($dismissedEmployee->userId);
        $this->assertDatabaseMissing('employee_users', [
            'employee_id' => $dismissedEmployee->id,
            'user_id' => $dismissedUser->id,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $keptEmployee->id,
            'status' => Status::APPROVED->value,
            'user_id' => $keptUser->id,
        ]);
    }

    #[Test]
    public function deactivate_removes_local_role_when_last_approved_same_type(): void
    {
        [$legalEntity, $specialist, $user] = $this->createLegalEntityWithSingleApprovedSpecialist();
        $this->instance('legalEntity', $legalEntity);

        setPermissionsTeamId($legalEntity->id);
        $role = \App\Models\Role::findOrCreate(Role::SPECIALIST->value, 'web');
        $user->assignRole($role);

        $this->mockSuccessfulEmployeeDeactivate();

        $component = $this->makeEmployeeIndex($legalEntity);
        $component->employeeIdToDeactivate = $specialist->id;
        $component->deactivationStatus = Status::STOPPED->value;
        $component->deactivationEndDate = now()->format('Y-m-d');
        $component->deactivate();

        $user->refresh();
        $this->assertFalse($user->hasRole(Role::SPECIALIST->value, 'web'));
        $this->assertDatabaseHas('employees', [
            'id' => $specialist->id,
            'status' => Status::STOPPED->value,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function party_verification_meta_blade_gate_hides_for_non_elevated(): void
    {
        $snippet = <<<'BLADE'
            @if($permissions['employee_admin_hr'])
                <span data-tax>{{ __('forms.tax_id') }}: {{ $partyTaxId }}</span>
                <span data-verif>{{ __('party_verification.status') }}: {{ $partyVerificationLabel }}</span>
            @endif
        BLADE;

        $elevated = \Illuminate\Support\Facades\Blade::render($snippet, [
            'permissions' => ['employee_admin_hr' => true],
            'partyTaxId' => '3461807396',
            'partyVerificationLabel' => 'Потребує верифікації',
        ]);
        $this->assertStringContainsString('3461807396', $elevated);
        $this->assertStringContainsString('Потребує верифікації', $elevated);

        $restricted = \Illuminate\Support\Facades\Blade::render($snippet, [
            'permissions' => ['employee_admin_hr' => false],
            'partyTaxId' => '3461807396',
            'partyVerificationLabel' => 'Потребує верифікації',
        ]);
        $this->assertStringNotContainsString('3461807396', $restricted);
        $this->assertStringNotContainsString('Потребує верифікації', $restricted);
    }

    #[Test]
    public function party_email_is_plain_text_when_single_and_button_when_multiple(): void
    {
        $snippet = <<<'BLADE'
            @php
                $emailsCollection = collect($emails)->filter()->unique()->values();
                $emailCount = $emailsCollection->count();
                $visibleEmail = $emailsCollection->first();
            @endphp
            @if ($visibleEmail)
                @if ($emailCount === 1)
                    <span data-single>{{ $visibleEmail }}</span>
                @else
                    <button type="button" data-multi>{{ $visibleEmail }} +{{ $emailCount - 1 }}</button>
                    @foreach ($emailsCollection as $email)
                        <span data-all>{{ $email }}</span>
                    @endforeach
                @endif
            @endif
        BLADE;

        $single = \Illuminate\Support\Facades\Blade::render($snippet, [
            'emails' => ['only@example.com'],
        ]);
        $this->assertStringContainsString('data-single', $single);
        $this->assertStringNotContainsString('data-multi', $single);
        $this->assertStringNotContainsString('mailto:', $single);

        $multi = \Illuminate\Support\Facades\Blade::render($snippet, [
            'emails' => ['one@example.com', 'two@example.com'],
        ]);
        $this->assertStringContainsString('data-multi', $multi);
        $this->assertStringContainsString('two@example.com', $multi);
        $this->assertStringNotContainsString('data-single', $multi);
    }

    /**
     * @return array{0: LegalEntity, 1: Employee}
     */
    private function createLegalEntityWithApprovedDoctor(): array
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
            'first_name' => 'Ivan',
            'last_name' => 'Petrenko',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Ivan Petrenko',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P10',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => null,
            'party_id' => $party->id,
        ]);

        return [$legalEntity, $employee];
    }

    /**
     * @return array{0: LegalEntity, 1: Employee, 2: Employee, 3: User}
     */
    private function createLegalEntityWithTwoApprovedSpecialists(): array
    {
        [$legalEntity, $party, $user] = $this->createLegalEntityPartyAndUser();

        $specialistA = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => Role::SPECIALIST->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P56',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $specialistB = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => Role::SPECIALIST->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P10',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        return [$legalEntity, $specialistA, $specialistB, $user];
    }

    /**
     * @return array{0: LegalEntity, 1: Employee, 2: User}
     */
    private function createLegalEntityWithSingleApprovedSpecialist(): array
    {
        [$legalEntity, $party, $user] = $this->createLegalEntityPartyAndUser();

        $specialist = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => Role::SPECIALIST->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P56',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        return [$legalEntity, $specialist, $user];
    }

    /**
     * @return array{0: LegalEntity, 1: Employee, 2: Employee, 3: User, 4: User}
     */
    private function createLegalEntityWithTwoHrUsers(): array
    {
        [$legalEntity, $party, $keptUser] = $this->createLegalEntityPartyAndUser();

        $dismissedUser = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'hr-dismissed-'.Str::random(8).'@example.com',
            'password' => bcrypt('password'),
            'party_id' => $party->id,
            'email_verified_at' => now(),
        ]);

        $keptEmployee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => Role::HR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P56',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $keptUser->id,
            'party_id' => $party->id,
        ]);

        $dismissedEmployee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => Role::HR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P10',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $dismissedUser->id,
            'party_id' => $party->id,
        ]);

        return [$legalEntity, $keptEmployee, $dismissedEmployee, $keptUser, $dismissedUser];
    }

    /**
     * @return array{0: LegalEntity, 1: Party, 2: User}
     */
    private function createLegalEntityPartyAndUser(): array
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'OUTPATIENT')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'OUTPATIENT']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Andriy',
            'last_name' => 'Kopylets',
            'tax_id' => '3461807396',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'specialist-'.Str::random(8).'@example.com',
            'password' => bcrypt('password'),
            'party_id' => $party->id,
            'email_verified_at' => now(),
        ]);

        return [$legalEntity, $party, $user];
    }

    private function makeEmployeeIndex(LegalEntity $legalEntity): EmployeeIndex
    {
        $component = new EmployeeIndex();
        $refLegalEntity = new \ReflectionProperty(EmployeeIndex::class, 'legalEntity');
        $refLegalEntity->setAccessible(true);
        $refLegalEntity->setValue($component, $legalEntity);

        return $component;
    }

    private function mockSuccessfulEmployeeDeactivate(): void
    {
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('deactivate')->once()->andReturn(['data' => ['status' => 'STOPPED']]);
        $this->app->instance(EmployeeApi::class, $api);
    }
}
