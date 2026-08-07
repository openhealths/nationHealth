<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\Employee\EmployeeIndex;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeDeactivateTest extends TestCase
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
    public function deactivate_stopped_sends_end_date_and_updates_local_employee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'Europe/Kyiv'));

        [$legalEntity, $employee] = $this->createApprovedEmployee(startDate: '2026-07-01');

        $this->mockEmployeeApi()
            ->shouldReceive('deactivate')
            ->once()
            ->with($employee->uuid, '2026-07-15', Status::STOPPED->value)
            ->andReturn($this->fakeEHealthResponse());

        Livewire::test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->call('showModalDeactivate', $employee->id)
            ->set('deactivationStatus', Status::STOPPED->value)
            ->set('deactivationEndDate', '2026-07-15')
            ->call('deactivate')
            ->assertDispatched('flashMessage');

        $employee->refresh();

        $this->assertSame(Status::STOPPED, $employee->status);
        $this->assertFalse((bool) $employee->isActive);
        $this->assertSame('15.07.2026', $employee->endDate);
    }

    #[Test]
    public function deactivate_entered_in_error_omits_end_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'Europe/Kyiv'));

        [$legalEntity, $employee] = $this->createApprovedEmployee(startDate: '2026-07-01');

        $this->mockEmployeeApi()
            ->shouldReceive('deactivate')
            ->once()
            ->with($employee->uuid, null, Status::ENTERED_IN_ERROR->value)
            ->andReturn($this->fakeEHealthResponse());

        Livewire::test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->call('showModalDeactivate', $employee->id)
            ->set('deactivationStatus', Status::ENTERED_IN_ERROR->value)
            ->call('deactivate')
            ->assertDispatched('flashMessage');

        $employee->refresh();

        $this->assertSame(Status::ENTERED_IN_ERROR, $employee->status);
        $this->assertFalse((bool) $employee->isActive);
        $this->assertNull($employee->endDate);
    }

    #[Test]
    public function deactivate_stopped_rejects_end_date_before_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'Europe/Kyiv'));

        [$legalEntity, $employee] = $this->createApprovedEmployee(startDate: '2026-07-10');

        $this->mockEmployeeApi()->shouldNotReceive('deactivate');

        Livewire::test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->call('showModalDeactivate', $employee->id)
            ->set('deactivationStatus', Status::STOPPED->value)
            ->set('deactivationEndDate', '2026-07-01')
            ->call('deactivate')
            ->assertDispatched('flashMessage');

        $employee->refresh();
        $this->assertSame(Status::APPROVED, $employee->status);
    }

    #[Test]
    public function deactivate_stopped_rejects_end_date_in_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'Europe/Kyiv'));

        [$legalEntity, $employee] = $this->createApprovedEmployee(startDate: '2026-07-01');

        $this->mockEmployeeApi()->shouldNotReceive('deactivate');

        Livewire::test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->call('showModalDeactivate', $employee->id)
            ->set('deactivationStatus', Status::STOPPED->value)
            ->set('deactivationEndDate', '2026-07-25')
            ->call('deactivate')
            ->assertDispatched('flashMessage');

        $employee->refresh();
        $this->assertSame(Status::APPROVED, $employee->status);
    }

    #[Test]
    public function policy_denies_update_for_stopped_and_entered_in_error(): void
    {
        [$legalEntity, $employee] = $this->createApprovedEmployee();
        $this->instance('legalEntity', $legalEntity);

        $user = User::query()->firstOrFail();

        $employee->update(['status' => Status::STOPPED->value]);
        $this->assertTrue((new \App\Policies\EmployeePolicy())->update($user, $employee->fresh())->denied());

        $employee->update(['status' => Status::ENTERED_IN_ERROR->value]);
        $this->assertTrue((new \App\Policies\EmployeePolicy())->update($user, $employee->fresh())->denied());
    }

    /**
     * @return \Mockery\MockInterface&EmployeeApi
     */
    private function mockEmployeeApi(): EmployeeApi
    {
        $api = Mockery::mock(EmployeeApi::class);
        $this->app->instance(EmployeeApi::class, $api);

        return $api;
    }

    private function fakeEHealthResponse(): EHealthResponse
    {
        return Mockery::mock(EHealthResponse::class);
    }

    /**
     * @return array{0: LegalEntity, 1: Employee}
     */
    private function createApprovedEmployee(string $startDate = '2026-01-01'): array
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Тестовий',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'hr-deactivate-'.Str::random(8).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => Role::HR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => $startDate,
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $this->actingAs($user);

        return [$legalEntity, $employee];
    }
}
