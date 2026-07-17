<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeePositionAddEmailScopeTest extends TestCase
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
    public function users_with_employee_in_legal_entity_excludes_other_facilities(): void
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

        $localUser = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'local@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $foreignUser = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'foreign@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $localEmployee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Andrii Kopylets',
            'employee_type' => Role::OWNER->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $currentLe->id,
            'is_active' => true,
            'position' => 'P10',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $localUser->id,
            'party_id' => $party->id,
        ]);
        $localUser->employees()->attach($localEmployee->id);

        $foreignEmployee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Andrii Kopylets',
            'employee_type' => Role::ADMIN->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $otherLe->id,
            'is_active' => true,
            'position' => 'P10',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $foreignUser->id,
            'party_id' => $party->id,
        ]);
        $foreignUser->employees()->attach($foreignEmployee->id);

        $emails = $party->usersWithEmployeeInLegalEntity($currentLe->id)->pluck('email');

        $this->assertTrue($emails->contains('local@example.com'));
        $this->assertFalse($emails->contains('foreign@example.com'));
        $this->assertCount(1, $emails);
    }

    /**
     * @return array{0: LegalEntity, 1: LegalEntity}
     */
    private function createTwoLegalEntities(): array
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $currentLe = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $otherLe = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        return [$currentLe, $otherLe];
    }
}
