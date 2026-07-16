<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Status;
use App\Enums\User\Role;
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
        $admin->shouldReceive('hasAllowedRole')->with([Role::ADMIN, Role::HR])->andReturn(true);

        $response = (new EmployeePolicy)->update($admin, $employee);

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
    public function actions_dropdown_blocks_non_admin_edit_when_employee_has_no_user(): void
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

        $this->assertStringContainsString('tryEdit(' . $employee->id . ')', $html);
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
}
