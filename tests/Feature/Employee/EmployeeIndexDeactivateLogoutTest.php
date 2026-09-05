<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\Actions\Logout;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\Employee\EmployeeIndex;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeIndexDeactivateLogoutTest extends TestCase
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
    public function deactivate_logs_out_when_employee_belongs_to_current_user_party(): void
    {
        [$legalEntity, $employee, $user] = $this->createDeactivateScenario(sameParty: true);
        $this->instance('legalEntity', $legalEntity);
        $this->mockSuccessfulDeactivate();
        $this->mockLogout(shouldBeCalled: true);
        Auth::shouldUse('ehealth');

        Livewire::actingAs($user, 'ehealth')
            ->test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->set('employeeIdToDeactivate', $employee->id)
            ->set('deactivationEndDate', \Illuminate\Support\Carbon::now('Europe/Kyiv')->format('Y-m-d'))
            ->call('deactivate');
    }

    #[Test]
    public function deactivate_keeps_session_when_employee_belongs_to_another_party(): void
    {
        [$legalEntity, $employee, $user] = $this->createDeactivateScenario(sameParty: false);
        $this->instance('legalEntity', $legalEntity);
        $this->mockSuccessfulDeactivate();
        $this->mockLogout(shouldBeCalled: false);
        Auth::shouldUse('ehealth');

        Livewire::actingAs($user, 'ehealth')
            ->test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->set('employeeIdToDeactivate', $employee->id)
            ->set('deactivationEndDate', \Illuminate\Support\Carbon::now('Europe/Kyiv')->format('Y-m-d'))
            ->call('deactivate')
            ->assertNoRedirect()
            ->assertDispatched('flashMessage');

        $this->assertAuthenticatedAs($user, 'ehealth');
    }

    /**
     * @return array{0: LegalEntity, 1: Employee, 2: User}
     */
    private function createDeactivateScenario(bool $sameParty): array
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

        $sessionParty = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Session',
            'last_name' => 'User',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $employeeParty = $sameParty ? $sessionParty : Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Other',
            'last_name' => 'Party',
            'tax_id' => '9876543210',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::forceCreate([
            'email' => 'deactivate-test-'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
            'party_id' => $sessionParty->id,
            'uuid' => (string) Str::uuid(),
            'email_verified_at' => now(),
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P10',
            'start_date' => '2020-01-01',
            'user_id' => null,
            'party_id' => $employeeParty->id,
        ]);

        return [$legalEntity, $employee, $user];
    }

    private function mockSuccessfulDeactivate(): void
    {
        $response = Mockery::mock(EHealthResponse::class);

        $employeeApi = Mockery::mock(EmployeeApi::class);
        $employeeApi->shouldReceive('deactivate')->once()->andReturn($response);
        $this->instance(EmployeeApi::class, $employeeApi);
    }

    private function mockLogout(bool $shouldBeCalled): void
    {
        $logout = Mockery::mock(Logout::class);

        if ($shouldBeCalled) {
            $logout->shouldReceive('__invoke')
                ->once()
                ->with(true, __('employees.dismissalSuccess'))
                ->andReturn(redirect()->route('login'));
        } else {
            $logout->shouldNotReceive('__invoke');
        }

        $this->instance(Logout::class, $logout);
    }
}
