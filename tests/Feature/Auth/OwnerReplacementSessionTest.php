<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\EHealth\Services\TokenStorage;
use App\Auth\SessionBinder;
use App\Classes\eHealth\Api\Auth as AuthApi;
use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Classes\eHealth\EHealthResponse;
use App\Events\EHealthUserLogin;
use App\Events\LegalEntityCreate;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Http\Controllers\Auth\EHealthLoginController;
use App\Listeners\OwnerNewReplace;
use App\Livewire\LegalEntity\EditLegalEntity;
use App\Livewire\LegalEntity\Forms\LegalEntitiesForms;
use App\Livewire\LegalEntity\LegalEntity as LegalEntityComponent;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Permission;
use App\Models\Relations\Party;
use App\Models\Role;
use App\Models\User;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class OwnerReplacementSessionTest extends TestCase
{
    use DatabaseTransactions;

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

    public static function ownerScenarios(): array
    {
        return [
            'another person' => ['different_party'],
            'new email in the same party' => ['same_party'],
            'new owner employee for the same user' => ['same_user'],
        ];
    }

    public function test_failed_new_owner_login_keeps_the_old_owner_and_preserves_the_original_error(): void
    {
        [$entity, $oldUser, $newUser, $oldEmployee, $details] = $this->fixture('different_party');
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

    public function test_submitting_an_owner_replacement_logs_out_the_current_owner_with_the_success_message(): void
    {
        [$entity, $oldUser, $newUser] = $this->fixture('different_party');
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

    private function completeLogin(LegalEntity $entity, User $user): \Illuminate\Http\RedirectResponse
    {
        Session::start();
        Session::put('selected_legal_entity_uuid_for_ehealth', $entity->uuid);
        Session::put('logined_guard', 'ehealth');
        Session::put('single_role_login', 'OWNER');
        Session::put('mis_2fa', ['user_id' => $user->id, 'verified_at' => now()->toIso8601String()]);
        $controller = Mockery::mock(EHealthLoginController::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('sendEHealthTokenRequest')->once()->andReturn($this->tokenData($entity, $user));
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
