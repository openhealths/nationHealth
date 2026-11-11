<?php

declare(strict_types=1);

namespace Tests\Feature\DeviceRequest;

use App\Classes\eHealth\Api\DeviceDefinition;
use App\Classes\eHealth\Api\Dictionary;
use App\Classes\eHealth\Api\Patient\DeviceRequest as DeviceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;

use App\Exceptions\EHealth\EHealthResponseException;
use App\Livewire\Components\Sidebar;
use App\Livewire\DeviceRequest\DeviceRequestIndex;
use App\Livewire\Person\Records\PatientPrescriptionRequests;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Services\SignatureService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeClinicalFixtures;
use Tests\TestCase;

class DeviceRequestPatientUiTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;
    private Person $person;
    private DeviceRequestApi $api;
    private string $requestUuid;
    private bool $canReadDispenses = true;
    private bool $canReadRequests = true;
    private bool $canPerformRequestRead = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->legalEntity = LegalEntity::create(['uuid' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->instance('legalEntity', $this->legalEntity);
        $this->person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $this->person->names()->create(['first_name' => 'Тест', 'last_name' => 'Пацієнт', 'language' => 'uk']);
        $this->actingAs(User::create(['email' => Str::random(12).'@example.com', 'password' => 'unused']));
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            if ($ability === 'limitedAction') {
                return false;
            }
            if ($ability === 'create') {
                return false;
            }
            if ($ability === 'perform' && end($arguments) === 'device_request:read') {
                return $this->canPerformRequestRead;
            }
            if ($ability === 'device_dispense:read' || ($ability === 'perform' && end($arguments) === 'device_dispense:read')) {
                return $this->canReadDispenses;
            }
            if ($ability === 'viewAny' && ($arguments[0] ?? null) === DeviceRequestRequest::class) {
                return $this->canReadRequests;
            }

            return true;
        });
        $signer = Mockery::mock(SignatureService::class);
        $signer->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $this->instance(SignatureService::class, $signer);
        $this->api = Mockery::mock(DeviceRequestApi::class);
        $this->api->shouldReceive('getBySearchParams')->andReturn($this->response([]))->byDefault();
        $this->instance(DeviceRequestApi::class, $this->api);
        $dictionary = Mockery::mock(Dictionary::class);
        $dictionary->shouldReceive('getMany')->andReturn(NativeClinicalFixtures::response('GET /api/v2/dictionaries', []));
        $this->instance(Dictionary::class, $dictionary);
        $definitions = Mockery::mock(DeviceDefinition::class);
        $definitions->shouldReceive('getMany')->andReturn(NativeClinicalFixtures::definitionResponse([], NativeClinicalFixtures::paging(1, 0, 0)));
        $this->instance(DeviceDefinition::class, $definitions);
        $this->requestUuid = (string) Str::uuid();
        $this->api->shouldReceive('getById')->with($this->person->uuid, $this->requestUuid)
            ->andReturn($this->response(['id' => $this->requestUuid, 'status' => 'active', 'requisition' => 'TEST-REQUEST']));
    }

    public function testEmptyDispensesAreVisibleOnlyAfterSuccessfulLoading(): void
    {
        $this->api->shouldReceive('getDispenses')->once()
            ->with($this->person->uuid, ['based_on' => $this->requestUuid, 'page' => 1])
            ->andReturn($this->response([]));

        $this->patientComponent()->call('showDetails', $this->requestUuid)
            ->assertDontSee(__('device-requests.labels.dispenses_empty'))
            ->call('loadDispenses')->assertSet('dispensesLoaded', true)
            ->assertSee(__('device-requests.labels.dispenses_empty'))
            ->assertSee(__('device-requests.labels.dispenses_hint'));
    }

    public function testFailedReloadClearsPreviousDispensesAndShowsConnectionErrorOnce(): void
    {
        $this->api->shouldReceive('getDispenses')->once()->andReturn($this->response([['id' => 'old-dispense']]));
        $this->api->shouldReceive('getDispenses')->once()->andThrow(new EHealthConnectionException('Timeout'));

        $component = $this->patientComponent()->call('showDetails', $this->requestUuid)
            ->call('loadDispenses')->assertSet('dispensesLoaded', true)
            ->call('loadDispenses')->assertSet('dispensesLoaded', false)
            ->assertSet('dispenses', [])->assertSet('dispenseDetails', [])
            ->assertDontSee(__('device-requests.labels.dispenses_empty'))
            ->assertSee(__('messages.connection_exception'));

        $component->call('search')->assertDontSee(__('messages.connection_exception'));
    }

    public function testChangingRequestClearsDispensesAndTheirLoadedState(): void
    {
        $this->api->shouldReceive('getDispenses')->andReturn($this->response([['id' => 'old-dispense']]));
        $otherUuid = (string) Str::uuid();
        $this->api->shouldReceive('getById')->with($this->person->uuid, $otherUuid)
            ->andReturn($this->response(['id' => $otherUuid, 'status' => 'active']));

        $this->patientComponent()->call('showDetails', $this->requestUuid)->call('loadDispenses')
            ->call('showDetails', $otherUuid)->assertSet('dispenses', [])
            ->assertSet('dispensesLoaded', false)->assertSet('dispenseDetails', []);
    }

    public function testAllDispensePagesAreLoadedForTheSelectedRequest(): void
    {
        foreach ([1, 2] as $page) {
            $this->api->shouldReceive('getDispenses')->once()
                ->with($this->person->uuid, ['based_on' => $this->requestUuid, 'page' => $page])
                ->andReturn($this->response([['id' => 'dispense-'.$page]], $page, 2));
        }

        $this->patientComponent()->call('showDetails', $this->requestUuid)->call('loadDispenses')
            ->assertSet('dispenses', [['id' => 'dispense-1'], ['id' => 'dispense-2']]);
    }

    public function testForeignDispenseDoesNotLeavePreviousDetailsOnScreen(): void
    {
        $ownUuid = (string) Str::uuid();
        $foreignUuid = (string) Str::uuid();
        $this->api->shouldReceive('getDispense')->with($this->person->uuid, $ownUuid)->once()
            ->andReturn($this->response(['id' => $ownUuid, 'based_on' => ['identifier' => ['value' => $this->requestUuid]]]));
        $this->api->shouldReceive('getDispense')->with($this->person->uuid, $foreignUuid)->once()
            ->andReturn($this->response(['id' => $foreignUuid, 'based_on' => ['identifier' => ['value' => (string) Str::uuid()]]]));

        $this->patientComponent()->call('showDetails', $this->requestUuid)->call('showDispense', $ownUuid)
            ->assertSet('dispenseDetails.id', $ownUuid)->call('showDispense', $foreignUuid)
            ->assertSet('dispenseDetails', []);
    }

    public function testDispenseLoadingRequiresPermissionAndASelectedRequest(): void
    {
        $this->api->shouldNotReceive('getDispenses');
        $this->patientComponent()->call('loadDispenses')->assertHasErrors(['selectedId']);
        $this->canReadDispenses = false;
        $this->patientComponent()->call('showDetails', $this->requestUuid)->call('loadDispenses')
            ->assertStatus(200)->assertSee(__('device-requests.policy.perform'));
    }

    public function testPatientNavigationContainsAuthorizedRequestTabAndNotTheStub(): void
    {
        $this->patientComponent()->assertSee(__('device-requests.labels.patient_tab'))
            ->assertSee(route('persons.device-requests', [$this->legalEntity, 'person' => $this->person->id]), false)
            ->assertDontSee(__('patients.prescription_requests'));

        $this->canReadRequests = false;
        $this->patientComponent()->assertDontSeeHtml('href="'.route('persons.device-requests', [$this->legalEntity, 'person' => $this->person->id]).'"');
    }

    public function testLegacyPrescriptionPageOffersAuthorizedPatientRequestNavigation(): void
    {
        $parameters = ['legalEntity' => $this->legalEntity, 'personId' => $this->person->id];
        Livewire::test(PatientPrescriptionRequests::class, $parameters)
            ->assertSee(route('persons.device-requests', [$this->legalEntity, 'person' => $this->person->id]), false)
            ->assertSeeHtml('href="'.route('persons.device-requests', [$this->legalEntity, 'person' => $this->person->id]).'"');

        $this->canReadRequests = false;
        Livewire::test(PatientPrescriptionRequests::class, $parameters)
            ->assertDontSee(__('device-requests.labels.patient_tab'));
    }

    public function testPatientSearchDoesNotDefaultToCurrentDoctorOrClinic(): void
    {
        $remote = ['id' => (string) Str::uuid(), 'status' => 'active', 'requisition' => 'OTHER-CLINIC',
            'requester' => ['display_value' => 'Інший лікар'], 'requester_legal_entity' => ['display_value' => 'Інший заклад']];
        $this->api->shouldReceive('getBySearchParams')->once()->with($this->person->uuid, ['page' => 1])
            ->andReturn($this->response([$remote]));

        $this->patientComponent()->assertSet('patientContext', true)
            ->assertSet('filters.parameters.requester_legal_entity', '')->assertSet('filters.parameters.requester', '')
            ->assertSee('OTHER-CLINIC')->assertSee('Інший лікар')->assertSee('Інший заклад');
    }

    public function testSidebarOffersGeneralSearchOnlyWithDeviceRequestReadPermission(): void
    {
        $this->legalEntity->setRelation('type', new LegalEntityType(['name' => 'PRIMARY_CARE']));
        $url = route('device-requests.index', [$this->legalEntity]);
        Livewire::test(Sidebar::class)->assertSeeHtml('href="'.$url.'"');
        $this->canReadRequests = false;
        Livewire::test(Sidebar::class)->assertDontSeeHtml('href="'.$url.'"');
    }

    public function testEveryDocumentedSearchFilterIsForwardedOnlyForTheLockedPatient(): void
    {
        $component = $this->patientComponent();
        $filters = [
            'requester_legal_entity' => (string) Str::uuid(), 'requester' => (string) Str::uuid(),
            'status' => 'completed', 'code' => '0', 'code_reference' => (string) Str::uuid(),
            'context_episode_id' => (string) Str::uuid(), 'encounter' => (string) Str::uuid(),
            'program' => (string) Str::uuid(), 'based_on' => (string) Str::uuid(),
            'priority' => 'routine', 'performer' => (string) Str::uuid(),
        ];
        foreach (['authored_on', 'occurrence_period_start', 'occurrence_period_end'] as $date) {
            $filters[$date.'_from'] = '2026-10-01T09:00:00+00:00';
            $filters[$date.'_to'] = '2026-10-21T09:00:00+00:00';
        }
        $this->api->shouldReceive('getBySearchParams')->once()->with($this->person->uuid, $filters + ['page' => 1])
            ->andReturn($this->response([]));
        $component->set('requesterEntitySearch', $filters['requester_legal_entity'])
            ->set('filters.parameters', $filters + ['person_id' => (string) Str::uuid(), 'unknown' => 'ignored'])
            ->call('search')->assertHasNoErrors();
    }

    public function testClinicNameFilterUsesTheSelectedClinicUuidForTheLockedPatient(): void
    {
        $otherClinic = LegalEntity::create(['uuid' => (string) Str::uuid(), 'status' => 'ACTIVE', 'beneficiary' => 'Інший заклад']);
        $component = $this->patientComponent();
        $this->api->shouldReceive('getBySearchParams')->once()
            ->with($this->person->uuid, ['requester_legal_entity' => $otherClinic->uuid, 'page' => 1])
            ->andReturn($this->response([]));

        $component->assertSeeHtml('list="device-request-entities"')
            ->set('requesterEntitySearch', 'інший ЗАКЛАД')->call('search')->assertHasNoErrors()
            ->assertSet('filters.parameters.requester_legal_entity', $otherClinic->uuid)
            ->assertSet('personId', $this->person->id);
    }

    public function testUnknownClinicNameDoesNotCallTheApi(): void
    {
        $component = $this->patientComponent();
        $this->api->shouldNotReceive('getBySearchParams');

        $component->set('requesterEntitySearch', 'Невідомий заклад')->call('search')
            ->assertHasErrors(['requesterEntitySearch']);
    }

    public function testInvalidFilterAndReversedDateRangeDoNotCallTheApi(): void
    {
        $component = $this->patientComponent();
        $this->api->shouldNotReceive('getBySearchParams');
        $component->set('filters.parameters.requester', 'invalid')->call('search')->assertHasErrors(['filters.parameters.requester']);
        $component->set('filters.parameters.requester', '')
            ->set('filters.parameters.authored_on_from', '2026-10-21')->set('filters.parameters.authored_on_to', '2026-10-01')
            ->call('search')->assertHasErrors(['filters.parameters.authored_on_to']);
    }

    public function testPatientCardCannotSwitchItsLockedPatient(): void
    {
        $component = $this->patientComponent();
        $otherPerson = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $this->api->shouldNotReceive('getBySearchParams');

        foreach (['openPatient' => [$otherPerson->id], 'searchPatients' => [], 'clearPatient' => []] as $action => $arguments) {
            $component->call($action, ...$arguments)->assertStatus(200)
                ->assertSee(__('device-requests.policy.patient_context'))
                ->assertSet('personId', $this->person->id)->assertSet('patientContext', true)
                ->assertSet('patients', []);
        }
    }

    public function testGeneralRegistryRequiresPatientAndClearsPreviousPatientData(): void
    {
        $this->api->shouldReceive('getBySearchParams')->once()->with($this->person->uuid, ['page' => 1])
            ->andReturn($this->response([['id' => $this->requestUuid, 'status' => 'active', 'requisition' => 'PATIENT-ONLY']]));
        $component = Livewire::test(DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity])
            ->assertSet('personId', null)->assertSet('patientContext', false)
            ->assertSee(__('device-requests.labels.patient_required_hint'));
        $component->set('patientSearch', 'Пацієнт')->call('searchPatients')->assertCount('patients', 1)
            ->call('openPatient', $this->person->id)->assertSet('personId', $this->person->id)
            ->assertSee('PATIENT-ONLY')->assertDontSeeHtml('summary-tab-active')
            ->call('showDetails', $this->requestUuid)->assertSet('selectedId', $this->requestUuid)
            ->call('clearPatient')->assertSet('personId', null)->assertSet('requests', [])
            ->assertSet('selected', [])->assertSet('selectedId', null)->assertSet('phone', '')
            ->assertDontSee('PATIENT-ONLY');
    }

    public function testPatientRoutesAreCanonicalAndTheRegistryHasItsOwnRoute(): void
    {
        $this->assertStringEndsWith('/persons/'.$this->person->id.'/device-requests', route('persons.device-requests', [$this->legalEntity, $this->person]));
        $this->assertStringEndsWith('/persons/'.$this->person->id.'/device-requests/create', route('persons.device-requests.create', [$this->legalEntity, $this->person]));
        $this->assertStringEndsWith('/device-requests', route('device-requests.index', [$this->legalEntity]));
        $this->assertNull(app('router')->getRoutes()->getByName('device-requests.create'));
        $middleware = app('router')->getRoutes()->getByName('persons.device-requests')->gatherMiddleware();
        $this->assertContains('can:viewAny,'.DeviceRequestRequest::class, $middleware);
    }

    public function testPatientSurnameSearchIsCaseInsensitive(): void
    {
        Livewire::test(DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity])
            ->set('patientSearch', 'пацієнт')->call('searchPatients')
            ->assertCount('patients', 1);
    }

    public function testFilterResetClearsFormValuesAndKeepsThePatient(): void
    {
        $component = $this->patientComponent();
        $this->api->shouldReceive('getBySearchParams')->once()->with($this->person->uuid, ['page' => 1])
            ->andReturn($this->response([]));
        $component->set('filters.parameters.requester', (string) Str::uuid())
            ->set('requesterEntitySearch', (string) Str::uuid())
            ->set('filters.parameters.authored_on_from', '2026-10-20')
            ->call('resetFilters')->assertSet('filters.parameters.requester', '')
            ->assertSet('requesterEntitySearch', '')
            ->assertSet('filters.parameters.requester_legal_entity', '')
            ->assertSet('filters.parameters.authored_on_from', '')
            ->assertSet('personId', $this->person->id)->assertHasNoErrors();
    }

    public function testDraftStatusCannotBeSentAsARemoteSearchFilter(): void
    {
        $component = $this->patientComponent();
        $this->api->shouldNotReceive('getBySearchParams');
        $component->set('filters.parameters.status', 'draft')->call('search')
            ->assertHasErrors(['filters.parameters.status']);
    }

    public static function requestReadActions(): array
    {
        return [['search'], ['showDetails'], ['printout'], ['resetFilters'], ['goToPage']];
    }

    #[DataProvider('requestReadActions')]
    public function testReadActionsRecheckPermissionAfterMount(string $action): void
    {
        $component = $this->patientComponent();
        $this->canPerformRequestRead = false;
        $this->api->shouldNotReceive('getBySearchParams');
        $this->api->shouldNotReceive('getById');
        $component->set('filters.parameters.code', 'test-code')->set('page', 2);
        $arguments = match ($action) {
            'showDetails' => [$this->requestUuid],
            'goToPage' => [3],
            default => [],
        };
        $component->call($action, ...$arguments)->assertStatus(200)
            ->assertSee(__('device-requests.policy.perform'))
            ->assertSet('filters.parameters.code', 'test-code')->assertSet('page', 2);
    }

    public function testRemoteAccessDeniedIsDifferentFromLocalRouteAuthorization(): void
    {
        $this->api->shouldReceive('getBySearchParams')->once()
            ->andThrow(new EHealthResponseException(new ClientResponse(
                new Response(403, ['Content-Type' => 'application/json'], json_encode([
                    'error' => ['message' => 'Access denied'],
                ], JSON_THROW_ON_ERROR))
            )));

        $this->patientComponent()->assertStatus(200)->assertSet('requests', [])
            ->assertSee(__('errors.ehealth.messages.access_denied'))
            ->assertDontSee(__('device-requests.policy.perform'));
    }

    public function testPatientSelectionActionsStopBeforeChangingContextWhenReadIsRevoked(): void
    {
        $component = Livewire::test(DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity]);
        $this->canPerformRequestRead = false;
        $this->api->shouldNotReceive('getBySearchParams');
        $component->call('openPatient', $this->person->id)->assertSet('personId', null)
            ->assertSee(__('device-requests.policy.perform'));
        $component->set('patientSearch', 'Пацієнт')->call('searchPatients')->assertSet('patients', [])
            ->assertSee(__('device-requests.policy.perform'));
    }

    private function patientComponent(): Testable
    {
        return Livewire::test(DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person]);
    }

    private function response(array $data, int $page = 1, int $totalPages = 1): EHealthResponse
    {
        if (!array_is_list($data) && isset($data['based_on'])) {
            $data['based_on'] = array_replace_recursive(NativeClinicalFixtures::reference('device_request'), $data['based_on']);
            $data = NativeClinicalFixtures::deviceDispense($data['id'], $this->person->uuid, $data);

            return NativeClinicalFixtures::response('GET /api/patients/{patient_id}/device_dispenses/{device_dispense_id}', $data);
        }
        if (!array_is_list($data)) {
            $data = NativeClinicalFixtures::deviceRequest($data['id'] ?? $this->requestUuid, $this->person->uuid, $data);

            return NativeClinicalFixtures::response('GET /api/patients/{patient_id}/device_requests/{device_request_id}', $data);
        }
        // These UI list tests isolate selection and pagination with validated snapshots.
        // Full native list and malformed nested fields are exercised at the API boundary.
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn($data);
        $response->shouldReceive('getPaging')->andReturn(NativeClinicalFixtures::paging($page, $totalPages, count($data)));
        $response->shouldReceive('isNotLast')->andReturn($page < $totalPages);

        return $response;
    }
}
