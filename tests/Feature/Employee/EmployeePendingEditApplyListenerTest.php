<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Employee\RequestStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Events\EHealthUserLogin;
use App\Listeners\eHealth\EmployeeCreate;
use App\Listeners\eHealth\EmployeePendingEditApply;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Providers\EventServiceProvider;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeePendingEditApplyListenerTest extends TestCase
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
    public function listener_is_registered_on_ehealth_user_login_after_employee_create(): void
    {
        $listen = (new EventServiceProvider($this->app))->listens()[EHealthUserLogin::class] ?? [];

        $this->assertContains(EmployeePendingEditApply::class, $listen);

        $createIndex = array_search(EmployeeCreate::class, $listen, true);
        $applyIndex = array_search(EmployeePendingEditApply::class, $listen, true);

        $this->assertNotFalse($createIndex);
        $this->assertNotFalse($applyIndex);
        $this->assertGreaterThan($createIndex, $applyIndex);
    }

    #[Test]
    public function syncs_pending_edits_when_user_has_scope(): void
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

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Owner',
            'last_name' => 'Edit',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'owner-edit@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Owner Edit',
            'employee_type' => Role::OWNER->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $request = EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::NEW,
            'position' => 'P1',
            'employee_type' => Role::OWNER->value,
            'start_date' => '2024-01-10',
            'email' => $user->email,
            'employee_id' => $employee->id,
        ]);

        Gate::before(static fn (): bool => true);

        $processor = Mockery::mock(EmployeeRequestProcessor::class);
        $processor->shouldReceive('syncSinglePendingRequest')
            ->once()
            ->withArgs(function (EmployeeRequest $synced, LegalEntity $le) use ($request, $legalEntity): bool {
                return $synced->id === $request->id && $le->id === $legalEntity->id;
            })
            ->andReturn([
                'outcome' => EmployeeRequestProcessor::OUTCOME_APPROVED,
                'message' => 'ok',
            ]);
        $processor->shouldReceive('markOlderPendingEditsSuperseded')
            ->once()
            ->withArgs(fn (EmployeeRequest $applied): bool => $applied->id === $request->id);
        $this->instance(EmployeeRequestProcessor::class, $processor);

        $event = new EHealthUserLogin(
            $user,
            $legalEntity,
            (string) Str::uuid(),
            ['employee_request:read']
        );

        app(EmployeePendingEditApply::class)->handle($event);
    }

    #[Test]
    public function syncs_owner_party_edit_even_when_applied_at_already_set(): void
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

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Owner',
            'last_name' => 'Edit',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'owner-applied-at@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Owner Edit',
            'employee_type' => Role::OWNER->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        // Owner/party submit may stamp applied_at while remote status is still NEW.
        $request = EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::NEW,
            'position' => 'P1',
            'employee_type' => Role::OWNER->value,
            'start_date' => '2024-01-10',
            'email' => $user->email,
            'employee_id' => $employee->id,
            'applied_at' => now(),
        ]);

        Gate::before(static fn (): bool => true);

        $processor = Mockery::mock(EmployeeRequestProcessor::class);
        $processor->shouldReceive('syncSinglePendingRequest')
            ->once()
            ->withArgs(fn (EmployeeRequest $synced): bool => $synced->id === $request->id)
            ->andReturn([
                'outcome' => EmployeeRequestProcessor::OUTCOME_APPROVED,
                'message' => 'ok',
            ]);
        $processor->shouldReceive('markOlderPendingEditsSuperseded')->once();
        $this->instance(EmployeeRequestProcessor::class, $processor);

        app(EmployeePendingEditApply::class)->handle(new EHealthUserLogin(
            $user,
            $legalEntity,
            (string) Str::uuid(),
            ['employee_request:read']
        ));
    }
}
