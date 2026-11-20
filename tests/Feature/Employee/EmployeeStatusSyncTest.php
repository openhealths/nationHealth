<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Classes\eHealth\Api\EmployeeRequest as EmployeeRequestApi;
use App\Enums\Employee\RequestStatus;
use App\Enums\JobStatus;
use App\Enums\Status;
use App\Livewire\Employee\EmployeeIndex;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Permission;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeStatusSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected LegalEntity $entity;
    protected Employee $employee;
    protected array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $type = LegalEntityType::firstOrCreate(['name' => LegalEntity::TYPE_OUTPATIENT]);
        $this->entity = LegalEntity::factory()->create(['legal_entity_type_id' => $type->id]);
        $party = Party::factory()->create();
        $this->user = User::factory()->create(['party_id' => $party->id]);
        $this->employee = Employee::factory()->create([
            'employee_type' => 'RECEPTIONIST', 'division_id' => null,
            'legal_entity_id' => $this->entity->id, 'party_id' => $party->id,
        ]);
        $this->instance('legalEntity', $this->entity);
        $this->actingAs($this->user);
        $this->withSession([config('ehealth.api.oauth.bearer_token') => 'fixture-token', 'single_role_login' => true]);
        config(['ehealth.api.domain' => 'https://ehealth.example.test', 'ehealth.api.token' => 'test', 'ehealth.api.api_key' => 'test']);
    }

    public function test_single_sync_saves_the_status_from_employee_details(): void
    {
        setPermissionsTeamId($this->entity->id);
        $this->user->givePermissionToParent(Permission::findOrCreate('employee:read', 'web'));
        $row = $this->remote($this->employee);
        $row['is_active'] = false;
        $this->fakeResponses(['data' => $row]);
        $request = EmployeeRequest::create([
            'uuid' => (string) Str::uuid(), 'status' => RequestStatus::NEW,
            'employee_id' => $this->employee->id, 'legal_entity_id' => $this->entity->id,
            'position' => 'P1', 'employee_type' => 'RECEPTIONIST',
        ]);
        $requestApi = new EmployeeRequestApi(app(Factory::class));
        $requestApi->preventStrayRequests()->stub([fn () => Http::response(['data' => [
            'id' => $request->uuid, 'legal_entity_id' => $this->entity->uuid,
            'employee_type' => 'RECEPTIONIST', 'position' => 'P1', 'status' => 'APPROVED',
            'party' => ['email' => 'employee@example.test'],
            'inserted_at' => '2026-08-30T08:00:00Z', 'updated_at' => '2026-08-31T14:20:00Z',
        ]])]);
        $this->instance(EmployeeRequestApi::class, $requestApi);

        (new EmployeeIndex())->syncOne($this->employee->id);

        $this->assertEmployment($this->employee, 'STOPPED', false, '2026-09-01');
        $this->assertDatabaseHas('employee_requests', [
            'id' => $request->id, 'status' => 'APPROVED', 'applied_at' => '2026-08-31 17:20:00',
        ]);
    }

    public function test_wrong_employee_uuid_in_details_does_not_change_the_local_position(): void
    {
        setPermissionsTeamId($this->entity->id);
        $this->user->givePermissionToParent(Permission::findOrCreate('employee:read', 'web'));
        $row = $this->remote($this->employee);
        $row['id'] = (string) Str::uuid();
        $row['is_active'] = false;
        $this->fakeResponses(['data' => $row]);

        (new EmployeeIndex())->syncOne($this->employee->id);

        $this->assertCount(1, $this->requests);
        $this->assertEmployment($this->employee, 'APPROVED', true, null);
    }

    public function test_global_sync_handles_active_and_deactivated_positions_on_the_same_page(): void
    {
        Bus::fake();
        Notification::fake();
        $this->entity->setEntityStatus(JobStatus::COMPLETED);
        $this->entity->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_DIVISION);
        $active = $this->employee->replicate()->fill([
            'uuid' => (string) Str::uuid(), 'status' => Status::STOPPED, 'is_active' => false, 'end_date' => '2025-01-01',
        ]);
        $active->save();
        $activeRow = $this->remote($active);
        $activeRow['status'] = 'APPROVED';
        unset($activeRow['end_date']);
        $inactiveRow = $this->remote($this->employee);
        $inactiveRow['status'] = 'AUTODEACTIVATED';
        $this->fakeResponses(['data' => [$activeRow, $inactiveRow]]);

        $component = new EmployeeIndex();
        $component->boot();
        $component->sync();

        $this->assertEmployment($this->employee, 'AUTODEACTIVATED', false, '2026-09-01');
        $this->assertEmployment($active, 'APPROVED', true, null);
    }

    public static function invalidEmployeeFields(): array
    {
        return [
            'invalid active flag' => ['is_active', 'invalid'],
            'request status on a position' => ['status', 'SIGNED'],
            'invalid calendar date' => ['end_date', '2026-02-30T10:45:16.000Z'],
            'invalid timestamp' => ['end_date', '2026-09-01T99:45:16.000Z'],
        ];
    }

    #[DataProvider('invalidEmployeeFields')]
    public function test_single_sync_keeps_local_status_on_invalid_response(string $field, string $value): void
    {
        setPermissionsTeamId($this->entity->id);
        $this->user->givePermissionToParent(Permission::findOrCreate('employee:read', 'web'));
        $row = $this->remote($this->employee);
        $row['is_active'] = false;
        $row[$field] = $value;
        $this->fakeResponses(['data' => $row]);

        (new EmployeeIndex())->syncOne($this->employee->id);

        $this->assertCount(1, $this->requests);
        $this->assertEmployment($this->employee, 'APPROVED', true, null);
    }

    protected function remote(Employee $employee): array
    {
        return [
            'id' => $employee->uuid, 'position' => 'P1', 'employee_type' => 'RECEPTIONIST', 'status' => 'STOPPED',
            'start_date' => '2024-01-01', 'end_date' => '2026-09-01T10:45:16.000Z',
            'party' => [
                'id' => $employee->party->uuid, 'first_name' => 'Test', 'last_name' => 'Employee',
                'no_tax_id' => false, 'tax_id' => '1234567890', 'birth_date' => '1990-01-01',
                'verification_status' => 'VERIFIED', 'declaration_count' => 0, 'declaration_limit' => 0,
                'phones' => [['type' => 'MOBILE', 'number' => '+380500000000']],
                'documents' => [['type' => 'PASSPORT', 'number' => 'TEST000000']],
            ],
        ];
    }

    protected function fakeResponses(array ...$responses): void
    {
        $api = new EmployeeApi(app(Factory::class));
        $api->preventStrayRequests()->stub([function (Request $request) use (&$responses) {
            $this->requests[] = $request;

            return Http::response(array_shift($responses));
        }]);
        $this->instance(EmployeeApi::class, $api);
    }

    protected function assertEmployment(Employee $employee, string $status, bool $active, ?string $endDate): void
    {
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id, 'status' => $status, 'is_active' => $active, 'end_date' => $endDate,
        ]);
    }
}
