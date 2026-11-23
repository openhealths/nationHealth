<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Status;
use App\Livewire\Employee\EmployeeIndex;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Permission;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesEmployeeDictionaries;
use Tests\TestCase;

class EmployeeEsozComplianceTest extends TestCase
{
    use DatabaseTransactions;
    use FakesEmployeeDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeEmployeeDictionaries();
    }

    public static function deniedDeactivations(): array
    {
        return [
            'read-only user' => [false, Status::APPROVED, false],
            'another legal entity' => [true, Status::APPROVED, true],
            'new employee' => [false, Status::NEW, true],
            'stopped employee' => [false, Status::STOPPED, true],
        ];
    }

    #[DataProvider('deniedDeactivations')]
    public function test_denied_deactivation_has_no_api_or_database_side_effects(bool $foreign, Status $status, bool $canDeactivate): void
    {
        [$legalEntity, $employee] = $this->fixture($foreign, $status, $canDeactivate);
        $this->assertTrue(Gate::allows('viewAny', Employee::class));
        $this->assertTrue(Gate::denies('deactivate', $employee));
        $before = $employee->fresh()->getRawOriginal();
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldNotReceive('deactivate');
        $this->instance(EmployeeApi::class, $api);

        $component = Livewire::test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->assertStatus(200)
            ->call('showModalDeactivate', $employee->id)
            ->assertSet('showDeactivateModal', false)
            ->assertSee(__('employees.policy.deactivate_denied'))
            ->assertNotDispatched('flashMessage');
        $this->assertFalse(Session::has('error'));
        $component->call('$refresh')->assertDontSee(__('employees.policy.deactivate_denied'))
            ->set('employeeIdToDeactivate', $employee->id)
            ->set('deactivationStatus', Status::ENTERED_IN_ERROR->value)
            ->call('deactivate')
            ->assertStatus(200)
            ->assertSee(__('employees.policy.deactivate_denied'))
            ->assertNotDispatched('flashMessage')
            ->assertNoRedirect();

        $this->assertSame($before, $employee->fresh()->getRawOriginal());
    }

    public static function allowedDeactivations(): array
    {
        return [
            'stopped' => [Status::STOPPED, '2024-02-29'],
            'entered in error' => [Status::ENTERED_IN_ERROR, null],
        ];
    }

    #[DataProvider('allowedDeactivations')]
    public function test_authorized_deactivation_preserves_the_expected_payload(Status $status, ?string $endDate): void
    {
        [$legalEntity, $employee] = $this->fixture();
        $this->assertTrue(Gate::allows('deactivate', $employee));
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('deactivate')->once()->with($employee->uuid, $endDate, $status->value)
            ->andReturn(Mockery::mock(EHealthResponse::class));
        $this->instance(EmployeeApi::class, $api);

        Livewire::test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->call('showModalDeactivate', $employee->id)
            ->assertSet('showDeactivateModal', true)
            ->set('deactivationStatus', $status->value)
            ->set('deactivationEndDate', '2024-02-29')
            ->call('deactivate')
            ->assertStatus(200)
            ->assertSet('showDeactivateModal', false);

        $employee->refresh();
        $this->assertSame($status, $employee->status);
        $this->assertSame($endDate, $employee->getRawOriginal('end_date'));
        $this->assertFalse($employee->isActive);
    }

    public function test_employee_list_displays_the_record_identifier_and_employment_dates(): void
    {
        [$legalEntity, $employee] = $this->fixture(status: Status::STOPPED);
        $employee->update(['end_date' => '2024-02-29']);

        $component = Livewire::test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->set('status', [Status::STOPPED->value])
            ->assertStatus(200);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$component->html());
        $cells = new \DOMXPath($document);
        $text = implode(' ', array_map(
            static fn (\DOMNode $node): string => $node->textContent,
            iterator_to_array($cells->query('//tbody/tr/td'))
        ));

        $this->assertStringContainsString($employee->uuid, $text);
        $this->assertStringContainsString($employee->startDate, $text);
        $this->assertStringContainsString($employee->endDate, $text);
    }

    public static function singleSyncStatuses(): array
    {
        return [
            'dismissed' => ['STOPPED', Status::STOPPED],
            'automatic deactivation' => ['AUTODEACTIVATED', Status::AUTODEACTIVATED],
            'entered in error' => ['ENTERED IN ERROR', Status::ENTERED_IN_ERROR],
            'another position' => ['STOPPED', null, false],
            'invalid employee status' => ['DRAFT', null],
            'dismissed while waiting for details' => ['APPROVED', null, true, true],
        ];
    }

    #[DataProvider('singleSyncStatuses')]
    public function test_sync_one_saves_only_the_selected_positions_status(
        string $remote,
        ?Status $syncedStatus,
        bool $matchingUuid = true,
        bool $dismissDuringRequest = false,
    ): void {
        [, $employee] = $this->fixture();
        auth()->user()->update(['party_id' => $employee->partyId]);
        $employee->update(['employee_type' => 'RECEPTIONIST']);
        $other = $employee->replicate()->fill(['uuid' => (string) Str::uuid()]);
        $other->save();
        session([config('ehealth.api.oauth.bearer_token') => 'test-token']);
        config(['ehealth.api.domain' => 'https://ehealth.example.test']);
        $details = [
            'id' => $matchingUuid ? $employee->uuid : $other->uuid, 'status' => $remote, 'is_active' => true,
            'employee_type' => 'RECEPTIONIST', 'position' => 'P1', 'start_date' => '2024-01-01',
            'party' => [
                'id' => $employee->party->uuid, 'first_name' => 'Іван', 'last_name' => 'Тестовий',
                'no_tax_id' => false, 'tax_id' => '1234567890', 'birth_date' => '1990-01-01',
                'declaration_count' => 0, 'declaration_limit' => 0, 'verification_status' => 'VERIFIED',
                'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
                'documents' => [['type' => 'PASSPORT', 'number' => 'AB123456']],
            ],
        ];
        $api = new EmployeeApi(app(Factory::class));
        $api->preventStrayRequests()->stub([function (Request $request) use ($employee, $details, $dismissDuringRequest) {
            $this->assertSame('/api/employees/'.$employee->uuid, parse_url($request->url(), PHP_URL_PATH));
            if ($dismissDuringRequest) {
                $employee->update(['status' => Status::STOPPED]);
            }

            return Http::response(['data' => $details]);
        }]);
        $this->instance(EmployeeApi::class, $api);

        $index = new EmployeeIndex();
        $index->syncOne($employee->id);

        $this->assertSame($syncedStatus ?? ($dismissDuringRequest ? Status::STOPPED : Status::APPROVED), $employee->fresh()->status);
        $this->assertSame(Status::APPROVED, $other->fresh()->status);
        $this->assertSame($syncedStatus !== null, $index->refreshTrigger === 1);
    }

    /** @return array{LegalEntity, Employee} */
    private function fixture(bool $foreign = false, Status $status = Status::APPROVED, bool $canDeactivate = true): array
    {
        $type = LegalEntityType::firstOrCreate(['name' => 'PRIMARY_CARE']);
        $legalEntity = LegalEntity::create(['uuid' => (string) Str::uuid(), 'legal_entity_type_id' => $type->id, 'is_active' => true]);
        $targetEntity = $foreign
            ? LegalEntity::create(['uuid' => (string) Str::uuid(), 'legal_entity_type_id' => $type->id])
            : $legalEntity;
        $this->instance('legalEntity', $legalEntity);
        setPermissionsTeamId($legalEntity->id);
        Permission::findOrCreate('employee:read', 'web');
        Permission::findOrCreate('employee:deactivate', 'web');
        $user = User::forceCreate(['email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]);
        $user->givePermissionToParent($canDeactivate ? ['employee:read', 'employee:deactivate'] : ['employee:read']);
        session(['single_role_login' => true]);
        $this->actingAs($user);
        $party = Party::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Іван', 'last_name' => 'Тестовий',
            'birth_date' => '1990-01-01', 'gender' => 'MALE',
        ]);
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'employee_type' => 'DOCTOR', 'position' => 'P8',
            'start_date' => '2024-01-01', 'status' => $status->value, 'is_active' => true,
            'legal_entity_id' => $targetEntity->id, 'party_id' => $party->id,
        ]);

        return [$legalEntity, $employee];
    }
}
