<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\Employee\Forms\EmployeeForm;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Component;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeCustomPositionAndEmailTest extends TestCase
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
    public function custom_position_allowed_types_are_configured(): void
    {
        $allowed = config('ehealth.employee_type_custom_position_allowed');

        $this->assertSame(['ADMIN', 'HR', 'RECEPTIONIST'], $allowed);
    }

    #[Test]
    public function party_email_prefers_user_linked_to_current_legal_entity(): void
    {
        [$currentLe, $otherLe] = $this->createTwoLegalEntities();

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Andrii',
            'last_name' => 'Kopylets',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $otherLeUser = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'openhealthkopylets+drug25@gmail.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $currentLeUser = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'openhealthkopylets+pmd25@gmail.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $otherEmployee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Andrii Kopylets',
            'employee_type' => Role::OWNER->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $otherLe->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $otherLeUser->id,
            'party_id' => $party->id,
        ]);
        $otherLeUser->employees()->attach($otherEmployee->id);

        $currentEmployee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Andrii Kopylets',
            'employee_type' => Role::OWNER->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $currentLe->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $currentLeUser->id,
            'party_id' => $party->id,
        ]);
        $currentLeUser->employees()->attach($currentEmployee->id);

        $this->instance('legalEntity', $currentLe);

        $form = new EmployeeForm(new class extends Component
        {
            public function render()
            {
                return '';
            }
        }, 'form');

        $method = new ReflectionMethod(EmployeeForm::class, 'populatePartyData');
        $method->setAccessible(true);
        $method->invoke($form, $party);

        $this->assertSame('openhealthkopylets+pmd25@gmail.com', $form->party['email']);
    }

    #[Test]
    public function resolve_party_falls_back_to_linked_employee_when_user_party_id_is_null(): void
    {
        [$legalEntity] = $this->createTwoLegalEntities();

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Andrii',
            'last_name' => 'Kopylets',
            'tax_id' => '9876543210',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'owner-without-party@example.com',
            'password' => Hash::make('password'),
            'party_id' => null,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Andrii Kopylets',
            'employee_type' => Role::OWNER->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);

        $resolved = $user->resolveParty($legalEntity->id);

        $this->assertNotNull($resolved);
        $this->assertSame($party->id, $resolved->id);
        $this->assertSame($employee->id, $user->adminEmployeeForMisAction($legalEntity->id)?->id);
    }

    /**
     * @return array{0: LegalEntity, 1: LegalEntity}
     */
    private function createTwoLegalEntities(): array
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $current = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $other = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        return [$current, $other];
    }
}
