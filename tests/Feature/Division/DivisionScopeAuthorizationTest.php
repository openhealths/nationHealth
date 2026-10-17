<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Division as DivisionApi;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\Division\DivisionCreate;
use App\Livewire\Division\DivisionEdit;
use App\Livewire\Division\DivisionIndex;
use App\Livewire\Division\DivisionView;
use App\Livewire\Division\Forms\DivisionForm;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class DivisionScopeAuthorizationTest extends TestCase
{
    public static function creationRoles(): array
    {
        $cases = [];

        foreach (Role::cases() as $role) {
            $cases[$role->value] = [$role, true, $role === Role::OWNER];
        }

        $cases['OWNER without write scope'] = [Role::OWNER, false, false];

        return $cases;
    }

    #[DataProvider('creationRoles')]
    public function test_only_owner_with_write_scope_can_create_divisions(Role $role, bool $write, bool $allowed): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('checkPermissionTo')->andReturnFalse();
        $user->shouldReceive('cannot')->with('division:write')->andReturn(!$write);
        $user->shouldReceive('hasAllowedRole')->with([Role::OWNER])->andReturn($role === Role::OWNER);

        $response = Gate::forUser($user)->inspect('create', Division::class);

        $this->assertSame($allowed, $response->allowed());

        if (!$allowed) {
            $this->assertSame(404, $response->status());
        }
    }

    public static function modificationRolesAndScopes(): array
    {
        $cases = [];

        foreach (Role::cases() as $role) {
            foreach ([true, false] as $hasScope) {
                $cases[$role->value . ($hasScope ? ' with scope' : ' without scope')] = [$role, $hasScope];
            }
        }

        return $cases;
    }

    #[DataProvider('modificationRolesAndScopes')]
    public function test_registered_division_actions_require_scope_without_role_restrictions(Role $role, bool $hasScope): void
    {
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('checkPermissionTo')->andReturnFalse();
        $user->shouldReceive('hasAllowedRole')->with([Role::OWNER])->andReturn($role === Role::OWNER);

        foreach (['update' => 'write', 'activate' => 'activate', 'deactivate' => 'deactivate'] as $action => $scope) {
            $user->shouldReceive('cannot')->with('division:' . $scope)->andReturn(!$hasScope);
            $division = new Division([
                'uuid' => 'division-uuid',
                'legal_entity_id' => $entity->id,
                'status' => $action === 'activate' ? Status::INACTIVE : Status::ACTIVE,
            ]);
            $gate = Gate::forUser($user);

            $this->assertSame($hasScope, $gate->allows($action, $division), $action);

            $division->legalEntityId = 2;

            $this->assertTrue($gate->denies($action, $division), $action . ' for another legal entity');
        }
    }

    public static function divisionStatusTransitions(): array
    {
        $cases = [];

        foreach (['activate' => Status::INACTIVE, 'deactivate' => Status::ACTIVE] as $action => $allowedStatus) {
            foreach ([Status::ACTIVE, Status::INACTIVE, Status::DRAFT, Status::UNSYNCED] as $status) {
                $cases[$action . ' ' . $status->value] = [$action, $status, $status === $allowedStatus || ($action === 'deactivate' && $status === Status::UNSYNCED)];
            }
        }

        return $cases;
    }

    #[DataProvider('divisionStatusTransitions')]
    public function test_status_changes_only_allow_documented_transitions(string $action, Status $status, bool $allowed): void
    {
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('checkPermissionTo')->andReturnFalse();
        $user->shouldReceive('cannot')->with('division:' . $action)->andReturnFalse();
        $division = new Division([
            'uuid' => $status === Status::DRAFT ? null : 'division-uuid',
            'legal_entity_id' => $entity->id,
            'status' => $status,
        ]);

        $this->assertSame($allowed, Gate::forUser($user)->allows($action, $division));
    }

    public function test_division_form_routes_do_not_require_read_scopes(): void
    {
        foreach (['division.create' => 'can:create,' . Division::class, 'division.edit' => 'can:update,division'] as $routeName => $authorization) {
            $middleware = app('router')->getRoutes()->getByName($routeName)->gatherMiddleware();

            $this->assertNotContains('permission:division:read|division:details', $middleware, $routeName);
            $this->assertContains($authorization, $middleware, $routeName);
        }

        $middleware = app('router')->getRoutes()->getByName('healthcare-service.create')->gatherMiddleware();

        $this->assertContains('permission:division:read|division:details', $middleware);
    }

    public function test_non_owner_cannot_register_a_draft_through_editing(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('checkPermissionTo')->andReturnFalse();
        $user->shouldReceive('cannot')->with('division:write')->andReturnFalse();
        $user->shouldReceive('hasAllowedRole')->with([Role::OWNER])->andReturnFalse();
        $user->shouldReceive('cannot')->with('create', Division::class)
            ->andReturnUsing(fn (): bool => Gate::forUser($user)->denies('create', Division::class));
        Auth::shouldReceive('user')->once()->andReturn($user);
        $this->mock(DivisionApi::class)->shouldNotReceive('update')->shouldNotReceive('create');

        $component = new DivisionEdit();
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = ['uuid' => null];
        (new ReflectionMethod(DivisionEdit::class, 'divisionUpdate'))->invoke($component, new Division());

        $this->assertSame(__('divisions.policy.deny.create'), session('error'));
        $this->assertNull(session('success'));
    }

    public function test_division_update_cannot_be_called_directly_through_livewire(): void
    {
        $component = new DivisionEdit();
        $this->mock(DivisionApi::class)->shouldNotReceive('update')->shouldNotReceive('create');

        $this->expectException(MethodNotFoundException::class);

        (new ReflectionMethod(HandleComponents::class, 'callMethods'))->invoke(
            app(HandleComponents::class),
            $component,
            [['method' => 'divisionUpdate', 'params' => [new Division()]]],
            new ComponentContext($component)
        );
    }

    public function test_non_owner_cannot_submit_division_creation(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('checkPermissionTo')->andReturnFalse();
        $user->shouldReceive('cannot')->with('division:write')->andReturnFalse();
        $user->shouldReceive('hasAllowedRole')->with([Role::OWNER])->andReturnFalse();
        $user->shouldReceive('cannot')->with('create', Division::class)
            ->andReturnUsing(fn (): bool => Gate::forUser($user)->denies('create', Division::class));
        Auth::shouldReceive('user')->once()->andReturn($user);
        $this->mock(DivisionApi::class)->shouldNotReceive('create');

        $component = new DivisionCreate();
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->create();

        $this->assertSame(__('divisions.policy.deny.create'), session('error'));
        $this->assertNull(session('success'));
    }

    public static function scopes(): array
    {
        return [
            'no scopes' => [false, false, false],
            'read only' => [true, false, false],
            'details only' => [false, true, false],
            'read and details' => [true, true, false],
            'write only' => [false, false, true],
            'read and write' => [true, false, true],
            'details and write' => [false, true, true],
            'all scopes' => [true, true, true],
        ];
    }

    private function userWithScopes(bool $read, bool $details, bool $write = false): User
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('cannot')->with('division:read')->andReturn(!$read);
        $user->shouldReceive('cannot')->with('division:details')->andReturn(!$details);
        $user->shouldReceive('cannot')->with('division:write')->andReturn(!$write);
        $user->shouldReceive('checkPermissionTo')->andReturnFalse();

        return $user;
    }

    #[DataProvider('scopes')]
    public function test_policy_checks_the_scope_for_each_operation(bool $read, bool $details, bool $write): void
    {
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $division = new Division(['legal_entity_id' => $entity->id, 'uuid' => 'division-uuid']);
        $gate = Gate::forUser($this->userWithScopes($read, $details, $write));

        $this->assertSame($read, $gate->allows('viewAny', Division::class));
        $this->assertSame($details, $gate->allows('view', $division));
        $this->assertSame($read, $gate->allows('sync', Division::class));
        $this->assertSame($details, $gate->allows('sync', $division));
    }

    public function test_required_roles_can_sync_with_legal_entity_scope_intersections(): void
    {
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $division = new Division(['legal_entity_id' => $entity->id, 'uuid' => 'division-uuid']);

        foreach (config('ehealth.legal_entity_employee_types') as $type => $allowedRoles) {
            foreach (['OWNER', 'HR', 'ADMIN'] as $role) {
                if (!in_array($role, $allowedRoles, true)) {
                    continue;
                }

                $scopes = array_intersect(config('ehealth.roles.' . $role), config('ehealth.legal_entity_types.' . $type));
                $this->assertContains('division:read', $scopes, "$type / $role");
                $this->assertContains('division:details', $scopes, "$type / $role");

                $gate = Gate::forUser($this->userWithScopes(
                    in_array('division:read', $scopes, true),
                    in_array('division:details', $scopes, true)
                ));

                $this->assertTrue($gate->allows('sync', Division::class), "$type / $role: list sync");
                $this->assertTrue($gate->allows('sync', $division), "$type / $role: division sync");
            }
        }
    }

    public static function synchronizationDivisions(): array
    {
        return [
            'draft without uuid' => [Status::DRAFT, null, false],
            'draft with empty uuid' => [Status::DRAFT, '', false],
            'unsynced without uuid' => [Status::UNSYNCED, null, false],
            'active with uuid' => [Status::ACTIVE, 'division-uuid', true],
            'inactive with uuid' => [Status::INACTIVE, 'division-uuid', true],
            'unsynced with uuid' => [Status::UNSYNCED, 'division-uuid', true],
        ];
    }

    #[DataProvider('synchronizationDivisions')]
    public function test_details_synchronization_requires_an_ehealth_uuid(Status $status, ?string $uuid, bool $allowed): void
    {
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $division = new Division(['legal_entity_id' => $entity->id, 'status' => $status, 'uuid' => $uuid]);
        $gate = Gate::forUser($this->userWithScopes(true, true));

        $this->assertTrue($gate->allows('view', $division));
        $this->assertTrue($gate->allows('sync', Division::class));
        $response = $gate->inspect('sync', $division);

        $this->assertSame($allowed, $response->allowed());
        if (!$allowed) {
            $this->assertSame(404, $response->status());
        }
    }

    public function test_details_scope_does_not_allow_viewing_another_legal_entity_division(): void
    {
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $division = new Division(['legal_entity_id' => 2]);

        $response = Gate::forUser($this->userWithScopes(true, true))->inspect('view', $division);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());

        $response = Gate::forUser($this->userWithScopes(true, true))->inspect('sync', $division);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public static function scopesWithoutRead(): array
    {
        return [
            'details only' => [true, false],
            'write only' => [false, true],
            'details and write' => [true, true],
        ];
    }

    #[DataProvider('scopesWithoutRead')]
    public function test_list_synchronization_requires_read_scope(bool $details, bool $write): void
    {
        $user = $this->userWithScopes(false, $details, $write);
        $user->shouldReceive('cannot')->once()->with('viewAny', Division::class)
            ->andReturnUsing(fn (): bool => Gate::forUser($user)->denies('viewAny', Division::class));
        Auth::shouldReceive('user')->once()->andReturn($user);
        $this->mock(DivisionApi::class)->shouldNotReceive('getMany');

        new DivisionIndex()->sync();

        $this->assertSame(__('divisions.policy.deny.sync'), session('error'));
        $this->assertNull(session('success'));
    }

    public function test_read_scope_alone_cannot_start_details_synchronization(): void
    {
        $user = $this->userWithScopes(true, false);
        Auth::shouldReceive('user')->once()->andReturn($user);
        $this->mock(DivisionApi::class)->shouldNotReceive('getDetails');

        $this->assertNull(new DivisionView()->sync());
        $this->assertSame(__('divisions.policy.deny.sync'), session('error'));
        $this->assertNull(session('success'));
    }
}
