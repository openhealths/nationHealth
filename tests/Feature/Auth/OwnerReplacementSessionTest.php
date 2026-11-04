<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\EHealth\Services\TokenStorage;
use App\Auth\SessionBinder;
use App\Classes\eHealth\Api\Auth as AuthApi;
use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Middleware\CheckSessionToken;
use App\Events\EHealthUserLogin;
use App\Events\LegalEntityCreate;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Http\Controllers\Auth\EHealthLoginController;
use App\Listeners\OwnerNewReplace;
use App\Listeners\eHealth\EmployeeCreate;
use App\Livewire\LegalEntity\EditLegalEntity;
use App\Livewire\LegalEntity\Forms\LegalEntitiesForms;
use App\Livewire\LegalEntity\LegalEntity as LegalEntityComponent;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Permission;
use App\Models\Relations\Party;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Repository;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class OwnerReplacementSessionTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.default' => 'array',
            'permission.cache.store' => 'array',
            'ehealth.roles' => array_replace(array_fill_keys(array_keys(config('ehealth.roles')), []), ['OWNER' => ['employee:read']]),
            'ehealth.legal_entity_types' => array_fill_keys(array_keys(config('ehealth.legal_entity_types')), ['employee:read']),
        ]);
        Schema::create('persons', fn ($table) => $table->id());
        foreach ([
            '2025_12_10_000008_create_sessions_table.php',
            '2025_12_10_000012_create_legal_entity_types_table.php',
            '2025_12_10_000014_create_legal_entities_table.php',
            '2025_12_10_000016_create_permission_tables.php',
            '2025_12_10_000018_create_legal_entity_type_roles.php',
            '2025_12_10_000020_create_legal_entity_type_permissions_table.php',
            '2025_12_10_000022_create_divisions_table.php',
            '2025_12_10_000024_create_parties_table.php',
            '2025_12_10_000030_create_documents_table.php',
            '2025_12_10_000032_create_phones_table.php',
            '2025_12_10_000034_create_educations_table.php',
            '2025_12_10_000036_create_qualifications_table.php',
            '2025_12_10_000038_create_specialities_table.php',
            '2025_12_10_000040_create_science_degrees_table.php',
            '2025_12_10_000070_create_revisions_table.php',
            '2025_12_10_000088_create_users_table.php',
            '2025_12_10_000090_create_employees_table.php',
            '2025_12_10_000094_create_employee_requests_table.php',
            '2026_03_04_103846_create_employee_user_table.php',
        ] as $migration) {
            (require database_path('migrations/install/'.$migration))->up();
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'ehealth.api.callback_prod' => true]);
        Http::preventStrayRequests();
        // Exercise the real replacement listener through the OAuth callback,
        // without starting unrelated first-login synchronization jobs.
        Event::forget(EHealthUserLogin::class);
        Event::listen(EHealthUserLogin::class, OwnerNewReplace::class);
    }

    #[DataProvider('ownerScenarios')]
    public function test_new_owner_login_replaces_the_old_employee_and_keeps_the_new_session(string $scenario): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture($scenario);
        $this->mockEmployees($entity, $details);

        $response = $this->completeLogin($entity, $newUser);

        $this->assertSame(route('dashboard', [$entity]), $response->getTargetUrl());
        $this->assertAuthenticatedAs($newUser, 'ehealth');
        $this->assertSame(Session::getId(), $newUser->fresh()->sessionId);
        $this->assertTrue(app(TokenStorage::class)->hasBearerToken());
        $this->assertFalse(session()->has('error'));
        $this->assertSame('STOPPED', $oldEmployee->fresh()->status->value);
        $newEmployee = Employee::whereUuid($details['uuid'])->sole();
        $this->assertSame($newUser->id, $newEmployee->userId);
        $this->assertDatabaseHas('employee_users', ['employee_id' => $newEmployee->id, 'user_id' => $newUser->id]);
        $this->assertDatabaseMissing('employee_users', ['employee_id' => $oldEmployee->id, 'user_id' => $oldUser->id]);
        foreach (['web', 'ehealth'] as $guard) {
            $this->assertTrue($newUser->fresh()->hasRole('OWNER', $guard));
            $this->assertTrue($newUser->fresh()->hasRole('REORGANIZATION_OWNER', $guard));
            if (!$oldUser->is($newUser)) {
                $this->assertFalse($oldUser->fresh()->hasRole('OWNER', $guard));
                $this->assertFalse($oldUser->fresh()->hasRole('REORGANIZATION_OWNER', $guard));
                $this->assertDatabaseMissing('employee_users', ['employee_id' => $newEmployee->id, 'user_id' => $oldUser->id]);
            }
        }
    }

    public function test_successful_login_starts_a_fresh_inactivity_window(): void
    {
        $this->freezeSecond();
        [$entity, , $newUser, , $details] = $this->fixture('different_party');
        $this->mockEmployees($entity, $details);
        Session::put('last_activity', now()->subMinutes(61)->timestamp);

        $response = $this->completeLogin($entity, $newUser);

        $this->assertSame(route('dashboard', [$entity]), $response->getTargetUrl());
        $this->assertSame(now()->timestamp, session('last_activity'));

        $middleware = new CheckSessionToken(app(TokenStorage::class));
        $request = Request::create('/livewire/update', 'POST');
        $nextResponse = $middleware->handle($request, fn () => response('active'));

        $this->assertSame('active', $nextResponse->getContent());
        $this->assertAuthenticatedAs($newUser, 'ehealth');

        $this->travel(61)->minutes();
        Http::fake(['*' => Http::response(['data' => []])]);
        $expiredResponse = $middleware->handle($request, fn () => $this->fail('An expired session reached the protected action.'));

        $this->assertSame(route('login'), $expiredResponse->getTargetUrl());
        $this->assertSame(__('auth.session_expired'), session('error'));
        $this->assertGuest('ehealth');
    }

    public static function ownerScenarios(): array
    {
        return [
            'another person' => ['different_party'],
            'new email in the same party' => ['same_party'],
            'new owner employee for the same user' => ['same_user'],
        ];
    }

    #[DataProvider('replacementLogins')]
    public function test_owner_replacement_preserves_other_roles_and_employment(string $scenario, bool $hasOtherEntity, ?string $loginRole): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture($scenario);
        $details['position'] = 'P2';
        $details['party']['tax_id'] = '1234567890';
        $details['party']['second_name'] = 'Test';
        $otherEmployee = null;
        if ($hasOtherEntity) {
            [$otherEntity, $otherEmployee] = $this->employInAnotherEntity($entity, $oldUser, $oldEmployee);
            $otherEntity->update(['legal_entity_type_id' => DB::table('legal_entity_types')->whereName('OUTPATIENT')->value('id')]);
        }
        $otherPositions = [];
        foreach (array_diff(config('ehealth.legal_entity_employee_types.PRIMARY_CARE'), ['OWNER', 'REORGANIZATION_OWNER']) as $role) {
            $otherPositions[$role] = $this->additionalEmployee($entity, $oldUser, $role);
        }
        $before = array_map(fn (Employee $employee) => $employee->fresh()->getRawOriginal(), $otherPositions);
        $otherBefore = $otherEmployee?->fresh()->getRawOriginal();
        $this->mockEmployees($entity, $details);

        $response = $this->completeLogin($entity, $newUser, $loginRole);

        $this->assertSame(route('dashboard', [$entity]), $response->getTargetUrl());
        $this->assertSame('STOPPED', $oldEmployee->fresh()->status->value);
        $this->assertSame($oldUser->id, $oldEmployee->fresh()->userId);
        $this->assertDatabaseMissing('employee_users', ['employee_id' => $oldEmployee->id]);
        $newEmployee = Employee::whereUuid($details['uuid'])->sole();
        $this->assertSame('APPROVED', $newEmployee->status->value);
        $this->assertSame('P2', $newEmployee->position);
        $this->assertSame($newUser->id, $newEmployee->userId);
        $this->assertDatabaseHas('employee_users', ['employee_id' => $newEmployee->id, 'user_id' => $newUser->id]);
        $this->assertSame($before, array_map(fn (Employee $employee) => $employee->fresh()->getRawOriginal(), $otherPositions));
        $this->assertSame($otherBefore, $otherEmployee?->fresh()->getRawOriginal());
        foreach (['web', 'ehealth'] as $guard) {
            foreach (array_keys($otherPositions) as $role) {
                $this->assertTrue($oldUser->fresh()->hasRole($role, $guard));
            }
            $this->assertSame($oldUser->is($newUser), $oldUser->fresh()->hasRole('OWNER', $guard));
            $this->assertTrue($newUser->fresh()->hasRole('OWNER', $guard));
        }
        foreach ([...array_values($otherPositions), $otherEmployee] as $employee) {
            if ($employee) {
                $this->assertDatabaseHas('employee_users', ['employee_id' => $employee->id, 'user_id' => $oldUser->id]);
            }
        }
        if ($otherEmployee) {
            setPermissionsTeamId($otherEmployee->legalEntityId);
            foreach (['web', 'ehealth'] as $guard) {
                $this->assertTrue($oldUser->fresh()->hasRole('OWNER', $guard));
            }
        }
    }

    public static function replacementLogins(): array
    {
        $cases = [];
        foreach (['different_party', 'same_party', 'same_user'] as $scenario) {
            foreach ([false, true] as $otherEntity) {
                foreach (['OWNER', null] as $role) {
                    $cases[$scenario.' '.($otherEntity ? 'two entities' : 'one entity').' '.($role ?? 'combined')] = [$scenario, $otherEntity, $role];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('remainingRoleLogins')]
    public function test_former_owner_can_log_in_using_remaining_positions(bool $hasOtherEntity, ?string $loginRole): void
    {
        [$entity, $oldUser, $newUser, $oldOwner, $details] = $this->fixture('different_party');
        $doctor = $this->additionalEmployee($entity, $oldUser, 'DOCTOR');
        $receptionist = $this->additionalEmployee($entity, $oldUser, 'RECEPTIONIST');
        if ($hasOtherEntity) {
            $this->employInAnotherEntity($entity, $oldUser, $oldOwner);
        }
        $this->mockEmployees($entity, $details);
        $this->completeLogin($entity, $newUser);

        // Give OWNER a distinct scope so a combined login with only employee:read cannot enter its listener.
        foreach (['web', 'ehealth'] as $guard) {
            $ownerScope = Permission::findOrCreate('connection:write', $guard);
            $ownerScope->legalEntityTypes()->syncWithoutDetaching([$entity->legal_entity_type_id]);
            Role::findByName('OWNER', $guard)->givePermissionTo($ownerScope);
            foreach (['DOCTOR', 'RECEPTIONIST'] as $role) {
                Role::findByName($role, $guard)->givePermissionTo(Permission::findOrCreate('employee:read', $guard));
            }
        }
        $oldUser = $oldUser->fresh();
        $this->assertTrue(User::withLegalEntityAccess($oldUser->uuid, $entity->uuid)->whereKey($oldUser->id)->exists());
        $login = new \App\Livewire\Auth\Login();
        $login->legalEntityUuid = $entity->uuid;
        $this->assertTrue(new \ReflectionMethod($login, 'userHasRolesForLegalEntity')->invoke($login, $oldUser));
        $url = new \ReflectionMethod($login, 'buildEHealthLoginUrl')->invoke($login, $oldUser);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('employee:read', $query['scope']);

        $response = $this->completeLogin($entity, $oldUser, $loginRole);

        $this->assertSame(route('dashboard', [$entity]), $response->getTargetUrl());
        $this->assertAuthenticatedAs($oldUser, 'ehealth');
        $this->assertSame('STOPPED', $oldOwner->fresh()->status->value);
        $this->assertSame('APPROVED', $doctor->fresh()->status->value);
        $this->assertSame('APPROVED', $receptionist->fresh()->status->value);
        $this->assertDatabaseHas('employee_users', ['employee_id' => $doctor->id, 'user_id' => $oldUser->id]);
        $this->assertDatabaseHas('employee_users', ['employee_id' => $receptionist->id, 'user_id' => $oldUser->id]);
        foreach (['web', 'ehealth'] as $guard) {
            $this->assertTrue($oldUser->fresh()->hasRole('DOCTOR', $guard));
            $this->assertTrue($oldUser->fresh()->hasRole('RECEPTIONIST', $guard));
            $this->assertFalse($oldUser->fresh()->hasRole('OWNER', $guard));
        }
    }

    public static function remainingRoleLogins(): array
    {
        return [
            [false, 'DOCTOR'], [false, 'RECEPTIONIST'], [false, null],
            [true, 'DOCTOR'], [true, 'RECEPTIONIST'], [true, null],
        ];
    }

    #[DataProvider('supportedEntityTypes')]
    public function test_all_additional_employee_types_survive_owner_replacement(string $type): void
    {
        [$entity, $oldUser, $newUser, $oldOwner, $details] = $this->fixture('different_party');
        $entity->update(['legal_entity_type_id' => DB::table('legal_entity_types')->whereName($type)->value('id')]);
        $otherPositions = [];
        foreach (array_diff(config('ehealth.legal_entity_employee_types.'.$type), ['OWNER', 'REORGANIZATION_OWNER']) as $role) {
            $otherPositions[$role] = $this->additionalEmployee($entity, $oldUser, $role);
        }
        $before = array_map(fn (Employee $employee) => $employee->fresh()->getRawOriginal(), $otherPositions);
        $this->mockEmployees($entity, $details);

        $this->completeLogin($entity, $newUser, null);

        $this->assertSame('STOPPED', $oldOwner->fresh()->status->value);
        $this->assertSame($oldUser->id, $oldOwner->fresh()->userId);
        $this->assertDatabaseMissing('employee_users', ['employee_id' => $oldOwner->id]);
        $this->assertSame($before, array_map(fn (Employee $employee) => $employee->fresh()->getRawOriginal(), $otherPositions));
        foreach ($otherPositions as $role => $employee) {
            $this->assertDatabaseHas('employee_users', ['employee_id' => $employee->id, 'user_id' => $oldUser->id]);
            foreach (['web', 'ehealth'] as $guard) {
                $this->assertTrue($oldUser->fresh()->hasRole($role, $guard));
            }
        }
    }

    public static function supportedEntityTypes(): array
    {
        return [['PRIMARY_CARE'], ['OUTPATIENT'], ['EMERGENCY'], ['PHARMACY']];
    }

    #[DataProvider('otherEmploymentStates')]
    public function test_original_owner_can_return_with_a_new_employee_uuid_on_combined_login(bool $hasOtherEntity): void
    {
        [$entity, $original, $temporary, $firstEmployee, $details] = $this->fixture('different_party');
        if ($hasOtherEntity) {
            $this->employInAnotherEntity($entity, $original, $firstEmployee);
        }
        $this->mockEmployees($entity, $details);
        $this->completeLogin($entity, $temporary);
        $temporaryEmployee = Employee::whereUuid($details['uuid'])->sole();
        // Reproduce links left by the previous implementation before the original owner returns.
        $firstEmployee->users()->attach($original->id);
        foreach (['web', 'ehealth'] as $guard) {
            Auth::shouldUse($guard);
            $original->unsetRelation('roles')->assignRole(['OWNER', 'REORGANIZATION_OWNER']);
        }
        Auth::shouldUse('ehealth');
        $details['uuid'] = (string) Str::uuid();
        $details['party']['uuid'] = $original->party->uuid;
        $this->mockEmployees($entity, $details);

        $response = $this->completeLogin($entity, $original, null);

        $this->assertSame(route('dashboard', [$entity]), $response->getTargetUrl());
        $this->assertSame('STOPPED', $firstEmployee->fresh()->status->value);
        $this->assertSame('STOPPED', $temporaryEmployee->fresh()->status->value);
        $this->assertSame($original->id, $firstEmployee->fresh()->userId);
        $this->assertSame($temporary->id, $temporaryEmployee->fresh()->userId);
        $this->assertDatabaseMissing('employee_users', ['employee_id' => $firstEmployee->id]);
        $this->assertDatabaseMissing('employee_users', ['employee_id' => $temporaryEmployee->id]);
        $this->assertDatabaseHas('employees', ['uuid' => $details['uuid'], 'user_id' => $original->id, 'status' => 'APPROVED']);
        $this->assertSame(1, Employee::activeOwners($entity->id)->count());
    }

    #[DataProvider('otherEmploymentStates')]
    public function test_returning_owner_regains_owner_alongside_all_previous_positions_after_multiple_replacements(bool $hasOtherEntity): void
    {
        [$entity, $original, $second, $firstEmployee, $details] = $this->fixture('different_party');
        $third = $this->user($this->party());
        $positions = [];
        foreach (['ADMIN', 'DOCTOR', 'MED_ADMIN'] as $role) {
            $positions[$role] = $this->additionalEmployee($entity, $original, $role);
        }
        $positionsBefore = array_map(fn (Employee $employee) => $employee->fresh()->getRawOriginal(), $positions);
        $otherEmployee = null;
        if ($hasOtherEntity) {
            [, $otherEmployee] = $this->employInAnotherEntity($entity, $original, $firstEmployee);
        }
        $otherBefore = $otherEmployee?->fresh()->getRawOriginal();
        config(['ehealth.roles.OWNER' => ['employee:read', 'connection:write']]);
        foreach (['web', 'ehealth'] as $guard) {
            $permission = Permission::findOrCreate('connection:write', $guard);
            $permission->legalEntityTypes()->syncWithoutDetaching([$entity->legal_entity_type_id]);
            Role::findByName('OWNER', $guard)->givePermissionTo($permission);
            foreach (array_keys($positions) as $role) {
                Role::findByName($role, $guard)->givePermissionTo(Permission::findOrCreate('employee:read', $guard));
            }
        }
        $this->actingAs($original, 'ehealth');
        Event::fake([LegalEntityCreate::class]);
        $historicalOwners = [$firstEmployee];

        foreach ([$second, $third, $original] as $target) {
            if ($target->is($original)) {
                foreach (['web', 'ehealth'] as $guard) {
                    $this->assertFalse($original->fresh()->hasRole('OWNER', $guard));
                    foreach (array_keys($positions) as $role) {
                        $this->assertTrue($original->fresh()->hasRole($role, $guard));
                    }
                }
                $this->assertSame('employee:read', $original->fresh()->getScopes());
            }

            // Use the actual replacement preparation, including role assignment for an existing email.
            $component = new EditLegalEntity();
            $component->legalEntityForm = new LegalEntitiesForms($component, 'legalEntityForm');
            $component->legalEntityForm->owner = ['email' => $target->email];
            $preparedUser = new \ReflectionMethod(LegalEntityComponent::class, 'createUser')->invoke($component);
            $this->assertSame($target->id, $preparedUser->id);

            $login = new \App\Livewire\Auth\Login();
            $login->legalEntityUuid = $entity->uuid;
            $url = new \ReflectionMethod($login, 'buildEHealthLoginUrl')->invoke($login, $preparedUser);
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $scopes = explode(' ', $query['scope']);
            $this->assertContains('connection:write', $scopes);

            $details['uuid'] = (string) Str::uuid();
            $details['party']['uuid'] = $target->party->uuid;
            $this->mockEmployees($entity, $details);
            $response = $this->completeLogin($entity, $target, null, $scopes);
            $this->assertSame(route('dashboard', [$entity]), $response->getTargetUrl());
            foreach ($historicalOwners as $employee) {
                $this->assertSame('STOPPED', $employee->fresh()->status->value);
                $this->assertSame($employee->userId, $employee->fresh()->userId);
                $this->assertDatabaseMissing('employee_users', ['employee_id' => $employee->id]);
            }
            $historicalOwners[] = Employee::whereUuid($details['uuid'])->sole();
        }

        $this->assertSame($positionsBefore, array_map(fn (Employee $employee) => $employee->fresh()->getRawOriginal(), $positions));
        foreach ($positions as $employee) {
            $this->assertDatabaseHas('employee_users', ['employee_id' => $employee->id, 'user_id' => $original->id]);
        }
        $activeOwner = Employee::activeOwners($entity->id)->sole();
        $this->assertSame($original->id, $activeOwner->userId);
        $this->assertNotSame($firstEmployee->uuid, $activeOwner->uuid);
        $this->assertDatabaseHas('employee_users', ['employee_id' => $activeOwner->id, 'user_id' => $original->id]);
        foreach (['web', 'ehealth'] as $guard) {
            foreach (['OWNER', ...array_keys($positions)] as $role) {
                $this->assertTrue($original->fresh()->hasRole($role, $guard));
            }
            $this->assertFalse($second->fresh()->hasRole('OWNER', $guard));
            $this->assertFalse($third->fresh()->hasRole('OWNER', $guard));
        }
        if ($otherEmployee) {
            $this->assertSame($otherBefore, $otherEmployee->fresh()->getRawOriginal());
            $this->assertDatabaseHas('employee_users', ['employee_id' => $otherEmployee->id, 'user_id' => $original->id]);
            setPermissionsTeamId($otherEmployee->legalEntityId);
            foreach (['web', 'ehealth'] as $guard) {
                $this->assertTrue($original->fresh()->hasRole('OWNER', $guard));
            }
        }
    }

    public function test_owner_role_is_not_shared_with_an_hr_user_in_the_same_party(): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture('same_party');
        $hrUser = $this->user($oldUser->party);
        $hr = $this->additionalEmployee($entity, $hrUser, 'HR');
        $before = $hr->fresh()->getRawOriginal();
        $this->mockEmployees($entity, $details);

        $this->completeLogin($entity, $newUser);

        // A stale single-role selection must not bypass OWNER identity in the shared synchronizer.
        $this->actingAs($hrUser, 'ehealth');
        Session::put('single_role_login', 'OWNER');
        Repository::party()->syncUserEmployeesAndRoles($hrUser->party, $entity);

        $this->assertSame($before, $hr->fresh()->getRawOriginal());
        $this->assertDatabaseHas('employee_users', ['employee_id' => $hr->id, 'user_id' => $hrUser->id]);
        foreach (['web', 'ehealth'] as $guard) {
            $this->assertTrue($hrUser->fresh()->hasRole('HR', $guard));
            $this->assertFalse($hrUser->fresh()->hasRole('OWNER', $guard));
            $this->assertFalse($hrUser->fresh()->hasRole('REORGANIZATION_OWNER', $guard));
        }
        $this->assertDatabaseMissing('employee_users', ['employee_id' => Employee::whereUuid($details['uuid'])->sole()->id, 'user_id' => $hrUser->id]);
    }

    #[DataProvider('otherEmploymentStates')]
    public function test_an_unaccepted_invitation_keeps_the_owner_returned_by_ehealth(bool $hasOtherEntity): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture('different_party');
        if ($hasOtherEntity) {
            $this->employInAnotherEntity($entity, $oldUser, $oldEmployee);
        }
        $details['uuid'] = $oldEmployee->uuid;
        $details['party']['uuid'] = $oldUser->party->uuid;
        $this->mockEmployees($entity, $details);

        $this->completeLogin($entity, $oldUser, null);

        $this->assertSame('APPROVED', $oldEmployee->fresh()->status->value);
        $this->assertSame($oldUser->id, $oldEmployee->fresh()->userId);
        $this->assertSame(1, Employee::activeOwners($entity->id)->count());
        $this->assertDatabaseHas('employee_users', ['employee_id' => $oldEmployee->id, 'user_id' => $oldUser->id]);
    }

    public function test_repeated_combined_login_does_not_duplicate_the_owner(): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture('different_party');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->mockEmployees($entity, $details);
            $this->completeLogin($entity, $newUser, null);
        }

        $this->assertSame(1, Employee::activeOwners($entity->id)->count());
        $this->assertSame(1, Employee::whereUuid($details['uuid'])->count());
        $this->assertSame('STOPPED', $oldEmployee->fresh()->status->value);
        $this->assertSame($oldUser->id, $oldEmployee->fresh()->userId);
    }

    #[DataProvider('nonOwnerRoles')]
    public function test_other_roles_are_not_processed_as_owner_replacements(string $role): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee] = $this->fixture('different_party');
        if ($role === 'PHARMACY_OWNER') {
            $entity->update(['legal_entity_type_id' => DB::table('legal_entity_types')->whereName('PHARMACY')->value('id')]);
        }
        $employee = $this->additionalEmployee($entity, $oldUser, $role);
        $before = $employee->fresh()->getRawOriginal();
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->never();
        $this->instance(EmployeeApi::class, $api);

        $this->completeLogin($entity, $oldUser, $role);
        Repository::legalEntity()->disableOldOwner($employee, $newUser->id, $entity);

        $this->assertSame($before, $employee->fresh()->getRawOriginal());
        $this->assertDatabaseHas('employee_users', ['employee_id' => $employee->id, 'user_id' => $oldUser->id]);
        $this->assertSame('APPROVED', $oldEmployee->fresh()->status->value);
        foreach (['web', 'ehealth'] as $guard) {
            $this->assertTrue($oldUser->fresh()->hasRole($role, $guard));
        }
    }

    public static function nonOwnerRoles(): array
    {
        return array_map(fn ($role) => [$role], ['ADMIN', 'HR', 'DOCTOR', 'PHARMACY_OWNER', 'REORGANIZATION_OWNER']);
    }

    #[DataProvider('ownerScopes')]
    public function test_missing_or_incomplete_owner_scopes_do_not_trigger_replacement(bool $emptyRole): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee] = $this->fixture('different_party');
        $role = Role::findByName('OWNER', 'ehealth');
        if ($emptyRole) {
            $role->permissions()->detach();
        } else {
            $permission = Permission::findOrCreate('connection:write', 'ehealth');
            $permission->legalEntityTypes()->syncWithoutDetaching([$entity->legal_entity_type_id]);
            $role->givePermissionTo($permission);
        }
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->never();
        $this->instance(EmployeeApi::class, $api);

        $this->completeLogin($entity, $newUser, null);

        $this->assertSame('APPROVED', $oldEmployee->fresh()->status->value);
        $this->assertSame(1, Employee::whereLegalEntityId($entity->id)->count());
    }

    public static function ownerScopes(): array
    {
        return ['empty OWNER configuration' => [true], 'only non-owner scopes granted' => [false]];
    }

    public function test_combined_login_of_a_different_party_does_not_take_over_the_owner(): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture('different_party');
        $details['uuid'] = $oldEmployee->uuid;
        $details['party']['uuid'] = $oldUser->party->uuid;
        $before = $oldEmployee->fresh()->getRawOriginal();
        $this->mockEmployees($entity, $details);

        $response = $this->completeLogin($entity, $newUser, null);

        $this->assertSame(route('dashboard', [$entity]), $response->getTargetUrl());
        $this->assertSame($before, $oldEmployee->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('employee_users', ['employee_id' => $oldEmployee->id, 'user_id' => $newUser->id]);
    }

    #[DataProvider('invalidRemoteOwners')]
    public function test_invalid_remote_owner_data_cannot_change_local_employees(string $field, mixed $value): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture('different_party');
        data_set($details, $field, $value);
        $this->mockEmployees($entity, $details);

        $response = $this->completeLogin($entity, $newUser, null);

        $this->assertSame(route('login'), $response->getTargetUrl());
        $this->assertSame('APPROVED', $oldEmployee->fresh()->status->value);
        $this->assertDatabaseMissing('employees', ['uuid' => $details['uuid']]);
    }

    public static function invalidRemoteOwners(): array
    {
        return [
            ['employee_type', 'HR'], ['status', 'STOPPED'], ['is_active', false],
            ['party.uuid', null], ['legal_entity_uuid', '00000000-0000-4000-8000-000000000001'],
        ];
    }

    public function test_a_remote_uuid_cannot_overwrite_another_employee_type_or_entity(): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture('different_party');
        [$otherEntity, $otherOwner] = $this->employInAnotherEntity($entity, $oldUser, $oldEmployee);
        $doctor = $this->additionalEmployee($entity, $oldUser, 'DOCTOR');
        foreach ([$doctor, $otherOwner] as $employee) {
            $before = $employee->fresh()->getRawOriginal();
            $details['uuid'] = $employee->uuid;
            $this->mockEmployees($entity, $details);

            $this->completeLogin($entity, $newUser, null);

            $this->assertSame($before, $employee->fresh()->getRawOriginal());
            $this->assertSame('APPROVED', $oldEmployee->fresh()->status->value);
        }
    }

    public function test_a_new_owner_without_a_local_party_can_complete_the_first_login(): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture('different_party');
        $newUser->update(['party_id' => null]);
        $newUser->unsetRelation('party');
        $this->mockEmployees($entity, $details);

        $response = $this->completeLogin($entity, $newUser);

        $this->assertSame(route('dashboard', [$entity]), $response->getTargetUrl());
        $this->assertSame($details['party']['uuid'], $newUser->fresh()->party->uuid);
        $this->assertSame('STOPPED', $oldEmployee->fresh()->status->value);
        $this->assertSame($oldUser->id, $oldEmployee->fresh()->userId);
    }

    #[DataProvider('remoteOwnerCounts')]
    public function test_empty_or_ambiguous_remote_owner_lists_do_not_deactivate_anyone(int $count): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee] = $this->fixture('different_party');
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->once()->andReturn($this->response(array_fill(0, $count, ['uuid' => (string) Str::uuid()])));
        $api->shouldReceive('getDetails')->never();
        $this->instance(EmployeeApi::class, $api);

        $response = $this->completeLogin($entity, $newUser, null);

        $this->assertSame(route('login'), $response->getTargetUrl());
        $this->assertSame('APPROVED', $oldEmployee->fresh()->status->value);
        $this->assertDatabaseHas('employee_users', ['employee_id' => $oldEmployee->id, 'user_id' => $oldUser->id]);
    }

    public static function remoteOwnerCounts(): array
    {
        return [[0], [2]];
    }

    public function test_ambiguous_employee_requests_do_not_block_combined_owner_replacement(): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture('different_party');
        $details['party']['tax_id'] = '1234567890';
        $details['party']['second_name'] = 'Test';
        foreach (range(1, 2) as $attempt) {
            $request = EmployeeRequest::create([
                'uuid' => (string) Str::uuid(), 'legal_entity_id' => $entity->id, 'legal_entity_uuid' => $entity->uuid,
                'employee_type' => 'OWNER', 'position' => $details['position'], 'start_date' => $details['start_date'],
                'status' => 'NEW', 'email' => $newUser->email, 'party_id' => $newUser->partyId,
            ]);
            $request->revision()->create(['status' => 'SENT', 'data' => ['party' => $details['party']]]);
        }
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->once()->with(['legal_entity_id' => $entity->uuid, 'tax_id' => '1234567890'], 1, true)
            ->andReturn($this->response([$details]));
        $api->shouldReceive('getMany')->once()->with(['legal_entity_id' => $entity->uuid, 'status' => 'APPROVED', 'employee_type' => 'OWNER'])
            ->andReturn($this->response([['uuid' => $details['uuid']]]));
        $api->shouldReceive('getDetails')->once()->with($details['uuid'])->andReturn($this->response($details));
        $this->instance(EmployeeApi::class, $api);
        Event::forget(EHealthUserLogin::class);
        Event::listen(EHealthUserLogin::class, EmployeeCreate::class);
        Event::listen(EHealthUserLogin::class, OwnerNewReplace::class);

        $response = $this->completeLogin($entity, $newUser, null);

        $this->assertSame(route('dashboard', [$entity]), $response->getTargetUrl());
        $this->assertSame('STOPPED', $oldEmployee->fresh()->status->value);
        $this->assertDatabaseHas('employees', ['uuid' => $details['uuid'], 'user_id' => $newUser->id, 'status' => 'APPROVED']);
        $this->assertSame(1, Employee::activeOwners($entity->id)->count());
    }

    private function additionalEmployee(LegalEntity $entity, User $user, string $type): Employee
    {
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $entity->id, 'legal_entity_uuid' => $entity->uuid,
            'employee_type' => $type, 'status' => 'APPROVED', 'position' => 'P14', 'is_active' => true,
            'user_id' => $user->id, 'party_id' => $user->partyId,
            'start_date' => '2025-02-01', 'inserted_at' => '2025-02-01 00:00:00',
        ]);
        $employee->users()->attach($user->id);
        foreach (['web', 'ehealth'] as $guard) {
            Auth::shouldUse($guard);
            Role::findOrCreate($type, $guard);
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->assignRole($type);
        }
        Auth::shouldUse('ehealth');

        return $employee;
    }

    #[DataProvider('ownerScenarios')]
    public function test_new_owner_login_stops_the_old_employee_without_affecting_another_legal_entity(string $scenario): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture($scenario);
        [$otherEntity, $otherEmployee] = $this->employInAnotherEntity($entity, $oldUser, $oldEmployee);
        $this->mockEmployees($entity, $details);

        $response = $this->completeLogin($entity, $newUser);

        $this->assertSame(route('dashboard', [$entity]), $response->getTargetUrl());
        $this->assertAuthenticatedAs($newUser, 'ehealth');
        $this->assertSame(Session::getId(), $newUser->fresh()->sessionId);
        $this->assertTrue(app(TokenStorage::class)->hasBearerToken());
        $this->assertSame('STOPPED', $oldEmployee->fresh()->status->value);
        $this->assertFalse(Employee::activeOwners($entity->id)->whereKey($oldEmployee->id)->exists());
        $newEmployee = Employee::whereUuid($details['uuid'])->sole();
        $this->assertSame('APPROVED', $newEmployee->status->value);
        $this->assertDatabaseHas('employee_users', ['employee_id' => $newEmployee->id, 'user_id' => $newUser->id]);

        $this->assertSame('APPROVED', $otherEmployee->fresh()->status->value);
        $this->assertDatabaseHas('employee_users', ['employee_id' => $otherEmployee->id, 'user_id' => $oldUser->id]);
        setPermissionsTeamId($otherEntity->id);
        foreach (['web', 'ehealth'] as $guard) {
            $this->assertTrue($oldUser->fresh()->hasRole('OWNER', $guard));
            $this->assertTrue($oldUser->fresh()->hasRole('REORGANIZATION_OWNER', $guard));
        }
    }

    #[DataProvider('otherEmploymentStates')]
    public function test_failed_new_owner_login_keeps_the_old_owner_and_preserves_the_original_error(bool $hasOtherEmployment): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture('different_party');
        if ($hasOtherEmployment) {
            $this->employInAnotherEntity($entity, $oldUser, $oldEmployee);
        }
        $this->mockEmployees($entity, $details, failDetails: true);
        Http::fake(['*' => Http::response(['data' => []])]);

        $response = $this->completeLogin($entity, $newUser);

        $this->assertSame(route('login'), $response->getTargetUrl());
        $this->assertSame(__('auth.login.error.owner_replacement.new_owner_details_not_found'), session('error'));
        $this->assertGuest('ehealth');
        $this->assertGuest('web');
        $this->assertNull($newUser->fresh()->sessionId);
        $this->assertFalse(app(TokenStorage::class)->hasBearerToken());
        $this->assertSame('APPROVED', $oldEmployee->fresh()->status->value);
        $this->assertDatabaseMissing('employees', ['uuid' => $details['uuid']]);
        $this->assertDatabaseHas('employee_users', ['employee_id' => $oldEmployee->id, 'user_id' => $oldUser->id]);
        foreach (['web', 'ehealth'] as $guard) {
            $this->assertTrue($oldUser->fresh()->hasRole('OWNER', $guard));
        }
    }

    #[DataProvider('otherEmploymentStates')]
    public function test_submitting_an_owner_replacement_logs_out_the_current_owner_with_the_success_message(bool $hasOtherEmployment): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee] = $this->fixture('different_party');
        if ($hasOtherEmployment) {
            $this->employInAnotherEntity($entity, $oldUser, $oldEmployee);
        }
        Event::fake([LegalEntityCreate::class]);
        Gate::before(fn () => true);
        Session::start();
        app(TokenStorage::class)->store($this->tokenData($entity, $oldUser));
        Auth::guard('ehealth')->login($oldUser);
        Auth::shouldUse('ehealth');
        new SessionBinder()->bind($oldUser);
        Session::save();
        $sessionId = Session::getId();
        $request = Request::create('/livewire/update', 'POST');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);
        $api = Mockery::mock(AuthApi::class);
        $api->shouldReceive('logout')->once()->with('test-access-token')->andReturn($this->response([]));
        $this->instance(AuthApi::class, $api);

        // Keep the real updateLegalEntity(), handleSuccessResponse(), createUser()
        // and Logout; isolate signing, validation and upstream response mapping.
        $component = Mockery::mock(EditLegalEntity::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $form = Mockery::mock(LegalEntitiesForms::class, [$component, 'legalEntityForm'])->makePartial();
        $form->shouldReceive('allFieldsValidate')->once()->andReturnNull();
        $form->owner = ['email' => $newUser->email];
        $component->legalEntityForm = $form;
        $component->isOwnerChanged = true;
        foreach (['legalEntity' => $entity, 'entityCacheKey' => 'test.entity', 'ownerCacheKey' => 'test.owner', 'stepCacheKey' => 'test.steps'] as $property => $value) {
            new ReflectionProperty(LegalEntityComponent::class, $property)->setValue($component, $value);
        }
        $upstreamResponse = ['urgent' => ['employee_request_id' => 'request-uuid']];
        $component->shouldReceive('signLegalEntity')->once()->andReturn(['request' => [], 'response' => $upstreamResponse]);
        $component->shouldReceive('filterUnprovidedFields')->once()->andReturn($upstreamResponse);
        $component->shouldReceive('createNewLegalEntity')->once()->andReturn($entity);
        $component->shouldReceive('createEmployeeRequest')->once()->with($entity, [], 'request-uuid')->andReturnNull();

        $response = $component->updateLegalEntity();

        $this->assertNotNull($response);
        $this->assertSame(route('login'), $response->getTargetUrl());
        $this->assertSame(__('forms.replace_successful'), session('success'));
        $this->assertFalse(session()->has('error'));
        $this->assertGuest('ehealth');
        $this->assertNull($oldUser->fresh()->sessionId);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertFalse(app(TokenStorage::class)->hasBearerToken());
        Event::assertDispatched(LegalEntityCreate::class, fn ($event) => $event->authenticatedUser->is($oldUser) && $event->owner->is($newUser));
        $this->assertSame('APPROVED', $oldEmployee->fresh()->status->value);
        $this->assertDatabaseHas('employee_users', ['employee_id' => $oldEmployee->id, 'user_id' => $oldUser->id]);

        $this->withoutVite();
        $loginPage = <<<'BLADE'
            @extends('layouts.guest')
            @section('content')
                <livewire:components.x-message />
            @endsection
            BLADE;
        $html = Blade::render($loginPage);

        $this->assertSame(1, substr_count($html, e(__('forms.replace_successful'))));
        $this->assertFalse(session()->has('success'));
        $this->assertStringNotContainsString(e(__('forms.replace_successful')), Blade::render($loginPage));
    }

    public static function otherEmploymentStates(): array
    {
        return [
            'only the current entity' => [false],
            'also employed in another entity' => [true],
        ];
    }

    private function employInAnotherEntity(LegalEntity $entity, User $user, Employee $employee): array
    {
        $otherEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_type_id' => $entity->legal_entity_type_id,
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'is_active' => true,
        ]);
        $otherEmployee = $employee->replicate()->fill([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $otherEntity->id,
            'legal_entity_uuid' => $otherEntity->uuid,
        ]);
        $otherEmployee->save();
        $otherEmployee->users()->attach($user->id);
        setPermissionsTeamId($otherEntity->id);
        foreach (['web', 'ehealth'] as $guard) {
            Auth::shouldUse($guard);
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->assignRole(['OWNER', 'REORGANIZATION_OWNER']);
        }
        setPermissionsTeamId($entity->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        Auth::shouldUse('ehealth');

        return [$otherEntity, $otherEmployee];
    }

    private function fixture(string $scenario): array
    {
        $typeId = DB::table('legal_entity_types')->where('name', LegalEntity::TYPE_PRIMARY_CARE)->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => LegalEntity::TYPE_PRIMARY_CARE]);
        $entity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_type_id' => $typeId,
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'is_active' => true,
        ]);
        setPermissionsTeamId($entity->id);
        $oldParty = $this->party();
        $newParty = $scenario === 'different_party' ? $this->party() : $oldParty;
        $oldUser = $this->user($oldParty);
        $newUser = $scenario === 'same_user' ? $oldUser : $this->user($newParty);
        foreach (['web', 'ehealth'] as $guard) {
            Auth::shouldUse($guard);
            $permission = Permission::findOrCreate('employee:read', $guard);
            $permission->legalEntityTypes()->syncWithoutDetaching([$typeId]);
            $role = Role::findOrCreate('OWNER', $guard);
            $role->givePermissionTo($permission);
            Role::findOrCreate('REORGANIZATION_OWNER', $guard);
            $oldUser->assignRole(['OWNER', 'REORGANIZATION_OWNER']);
            $newUser->assignRole(['OWNER', 'REORGANIZATION_OWNER']);
        }
        Auth::shouldUse('ehealth');
        $oldEmployee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $entity->id,
            'legal_entity_uuid' => $entity->uuid,
            'party_id' => $oldParty->id,
            'user_id' => $oldUser->id,
            'employee_type' => 'OWNER',
            'status' => 'APPROVED',
            'is_active' => true,
            'position' => 'P1',
            'start_date' => '2025-02-01',
            'inserted_at' => '2025-02-01 00:00:00',
        ]);
        $oldEmployee->users()->attach($oldUser->id);
        $details = [
            'uuid' => (string) Str::uuid(),
            'employee_type' => 'OWNER',
            'status' => 'APPROVED',
            'is_active' => true,
            'position' => 'P1',
            'start_date' => '2026-01-01',
            'party' => array_merge($newParty->only(['uuid', 'first_name', 'last_name', 'gender']), [
                'birth_date' => '1990-01-01', 'phones' => [], 'documents' => [],
            ]),
        ];

        return [$entity, $oldUser, $newUser, $oldEmployee, $details];
    }

    private function party(): Party
    {
        return Party::create(['uuid' => (string) Str::uuid(), 'first_name' => 'Test', 'last_name' => 'Owner', 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
    }

    private function user(Party $party): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'owner-'.Str::random(12).'@example.com',
            'password' => bcrypt('password'), 'party_id' => $party->id,
            'inserted_at' => '2025-01-01 00:00:00', 'email_verified_at' => now(),
        ]);
    }

    private function mockEmployees(LegalEntity $entity, array $details, bool $failDetails = false): void
    {
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->once()->with(['legal_entity_id' => $entity->uuid, 'status' => 'APPROVED', 'employee_type' => 'OWNER'])
            ->andReturn($this->response([['uuid' => $details['uuid']]]));
        $expectation = $api->shouldReceive('getDetails')->once()->with($details['uuid']);
        if ($failDetails) {
            $expectation->andThrow(new EHealthConnectionException('Test connection failure'));
        } else {
            $expectation->andReturn($this->response($details));
        }
        $this->instance(EmployeeApi::class, $api);
    }

    private function completeLogin(LegalEntity $entity, User $user, ?string $role = 'OWNER', ?array $scopes = null): \Illuminate\Http\RedirectResponse
    {
        Session::start();
        Session::put('selected_legal_entity_uuid_for_ehealth', $entity->uuid);
        Session::put('logined_guard', 'ehealth');
        if ($role === null) {
            Session::forget('single_role_login');
        } else {
            Session::put('single_role_login', $role);
        }
        Session::put('mis_2fa', ['user_id' => $user->id, 'verified_at' => now()->toIso8601String()]);
        $controller = Mockery::mock(EHealthLoginController::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $tokenData = $this->tokenData($entity, $user);
        if ($scopes !== null) {
            $tokenData['details']['scope'] = implode(' ', $scopes);
        }
        $controller->shouldReceive('sendEHealthTokenRequest')->once()->andReturn($tokenData);
        $controller->shouldReceive('findOrCreateUser')->once()->andReturn($user);

        return $controller(Request::create('/ehealth/oauth', 'GET', ['code' => 'test-code']));
    }

    private function tokenData(LegalEntity $entity, User $user): array
    {
        return [
            'value' => 'test-access-token', 'user_id' => $user->uuid, 'expires_at' => now()->addHour()->timestamp,
            'details' => ['client_id' => $entity->uuid, 'refresh_token' => 'test-refresh-token', 'scope' => 'employee:read'],
        ];
    }

    private function response(array $data): EHealthResponse
    {
        return new EHealthResponse(new PsrResponse(200), fn () => $data);
    }
}
