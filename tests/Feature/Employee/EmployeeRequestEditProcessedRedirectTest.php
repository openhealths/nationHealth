<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Employee\RequestStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\Employee\EmployeeRequestEdit;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Policies\EmployeeRequestPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeRequestEditProcessedRedirectTest extends TestCase
{
    use DatabaseTransactions;

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
    public function edit_route_authorizes_view_instead_of_update(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertNotFalse($routes);
        $this->assertStringContainsString("name('edit')->middleware('can:view,employee_request')", $routes);
        $this->assertStringNotContainsString("name('edit')->middleware('can:update,employee_request')", $routes);
    }

    #[Test]
    public function policy_still_forbids_updating_a_processed_request(): void
    {
        [$legalEntity, , $request] = $this->createProcessedEmployeeRequest();
        $this->instance('legalEntity', $legalEntity);

        $user = \Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->andReturn(true);
        $user->shouldReceive('hasElevatedEmployeeRole')->andReturn(true);

        $policy = new EmployeeRequestPolicy();

        $this->assertTrue($policy->view($user, $request)->allowed());
        $this->assertTrue($policy->update($user, $request)->denied());
        $this->assertSame(__('employees.policy.req.processed_no_edit'), $policy->update($user, $request)->message());
    }

    #[Test]
    public function opening_a_processed_request_for_edit_redirects_to_employee_index(): void
    {
        [$legalEntity, $user, $request] = $this->createProcessedEmployeeRequest();

        $this->instance('legalEntity', $legalEntity);

        Livewire::actingAs($user)
            ->test(EmployeeRequestEdit::class, [
                'legalEntity' => $legalEntity,
                'employee_request' => $request,
            ])
            ->assertRedirect(route('employee.index', ['legalEntity' => $legalEntity]));
    }

    #[Test]
    public function sign_success_still_redirects_to_employee_index(): void
    {
        $source = file_get_contents(app_path('Livewire/Employee/AbstractEmployeeFormManager.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString('redirectIfEmployeeRequestAlreadyProcessed', $source);
        $this->assertStringContainsString("redirectRoute('employee.index', [legalEntity()], navigate: true)", $source);
    }

    /**
     * @return array{0: LegalEntity, 1: User, 2: EmployeeRequest}
     */
    private function createProcessedEmployeeRequest(): array
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
            'first_name' => 'Іван',
            'last_name' => 'Коваленко',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'hr-processed-redirect@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Коваленко Іван',
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

        $request = EmployeeRequest::create([
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

        return [$legalEntity, $user, $request];
    }
}
