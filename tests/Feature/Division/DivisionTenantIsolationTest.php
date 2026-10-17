<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Division as DivisionApi;
use App\Classes\eHealth\Api\Responses\Collections\DivisionCreate as DivisionResponse;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Middleware\CheckSessionToken;
use App\Dto\Division\Model as DivisionData;
use App\Enums\User\Role;
use App\Http\Middleware\EnsureSingleSession;
use App\Livewire\Division\DivisionComponent;
use App\Livewire\Division\DivisionCreate;
use App\Livewire\Division\DivisionEdit;
use App\Livewire\Division\DivisionIndex;
use App\Livewire\Division\DivisionView;
use App\Livewire\Division\Forms\DivisionForm;
use App\Livewire\Division\HealthcareService\HealthcareServiceCreate;
use App\Livewire\Division\HealthcareService\HealthcareServiceEdit;
use App\Livewire\Division\HealthcareService\HealthcareServiceIndex;
use App\Livewire\Division\HealthcareService\HealthcareServiceUpdate;
use App\Livewire\Encounter\EncounterComponent;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\User;
use App\Repositories\DivisionRepository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class DivisionTenantIsolationTest extends TestCase
{
    private ConnectionResolverInterface $originalResolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep real Eloquent binding and tenant constraints, but never connect to a development database.
        $this->originalResolver = Model::getConnectionResolver();
        $connection = new class (fn () => throw new RuntimeException('A live database must not be used by isolation fixtures.'), 'testing') extends PostgresConnection
        {
            public \Closure $selectRows;

            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                return ($this->selectRows)($query, $bindings);
            }
        };
        $connection->selectRows = function (string $query, array $bindings): array {
            preg_match('/from "([a-z_]+)"/', $query, $table);
            $rows = match ($table[1] ?? '') {
                'legal_entities' => [
                    ['id' => 1, 'uuid' => 'entity-one', 'status' => 'ACTIVE'],
                    ['id' => 2, 'uuid' => 'entity-two', 'status' => 'ACTIVE'],
                ],
                'divisions' => [
                    ['id' => 11, 'uuid' => 'division-one', 'legal_entity_id' => 1, 'status' => 'ACTIVE', 'name' => 'Own division'],
                    ['id' => 12, 'uuid' => 'division-inactive', 'legal_entity_id' => 1, 'status' => 'INACTIVE'],
                    ['id' => 13, 'uuid' => null, 'legal_entity_id' => 1, 'status' => 'DRAFT'],
                    ['id' => 14, 'uuid' => 'registered-draft', 'legal_entity_id' => 1, 'status' => 'DRAFT'],
                    ['id' => 15, 'uuid' => null, 'legal_entity_id' => 1, 'status' => 'UNSYNCED'],
                    ['id' => 22, 'uuid' => 'division-two', 'legal_entity_id' => 2, 'status' => 'ACTIVE', 'name' => 'Foreign division'],
                ],
                'healthcare_services' => [
                    ['id' => 111, 'uuid' => 'service-one', 'legal_entity_id' => 1, 'division_id' => 11, 'status' => 'ACTIVE'],
                    ['id' => 112, 'uuid' => 'service-other-division', 'legal_entity_id' => 1, 'division_id' => 12, 'status' => 'ACTIVE'],
                    ['id' => 222, 'uuid' => 'service-two', 'legal_entity_id' => 2, 'division_id' => 22, 'status' => 'ACTIVE'],
                ],
                default => throw new RuntimeException('Unexpected fixture query: ' . $query),
            };
            preg_match_all('/(?:"[a-z_]+"\.)?"([a-z_]+)" = \?/', $query, $conditions);
            $this->assertCount(count($bindings), $conditions[1], 'Every fixture query binding must be checked.');

            return array_values(array_map(static fn (array $row): object => (object) $row, array_filter(
                $rows,
                static function (array $row) use ($conditions, $bindings): bool {
                    foreach ($conditions[1] as $index => $column) {
                        if ((string) ($row[$column] ?? '') !== (string) $bindings[$index]) {
                            return false;
                        }
                    }

                    return true;
                }
            )));
        };
        $resolver = new ConnectionResolver(['pgsql' => $connection]);
        $resolver->setDefaultConnection('pgsql');
        Model::setConnectionResolver($resolver);
        config(['cache.default' => 'array']);
        $this->withoutMiddleware([EnsureSingleSession::class, CheckSessionToken::class]);
    }

    protected function tearDown(): void
    {
        Model::setConnectionResolver($this->originalResolver);

        parent::tearDown();
    }

    private function signIn(array $scopes, Role $role = Role::OWNER): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 101;
        $user->emailVerifiedAt = now();
        $user->shouldReceive('checkPermissionTo')->andReturnUsing(fn (string $permission): bool => in_array($permission, $scopes, true));
        $user->shouldReceive('cannot')->andReturnUsing(
            fn (string $ability, mixed $arguments = []): bool => str_contains($ability, ':')
                ? !in_array($ability, $scopes, true)
                : Gate::forUser($user)->denies($ability, $arguments)
        );
        $user->shouldReceive('hasAllowedRole')->andReturnUsing(fn (array $roles): bool => in_array($role, $roles, true));
        $this->actingAs($user, 'ehealth');
        // Membership in both entities must not bypass the eHealth session's legal-entity restriction.
        cache()->put('user_le_ids:101', [1, 2]);
        $this->withSession(['ehealth_legal_entity_uuid' => 'entity-one']);
    }

    private function interceptRoute(string $name): void
    {
        $route = app('router')->getRoutes()->getByName($name);
        $action = $route->getAction();
        // Exercise the configured URL, model binding and authorization without rendering unrelated UI.
        $action['uses'] = static fn (LegalEntity $legalEntity, ?Division $division = null, ?HealthcareService $healthcareService = null) => response()->json([
            'legal_entity_id' => $legalEntity->id,
            'division_id' => $division?->id,
            'healthcare_service_id' => $healthcareService?->id,
        ]);
        $route->setAction($action);
    }

    public static function routePermissions(): array
    {
        return [
            'owner list' => ['division.index', ['division:read'], Role::OWNER, 200],
            'hr list' => ['division.index', ['division:read'], Role::HR, 200],
            'admin list' => ['division.index', ['division:read'], Role::ADMIN, 200],
            'owner list with write only' => ['division.index', ['division:write'], Role::OWNER, 404],
            'hr list with write only' => ['division.index', ['division:write'], Role::HR, 404],
            'list requires read' => ['division.index', ['division:details'], Role::OWNER, 404],
            'owner details' => ['division.view', ['division:details'], Role::OWNER, 200],
            'hr details' => ['division.view', ['division:details'], Role::HR, 200],
            'admin details' => ['division.view', ['division:details'], Role::ADMIN, 200],
            'details requires details' => ['division.view', ['division:read'], Role::OWNER, 404],
            'owner create without read' => ['division.create', ['division:write'], Role::OWNER, 200],
            'hr cannot create' => ['division.create', ['division:write'], Role::HR, 404],
            'create requires write' => ['division.create', ['division:read'], Role::OWNER, 404],
            'owner edit without read' => ['division.edit', ['division:write'], Role::OWNER, 200],
            'hr edit' => ['division.edit', ['division:write'], Role::HR, 200],
            'admin edit' => ['division.edit', ['division:write'], Role::ADMIN, 200],
            'edit requires write' => ['division.edit', ['division:read'], Role::OWNER, 404],
            'own healthcare service' => ['healthcare-service.view', ['division:details', 'healthcare_service:read'], Role::HR, 200],
        ];
    }

    #[DataProvider('routePermissions')]
    public function test_division_http_routes_require_the_documented_scope(string $routeName, array $scopes, Role $role, int $status): void
    {
        $this->signIn($scopes, $role);
        $this->interceptRoute($routeName);

        $this->get(route($routeName, ['legalEntity' => 1, 'division' => 11, 'healthcareService' => 111]))->assertStatus($status);
    }

    public static function divisionRoutes(): array
    {
        return [['division.index'], ['division.create'], ['division.view'], ['division.edit']];
    }

    #[DataProvider('divisionRoutes')]
    public function test_substituting_legal_entity_id_is_denied_even_with_membership_in_both_entities(string $routeName): void
    {
        $this->signIn(['division:read', 'division:details', 'division:write']);
        $this->interceptRoute($routeName);

        $this->get(route($routeName, ['legalEntity' => 2, 'division' => 22]))->assertNotFound();
    }

    public static function mismatchedRoutes(): array
    {
        return [
            ['division.view', 22, null],
            ['division.edit', 22, null],
            ['healthcare-service.create', 22, null],
            ['healthcare-service.view', 22, 222],
            ['healthcare-service.edit', 22, 222],
            ['healthcare-service.update', 22, 222],
            ['healthcare-service.view', 11, 222],
            ['healthcare-service.edit', 11, 222],
            ['healthcare-service.update', 11, 222],
            ['healthcare-service.view', 11, 112],
            ['healthcare-service.edit', 11, 112],
            ['healthcare-service.update', 11, 112],
        ];
    }

    #[DataProvider('mismatchedRoutes')]
    public function test_substituting_a_foreign_division_or_service_in_the_url_is_denied(string $routeName, int $divisionId, ?int $serviceId): void
    {
        $this->signIn(['division:read', 'division:details', 'division:write', 'healthcare_service:read', 'healthcare_service:write']);
        $this->interceptRoute($routeName);

        $this->get(route($routeName, ['legalEntity' => 1, 'division' => $divisionId, 'healthcareService' => $serviceId]))->assertNotFound();
    }

    public static function invalidIds(): array
    {
        return [['0'], ['999999'], ['-1'], ['not-an-id'], ['1 OR 1=1']];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_division_ids_cannot_resolve_a_division_route(string $divisionId): void
    {
        $this->signIn(['division:details']);
        $this->interceptRoute('division.view');

        $response = $this->get(route('division.view', ['legalEntity' => 1, 'division' => $divisionId]));
        if (ctype_digit($divisionId)) {
            $response->assertNotFound();
        } else {
            $response->assertRedirect(route('url.page-not-found'));
        }
    }

    public static function privateActions(): array
    {
        return [
            [DivisionCreate::class, 'setDivisionData'],
            [DivisionEdit::class, 'setDivisionData'],
            [DivisionView::class, 'setDivisionData'],
            [DivisionIndex::class, 'setDivisionData'],
            [DivisionEdit::class, 'divisionUpdate'],
            [HealthcareServiceCreate::class, 'baseMount'],
            [DivisionIndex::class, 'getContractRequestDetailsStartJob'],
            [HealthcareServiceIndex::class, 'getContractRequestDetailsStartJob'],
        ];
    }

    #[DataProvider('privateActions')]
    public function test_livewire_cannot_dispatch_internal_model_loading_methods(string $componentClass, string $method): void
    {
        $component = new $componentClass();
        $this->expectException(MethodNotFoundException::class);

        (new ReflectionMethod(HandleComponents::class, 'callMethods'))->invoke(
            app(HandleComponents::class),
            $component,
            [['method' => $method, 'params' => [new LegalEntity(), new Division()]]],
            new ComponentContext($component)
        );
    }

    public static function configurationReaders(): array
    {
        return [
            [DivisionCreate::class],
            [DivisionEdit::class],
            [DivisionView::class],
            [DivisionIndex::class],
            [HealthcareServiceCreate::class],
            [HealthcareServiceEdit::class],
            [HealthcareServiceIndex::class],
            [HealthcareServiceUpdate::class],
            [EncounterComponent::class],
        ];
    }

    #[DataProvider('configurationReaders')]
    public function test_livewire_cannot_read_ehealth_configuration(string $componentClass): void
    {
        config(['ehealth.api.api_key' => 'audit-synthetic-key']);
        $component = new $componentClass();
        $component->setId('security-audit');
        $this->expectException(MethodNotFoundException::class);

        (new ReflectionMethod(HandleComponents::class, 'callMethods'))->invoke(
            app(HandleComponents::class),
            $component,
            [['method' => 'getFilteredKeysFromConfig', 'params' => ['api.api_key']]],
            new ComponentContext($component)
        );
    }

    public function test_encounter_can_still_filter_dictionary_configuration_internally(): void
    {
        config(['ehealth.audit_dictionary' => ['first', 'second', 'first']]);
        $component = new class extends EncounterComponent
        {
            public function dictionaryKeys(): array
            {
                return $this->getFilteredKeysFromConfig('audit_dictionary');
            }
        };

        $this->assertSame(['first', 'second'], $component->dictionaryKeys());
    }

    public static function detailViews(): array
    {
        return [['division-view'], ['division-edit']];
    }

    #[DataProvider('detailViews')]
    public function test_tampered_form_id_cannot_load_foreign_data_during_rendering(string $view): void
    {
        $this->app->instance('legalEntity', new LegalEntity(['id' => 1]));
        legalEntity()->id = 1;
        $component = new DivisionView();
        $form = new DivisionForm($component, 'divisionForm');
        $form->division = ['id' => 22];

        $this->expectException(ModelNotFoundException::class);

        view('livewire.division.' . $view, ['divisionForm' => $form, 'divisionId' => 22])->render();
    }

    public function test_foreign_form_id_is_rejected_before_saving_or_calling_ehealth(): void
    {
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $component = new DivisionEdit();
        $component->divisionId = 11;
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = ['id' => 22, 'uuid' => 'division-one'];
        $this->mock(DivisionApi::class)->shouldNotReceive('update')->shouldNotReceive('create');
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision');

        $component->update();

        $this->assertSame(__('divisions.policy.deny.edit'), session('error'));
    }

    public static function unavailableSyncDivisions(): array
    {
        return [
            'draft without uuid' => [''],
            'missing division' => ['missing-division'],
            'division of another legal entity' => ['division-two'],
        ];
    }

    #[DataProvider('unavailableSyncDivisions')]
    public function test_details_sync_rejects_unregistered_missing_and_foreign_divisions(string $uuid): void
    {
        $this->signIn(['division:details']);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $component = new DivisionView();
        $component->divisionUuid = $uuid;
        $this->mock(DivisionApi::class)->shouldNotReceive('getDetails');
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision');
        Log::shouldReceive('channel')->never();

        $this->assertNull($component->sync());
        $this->assertSame(__('divisions.policy.deny.sync'), session('error'));
        $this->assertNull(session('success'));
    }

    public function test_details_sync_updates_a_registered_division_of_the_current_legal_entity(): void
    {
        $this->signIn(['division:details']);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $component = new DivisionView();
        $component->divisionUuid = 'division-one';
        $response = $this->mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(new DivisionResponse([
            'uuid' => 'division-one', 'name' => 'Synchronized division', 'type' => 'CLINIC',
            'email' => 'division@example.com', 'status' => 'ACTIVE', 'addresses' => [], 'phones' => [],
        ]));
        $this->mock(DivisionApi::class)->shouldReceive('getDetails')->once()
            ->with('division-one')->andReturn($response);
        $this->mock(DivisionRepository::class)->shouldReceive('saveMappedDivision')->once()
            ->with(
                Mockery::on(fn (DivisionData $data): bool => $data->name === 'Synchronized division'),
                Mockery::on(fn (Division $division): bool => $division->id === 11 && $division->legalEntityId === 1),
                $entity
            );

        $redirect = $component->sync();

        $this->assertSame(route('division.view', [$entity, 11]), $redirect->getTargetUrl());
        $this->assertSame(__('Інформацію успішно оновлено'), session('success'));
        $this->assertNull(session('error'));
    }

    public function test_an_unauthenticated_browser_cannot_open_division_routes(): void
    {
        $this->interceptRoute('division.index');

        $this->get(route('division.index', ['legalEntity' => 1]))->assertRedirect(route('login'));
    }

    public function test_matching_session_legal_entity_does_not_replace_the_membership_check(): void
    {
        $this->signIn(['division:read']);
        cache()->put('user_le_ids:101', [2]);
        $this->interceptRoute('division.index');

        $this->get(route('division.index', ['legalEntity' => 1]))->assertNotFound();
    }

    public function test_draft_deletion_is_allowed_with_write_scope_only_in_its_legal_entity(): void
    {
        $this->signIn(['division:write'], Role::HR);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $division = new Division(['legal_entity_id' => 1, 'status' => 'DRAFT']);

        $this->assertTrue(Gate::allows('delete', $division));

        $division->legalEntityId = 2;

        $this->assertTrue(Gate::denies('delete', $division));
    }

    public static function deniedActions(): array
    {
        return [
            'activate foreign division' => ['activate', 22, ['division:activate']],
            'deactivate foreign division' => ['deactivate', 22, ['division:deactivate']],
            'delete foreign draft' => ['delete', 22, ['division:write']],
            'activate without scope' => ['activate', 12, []],
            'deactivate without scope' => ['deactivate', 11, []],
            'delete without write scope' => ['delete', 13, ['division:read']],
            'activate draft' => ['activate', 13, ['division:activate']],
            'deactivate draft' => ['deactivate', 13, ['division:deactivate']],
            'activate active division' => ['activate', 11, ['division:activate']],
            'deactivate inactive division' => ['deactivate', 12, ['division:deactivate']],
        ];
    }

    #[DataProvider('deniedActions')]
    public function test_livewire_actions_reject_foreign_ids_missing_scopes_and_invalid_statuses(string $action, int $id, array $scopes): void
    {
        $this->signIn($scopes);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $this->mock(DivisionApi::class)->shouldNotReceive('activate')->shouldNotReceive('deactivate');
        $this->mock(DivisionRepository::class)->shouldNotReceive('setAction')->shouldNotReceive('saveMappedDivision');
        Log::shouldReceive('channel')->with('db_errors')->andReturnSelf();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        new DivisionIndex()->{$action}($id);

        $this->assertNotEmpty(session('error'));
        $this->assertNull(session('success'));
    }

    public function test_new_division_cannot_adopt_a_uuid_submitted_by_the_browser(): void
    {
        $this->signIn(['division:write']);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $component = Mockery::mock(DivisionCreate::class)->makePartial();
        $component->shouldReceive('validateDivision')->once()->andReturnTrue();
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = [
            'uuid' => 'division-two', 'name' => 'New division', 'type' => 'CLINIC',
            'email' => 'new@example.com', 'externalId' => null, 'location' => null,
            'workingHours' => [], 'phones' => [], 'addresses' => [],
        ];
        $this->mock(DivisionRepository::class)->shouldReceive('saveMappedDivision')->once()
            ->with(Mockery::on(fn (DivisionData $data): bool => $data->uuid === null && $data->status === 'DRAFT'), Mockery::type(Division::class), $entity)
            ->andReturnUsing(function (DivisionData $data, Division $division): Division {
                $this->assertNull($division->uuid);
                $division->id = 77;

                return $division;
            });

        $this->assertSame(77, $component->store(false)->id);
        $this->assertSame(77, $component->divisionId);
        $this->assertNull($component->divisionForm->division['uuid']);
    }

    public static function rejectedCreationRetries(): array
    {
        return [
            'active division' => [11],
            'inactive division' => [12],
            'draft with remote identity' => [14],
            'non-draft without uuid' => [15],
            'foreign division' => [22],
            'another unregistered draft' => [13],
        ];
    }

    #[DataProvider('rejectedCreationRetries')]
    public function test_create_form_rejects_browser_supplied_ids_before_saving_or_posting(int $id): void
    {
        $this->signIn(['division:write']);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $component = Mockery::mock(DivisionCreate::class)->makePartial();
        $component->shouldNotReceive('validateDivision');
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = ['id' => $id, 'uuid' => null, 'status' => 'DRAFT'];
        $this->mock(DivisionApi::class)->shouldNotReceive('create');
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision');

        $this->assertNull($component->store(false));
        $component->create();

        $this->assertNotEmpty(session('error'));
        $this->assertNull(session('success'));
    }

    public static function usersWithoutCreatePermission(): array
    {
        return [
            'hr with write scope' => [Role::HR, ['division:write']],
            'admin with write scope' => [Role::ADMIN, ['division:write']],
            'owner without write scope' => [Role::OWNER, ['division:read']],
        ];
    }

    #[DataProvider('usersWithoutCreatePermission')]
    public function test_creation_retry_requires_create_permission_even_with_an_existing_draft(Role $role, array $scopes): void
    {
        $this->signIn($scopes, $role);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $component = Mockery::mock(DivisionCreate::class)->makePartial();
        $component->shouldNotReceive('validateDivision');
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = ['id' => 13];
        $this->mock(DivisionApi::class)->shouldNotReceive('create');
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision');

        $component->create();

        $this->assertSame(__('divisions.policy.deny.create'), session('error'));
    }

    public function test_owner_is_redirected_to_edit_for_its_saved_draft(): void
    {
        $this->signIn(['division:write']);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $component = Mockery::mock(DivisionCreate::class)->makePartial();
        $component->shouldNotReceive('validateDivision');
        $component->divisionId = 13;
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = [
            'id' => 13, 'uuid' => 'forged-uuid', 'name' => 'Draft division', 'type' => 'CLINIC',
            'email' => 'draft@example.com', 'externalId' => null, 'location' => null,
            'workingHours' => [], 'phones' => [], 'addresses' => [],
        ];
        $input = $component->divisionForm->division;
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision');
        $this->mock(DivisionApi::class)->shouldNotReceive('create');

        $redirect = $component->store(false);
        $component->create();

        $this->assertSame(route('division.edit', [$entity, 13]), $redirect->getTargetUrl());
        $this->assertSame($input, $component->divisionForm->division);
        $this->assertNull(session('error'));
    }

    public function test_edit_saves_the_mounted_division_with_its_server_uuid(): void
    {
        $this->signIn(['division:write'], Role::HR);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $component = Mockery::mock(DivisionEdit::class)->makePartial();
        $component->shouldReceive('validateDivision')->once()->andReturnTrue();
        $component->divisionId = 11;
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = [
            'id' => 11, 'uuid' => 'forged-uuid', 'name' => 'Updated division', 'type' => 'CLINIC',
            'email' => 'division@example.com', 'phones' => [], 'addresses' => [],
        ];
        $this->mock(DivisionRepository::class)->shouldReceive('saveMappedDivision')->once()
            ->with(
                Mockery::on(fn (DivisionData $data): bool => $data->uuid === 'division-one' && $data->status === 'UNSYNCED'),
                Mockery::on(fn (Division $division): bool => $division->id === 11 && $division->uuid === 'division-one'),
                $entity
            )->andReturnUsing(fn (DivisionData $data, Division $division): Division => $division);

        $this->assertSame(11, $component->store(false)->id);
        $this->assertSame('division-one', $component->divisionForm->division['uuid']);
    }

    public static function failedLocalCreationStages(): array
    {
        return ['validation failure' => [false], 'database failure' => [true]];
    }

    #[DataProvider('failedLocalCreationStages')]
    public function test_local_creation_failure_does_not_redirect_or_submit_to_ehealth(bool $valid): void
    {
        $this->signIn(['division:write']);
        $component = Mockery::mock(DivisionCreate::class)->makePartial();
        $component->shouldReceive('validateDivision')->once()->andReturn($valid);
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = [
            'name' => 'New division', 'type' => 'CLINIC', 'email' => 'division@example.com',
            'phones' => [], 'addresses' => [],
        ];
        $this->mock(DivisionApi::class)->shouldNotReceive('create');
        $repository = $this->mock(DivisionRepository::class);
        if ($valid) {
            $repository->shouldReceive('saveMappedDivision')->once()->andThrow(new RuntimeException('Database failed'));
            Log::shouldReceive('channel')->once()->with('db_errors')->andReturnSelf();
            Log::shouldReceive('error')->once();
            $entity = new LegalEntity();
            $entity->id = 1;
            $this->app->instance('legalEntity', $entity);
        } else {
            $repository->shouldNotReceive('saveMappedDivision');
        }

        $component->create();

        $this->assertNull($component->divisionId);
        $this->assertNull(\Livewire\store($component)->get('redirect'));
        $this->assertNull(session('success'));
    }

    public static function tamperedDivisionFormIds(): array
    {
        return [
            'create with another draft' => [DivisionCreate::class, 13],
            'create with foreign division' => [DivisionCreate::class, 22],
            'edit with another draft' => [DivisionEdit::class, 13],
            'edit with foreign division' => [DivisionEdit::class, 22],
        ];
    }

    #[DataProvider('tamperedDivisionFormIds')]
    public function test_form_id_cannot_replace_the_component_division(string $componentClass, int $id): void
    {
        $this->signIn(['division:write']);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $component = Mockery::mock($componentClass)->makePartial();
        $component->shouldNotReceive('validateDivision');
        $component->divisionId = 11;
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = ['id' => $id];
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveMappedDivision');
        $this->mock(DivisionApi::class)->shouldNotReceive('create')->shouldNotReceive('update');

        $this->assertNull($component->store(false));

        $this->assertSame(__('divisions.policy.deny.edit'), session('error'));
        $this->assertNull(session('success'));
    }

    public function test_create_division_identity_cannot_be_replaced_through_livewire(): void
    {
        $component = new class extends DivisionCreate
        {
            public function mount(?LegalEntity $legalEntity = null): void
            {
                $this->divisionId = 13;
            }

            public function render(): \Illuminate\View\View
            {
                return view('errors.404');
            }
        };
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test($component)->set('divisionId', 22);
    }

    public function test_edit_division_identity_cannot_be_replaced_through_livewire(): void
    {
        $component = new class extends DivisionEdit
        {
            public function mount(?LegalEntity $legalEntity = null, ?Division $division = null): void
            {
                $this->divisionId = 11;
            }

            public function render(): \Illuminate\View\View
            {
                return view('errors.404');
            }
        };
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test($component)->set('divisionId', 22);
    }

    public function test_healthcare_service_filter_cannot_expose_a_foreign_division_uuid(): void
    {
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $component = new HealthcareServiceIndex();
        $component->divisionFilter = 22;
        $this->expectException(ModelNotFoundException::class);

        $component->healthcareServices();
    }

    public static function lockedHealthcareIdentifiers(): array
    {
        return [['divisionId', 22], ['divisionUuid', 'division-two']];
    }

    #[DataProvider('lockedHealthcareIdentifiers')]
    public function test_healthcare_service_livewire_identifiers_cannot_be_replaced(string $property, mixed $value): void
    {
        $component = new class extends HealthcareServiceIndex
        {
            public function mount(?LegalEntity $legalEntity = null, ?Division $division = null): void
            {
                $this->divisionId = 11;
                $this->divisionUuid = 'division-one';
            }

            public function render(): \Illuminate\View\View
            {
                return view('errors.404');
            }
        };
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test($component)->set($property, $value);
    }

    public function test_division_details_remote_uuid_cannot_be_replaced(): void
    {
        $component = new class extends DivisionView
        {
            public function mount(?LegalEntity $legalEntity = null, ?Division $division = null): void
            {
                $this->divisionUuid = 'division-one';
            }

            public function render(): \Illuminate\View\View
            {
                return view('errors.404');
            }
        };
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test($component)->set('divisionUuid', 'division-two');
    }

    public function test_authorized_model_uuid_replaces_a_tampered_form_uuid_before_saving(): void
    {
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        $division = Division::findOrFail(11);
        $component = new DivisionEdit();
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = [
            'id' => 11, 'uuid' => 'division-two', 'name' => 'Own changes', 'type' => 'CLINIC',
            'email' => 'own@example.com', 'externalId' => null, 'location' => null,
            'workingHours' => [], 'phones' => [], 'addresses' => [],
        ];
        $this->mock(DivisionRepository::class)->shouldReceive('saveMappedDivision')->once()
            ->with(Mockery::on(fn (DivisionData $data): bool => $data->uuid === 'division-one' && $data->status === 'UNSYNCED'), $division, $entity)
            ->andReturn($division);

        (new ReflectionMethod(DivisionComponent::class, 'saveToDB'))->invoke($component, $division);

        $this->assertSame('division-one', $component->divisionForm->division['uuid']);
    }
}
