<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire;

use App\Core\BaseForm;
use App\Enums\JobStatus;
use App\Enums\Person\EncounterStatus;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterReferrals;
use App\Livewire\Referral\ReferralIndex;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Mockery;
use RuntimeException;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class EncounterReferralFlowTest extends TestCase
{
    use MocksBasicDictionaries;

    private const string EMPLOYEE_UUID = '10050000-0000-4000-8000-000000000010';

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockBasicDictionaries(['SERVICE_REQUEST_PRIORITY' => ['routine' => 'Планове', 'urgent' => 'Ургентне']]);
        Cache::forever(ServiceDictionary::KEY, [[
            'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'code' => 'SYNTHETIC',
            'name' => 'Synthetic service', 'category' => 'diagnostic_procedure',
            'is_composition' => false, 'is_active' => true, 'request_allowed' => true,
            'inserted_at' => '2026-01-01T09:00:00Z', 'updated_at' => '2026-01-01T09:00:00Z',
            'inserted_by' => self::EMPLOYEE_UUID, 'updated_by' => self::EMPLOYEE_UUID,
        ]]);
        Cache::forever(ServiceDictionary::KEY.':fresh', true);
        $employee = (new Employee())->forceFill(['id' => 10, 'uuid' => self::EMPLOYEE_UUID]);
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('activeDoctorEmployee')->andReturn($employee);
        $user->shouldReceive('isAssistantOnly')->andReturn(false);
        $user->shouldReceive('cannot')->with('create', Mockery::on(static fn (array $arguments): bool =>
            $arguments[0] === ServiceRequestRequest::class
            && $arguments[1] === $employee
            && $arguments[2] === 'diagnostic_procedure'
            && $arguments[3] instanceof Encounter))->andReturn(false);
        Auth::shouldReceive('user')->andReturn($user);
        $this->mock(ServiceRequestRequestRepository::class)->shouldReceive('authorForDraft')
            ->with(10, self::EMPLOYEE_UUID)->andReturn($employee);
    }

    public function testFinishedEncounterOpensAndInitializesReferralDrawer(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->encounterReferralForm = ['service_id' => 'old-selection'];
        $component->encounterReferralServiceResults = [['id' => 'old-selection']];
        $component->encounterReferralSelectedService = ['id' => 'old-selection'];
        $component->encounterReferralWarningMessage = 'old-error';

        $component->openEncounterReferralDrawer();

        $this->assertTrue($component->showEncounterReferralDrawer);
        $this->assertSame('', $component->encounterReferralForm['service_id']);
        $this->assertSame(now()->addMinutes(30)->format('d.m.Y'), $component->encounterReferralForm['started_at']);
        $this->assertSame([], $component->encounterReferralServiceResults);
        $this->assertNull($component->encounterReferralSelectedService);
        $this->assertSame('', $component->encounterReferralWarningMessage);
        $this->assertTrue($component->authMethodsLoaded);
        $this->assertTrue($component->programsLoaded);
        $this->assertNull($component->redirectTarget);
    }

    public function testPrepersonEncounterOpensDrawerWithoutRedirectingToPatientPage(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->prepersonId = 20;
        $component->encounter->person_id = null;
        $component->encounter->preperson_id = 20;

        $component->openEncounterReferralDrawer();

        $this->assertTrue($component->showEncounterReferralDrawer);
        $this->assertNull($component->redirectTarget);
    }

    public function testUnfinishedEncounterCannotOpenReferralDrawer(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->encounter->status = EncounterStatus::DRAFT;

        $component->openEncounterReferralDrawer();

        $this->assertFalse($component->showEncounterReferralDrawer);
        $this->assertFalse($component->authMethodsLoaded);
        $this->assertNotNull(Session::get('error'));
    }

    public function testValidReferralCreatesDraftAndOpensExistingSignatureModal(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->openEncounterReferralDrawer();
        $component->encounterReferralForm['service_id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $employeeContext = ['employee_id' => 10, 'employee_uuid' => self::EMPLOYEE_UUID];
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldReceive('resolveEncounterEmployeeContext')->once()
            ->with($component->encounter, 10)->andReturn($employeeContext);
        $lifecycle->shouldReceive('createEncounterDraft')->once()
            ->with($component->encounter, Mockery::type('array'), $employeeContext, null)
            ->andReturn('11111111-2222-4333-8444-555555555555');
        $lifecycle->shouldReceive('prepareEncounterDraftForSigning')->once()->with(
            $component->encounter,
            Mockery::on(static fn (array $formData): bool =>
                $formData['service_id'] === 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'
                && $formData['kind'] === 'service_request'
                && $formData['program_id'] === null),
            $employeeContext,
            '11111111-2222-4333-8444-555555555555'
        )->andReturn('11111111-2222-4333-8444-555555555555');
        $this->instance(ReferralRequestLifecycleService::class, $lifecycle);

        $component->validateEncounterReferral();

        $this->assertSame('11111111-2222-4333-8444-555555555555', $component->encounterReferralRequestIdToSign);
        $this->assertFalse($component->showEncounterReferralDrawer);
        $this->assertTrue($component->showSignatureModal);
        $this->assertSame('sign_referral', $component->actionType);
    }

    public function testInvalidReferralDoesNotCreateDraftOrOpenSignature(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->openEncounterReferralDrawer();
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('createEncounterDraft', 'prepareEncounterDraftForSigning');
        $this->instance(ReferralRequestLifecycleService::class, $lifecycle);

        try {
            $component->validateEncounterReferral();
            $this->fail('A service must be selected before creating the draft.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('encounterReferralForm.service_id', $exception->errors());
        }

        $this->assertTrue($component->showEncounterReferralDrawer);
        $this->assertFalse($component->showSignatureModal);
        $this->assertNull($component->encounterReferralRequestIdToSign);
    }

    public function testDraftFailureKeepsFormOpenWithoutReportingSuccess(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->openEncounterReferralDrawer();
        $component->encounterReferralForm['service_id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldReceive('resolveEncounterEmployeeContext')->once()->with($component->encounter, 10)
            ->andReturn(['employee_id' => 10, 'employee_uuid' => self::EMPLOYEE_UUID]);
        $lifecycle->shouldReceive('createEncounterDraft')->once()->andThrow(new RuntimeException('Draft failed'));
        $lifecycle->shouldNotReceive('prepareEncounterDraftForSigning');
        $this->instance(ReferralRequestLifecycleService::class, $lifecycle);

        $component->validateEncounterReferral();

        $this->assertTrue($component->showEncounterReferralDrawer);
        $this->assertFalse($component->showSignatureModal);
        $this->assertNull($component->encounterReferralRequestIdToSign);
        $this->assertStringContainsString('Draft failed', $component->encounterReferralWarningMessage);
        $this->assertNull(Session::get('success'));
    }

    public function testManualStatusCheckReportsCompletedReferralWithoutRemoteRequest(): void
    {
        $component = $this->finishedEncounterComponent();
        $record = new ServiceRequestRequest();
        $record->uuid = '11111111-2222-4333-8444-555555555555';
        $record->status = 'active';
        $record->requestNumber = '0000-TEST-1234-5678';
        $component->encounterReferralRequestIdToSign = $record->uuid;
        $component->encounterReferralSubmission = ['status' => JobStatus::PENDING->value];
        $component->form = Mockery::mock(BaseForm::class);
        $component->form->shouldReceive('resetSigningFields')->once();

        $ownership = Mockery::mock(new MedicalRequestOwnership());
        $ownership->shouldReceive('serviceForEncounter')->once()->with($record->uuid, $component->encounter)->andReturn($record);
        $this->instance(MedicalRequestOwnership::class, $ownership);
        $this->mock(ServiceRequestRequestRepository::class)
            ->shouldReceive('submissionState')->once()->with($record)->andReturn(['status' => JobStatus::PROCESSED->value]);
        $this->mock(ReferralRequestLifecycleService::class)->shouldNotReceive('fetchRemoteReferral');

        $component->checkEncounterReferralSubmission(true);

        $this->assertSame(__('referrals.submission_created', ['number' => $record->requestNumber]), Session::get('success'));
        $this->assertNull($component->encounterReferralRequestIdToSign);
        $this->assertSame([], $component->encounterReferralSubmission);
        Session::forget('success');
        $component->checkEncounterReferralSubmission(true);
        $this->assertNull(Session::get('success'));
    }

    public function testManualStatusCheckKeepsPendingReferralWhenRemoteIsUnavailable(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->encounter->person_id = 1;
        $record = new ServiceRequestRequest();
        $record->uuid = '11111111-2222-4333-8444-555555555555';
        $record->status = 'draft';
        $component->encounterReferralRequestIdToSign = $record->uuid;
        $pending = ['status' => JobStatus::PENDING->value, 'error' => null];
        $component->encounterReferralSubmission = $pending;
        $person = new Person();
        $person->uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $ownership = Mockery::mock(new MedicalRequestOwnership());
        $ownership->shouldReceive('serviceForEncounter')->once()->with($record->uuid, $component->encounter)->andReturn($record);
        $this->instance(MedicalRequestOwnership::class, $ownership);
        $repository = $this->mock(ServiceRequestRequestRepository::class);
        $repository->shouldReceive('patientForEncounter')->once()->with($component->encounter)->andReturn($person);
        $repository->shouldReceive('submissionState')->once()->with($record)->andReturn($pending);
        $repository->shouldNotReceive('importResource');
        $this->mock(ReferralRequestLifecycleService::class)
            ->shouldReceive('fetchRemoteReferral')->once()->andThrow(new RuntimeException('Unavailable'));

        $component->checkEncounterReferralSubmission(true);

        $this->assertSame($pending, $component->encounterReferralSubmission);
        $this->assertSame($record->uuid, $component->encounterReferralRequestIdToSign);
        $this->assertNull(Session::get('success'));
        $this->assertSame(__('referrals.submission_refresh_failed'), Session::get('warning'));
    }

    public function testReferralRegistryStartsEmptyUntilSearch(): void
    {
        $component = new ReferralIndex();

        $this->assertFalse($component->hasSearched);
        $this->assertSame([], $component->searchResults);
    }

    private function finishedEncounterComponent(): EncounterReferralHarness
    {
        $component = new EncounterReferralHarness();
        $component->encounter = new Encounter(['status' => EncounterStatus::FINISHED]);

        return $component;
    }
}

class EncounterReferralHarness extends Component
{
    use ManagesEncounterReferrals;

    public Encounter $encounter;

    public BaseForm $form;

    public ?int $prepersonId = null;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    public ?string $redirectTarget = null;

    public bool $authMethodsLoaded = false;

    public bool $programsLoaded = false;

    protected function resolveEncounterModelForStandalone(): ?Encounter
    {
        return $this->encounter;
    }

    protected function loadEncounterReferralAuthMethods(Encounter $encounter): void
    {
        $this->authMethodsLoaded = true;
    }

    protected function loadEncounterReferralPrograms(): void
    {
        $this->programsLoaded = true;
    }

    protected function resolveDefaultEncounterReferralProgramId(): string
    {
        return '';
    }

    public function validate($rules = null, $messages = [], $attributes = [])
    {
        return Validator::make(['encounterReferralForm' => $this->encounterReferralForm], $rules, $messages, $attributes)->validate();
    }

    public function redirectRoute($name, $parameters = [], $absolute = true, $navigate = false)
    {
        $this->redirectTarget = $name;
    }
}
