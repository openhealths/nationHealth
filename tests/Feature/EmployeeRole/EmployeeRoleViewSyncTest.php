<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeRole;

use App\Classes\eHealth\Api\EmployeeRole as EmployeeRoleApi;
use App\Enums\EmployeeRole\Status;
use App\Livewire\EmployeeRole\EmployeeRoleView;
use App\Models\EmployeeRole;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Tests\TestCase;

/**
 * Refreshing an employee role from eHealth on its view page.
 *
 * Only the HTTP transport is faked: the eHealth client still builds the request, validates the response and the
 * repository writes it, so the test covers the whole path from the API answer to the stored record.
 */
class EmployeeRoleViewSyncTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    /**
     * Role that exists locally as ACTIVE before every test.
     *
     * @var EmployeeRole
     */
    private EmployeeRole $employeeRole;

    /**
     * Requests the faked eHealth client received.
     *
     * @var Request[]
     */
    private array $sentRequests = [];

    /**
     * A role deactivated in eHealth becomes inactive locally after sync.
     *
     * @return void
     */
    public function test_sync_stores_the_role_as_returned_by_ehealth(): void
    {
        $updatedBy = $this->faker->uuid();

        $this->fakeEHealthResponse(200, [
            'data' => $this->ehealthEmployeeRole([
                'status' => Status::INACTIVE->value,
                'is_active' => false,
                'end_date' => '2026-10-20',
                'updated_by' => $updatedBy
            ])
        ]);

        $this->employeeRoleView()->sync();

        $employeeRole = $this->employeeRole->fresh();
        $this->assertSame(Status::INACTIVE, $employeeRole->status);
        $this->assertFalse($employeeRole->isActive);
        $this->assertSame('2026-10-20', $employeeRole->endDate->toDateString());
        $this->assertSame($updatedBy, $employeeRole->ehealthUpdatedBy);
        $this->assertSame(__('employee-roles.success.synced'), session('success'));

        $this->assertCount(1, $this->sentRequests);
        $this->assertSame('GET', $this->sentRequests[0]->method());
        $this->assertStringEndsWith("/api/employee_roles/{$this->employeeRole->uuid}", $this->sentRequests[0]->url());
    }

    /**
     * An eHealth error leaves the stored role untouched and tells the user about the failure.
     *
     * @return void
     */
    public function test_failed_ehealth_request_leaves_the_role_unchanged(): void
    {
        $this->fakeEHealthResponse(500, ['error' => ['message' => 'Internal server error']]);

        $this->employeeRoleView()->sync();

        $this->assertRoleUnchanged();
        $this->assertNull(session('success'));
        $this->assertSame('500: Internal server error', session('error'));
    }

    /**
     * A response that fails validation is not written to the database.
     *
     * @return void
     */
    public function test_invalid_ehealth_response_leaves_the_role_unchanged(): void
    {
        $invalidEmployeeRole = $this->ehealthEmployeeRole(['status' => Status::INACTIVE->value]);
        unset($invalidEmployeeRole['is_active']);

        $this->fakeEHealthResponse(200, ['data' => $invalidEmployeeRole]);

        $this->employeeRoleView()->sync();

        $this->assertRoleUnchanged();
        $this->assertNull(session('success'));
        $this->assertSame(__('messages.database_error'), session('error'));
    }

    /**
     * Create the locally stored role that the tests synchronize.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->employeeRole = EmployeeRole::factory()->create([
            'status' => Status::ACTIVE,
            'is_active' => true,
            'end_date' => null
        ]);
    }

    /**
     * Build the schema the way a fresh installation does: install migrations hold the full current schema, update
     * migrations are only for upgrading existing databases.
     *
     * @return array
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--path' => database_path('migrations/install'),
            '--realpath' => true
        ];
    }

    /**
     * Assert that the stored role still holds the values it had before sync.
     *
     * @return void
     */
    private function assertRoleUnchanged(): void
    {
        $employeeRole = $this->employeeRole->fresh();

        $this->assertSame(Status::ACTIVE, $employeeRole->status);
        $this->assertTrue($employeeRole->isActive);
        $this->assertNull($employeeRole->endDate);
    }

    /**
     * Make the eHealth employee role client answer every request with the given response, without network access.
     *
     * @param  int  $status
     * @param  array  $body
     * @return void
     */
    private function fakeEHealthResponse(int $status, array $body): void
    {
        $employeeRoleApi = new EmployeeRoleApi($this->app->make(Factory::class))
            ->stub([
                function (Request $request) use ($status, $body): PromiseInterface {
                    $this->sentRequests[] = $request;

                    return Factory::response($body, $status);
                }
            ])
            ->preventStrayRequests();

        $this->app->instance(EmployeeRoleApi::class, $employeeRoleApi);
    }

    /**
     * Component instance for the stored role, in the state Livewire restores between requests.
     *
     * The component is called directly instead of through Livewire::test(), because mount() and render() load
     * dictionaries, which this test does not cover.
     *
     * @return EmployeeRoleView
     */
    private function employeeRoleView(): EmployeeRoleView
    {
        $employeeRoleView = new EmployeeRoleView();
        $employeeRoleView->employeeRoleId = $this->employeeRole->id;
        $employeeRoleView->employeeRoleUuid = $this->employeeRole->uuid;

        return $employeeRoleView;
    }

    /**
     * Employee role in the shape the eHealth "get employee role by id" endpoint returns it.
     *
     * @param  array  $overrides
     * @return array
     */
    private function ehealthEmployeeRole(array $overrides = []): array
    {
        return array_replace([
            'id' => $this->employeeRole->uuid,
            'employee_id' => $this->employeeRole->employee->uuid,
            'healthcare_service_id' => $this->employeeRole->healthcareService->uuid,
            'start_date' => '2026-01-01T00:00:00.000000Z',
            'end_date' => null,
            'status' => Status::ACTIVE->value,
            'is_active' => true,
            'inserted_at' => '2026-01-01T00:00:00.000000Z',
            'inserted_by' => $this->faker->uuid(),
            'updated_at' => '2026-10-20T10:00:00.000000Z',
            'updated_by' => $this->faker->uuid()
        ], $overrides);
    }
}
