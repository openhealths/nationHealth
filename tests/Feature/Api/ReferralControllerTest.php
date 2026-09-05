<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReferralControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected LegalEntity $legalEntity;

    protected Employee $employee;

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

    protected function setUp(): void
    {
        parent::setUp();

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'ref_ctrl_' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Д-р Іван Петренко',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);
        $this->user->employees()->attach($this->employee->id);

        if (config('permission.teams')) {
            setPermissionsTeamId($this->legalEntity->id);
        }
    }

    /**
     * Guard against the endpoints ever being re-exposed on the stateless, unauthenticated api stack.
     */
    public function test_referral_endpoints_are_not_registered_as_public_api_routes(): void
    {
        $publicReferralRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_contains($route->uri(), 'referrals'))
            ->reject(static fn ($route): bool => in_array('web', $route->gatherMiddleware(), true));

        $this->assertTrue(
            $publicReferralRoutes->isEmpty(),
            'Referral routes must not be reachable outside the authenticated dashboard stack.'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function referralRouteProvider(): array
    {
        return [
            'search' => ['referrals.api.search', 'GET', 'service_request:read'],
            'process' => ['referrals.api.process', 'POST', 'service_request:makeinprogress'],
            'complete' => ['referrals.api.complete', 'POST', 'service_request:complete'],
            'cancel usage' => ['referrals.api.cancel-usage', 'POST', 'service_request:use'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('referralRouteProvider')]
    public function test_referral_route_requires_authentication_and_its_ehealth_permission(
        string $routeName,
        string $method,
        string $permission
    ): void {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route [{$routeName}] is not registered.");
        $this->assertContains($method, $route->methods());

        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth:ehealth', $middleware);
        $this->assertContains('can:access,legalEntity', $middleware);
        $this->assertContains(
            'permission:' . $permission,
            $middleware,
            "Route [{$routeName}] must require the [{$permission}] eHealth scope."
        );
    }

    public function test_guest_cannot_reach_referral_endpoints(): void
    {
        $response = $this->postJson($this->url('complete'), ['resource_uuid' => (string) Str::uuid()]);

        $this->assertContains(
            $response->getStatusCode(),
            [401, 403, 302],
            'Unauthenticated callers must not be able to mutate referrals.'
        );
    }

    /**
     * Regression: the controller used to pass the payload array into the `$resourceType`
     * string parameter, which threw a TypeError on every call.
     */
    public function test_complete_forwards_resource_type_and_payload_in_the_right_order(): void
    {
        $referralUuid = (string) Str::uuid();
        $resourceUuid = (string) Str::uuid();

        $mock = Mockery::mock(ReferralRequestLifecycleService::class);
        $mock->shouldReceive('completeReferral')
            ->once()
            ->withArgs(function (
                string $uuid,
                string $passedResourceUuid,
                string $resourceType,
                array $payload
            ) use ($referralUuid, $resourceUuid): bool {
                return $uuid === $referralUuid
                    && $passedResourceUuid === $resourceUuid
                    && $resourceType === 'procedure'
                    && ($payload['note'] ?? null) === 'redeem';
            })
            ->andReturn(['status' => 'completed']);

        $this->app->instance(ReferralRequestLifecycleService::class, $mock);

        $this->actingAsDoctor()
            ->postJson($this->url('complete', $referralUuid), [
                'resource_uuid' => $resourceUuid,
                'resource_type' => 'procedure',
                'payload' => ['note' => 'redeem'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_complete_defaults_the_resource_type_to_encounter(): void
    {
        $mock = Mockery::mock(ReferralRequestLifecycleService::class);
        $mock->shouldReceive('completeReferral')
            ->once()
            ->withArgs(static fn (string $uuid, string $resourceUuid, string $resourceType, array $payload): bool
                => $resourceType === 'encounter' && $payload === [])
            ->andReturn(['status' => 'completed']);

        $this->app->instance(ReferralRequestLifecycleService::class, $mock);

        $this->actingAsDoctor()
            ->postJson($this->url('complete'), ['encounter_uuid' => (string) Str::uuid()])
            ->assertOk();
    }

    public function test_complete_rejects_an_unsupported_resource_type(): void
    {
        $mock = Mockery::mock(ReferralRequestLifecycleService::class);
        $mock->shouldNotReceive('completeReferral');
        $this->app->instance(ReferralRequestLifecycleService::class, $mock);

        $this->actingAsDoctor()
            ->postJson($this->url('complete'), [
                'resource_uuid' => (string) Str::uuid(),
                'resource_type' => 'observation',
            ])
            ->assertStatus(422);
    }

    public function test_process_is_refused_when_the_user_has_no_employee_in_this_legal_entity(): void
    {
        $otherLegalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $this->legalEntity->legal_entity_type_id,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $otherLegalEntity);

        $mock = Mockery::mock(ReferralRequestLifecycleService::class);
        $mock->shouldNotReceive('takeIntoWork');
        $this->app->instance(ReferralRequestLifecycleService::class, $mock);

        $this->actingAsDoctor()
            ->postJson($this->url('process'), ['patient_uuid' => (string) Str::uuid()])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_ehealth_validation_errors_are_surfaced_with_their_details(): void
    {
        $details = ['error' => ['type' => 'validation_failed', 'message' => 'Invalid referral']];

        $mock = Mockery::mock(ReferralRequestLifecycleService::class);
        $mock->shouldReceive('completeReferral')->once()->andThrow(new EHealthValidationException($details));
        $this->app->instance(ReferralRequestLifecycleService::class, $mock);

        $this->actingAsDoctor()
            ->postJson($this->url('complete'), ['resource_uuid' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.error.type', 'validation_failed');
    }

    public function test_unexpected_failures_do_not_leak_internals_to_the_client(): void
    {
        $mock = Mockery::mock(ReferralRequestLifecycleService::class);
        $mock->shouldReceive('completeReferral')
            ->once()
            ->andThrow(new RuntimeException('SQLSTATE[42P01]: undefined_table service_request_requests'));
        $this->app->instance(ReferralRequestLifecycleService::class, $mock);

        $response = $this->actingAsDoctor()
            ->postJson($this->url('complete'), ['resource_uuid' => (string) Str::uuid()])
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent() ?: '');
    }

    private function actingAsDoctor(): static
    {
        return $this->actingAs($this->user, 'ehealth')->withoutMiddleware();
    }

    private function url(string $action, ?string $referralUuid = null): string
    {
        $uuid = $referralUuid ?? (string) Str::uuid();
        $base = "/dashboard/{$this->legalEntity->id}/referrals/api";

        return $action === 'search'
            ? "{$base}/search"
            : "{$base}/{$uuid}/{$action}";
    }
}
