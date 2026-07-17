<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\Employee\EmployeeIndex;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeIndexSearchTest extends TestCase
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
    #[DataProvider('nameSearchVariantsProvider')]
    public function search_matches_name_parts_in_any_order(string $search): void
    {
        [$legalEntity, $party] = $this->createLegalEntityWithParty(
            firstName: 'Іван',
            lastName: 'Петренко',
            secondName: 'Олегович',
        );

        $ids = $this->searchPartyIds($legalEntity, $search);

        $this->assertContains($party->id, $ids->all());
    }

    #[Test]
    public function search_requires_every_word_to_match_some_name_part(): void
    {
        [$legalEntity] = $this->createLegalEntityWithParty(
            firstName: 'Іван',
            lastName: 'Петренко',
            secondName: 'Олегович',
        );

        $this->createLegalEntityWithParty(
            firstName: 'Марія',
            lastName: 'Коваленко',
            secondName: 'Іванівна',
            legalEntity: $legalEntity,
        );

        $ids = $this->searchPartyIds($legalEntity, 'Петренко Марія');

        $this->assertCount(0, $ids);
    }

    #[Test]
    public function verification_filters_apply_only_for_elevated_roles(): void
    {
        [$legalEntity] = $this->createLegalEntityWithParty(
            firstName: 'Іван',
            lastName: 'Петренко',
            secondName: 'Олегович',
        );

        $component = new EmployeeIndex();
        $component->search = '';
        $component->status = [];
        $component->filter = [
            'phone' => '',
            'email' => '',
            'role' => '',
            'position' => '',
            'division_id' => '',
            'tax_id' => '3461807396',
            'verification_status' => 'VERIFICATION_NEEDED',
        ];

        $legalEntityProperty = new \ReflectionProperty(EmployeeIndex::class, 'legalEntity');
        $legalEntityProperty->setValue($component, $legalEntity);

        $method = new ReflectionMethod(EmployeeIndex::class, 'applyDatabaseFilters');

        $doctor = \Mockery::mock(User::class)->makePartial();
        $doctor->shouldReceive('hasAllowedRole')
            ->with([Role::ADMIN, Role::HR, Role::OWNER, Role::PHARMACY_OWNER])
            ->andReturn(false);
        \Illuminate\Support\Facades\Auth::setUser($doctor);

        $restrictedQuery = Party::query();
        $method->invoke($component, $restrictedQuery);
        $this->assertStringNotContainsString('verification_status', $restrictedQuery->toSql());
        $this->assertStringNotContainsString('tax_id', $restrictedQuery->toSql());

        $admin = \Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('hasAllowedRole')
            ->with([Role::ADMIN, Role::HR, Role::OWNER, Role::PHARMACY_OWNER])
            ->andReturn(true);
        \Illuminate\Support\Facades\Auth::setUser($admin);

        $elevatedQuery = Party::query();
        $method->invoke($component, $elevatedQuery);
        $this->assertStringContainsString('verification_status', $elevatedQuery->toSql());
        $this->assertStringContainsString('tax_id', $elevatedQuery->toSql());
    }

    #[Test]
    public function email_filter_scopes_party_users_without_party_relation(): void
    {
        [$legalEntity, $party] = $this->createLegalEntityWithParty(
            firstName: 'Іван',
            lastName: 'Петренко',
            secondName: 'Олегович',
        );

        User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'filter-me@example.com',
            'password' => bcrypt('password'),
            'party_id' => $party->id,
        ]);

        $this->createLegalEntityWithParty(
            firstName: 'Марія',
            lastName: 'Коваленко',
            secondName: 'Іванівна',
            legalEntity: $legalEntity,
        );

        $component = new EmployeeIndex();
        $component->search = '';
        $component->status = [];
        $component->filter = [
            'phone' => '',
            'email' => 'filter-me',
            'role' => '',
            'position' => '',
            'division_id' => '',
            'tax_id' => '',
            'verification_status' => '',
        ];

        $legalEntityProperty = new \ReflectionProperty(EmployeeIndex::class, 'legalEntity');
        $legalEntityProperty->setValue($component, $legalEntity);

        $query = Party::query();
        $method = new ReflectionMethod(EmployeeIndex::class, 'applyDatabaseFilters');
        $method->invoke($component, $query);

        $ids = $query->pluck('id');

        $this->assertStringContainsString('users', $query->toSql());
        $this->assertContains($party->id, $ids->all());
        $this->assertCount(1, $ids);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nameSearchVariantsProvider(): array
    {
        return [
            'last then first' => ['Петренко Іван'],
            'first then last' => ['Іван Петренко'],
            'patronymic only' => ['Олегович'],
            'full reversed' => ['Олегович Іван Петренко'],
            'partial last name' => ['Петрен'],
            'case insensitive' => ['петренко іван'],
        ];
    }

    /**
     * @return array{0: LegalEntity, 1: Party}
     */
    private function createLegalEntityWithParty(
        string $firstName,
        string $lastName,
        string $secondName,
        ?LegalEntity $legalEntity = null,
    ): array {
        if ($legalEntity === null) {
            $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
                ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

            $legalEntity = LegalEntity::create([
                'uuid' => (string) Str::uuid(),
                'status' => 'ACTIVE',
                'sync_status' => 'COMPLETED',
                'legal_entity_type_id' => $typeId,
                'is_active' => true,
            ]);
        }

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'second_name' => $secondName,
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => trim("$lastName $firstName $secondName"),
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => now()->format('Y-m-d'),
            'party_id' => $party->id,
        ]);

        return [$legalEntity, $party];
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function searchPartyIds(LegalEntity $legalEntity, string $search): \Illuminate\Support\Collection
    {
        $component = new EmployeeIndex();
        $component->search = $search;
        $component->filter = [
            'phone' => '',
            'email' => '',
            'role' => '',
            'position' => '',
            'division_id' => '',
        ];
        $component->status = [];

        $legalEntityProperty = new \ReflectionProperty(EmployeeIndex::class, 'legalEntity');
        $legalEntityProperty->setValue($component, $legalEntity);

        $query = Party::query();

        $method = new ReflectionMethod(EmployeeIndex::class, 'applyDatabaseFilters');
        $method->invoke($component, $query);

        return $query->pluck('id');
    }
}
