<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Classes\eHealth\Api\DeviceDefinition;
use App\Classes\eHealth\Api\Patient\DeviceRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\DeviceRequest\DeviceRequestIndex;
use App\Livewire\Person\Records\PatientDeviceRequestView;
use App\Livewire\Person\Records\PatientDeviceRequests;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\LegalEntityRepository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use App\Services\SignatureService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use ReflectionMethod;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class PatientDeviceRequestsTest extends TestCase
{
    use DatabaseTransactions;
    use MocksBasicDictionaries;

    private LegalEntity $legalEntity;
    private Person $person;
    private User $user;
    private Employee $employee;
    private string $requestId;
    private string $programId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['pagination.per_page' => 10]);
        app()->setLocale('uk');
        $this->requestId = (string) Str::uuid();
        $this->programId = (string) Str::uuid();
        $this->mockBasicDictionaries([
            'eHealth/assistive_devices' => ['orthosis' => 'Ортез'],
            'device_definition_classification_type' => [],
            'device_request_code_parameter' => ['brakes' => 'Здатність відкриватися'],
            'device_definition_packaging_type' => ['box' => 'Коробка'],
            'device_unit' => ['piece' => 'Штука'],
            'FUNDING_SOURCE' => [],
        ]);
        Cache::put(MedicalProgramDictionary::KEY, [['id' => $this->programId, 'name' => 'Доступні вироби', 'type' => 'DEVICE', 'is_active' => true, 'medical_program_settings' => []]], now()->addDay());
        Cache::put(MedicalProgramDictionary::KEY.':fresh', true, now()->addDay());
        $party = Party::forceCreate(['uuid' => (string) Str::uuid(), 'firstName' => 'Олена', 'lastName' => 'Лікар',
            'taxId' => '1122334455', 'birthDate' => '1980-02-02', 'gender' => 'FEMALE']);
        $this->user = User::forceCreate(['uuid' => (string) Str::uuid(), 'email' => Str::uuid().'@example.com', 'password' => 'test', 'partyId' => $party->id]);
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);
        $this->legalEntity = LegalEntity::forceCreate(['uuid' => (string) Str::uuid(), 'status' => 'ACTIVE',
            'syncStatus' => 'COMPLETED', 'legalEntityTypeId' => $typeId, 'isActive' => true]);
        $this->instance('legalEntity', $this->legalEntity);
        setPermissionsTeamId($this->legalEntity->id);
        $this->employee = Employee::forceCreate(['uuid' => (string) Str::uuid(), 'userId' => $this->user->id,
            'partyId' => $party->id, 'legalEntityId' => $this->legalEntity->id, 'employeeType' => 'DOCTOR',
            'status' => 'APPROVED', 'isActive' => true, 'position' => 'Doctor', 'startDate' => now()->toDateString()]);
        $this->user->employees()->attach($this->employee->id);
        $this->person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $this->person->names()->forceCreate(['firstName' => 'Тарас', 'lastName' => 'Шевченко', 'language' => 'uk']);
        $this->actingAs($this->user);
        $this->grantMedicalEventAbilities($this->user);
        $signature = Mockery::mock(SignatureService::class);
        $signature->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $this->instance(SignatureService::class, $signature);
    }

    private function response(array $data, int $totalPages = 1): EHealthResponse
    {
        return new EHealthResponse(new Response(200, [], json_encode(['data' => $data, 'paging' => ['total_pages' => $totalPages, 'total_entries' => count($data)]], JSON_THROW_ON_ERROR)));
    }

    private function record(): array
    {
        return ['uuid' => $this->requestId, 'status' => 'active', 'intent' => 'order', 'requisition' => '0000-KQR5-ADR4-N5F1',
            'code' => ['coding' => [['code' => 'orthosis', 'system' => 'eHealth/assistive_devices']]],
            'authored_on' => '2026-10-28', 'dispense_valid_to' => '2026-11-28',
            'quantity' => ['value' => 1], 'program' => ['identifier' => ['value' => $this->programId]],
            'requester_legal_entity' => ['display_value' => 'Лікарня №4', 'identifier' => ['value' => $this->legalEntity->uuid]],
            'encounter' => ['identifier' => ['value' => (string) Str::uuid()]],
            'parameter' => [['code' => ['coding' => [['code' => 'brakes']]], 'value_boolean' => false]],
            'reason' => [['display_value' => '<script>reason</script>']]];
    }

    public function test_registry_uses_patient_scoped_search_and_links_to_the_new_detail_page(): void
    {
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()->with($this->person->uuid, ['page' => 1])->andReturn($this->response([$this->record()]));
        $this->instance(DeviceRequest::class, $api);
        Livewire::test(PatientDeviceRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person])
            ->assertSee('Ортез')->assertSee('Лікарня №4')->assertSee('28.10.2026')
            ->assertSeeHtml(route('persons.device-requests.view', [$this->legalEntity, 'person' => $this->person, 'deviceRequest' => $this->requestId]));
    }

    public function test_search_validation_preserves_card_dates_and_references(): void
    {
        $record = $this->record();
        $record['id'] = $record['uuid'];
        unset($record['uuid']);
        $records = (new ReflectionMethod(DeviceRequest::class, 'validateMany'))->invoke(app(DeviceRequest::class), $this->response([$record]));
        foreach (['authored_on', 'requester_legal_entity', 'encounter', 'dispense_valid_to'] as $field) {
            $this->assertSame($record[$field], $records[0][$field]);
        }
    }

    public function testRequesterFilterChangesClearingResetAndPaginationPreservePatientApiContext(): void
    {
        $otherEntityUuid = (string) Str::uuid();
        $episodeUuid = (string) Str::uuid();
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()->ordered()
            ->with($this->person->uuid, ['page' => 1])
            ->andReturn($this->response([], 3));
        $filters = ['requester_legal_entity' => $otherEntityUuid, 'status' => 'active', 'context_episode_id' => $episodeUuid];
        $api->shouldReceive('getBySearchParams')->once()->ordered()
            ->with($this->person->uuid, $filters + ['page' => 1])->andReturn($this->response([], 3));
        $api->shouldReceive('getBySearchParams')->once()->ordered()
            ->with($this->person->uuid, $filters + ['page' => 2])->andReturn($this->response([], 3));
        $api->shouldReceive('getBySearchParams')->once()->ordered()
            ->with($this->person->uuid, ['status' => 'active', 'context_episode_id' => $episodeUuid, 'page' => 1])
            ->andReturn($this->response([], 3));
        $api->shouldReceive('getBySearchParams')->once()->ordered()
            ->with($this->person->uuid, ['requester_legal_entity' => $otherEntityUuid, 'status' => 'active', 'context_episode_id' => $episodeUuid, 'page' => 1])
            ->andReturn($this->response([], 3));
        $api->shouldReceive('getBySearchParams')->once()->ordered()
            ->with($this->person->uuid, ['page' => 1])->andReturn($this->response([]));
        $this->instance(DeviceRequest::class, $api);

        Livewire::test(PatientDeviceRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person])
            ->assertSet('filters.parameters.requester_legal_entity', '')
            ->set('filters.parameters.requester_legal_entity', $otherEntityUuid)
            ->set('filters.parameters.status', 'active')->set('filters.parameters.context_episode_id', $episodeUuid)
            ->call('search')->assertHasNoErrors()->assertSet('requesterEntitySearch', $otherEntityUuid)
            ->call('goToPage', 2)->assertSet('page', 2)->assertSet('filters.parameters.requester_legal_entity', $otherEntityUuid)
            ->set('filters.parameters.requester_legal_entity', '')->call('search')->assertSet('requesterEntitySearch', '')
            ->set('filters.parameters.requester_legal_entity', $otherEntityUuid)->call('search')
            ->call('resetFilters')->assertSet('requesterEntitySearch', '')->assertSet('page', 1)
            ->assertSet('personId', $this->person->id)->assertHasNoErrors();
    }

    public function testInvalidRequesterUuidShowsAFieldErrorWithoutCallingApi(): void
    {
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()
            ->with($this->person->uuid, ['page' => 1])
            ->andReturn($this->response([]));
        $this->instance(DeviceRequest::class, $api);
        Livewire::test(PatientDeviceRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person])
            ->set('filters.parameters.requester_legal_entity', 'not-a-uuid')->call('search')
            ->assertHasErrors(['filters.parameters.requester_legal_entity' => 'uuid']);
    }

    public function testGeneralRegistryStillResolvesLegalEntityNamesForThePatientApi(): void
    {
        $otherEntityUuid = (string) Str::uuid();
        $repository = Mockery::mock(LegalEntityRepository::class)->makePartial();
        $repository->shouldReceive('getLegalEntitiesList')->once()->andReturn([
            ['uuid' => $this->legalEntity->uuid, 'name' => 'Поточна лікарня'],
            ['uuid' => $otherEntityUuid, 'name' => 'Інша лікарня'],
        ]);
        $this->instance(LegalEntityRepository::class, $repository);
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()->ordered()
            ->with($this->person->uuid, ['page' => 1])
            ->andReturn($this->response([]));
        $api->shouldReceive('getBySearchParams')->once()->ordered()
            ->with($this->person->uuid, ['requester_legal_entity' => $otherEntityUuid, 'page' => 1])
            ->andReturn($this->response([]));
        $this->instance(DeviceRequest::class, $api);
        Livewire::test(DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person])
            ->set('requesterEntitySearch', 'інша лікарня')->call('search')->assertHasNoErrors()
            ->assertSet('filters.parameters.requester_legal_entity', $otherEntityUuid);
    }

    public function test_details_are_patient_scoped_and_the_existing_catalog_is_loaded_only_on_open(): void
    {
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getById')->once()->with($this->person->uuid, $this->requestId)->andReturn($this->response($this->record()));
        $this->instance(DeviceRequest::class, $api);
        $service = Mockery::mock(DeviceRequestLifecycleService::class)->makePartial();
        $service->shouldReceive('dictionary')->with('eHealth/assistive_devices')->andReturn(['orthosis' => 'Ортез']);
        $this->instance(DeviceRequestLifecycleService::class, $service);
        $catalog = Mockery::mock(DeviceDefinition::class);
        $catalog->shouldNotReceive('getById');
        $catalog->shouldReceive('getMany')->once()->with(Mockery::on(fn (array $filters): bool => $filters['medical_program_id'] === $this->programId && $filters['classification_type_code'] === 'orthosis' && $filters['page_size'] === 10))
            ->andReturn($this->response([]));
        $this->instance(DeviceDefinition::class, $catalog);
        Livewire::test(PatientDeviceRequestView::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person, 'deviceRequest' => $this->requestId])
            ->assertSee('Загальна інформація')->assertSee('Інформація про виріб')->assertSee('Додаткова інформація')
            ->assertSee('Здатність відкриватися')->assertSee(__('device-requests.form.no'))
            ->assertSeeHtml('&lt;script&gt;reason&lt;/script&gt;')
            ->set('showDeviceCatalog', true)->assertSee(__('dictionaries.medical_device.page_title'));
    }

    public function test_draft_filter_only_returns_the_current_doctors_drafts_for_this_patient(): void
    {
        $ownDraft = DeviceRequestRequest::create(['uuid' => (string) Str::uuid(), 'employeeId' => $this->employee->id,
            'personId' => $this->person->id, 'deviceId' => (string) Str::uuid(), 'status' => 'draft', 'requestPayload' => $this->record()]);
        $otherPerson = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        DeviceRequestRequest::create(['uuid' => (string) Str::uuid(), 'employeeId' => $this->employee->id,
            'personId' => $otherPerson->id, 'deviceId' => (string) Str::uuid(), 'status' => 'draft', 'requestPayload' => $this->record()]);
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()->andReturn($this->response([]));
        $this->instance(DeviceRequest::class, $api);
        Livewire::test(PatientDeviceRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person])
            ->set('filters.parameters.status', 'draft')->call('search')->assertSet('requests', [])
            ->assertSet('drafts', fn (array $drafts): bool => count($drafts) === 1 && $drafts[0]['uuid'] === $ownDraft->uuid);
    }

    public function test_read_scope_without_an_active_employee_cannot_fetch_patient_requests(): void
    {
        $this->employee->update(['is_active' => false]);
        $this->user->employees()->detach();
        $this->user->unsetRelation('employees');
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldNotReceive('getBySearchParams');
        $this->instance(DeviceRequest::class, $api);
        Livewire::test(PatientDeviceRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person])
            ->assertSee(__('device-requests.patient.tab'))->assertSet('requests', [])
            ->call('search')->assertStatus(200)->assertSee(__('device-requests.policy.perform'));
    }

    public function test_active_employee_of_the_current_party_can_read_requests_without_a_pivot(): void
    {
        $this->user->employees()->detach();
        $this->user->unsetRelation('employees');
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()
            ->with($this->person->uuid, ['page' => 1])->andReturn($this->response([$this->record()]));
        $this->instance(DeviceRequest::class, $api);

        Livewire::test(PatientDeviceRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person])
            ->assertSee('Ортез')->assertDontSee(__('device-requests.policy.perform'));
    }
    public function testPatientRegistryCannotSwitchOrClearThePatient(): void
    {
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()->with($this->person->uuid, ['page' => 1])
            ->andReturn($this->response([]));
        $this->instance(DeviceRequest::class, $api);
        $otherPerson = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);

        Livewire::test(PatientDeviceRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person])
            ->call('openPatient', $otherPerson->id)->assertSet('personId', $this->person->id)
            ->call('clearPatient')->assertSet('personId', $this->person->id)
            ->assertSee(__('device-requests.policy.patient_context'));
    }

    public function testPatientRegistryDenialPreservesFiltersAndPaginationWithoutApiCalls(): void
    {
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()->andReturn($this->response([]));
        $this->instance(DeviceRequest::class, $api);
        $component = Livewire::test(PatientDeviceRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person]);
        $this->employee->update(['is_active' => false]);

        $component->set('filters.parameters.code', 'orthosis')->set('page', 2)
            ->call('resetFilters')->assertSet('filters.parameters.code', 'orthosis')->assertSet('page', 2)
            ->call('search')->assertSet('page', 2)->assertSee(__('device-requests.policy.perform'));
    }

    public function testDetailActionsStopBeforeOpeningModalsWhenAccessIsRevoked(): void
    {
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getById')->once()->with($this->person->uuid, $this->requestId)
            ->andReturn($this->response($this->record()));
        $api->shouldNotReceive('getDispenses');
        $this->instance(DeviceRequest::class, $api);
        $service = Mockery::mock(DeviceRequestLifecycleService::class)->makePartial();
        $service->shouldReceive('dictionary')->andReturn(['orthosis' => 'Ортез']);
        $service->shouldNotReceive('assertTransition');
        $this->instance(DeviceRequestLifecycleService::class, $service);
        $component = Livewire::test(PatientDeviceRequestView::class, [
            'legalEntity' => $this->legalEntity, 'person' => $this->person, 'deviceRequest' => $this->requestId,
        ]);
        $this->employee->update(['is_active' => false]);

        $component->call('openSmsModal')->assertSet('showSmsModal', false)
            ->call('loadDispenses')->assertSet('showDispenses', false)
            ->call('startAction', 'revoke')->assertSet('showStatusActionModal', false)->assertSet('action', '')
            ->assertSee(__('device-requests.policy.perform'));
    }

}
