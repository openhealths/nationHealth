<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\Api\CarePlanActivity as CarePlanActivityApi;
use App\Classes\eHealth\Api\Patient\ServiceRequest;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\CarePlanStatus;
use App\Enums\JobStatus;
use App\Enums\Person\ApprovalStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow;
use App\Livewire\CarePlan\CarePlanApprovals;
use App\Livewire\CarePlan\CarePlanShow;
use App\Livewire\Dictionary\ServiceCatalog;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterReferrals;
use App\Livewire\EncounterPackage\Forms\EncounterForm;
use App\Livewire\Person\Records\PatientApproval;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Permission;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\Role as RoleModel;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\EHealthJobResolver;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use App\Services\MedicalEvents\PatientApprovalService;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Services\SignatureService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\Feature\CarePlan\BrowserUatReferralSigningHarness;
use Tests\Feature\CarePlan\CarePlanRefreshFailureHarness;
use Tests\Support\CarePlanWorkflowHarness;
use Tests\TestCase;

class CarePlanShowActionsTest extends TestCase
{
    use CreatesFhirCarePlans;

    use DatabaseTransactions;
    use MocksBasicDictionaries;

    protected Person $person;

    protected User $user;

    protected Employee $employee;

    protected LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockBasicDictionaries([
            'SERVICE_REQUEST_PRIORITY' => ['routine' => 'Планове', 'urgent' => 'Ургентне'],
            'device_request_priority' => ['routine' => 'Плановий', 'urgent' => 'Ургентний', 'forced' => 'Примусовий'],
        ]);

        config(['cipher.api.domain' => 'https://cipher.invalid']);
        Cache::put('knedp_certificate_authority', [], 60);

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Action',
            'last_name' => 'Patient',
            'birth_date' => '1991-02-02',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
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

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Action',
            'last_name' => 'Doctor',
            'tax_id' => '1122334455',
            'birth_date' => '1975-01-01',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'action_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr Action',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
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

        $this->grantMedicalEventAbilities($this->user);
    }

    public function test_plan_detail_sync_keeps_patient_and_approval_context_in_its_job_payload(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        Bus::fake();

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])->call('sync')->assertHasNoErrors();

        Bus::assertBatched(function (PendingBatch $batch) use ($plan): bool {
            $job = $batch->jobs->first();
            $this->assertSame($plan->uuid, $job->resourceUuid);
            $this->assertSame([
                'patient_uuid' => $this->person->uuid,
                'person_id' => $this->person->id,
                'patient_care_plan_registry' => true,
                'care_plan_approvals' => true,
            ], $job->patientContext);

            return true;
        });
    }

    public function test_approved_plan_loads_report_identifier_codes_and_preserves_patient_isolation(): void
    {
        $this->actingAs($this->user);
        $carePlan = $this->makeSignedNewPlan();
        $this->grantApproval($carePlan, $this->employee, ApprovalStatus::ACTIVE->value);
        $author = Identifier::create(['value' => $this->employee->uuid]);
        $displayCode = Identifier::create(['value' => 'TEST-DISPLAY', 'display_value' => 'Тестовий діагностичний звіт']);
        $fallbackCode = Identifier::create(['value' => 'TEST-FALLBACK']);
        $otherPerson = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $reports = collect([
            [$this->person->id, $displayCode->id],
            [$this->person->id, $fallbackCode->id],
            [$otherPerson->id, $displayCode->id],
        ])->map(fn (array $fixture) => DiagnosticReport::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $fixture[0], 'code_id' => $fixture[1],
            'status' => 'final', 'issued' => '2026-10-23 09:00:00',
            'recorded_by_id' => $author->id, 'primary_source' => true,
        ]));
        $api = Mockery::mock(CarePlanApi::class);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData', 'validate')->andReturn([]);
        $api->shouldReceive('getDetails')->andReturn($response);
        $this->app->instance(CarePlanApi::class, $api);
        $wasPreventingMissingAttributes = Model::preventsAccessingMissingAttributes();
        $wasPreventingLazyLoading = Model::preventsLazyLoading();
        Model::preventAccessingMissingAttributes();
        Model::preventLazyLoading();

        try {
            $component = Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()]);
            $component->assertSuccessful();
            $choices = array_column($component->get('availableReports'), null, 'uuid');

            $this->assertCount(2, $choices);
            $this->assertSame('Тестовий діагностичний звіт', $choices[$reports[0]->uuid]['name']);
            $this->assertSame('TEST-FALLBACK', $choices[$reports[1]->uuid]['name']);
            $this->assertSame('23.10.2026', $choices[$reports[0]->uuid]['date']);
            $this->assertArrayNotHasKey($reports[2]->uuid, $choices);
            $this->assertTrue($component->instance()->canChangePlanLifecycle);
        } finally {
            Model::preventAccessingMissingAttributes($wasPreventingMissingAttributes);
            Model::preventLazyLoading($wasPreventingLazyLoading);
        }
    }

    public function test_new_plan_without_approval_shows_activate_and_hides_lifecycle_buttons(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan]);

        $this->assertTrue($component->instance()->canRequestPatientApproval);
        $this->assertFalse($component->instance()->canChangePlanLifecycle);
        $component
            ->assertSee(__('care-plan.activate_plan_patient_approval'))
            ->assertDontSee(__('care-plan.cancel_care_plan'))
            ->assertDontSee(__('care-plan.complete_care_plan'));
    }

    public function test_unknown_activity_renders_error_in_the_action_response_without_event_or_signature(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $this->grantCarePlanWriteApproval($plan, $this->employee);
        $message = __('care-plan.document_context_unavailable');

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $plan]);
        $component->call('openSignatureModal', 'cancel_activity', PHP_INT_MAX)
            ->assertSet('showSignatureModal', false)
            ->assertSee($message)
            ->assertNotDispatched('flashMessage');
        $this->assertNull(session('error'));
        $this->assertFalse(session()->has('success'));
    }

    public function test_post_sign_refresh_failure_keeps_the_accepted_signature_success(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $this->grantCarePlanWriteApproval($plan, $this->employee);
        $request = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id,
            'employee_id' => $this->employee->id, 'status' => 'new',
        ]);
        $service = Mockery::mock(MedicationRequestLifecycleService::class);
        $service->shouldReceive('signPrescription')->once()->andReturn([
            'success_message' => 'Prescription accepted', 'warning_message' => 'Existing quantity warning',
        ]);
        $this->instance(MedicationRequestLifecycleService::class, $service);

        Livewire::test(CarePlanRefreshFailureHarness::class, ['carePlan' => $plan])
            ->call('prepareSignedRequest', $request->uuid)
            ->call('signEPrescription')
            ->assertSee('Prescription accepted')->assertSee('Existing quantity warning')
            ->assertSee(__('care-plan.eprescription_signed_refresh_pending'))
            ->assertDontSee('Помилка при підписанні рецепту')
            ->assertSet('showSignatureModal', false)->assertSet('ePrescriptionRequestIdToSign', null);
    }

    public function test_prescription_sync_keeps_signed_requests_and_prescription_states_separate(): void
    {
        $this->actingAs($this->user);
        $this->grantMedicalEventAbilities($this->user, ['medication_request_request:read']);
        $plan = $this->makeSignedNewPlan();
        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id,
            'uuid' => (string) Str::uuid(), 'kind' => 'medication_request', 'status' => 'draft',
        ]);
        $basedOn = Identifier::create(['value' => $activity->uuid]);
        $records = collect(range(1, 3))->map(fn ($i) => MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id,
            'employee_id' => $this->employee->id, 'based_on_id' => $basedOn->id,
            'status' => 'new', 'request_number' => 'SYNC-'.$i,
        ]));
        $records[1]->fill(['resource_type' => MedicationRequestRequest::TYPE_PRESCRIPTION,
            'source' => MedicationRequestRequest::SOURCE_EHEALTH, 'status' => 'active'])->save();
        $activeId = (string) Str::uuid();
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/medication-official-responses.json')), true, flags: JSON_THROW_ON_ERROR);
        $native = function (bool $request, string $uuid, string $status) use ($fixtures, $activity, $records): array {
            $data = $fixtures[$request ? 'request_detail' : 'detail'];
            $data['id'] = $uuid;
            $data['status'] = $status;
            if ($request) {
                $data['request_number'] = $records->firstWhere('uuid', $uuid)->requestNumber;
            }
            $data['person']['id'] = $this->person->uuid;
            $data['employee']['id'] = $this->employee->uuid;
            $data['legal_entity']['id'] = $this->legalEntity->uuid;
            $data['based_on'] = [['identifier' => ['value' => $activity->uuid,
                'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan_activity']]]]]];

            return $data;
        };
        $active = $native(false, $activeId, 'ACTIVE');
        $active['request_number'] = 'SYNC-1';
        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class, [app(EHealthJobResolver::class)])->makePartial();
        $lifecycle->shouldReceive('getByCarePlan')->once()->with($plan->uuid, [])->andReturn([
            $active, $native(false, $records[1]->uuid, 'COMPLETED'),
        ]);
        $lifecycle->shouldReceive('getRequestsByCarePlan')->once()->with($plan->uuid, [])->andReturn([
            $native(true, $records[0]->uuid, 'REJECTED'), $native(true, $records[2]->uuid, 'REJECTED'),
        ]);
        $this->instance(MedicationRequestLifecycleService::class, $lifecycle);

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])->call('syncEPrescriptions');

        $this->assertSame('signed', $records[0]->fresh()->status);
        $this->assertSame(MedicationRequestRequest::TYPE_PRESCRIPTION, MedicationRequestRequest::whereUuid($activeId)->firstOrFail()->resourceType);
        $this->assertSame($activeId, $records[0]->fresh()->ehealthPayload['active_id']);
        $this->assertSame('completed', $records[1]->fresh()->status);
        $this->assertSame('rejected', $records[2]->fresh()->status);
        $this->assertSame($this->person->id, $records[0]->fresh()->personId);
    }

    public function test_referral_without_based_on_shows_a_local_error_and_preserves_draft(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $draft = ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'employee_id' => $this->employee->id,
            'person_id' => $this->person->id, 'status' => 'new', 'service_id' => '37003-00',
        ]);

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('openSignatureModal', 'sign_servicerequest', null, $draft->uuid)
            ->call('signReferral')
            ->assertSet('showSignatureModal', false)
            ->assertSee(__('care-plan.document_context_unavailable'))
            ->assertNotDispatched('flashMessage');
        $this->assertSame('new', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->basedOnId);
        $this->assertFalse(session()->has('success'));
    }

    public function test_referral_sync_checks_ownership_for_the_requested_resource_kind(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $uuid = (string) Str::uuid();
        ServiceRequestRequest::create([
            'uuid' => $uuid, 'employee_id' => $this->employee->id,
            'person_id' => $this->person->id, 'status' => 'new', 'service_id' => '37003-00',
        ]);
        $otherPerson = Person::create([
            'uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE',
            'patient_signed' => true, 'process_disclosure_data_consent' => true,
        ]);
        $device = DeviceRequestRequest::create([
            'uuid' => $uuid, 'employee_id' => $this->employee->id,
            'person_id' => $otherPerson->id, 'status' => 'new', 'device_id' => (string) Str::uuid(),
        ]);
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('syncReferralFromRemote');
        $this->instance(ReferralRequestLifecycleService::class, $lifecycle);

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('syncReferralFromEHealth', $uuid, 'device_request')
            ->assertSee(__('care-plan.document_context_unavailable'))
            ->assertNotDispatched('flashMessage');
        $this->assertSame('new', $device->fresh()->status);
        $this->assertSame($otherPerson->id, $device->fresh()->personId);
    }

    public function test_empty_new_plan_with_approval_offers_cancel_but_not_complete(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();
        $this->grantApproval($carePlan, $this->employee, ApprovalStatus::ACTIVE->value);

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()]);

        $this->assertFalse($component->instance()->canRequestPatientApproval);
        $this->assertTrue($component->instance()->canChangePlanLifecycle);
        $component
            ->assertDontSeeHtml('wire:click="openMethodSelectionModal"')
            ->assertSee(__('care-plan.cancel_care_plan'))
            ->assertDontSee(__('care-plan.complete_care_plan'));
    }

    public function test_cancelled_plan_hides_lifecycle_buttons_and_blocks_cancel(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();
        $carePlan->update(['status' => 'cancelled']);
        $this->grantApproval($carePlan, $this->employee, ApprovalStatus::ACTIVE->value);

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()]);

        $this->assertTrue($component->instance()->isTerminalCarePlan);
        $this->assertFalse($component->instance()->canChangePlanLifecycle);
        $this->assertFalse($component->instance()->canRequestPatientApproval);
        $component
            ->assertDontSee(__('care-plan.cancel_care_plan'))
            ->assertDontSee(__('care-plan.complete_care_plan'))
            ->assertDontSeeHtml('wire:click="openMethodSelectionModal"')
            ->assertSee(__('care-plan.status.cancelled'))
            ->assertSee(__('forms.synchronise_with_eHealth'))
            ->call('openSignatureModal', 'cancel')
            ->assertSet('showSignatureModal', false)
            ->assertNotDispatched('flashMessage');
    }

    public function test_show_page_offers_ehealth_sync_next_to_new_activity_actions(): void
    {
        $this->actingAs($this->user);

        Livewire::test(CarePlanShow::class, ['carePlan' => $this->makeSignedNewPlan()])
            ->assertSee(__('forms.synchronise_with_eHealth'))
            ->assertSee(__('care-plan.new_prescription'));
    }

    public function test_cancel_modal_shows_the_required_status_reason_select(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();
        $this->grantApproval($carePlan, $this->employee, ApprovalStatus::ACTIVE->value);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()])
            ->call('openSignatureModal', 'cancel')
            ->assertSet('showSignatureModal', true)
            ->assertSet('actionType', 'cancel')
            ->assertSee(__('care-plan.status_reason'), false)
            ->assertSeeHtml('wire:model="statusReason"')
            ->assertSeeHtml('id="statusReason"');
    }

    public function test_inpatient_activate_skips_auth_method_modal_and_creates_approval_without_otp(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();
        (new CarePlanWorkflowHarness())->updateCarePlanDraft($carePlan, ['termsOfService' => 'INPATIENT']);

        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('getStatusCode')->andReturn(201);
        $response->shouldReceive('getData', 'validate')->andReturn([
            'id' => (string) Str::uuid(), 'is_verified' => true, 'status' => 'active',
        ]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('createApproval')
            ->once()
            ->with(
                $this->person->uuid,
                Mockery::on(static fn (array $payload): bool => !array_key_exists('authorize_with', $payload))
            )
            ->andReturn($response);
        $this->instance(ApprovalApi::class, $api);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()])
            ->call('openMethodSelectionModal')
            ->assertSet('showMethodSelectionModal', false)
            ->assertSet('showAuthModal', false)
            ->assertSeeHtml('role="alert"')
            ->assertNotDispatched('flashMessage');
    }

    public function test_outpatient_activate_opens_auth_method_modal(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();
        (new CarePlanWorkflowHarness())->updateCarePlanDraft($carePlan, ['termsOfService' => 'OUTPATIENT']);

        $authResponse = Mockery::mock(EHealthResponse::class);
        $authResponse->shouldReceive('getData', 'validate')->andReturn([
            ['id' => (string) Str::uuid(), 'type' => 'OTP', 'phone_number' => '+380000000000'],
        ]);

        $personApi = Mockery::mock(PersonApi::class);
        $personApi->shouldReceive('getAuthMethods')
            ->andReturn($authResponse);
        $this->instance(PersonApi::class, $personApi);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()])
            ->call('openMethodSelectionModal')
            ->assertSet('showMethodSelectionModal', true)
            ->assertSet('showAuthModal', false);
    }

    public function test_cancel_sign_without_kep_flashes_an_error_the_doctor_can_see(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();
        $this->grantApproval($carePlan, $this->employee, ApprovalStatus::ACTIVE->value);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()])
            ->call('openSignatureModal', 'cancel')
            ->set('statusReason', 'typo')
            ->call('sign')
            ->assertSeeHtml('role="alert"')
            ->assertNotDispatched('flashMessage')
            ->assertSet('showSignatureModal', true);
    }

    public function test_plan_page_loads_multiple_prescriptions_with_strict_identifier_relations(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id,
            'uuid' => (string) Str::uuid(), 'kind' => 'medication_request', 'status' => 'draft',
        ]);
        foreach (range(1, 2) as $index) {
            $basis = Identifier::create(['value' => $activity->uuid]);
            MedicationRequestRequest::create([
                'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id,
                'employee_id' => $this->employee->id, 'based_on_id' => $basis->id,
                'status' => 'signed', 'request_number' => 'SYNTHETIC-'.$index,
            ]);
        }
        $previous = Model::preventsLazyLoading();
        Model::preventLazyLoading();

        try {
            Livewire::test(CarePlanShow::class, ['carePlan' => $plan])->assertOk()
                ->assertSet('activePrescriptions', static fn (array $rows): bool => count($rows) === 2);
        } finally {
            Model::preventLazyLoading($previous);
        }
    }

    public static function approvalConfirmationMethods(): array
    {
        return [
            'patient SMS' => [['type' => 'OTP', 'phone_number' => '+38093*****85'], true],
            'patient documents' => [['type' => 'OFFLINE'], false],
            'representative SMS' => [['type' => 'THIRD_PERSON', 'reference' => ['type' => 'OTP', 'phone_number' => '+38093*****85']], true],
            'representative documents' => [['type' => 'THIRD_PERSON', 'reference' => ['type' => 'OFFLINE']], false],
        ];
    }

    #[DataProvider('approvalConfirmationMethods')]
    public function test_selected_approval_method_controls_the_request_and_confirmation(array $method, bool $requiresOtp): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $authId = (string) Str::uuid();
        $approvalId = (string) Str::uuid();
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        $authResponse = Mockery::mock(EHealthResponse::class);
        $authResponse->shouldReceive('validate')->andReturn([$method + ['id' => $authId]]);
        $this->mock(PersonApi::class)->shouldReceive('getAuthMethods')->once()->andReturn($authResponse);
        $created = Mockery::mock(EHealthResponse::class);
        $created->shouldReceive('successful')->andReturnTrue();
        $created->shouldReceive('getStatusCode')->andReturn(201);
        $created->shouldReceive('validate')->andReturn([
            'id' => $approvalId, 'is_verified' => false, 'status' => 'new',
            'expires_at' => now()->addYear()->toIso8601String(),
            'authentication_method_current' => $method,
        ]);
        $verified = Mockery::mock(EHealthResponse::class);
        $verified->shouldReceive('successful')->andReturnTrue();
        $verified->shouldReceive('validate')->andReturn([
            'id' => $approvalId, 'is_verified' => true, 'status' => ApprovalStatus::ACTIVE->value,
            'expires_at' => now()->addYear()->toIso8601String(),
        ]);
        $api = $this->mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('createApproval')->once()->with($this->person->uuid, Mockery::on(
            fn (array $payload): bool => $payload['authorize_with'] === $authId
        ))->andReturn($created);
        $api->shouldReceive('verify')->once()->with($this->person->uuid, $approvalId, $requiresOtp ? ['code' => 1234] : [])->andReturn($verified);
        $api->shouldNotReceive('getApprovalDetails');
        $api->shouldNotReceive('resendSms');

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('openMethodSelectionModal')
            ->assertSee(__('care-plan.approval_request_with_method'))
            ->assertDontSee(__('patients.change_phone_number'))
            ->call('selectAuthMethod', $authId)
            ->assertSet('showAuthModal', true)
            ->assertSet('currentAuthMethod.type', $method['type']);
        if ($requiresOtp) {
            $component->assertSeeHtml('id="verificationCode"')
                ->assertDontSeeHtml('wire:model="offlineAcknowledged"')
                ->call('verify')->assertHasErrors('verificationCode')
                ->set('verificationCode', '1234');
        } else {
            $component->assertDontSeeHtml('id="verificationCode"')
                ->assertSeeHtml('wire:model="offlineAcknowledged"')
                ->call('resendSms')
                ->call('verify')->assertHasErrors('offlineAcknowledged')
                ->assertSee(__('care-plan.approval_offline_acknowledgement_required'))
                ->set('offlineAcknowledged', true);
            if ($method['type'] === 'THIRD_PERSON') {
                $component->assertSee(__('patients.leaflet.informed_representative'));
            }
        }
        $component->call('verify')->assertHasNoErrors()->assertSet('showAuthModal', false);
        $this->assertDatabaseHas('approvals', ['uuid' => $approvalId, 'is_verified' => true]);
        $this->assertTrue(Repository::carePlan()->hasGrantedApproval($plan->fresh(), $this->employee->uuid));
    }

    public function test_invalid_method_does_not_create_an_approval(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([['id' => (string) Str::uuid(), 'type' => 'OTP']]);
        $this->mock(PersonApi::class)->shouldReceive('getAuthMethods')->once()->andReturn($response);
        $this->mock(ApprovalApi::class)->shouldNotReceive('createApproval');
        Bus::fake();

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('openMethodSelectionModal')
            ->call('selectAuthMethod', (string) Str::uuid())
            ->assertHasErrors('selectedAuthMethodUuid')
            ->assertSet('showMethodSelectionModal', true);

        $this->assertDatabaseCount('approvals', 0);
        Bus::assertNothingBatched();
    }

    public function test_denied_method_selection_has_no_api_database_or_queue_side_effects(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $this->user->revokePermissionTo('care_plan:write');
        $this->user->unsetRelation('permissions');
        $this->mock(PersonApi::class)->shouldNotReceive('getAuthMethods');
        $this->mock(ApprovalApi::class)->shouldNotReceive('createApproval', 'verify');
        Bus::fake();

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('openMethodSelectionModal')->assertSet('showMethodSelectionModal', false)
            ->call('selectAuthMethod', (string) Str::uuid())
            ->assertSee(__('care-plan.approval_access_denied'))
            ->call('verify')->assertSet('showAuthModal', false);

        $this->assertDatabaseCount('approvals', 0);
        Bus::assertNothingBatched();
    }

    public function test_resuming_a_remotely_verified_approval_reconciles_local_access(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $identifier = Identifier::create(['value' => $this->employee->uuid]);
        $approval = Approval::create([
            'uuid' => (string) Str::uuid(), 'approvable_type' => CarePlan::class, 'approvable_id' => $plan->id,
            'granted_to_id' => $identifier->id, 'granted_to_type' => 'employee',
            'access_level' => 'write', 'status' => 'NEW', 'is_verified' => false,
        ]);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('validate')->andReturn(['id' => $approval->uuid, 'is_verified' => true, 'status' => 'active', 'expires_at' => now()->addYear()->toIso8601String()]);
        $this->mock(ApprovalApi::class)->shouldReceive('getApprovalDetails')->once()
            ->with($this->person->uuid, $approval->uuid)->andReturn($response);

        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $plan])
            ->call('verifyExistingApproval', $approval->uuid)->assertSet('showAuthModal', false);

        $this->assertTrue($approval->fresh()->getAttribute('is_verified'));
        $this->assertTrue(Repository::carePlan()->hasGrantedApproval($plan->fresh(), $this->employee->uuid));
    }

    public function test_existing_patient_approval_reuses_representative_sms_confirmation(): void
    {
        $this->actingAs($this->user);
        $role = RoleModel::findOrCreate('DOCTOR', 'web');
        $role->givePermissionTo(Permission::findOrCreate('approval:create', 'web'));
        $this->user->assignRole($role);
        $this->user->givePermissionToParent(...$role->permissions);
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $service->shouldReceive('details')->once()->with(Mockery::type(Person::class), 'approval')
            ->andReturn(['authentication_method_current' => ['type' => 'THIRD_PERSON', 'number' => '+38093*****85']]);
        $service->shouldReceive('smsResendAvailableAt')->once()->andReturn(now()->addMinute()->timestamp);
        $this->mock(PersonApi::class)->shouldNotReceive('getAuthMethods');
        $component = new PatientApproval();
        $component->personId = $this->person->id;
        $component->step = 'confirming';
        $component->approvalId = 'approval';
        $component->selectedAuthType = 'THIRD_PERSON';
        $component->selectedAuthUuid = (string) Str::uuid();

        $component->continueAfterCreation();

        $this->assertSame('code', $component->step);
        $this->assertSame('+38093*****85', $component->smsPhone);
    }

    private function makeSignedNewPlan(): CarePlan
    {
        return $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Show Actions Plan',
            'status' => 'new',
        ]);
    }

    public function test_encounter_referral_signing_persists_for_the_encounter_patient_before_submission(): void
    {
        $permission = Permission::findOrCreate('service_request:write', 'web');
        $permission->legalEntityTypes()->syncWithoutDetaching([$this->legalEntity->legalEntityTypeId]);
        $role = RoleModel::findOrCreate($this->employee->employeeType, 'web');
        $role->syncPermissions([$permission]);
        $this->user->assignRoleParent($role);
        Cache::memo()->forget('allowed_permissions:'.$this->legalEntity->id.':web');
        $this->user = $this->user->fresh();
        $this->actingAs($this->user);
        Http::preventStrayRequests();
        $encounter = new Encounter();
        $encounter->setRawAttributes(['id' => 99, 'person_id' => $this->person->id, 'uuid' => (string) Str::uuid()]);
        $encounter->exists = true;
        $encounter->setRelation('episode', null);
        $encounter->setRelation('person', $this->person);
        $encounter->setRelation('class', null);
        $context = Identifier::create(['value' => $encounter->uuid]);
        $request = ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id,
            'employee_id' => $this->employee->id, 'context_id' => $context->id, 'status' => 'draft',
            'service_id' => (string) Str::uuid(),
        ]);
        $component = new BrowserUatReferralSigningHarness();
        $component->encounter = $encounter;
        $component->encounterReferralRequestIdToSign = $request->uuid;
        $component->form = Mockery::mock(EncounterForm::class);
        $component->form->shouldReceive('signingRules')->andReturn([]);
        $component->form->shouldReceive('resetSigningFields')->once();
        $component->form->shouldReceive('validate')->andReturn([
            'password' => 'synthetic', 'knedp' => 'synthetic',
            'keyContainerUpload' => UploadedFile::fake()->create('synthetic.jks'),
        ]);
        $lifecycle = $this->mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldReceive('resolveEncounterEmployeeContext')->andReturn([
            'employee_id' => $this->employee->id,
            'employee_uuid' => $this->employee->uuid, 'legal_entity_uuid' => $this->legalEntity->uuid,
        ]);
        $lifecycle->shouldReceive('buildSignDbData')->andReturn([
            'uuid' => $request->uuid, 'service_id' => (string) Str::uuid(),
            'started_at' => now()->addMinutes(30)->toIso8601String(),
            'ended_at' => now()->addDay()->toIso8601String(),
        ]);
        $this->mock(SignatureService::class)->shouldReceive('signData')->once()->andReturn('synthetic-signature');
        // Keep patient, author and activity resolution real, including the checks before and after KEP.
        $repository = $this->partialMock(ServiceRequestRequestRepository::class);
        $repository->shouldReceive('hasPendingSubmission')->once()->with(Mockery::on(static fn ($record): bool => $record->id === $request->id && $record->personId === $request->personId))->andReturn(false);
        $repository->shouldReceive('submissionState')->once()->with(Mockery::on(static fn ($record): bool => $record->id === $request->id && $record->personId === $request->personId))->andReturn([
            'id' => 1, 'status' => JobStatus::PENDING->value, 'error' => null,
        ]);
        $repository->shouldReceive('getResource')->once()
            ->with(Mockery::on(static fn ($record): bool => $record->id === $request->id && $record->personId === $request->personId))
            ->andReturn(['id' => $request->uuid]);
        $repository->shouldReceive('storeResource')->once()
            ->with(Mockery::type('array'), Mockery::on(fn (Person $person): bool => $person->is($this->person)), $this->employee->id, null)->andReturn($request);
        $previous = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();

        try {
            $component->signEncounterReferral();
            $this->assertTrue($component->submitted, (string) session('error'));
            Http::assertNothingSent();
        } finally {
            Model::preventAccessingMissingAttributes($previous);
        }
    }

    public function test_synced_plan_with_code_only_category_renders_under_strict_eloquent_guards(): void
    {
        $this->actingAs($this->user);
        $this->withoutExceptionHandling();
        $this->assertTrue($this->user->can('care_plan:read'));
        $plan = $this->makeSignedNewPlan();
        $plan->categoryConcept()->update(['text' => null]);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData', 'validate')->andReturn([]);
        $this->mock(CarePlanApi::class)
            ->shouldReceive('getDetails')->andReturn($response);
        $previous = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();

        try {
            Livewire::test(CarePlanShow::class, ['carePlan' => $plan->fresh()])
                ->assertOk()->assertSee('Show Actions Plan')->assertSee('TEST');
        } finally {
            Model::preventAccessingMissingAttributes($previous);
        }
    }

    public function test_supporting_episode_display_is_patient_scoped_and_does_not_change_fhir_references(): void
    {
        $episode = Episode::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id,
            'name' => 'Контроль лікування', 'status' => 'active',
        ]);
        $episode->period()->create(['start' => '2026-10-26']);
        $plan = $this->createFhirCarePlan([
            'person_id' => $this->person->id, 'legal_entity_id' => $this->legalEntity->id,
            'author_id' => $this->employee->id,
            'supporting_info' => ['episodes' => [['uuid' => $episode->uuid]]],
        ]);
        $repository = Repository::carePlan();
        $before = $repository->getResource($plan->id)['supportingInfo'];
        $presented = $repository->findById($plan->id);
        $this->assertSame('Контроль лікування', $presented->supportingInfo['episodes'][0]['name']);
        $this->assertSame('26.10.2026', $presented->supportingInfo['episodes'][0]['date']);
        $this->assertSame($before, $repository->getResource($plan->id)['supportingInfo']);
        $foreign = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $rows = (new CarePlanWorkflowHarness())->resolveEpisodeLabels([['uuid' => $episode->uuid]], $foreign->id);
        $this->assertSame($episode->uuid, $rows[0]['name']);
        $this->assertSame('—', $rows[0]['date']);
    }

    public function test_queued_plan_opens_auth_method_selection_only_after_confirmed_creation(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $plan->update(['status' => 'draft']);
        $link = $plan->ehealthLinks()->create(['entity' => 'job', 'status' => 'PENDING']);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([['id' => 'synthetic-method', 'type' => 'OTP', 'phone_number' => '+380000000000']]);
        $this->mock(PersonApi::class)->shouldReceive('getAuthMethods')->once()->with($this->person->uuid)->andReturn($response);
        $planResponse = new EHealthResponse(new Response(200, [], '{"data":[]}'));
        $this->mock(CarePlanApi::class)->shouldReceive('getDetails')->andReturn($planResponse);

        $component = Livewire::withQueryParams(['activate' => 1])->test(CarePlanShow::class, ['carePlan' => $plan])
            ->assertSet('activateAfterSubmission', true)->assertSet('showMethodSelectionModal', false);
        $plan->update(['status' => 'new']);
        $link->update(['status' => 'PROCESSED']);
        $component->call('checkPlanSubmissionStatus')->assertSet('showActivationPrompt', true)
            ->assertSet('showMethodSelectionModal', false)
            ->call('acceptPlanActivation')->assertSet('showActivationPrompt', false)
            ->assertSet('showMethodSelectionModal', true)
            ->assertSet('activateAfterSubmission', false)
            ->call('checkPlanSubmissionStatus');
    }

    public function test_activity_receipt_tracks_pending_and_confirmed_referral_number(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $activity = $this->createFhirCarePlanActivity(['care_plan_id' => $plan->id, 'author_id' => $this->employee->id,
            'scheduled_period_start' => now(), 'scheduled_period_end' => now()->addWeek()]);
        $basedOn = Identifier::create(['value' => $activity->uuid]);
        $referral = ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'employee_id' => $this->employee->id,
            'based_on_id' => $basedOn->id, 'status' => 'draft', 'service_id' => (string) Str::uuid(),
        ]);
        $link = $referral->ehealthLinks()->create(['entity' => 'job', 'status' => 'PENDING']);
        $repository = Repository::carePlanActivity();
        $this->assertSame('PENDING', $repository->submissionStates($activity)[$referral->uuid]['status']);
        Livewire::test(CarePlanActivityShow::class, ['carePlan' => $plan, 'activity' => $activity])
            ->assertOk()->assertSee('Очікується')->assertSeeHtml('wire:poll.3s="checkSubmissionStatus"');
        $referral->update(['status' => 'active', 'request_number' => 'SYNTHETIC-REFERRAL']);
        $link->update(['status' => 'PROCESSED']);
        $state = $repository->submissionStates($activity)[$referral->uuid];
        $this->assertSame('PROCESSED', $state['status']);
        $this->assertSame('SYNTHETIC-REFERRAL', $state['number']);
    }

    public function test_activity_displays_service_name_from_cached_nested_catalog_without_an_api_lookup(): void
    {
        $this->actingAs($this->user);
        Http::preventStrayRequests();
        $plan = $this->makeSignedNewPlan();
        $serviceUuid = (string) Str::uuid();
        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id, 'product_reference' => $serviceUuid,
            'scheduled_period_start' => now(), 'scheduled_period_end' => now()->addWeek(),
        ]);
        Cache::put('dictionaries.service', [[
            'id' => (string) Str::uuid(), 'groups' => [[
                'id' => (string) Str::uuid(), 'services' => [[
                    'id' => $serviceUuid, 'code' => 'SYNTHETIC', 'name' => 'Українська назва послуги', 'category' => 'diagnostic_procedure',
                ]],
            ]],
        ]], now()->addDay());
        Cache::put('dictionaries.service:fresh', true, now()->addDay());

        Livewire::test(CarePlanActivityShow::class, ['carePlan' => $plan, 'activity' => $activity])
            ->assertOk()->assertSet('activityProductLabel', 'SYNTHETIC Українська назва послуги');
    }

    public function test_legacy_new_plan_opens_for_sync_without_enabling_mutations(): void
    {
        $this->actingAs($this->user);
        $plan = CarePlan::forceCreate([
            'personId' => $this->person->id, 'legalEntityId' => $this->legalEntity->id,
            'uuid' => (string) Str::uuid(), 'title' => 'Historical new plan', 'status' => 'new',
        ]);
        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->assertOk()->assertSee('Historical new plan')
            ->assertSee(__('forms.synchronise_with_eHealth'))
            ->assertSee(__('care-plan.full_sync_required'))
            ->assertDontSeeHtml('wire:click="initActivityForm')
            ->assertDontSee(__('care-plan.cancel_care_plan'))
            ->assertDontSee(__('care-plan.complete_care_plan'));
    }

    public function test_service_catalog_filters_categories_and_resets_for_another_selection(): void
    {
        config(['pagination.per_page' => '']);
        $this->actingAs($this->user);
        $groupId = (string) Str::uuid();
        $laboratoryId = (string) Str::uuid();
        $this->cacheServicePickerCatalog($groupId, $laboratoryId);

        Livewire::test(ServiceCatalog::class, [
            'legalEntity' => $this->legalEntity, 'selectionMode' => true,
            'allowGroupSelection' => true, 'selectionEvent' => 'care-plan-service-selected',
        ])->set('filterCategory', 'laboratory_procedure')->call('search')
            ->assertSee('Аналіз; вітамін B12')->assertDontSee('Реабілітаційна послуга')
            ->call('resetFilters')->assertSee('Аналіз; вітамін B12')->assertSee('Реабілітаційна послуга')
            ->call('selectService', $groupId)->assertDispatched('care-plan-service-selected', function ($event, $parameters) use ($groupId): bool {
                return $parameters['service']['id'] === $groupId && $parameters['service']['type'] === 'service_group';
            });
    }

    public function test_service_selection_saves_count_and_keeps_group_reference_type(): void
    {
        $this->actingAs($this->user);
        $groupId = (string) Str::uuid();
        $laboratoryId = (string) Str::uuid();
        $this->cacheServicePickerCatalog($groupId, $laboratoryId);
        $plan = $this->makeSignedNewPlan();
        $this->grantCarePlanWriteApproval($plan, $this->employee);

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('initActivityForm', 'service_request')
            ->set('showServiceSearchDrawer', true)
            ->assertSeeLivewire(ServiceCatalog::class)
            ->dispatch('care-plan-service-selected', service: ['id' => $laboratoryId, 'name' => 'Untrusted label'])
            ->assertSet('selectedProduct.name', 'Аналіз; вітамін B12')
            ->set('activityForm.quantity', '45')->set('activityForm.quantity_code', 'PIECE')
            ->call('saveActivity')->assertHasNoErrors();

        $activity = Repository::carePlanActivity()->findOwned(
            $plan->id,
            CarePlanActivity::whereCarePlanId($plan->id)->value('id')
        );
        $resource = Repository::carePlanActivity()->getResource($activity);
        $this->assertEquals(45, $resource['detail']['quantity']['value']);
        $this->assertSame('SERVICE_UNIT', $resource['detail']['quantity']['system']);
        $this->assertSame('PIECE', $resource['detail']['quantity']['code']);

        $component->call('editActivity', $activity->id)
            ->assertSet('selectedProduct.name', 'Аналіз; вітамін B12')
            ->assertSet('activityForm.quantity_code', 'PIECE')
            ->assertSet('activityForm.quantity_system', 'SERVICE_UNIT');

        $component->call('initActivityForm', 'service_request')
            ->call('selectCarePlanServiceFromCatalog', ['id' => $groupId])
            ->assertSet('activityForm.product_reference_type', 'service_group')
            ->assertSet('activityForm.quantity_code', 'PIECE')
            ->set('activityForm.quantity_code', 'INVALID')->call('saveActivity')
            ->assertHasErrors('activityForm.quantity_code')
            ->set('activityForm.quantity_code', 'PIECE')->call('saveActivity')->assertHasNoErrors();
        $groupActivity = CarePlanActivity::whereCarePlanId($plan->id)->orderByDesc('id')->firstOrFail();
        $groupResource = Repository::carePlanActivity()->getResource($groupActivity);
        $this->assertSame('service_group', data_get($groupResource, 'detail.productReference.identifier.type.coding.0.code'));
    }

    public function test_service_activity_save_and_prepare_signature_keep_the_created_identity(): void
    {
        $this->actingAs($this->user);
        $groupId = (string) Str::uuid();
        $serviceId = (string) Str::uuid();
        $this->cacheServicePickerCatalog($groupId, $serviceId);
        $plan = $this->makeSignedNewPlan();
        $this->grantCarePlanWriteApproval($plan, $this->employee);

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('initActivityForm', 'service_request')
            ->call('selectCarePlanServiceFromCatalog', ['id' => $serviceId])
            ->call('saveActivity')->assertHasNoErrors();
        $activityId = $component->get('activityForm.id');
        $this->assertNotNull($activityId);
        $activity = CarePlanActivity::findOrFail($activityId);
        $uuid = $activity->uuid;

        $component->set('activityForm.description', 'Updated before signing')
            ->call('saveActivityAndSign')->assertHasNoErrors()
            ->assertSet('activityForm.id', $activityId)
            ->set('showSignatureModal', false)
            ->set('activityForm.description', 'Updated after closing KEP')
            ->call('saveActivity')->assertHasNoErrors()
            ->assertSet('activityForm.id', $activityId);
        $this->assertSame(1, CarePlanActivity::whereCarePlanId($plan->id)->count());
        $this->assertSame($uuid, $activity->fresh()->uuid);
        $this->assertSame('Updated after closing KEP', Repository::carePlanActivity()->getResource($activityId)['detail']['description']);

        $component->call('initActivityForm', 'service_request')->assertSet('activityForm.id', null)
            ->call('selectCarePlanServiceFromCatalog', ['id' => $serviceId])
            ->call('saveActivity')->assertHasNoErrors();
        $this->assertNotSame($activityId, $component->get('activityForm.id'));
        $this->assertSame(2, CarePlanActivity::whereCarePlanId($plan->id)->count());
    }

    public function test_active_plan_without_write_approval_cannot_open_status_actions(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $plan->update(['status' => 'active']);
        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('openSignatureModal', 'cancel')->assertSet('showSignatureModal', false)
            ->assertSee(__('care-plan.lifecycle_access_required'));
    }

    public function test_completed_activities_enable_completion_and_block_plan_cancellation(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $plan->update(['status' => 'active']);
        $this->grantCarePlanWriteApproval($plan, $this->employee);
        $this->createFhirCarePlanActivity([
            'scheduled_period_start' => now(), 'scheduled_period_end' => now()->addWeek(),
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id,
            'kind' => 'service_request', 'status' => 'completed',
        ]);
        $api = Mockery::mock(CarePlanApi::class);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData', 'validate')->andReturn([]);
        $api->shouldReceive('getDetails')->andReturn($response);
        $this->instance(CarePlanApi::class, $api);

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $plan]);
        $this->assertFalse($component->instance()->canCancelPlan);
        $this->assertTrue($component->instance()->canCompletePlan);
        $plan->authorReference->update(['value' => (string) Str::uuid()]);
        $component->call('openSignatureModal', 'cancel')->assertSet('showSignatureModal', false)
            ->assertSee(__('care-plan.lifecycle_access_required'));
    }

    private function cacheServicePickerCatalog(string $groupId, string $laboratoryId, bool $requestAllowed = true): void
    {
        $this->mockBasicDictionaries(['SERVICE_CATEGORY' => [
            'laboratory_procedure' => 'Лабораторні процедури', 'procedure' => 'Процедури',
        ], 'eHealth/SNOMED/service_request_categories' => [
            'laboratory_procedure' => 'Лабораторні дослідження', 'procedure' => 'Процедури',
        ], 'SERVICE_REQUEST_PRIORITY' => ['routine' => 'Планове', 'urgent' => 'Ургентне']]);
        Cache::put('dictionaries.service', [[
            'id' => $groupId, 'code' => 'GROUP', 'name' => 'Група послуг',
            'is_active' => true, 'request_allowed' => $requestAllowed,
            'services' => [
                ['id' => $laboratoryId, 'code' => 'B34015', 'name' => 'Аналіз; вітамін B12',
                    'category' => 'laboratory_procedure', 'is_active' => true, 'request_allowed' => true],
                ['id' => (string) Str::uuid(), 'code' => 'REHAB', 'name' => 'Реабілітаційна послуга',
                    'category' => 'procedure', 'is_active' => true, 'request_allowed' => true],
            ],
        ]], now()->addDay());
        Cache::put('dictionaries.service:fresh', true, now()->addDay());
    }

    public function test_group_referral_requires_a_category_and_keeps_the_activity_group(): void
    {
        $this->actingAs($this->user);
        $groupId = (string) Str::uuid();
        $this->cacheServicePickerCatalog($groupId, (string) Str::uuid());
        $plan = $this->makeSignedNewPlan();
        $this->grantCarePlanWriteApproval($plan, $this->employee);
        $activity = $this->createFhirCarePlanActivity([
            'scheduled_period_start' => now(), 'scheduled_period_end' => now()->addWeek(),
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id,
            'kind' => 'service_request', 'status' => 'scheduled',
            'product_reference' => $groupId, 'product_reference_type' => 'service_group',
            'quantity' => 3, 'quantity_system' => 'SERVICE_UNIT', 'quantity_code' => 'PIECE',
        ]);
        $this->mock(CarePlanActivityApi::class)
            ->shouldReceive('assertRegisteredInEHealth')->once()->andReturnNull();

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('initReferralForm', $activity->id)
            ->assertSet('showReferralDrawer', true)->assertSet('referralServiceType', 'service_group')
            ->assertSet('referralForm.code', $groupId)->assertSet('referralForm.category', '')
            ->assertSeeHtml('wire:model="referralForm.category"')
            ->set('referralForm.category', 'invented')->call('validateReferral')
            ->assertHasErrors('referralForm.category');
    }

    #[DataProvider('referralPages')]
    public function test_referral_pages_save_the_same_local_draft_without_ehealth_and_then_prepare_signing(string $page): void
    {
        $this->actingAs($this->user);
        $groupId = (string) Str::uuid();
        $this->cacheServicePickerCatalog($groupId, (string) Str::uuid());
        $plan = $this->makeSignedNewPlan();
        $this->grantCarePlanWriteApproval($plan, $this->employee);
        $activity = $this->createFhirCarePlanActivity([
            'scheduled_period_start' => now(), 'scheduled_period_end' => now()->addWeek(),
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id,
            'kind' => 'service_request', 'status' => 'scheduled', 'program' => (string) Str::uuid(),
            'product_reference' => $groupId, 'product_type' => 'service_group',
            'quantity' => 3, 'quantity_system' => 'SERVICE_UNIT', 'quantity_code' => 'PIECE',
        ]);
        $this->mock(CarePlanActivityApi::class)
            ->shouldReceive('assertRegisteredInEHealth')->once()->andReturnNull();
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class, [
            app(EHealthJobResolver::class),
        ])->makePartial();
        $this->instance(ReferralRequestLifecycleService::class, $lifecycle);
        $api = $this->mock(ServiceRequest::class);
        $api->shouldNotReceive('createSigned');
        $parameters = ['carePlan' => $plan];
        if ($page !== CarePlanShow::class) {
            $parameters['activity'] = $activity;
        }
        $component = Livewire::test($page, $parameters)->call('initReferralForm', $activity->id)
            ->assertSeeHtml('wire:click="saveReferralDraft"')
            ->set('referralForm.category', 'laboratory_procedure')
            ->call('saveReferralDraft')->assertHasNoErrors()->assertSet('showSignatureModal', false)
            ->assertSee(__('referrals.draft_saved'));
        $uuid = $component->get('referralRequestIdToSign');
        $component->set('referralForm.note', 'Local edit')->call('saveReferralDraft')->assertHasNoErrors()
            ->assertSet('referralRequestIdToSign', $uuid)->assertSet('showSignatureModal', false);
        $api->shouldNotHaveReceived('prequalify');
        $lifecycle->shouldNotHaveReceived('trySyncDraftFromEHealth');
        $record = ServiceRequestRequest::whereUuid($uuid)->sole();
        $this->assertSame('draft', $record->status);
        $this->assertSame('Local edit', $record->note);
        $this->assertSame(1, ServiceRequestRequest::wherePersonId($this->person->id)->count());

        $lifecycle->shouldReceive('trySyncDraftFromEHealth')->twice()->andReturnFalse();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([['program_id' => $activity->program, 'program_name' => 'Тестова програма', 'status' => 'VALID']]);
        $api->shouldReceive('prequalify')->once()->andReturn($response);
        $component->call('validateReferral')->assertHasNoErrors()->assertSet('showSignatureModal', true)
            ->assertSet('referralRequestIdToSign', $uuid);

        MedicalEventsRepository::serviceRequest()->beginSubmission($record, ['status' => 'active']);
        $component->set('showSignatureModal', false)->set('referralForm.note', 'Must not overwrite pending');
        foreach (['saveReferralDraft', 'validateReferral'] as $action) {
            $component->call($action)->assertHasErrors('submission')->assertSet('showSignatureModal', false)
                ->assertSet('showReferralDrawer', true)->assertSee(__('care-plan.submission_pending'));
        }
        $this->assertSame('Local edit', $record->fresh()->note);
    }

    public static function referralPages(): array
    {
        return [
            'plan' => [CarePlanShow::class],
            'activity' => [CarePlanActivityShow::class],
        ];
    }

    public function test_local_referral_save_is_denied_without_current_patient_access(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $lifecycle = $this->mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('createCarePlanDraft');
        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('saveReferralDraft')->assertSet('showSignatureModal', false)
            ->assertSee(__('care-plan.policy.manage_activities_denied'));
        $this->assertDatabaseCount('service_request_requests', 0);
    }

    public function test_active_group_can_be_prescribed_without_permission_to_issue_a_referral(): void
    {
        $this->actingAs($this->user);
        $groupId = (string) Str::uuid();
        $this->cacheServicePickerCatalog($groupId, (string) Str::uuid(), requestAllowed: false);
        $plan = $this->makeSignedNewPlan();
        $this->grantCarePlanWriteApproval($plan, $this->employee);
        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('initActivityForm', 'service_request')
            ->call('selectCarePlanServiceFromCatalog', ['id' => $groupId])->call('saveActivity')
            ->assertHasNoErrors();
        $activity = CarePlanActivity::whereCarePlanId($plan->id)->firstOrFail();
        $activity->update(['status' => 'scheduled']);
        $this->mock(CarePlanActivityApi::class)
            ->shouldReceive('assertRegisteredInEHealth')->once()->andReturnNull();
        $component->call('initReferralForm', $activity->id)->assertSet('showReferralDrawer', false)
            ->assertSee(__('care-plan.service_group_referral_not_allowed'));
    }

    private function grantApproval(CarePlan $carePlan, Employee $employee, string $status): void
    {
        $identifier = Identifier::create(['value' => $employee->uuid]);

        Approval::create([
            'uuid' => (string) Str::uuid(),
            'approvable_type' => CarePlan::class,
            'approvable_id' => $carePlan->id,
            'granted_to_id' => $identifier->id,
            'granted_to_type' => 'employee',
            'status' => $status,
            'is_verified' => true,
            'access_level' => 'write',
            'expires_at' => now()->addYear(),
        ]);
    }

    public static function cancellationDenials(): array
    {
        return [
            'creator without scope' => [false, true],
            'scope without authorship, receipt or declaration' => [true, false],
        ];
    }

    #[DataProvider('cancellationDenials')]
    public function test_cancellation_denial_blocks_cancel_and_recreate_without_side_effects(bool $hasScope, bool $isCreator): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $this->grantApproval($plan, $this->employee, ApprovalStatus::ACTIVE->value);
        $approval = $plan->approvals()->firstOrFail();
        if ($isCreator) {
            $approval->update(['created_by_id' => $approval->grantedToId]);
        } else {
            $stranger = Identifier::create(['value' => (string) Str::uuid()]);
            $approval->update(['granted_to_id' => $stranger->id]);
        }
        if ($hasScope) {
            $this->grantMedicalEventAbilities($this->user, ['approval:cancel']);
        }
        $plan->update(['terms_of_service' => 'INPATIENT']);
        $this->mock(PatientApprovalService::class)->shouldNotReceive('cancel');
        $this->mock(PersonApi::class)->shouldNotReceive('getAuthMethods');
        $this->mock(ApprovalApi::class)->shouldNotReceive('cancelApproval', 'createApproval', 'verify');

        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $plan])
            ->call('cancelApproval', $approval->uuid)->assertSee(__('approval.messages.cancel_forbidden'))
            ->call('recreateApproval', $approval->uuid)->assertSee(__('approval.messages.cancel_forbidden'));

        $this->assertSame(ApprovalStatus::ACTIVE->value, $approval->fresh()->status);
        $this->assertSame(1, $plan->approvals()->count());
    }

    public function test_terminal_plan_can_revoke_an_existing_approval_but_cannot_grant_a_new_one(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $plan->update(['status' => 'completed']);
        $this->grantApproval($plan, $this->employee, ApprovalStatus::ACTIVE->value);
        $approval = $plan->approvals()->firstOrFail();
        $approval->update(['created_by_id' => $approval->grantedToId]);
        $approvalUuid = $approval->uuid;
        $this->grantMedicalEventAbilities($this->user, ['approval:cancel']);
        $response = new EHealthResponse(new Response(200, [], '{"data":[]}'));
        $this->mock(PersonApi::class)->shouldReceive('getAuthMethods')->andReturn($response);

        $this->mock(PatientApprovalService::class)->shouldReceive('cancel')->once()
            ->with(Mockery::on(fn (Approval $candidate): bool => $candidate->is($approval)))->andReturnNull();
        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $plan->fresh()])
            ->assertSet('isReadOnly', true)->assertSeeHtml('wire:click="cancelApproval')
            ->call('cancelApproval', $approvalUuid)->assertSee(__('care-plan.approval_cancelled'))
            ->call('cancelApproval', (string) Str::uuid())->assertSee(__('care-plan.approval_cancel_error'));
        $plan->approvals()->whereUuid($approvalUuid)->update(['status' => ApprovalStatus::PENDING->value]);
        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $plan->fresh()])
            ->assertSeeHtml('wire:click="cancelApproval')
            ->assertDontSee(__('care-plan.no_approvals_found'))
            ->assertDontSeeHtml('wire:click="recreateApproval')
            ->assertDontSeeHtml('wire:click="verifyExistingApproval')
            ->call('createApproval')->assertSee(__('care-plan.cannot_grant_terminal', ['status' => CarePlanStatus::labelFor($plan->status)]));
    }
}

class BrowserUatReferralSigningHarness extends Component
{
    use ManagesEncounterReferrals;

    public Encounter $encounter;
    public EncounterForm $form;
    public bool $submitted = false;

    protected function resolveEncounterModelForStandalone(): ?Encounter
    {
        return $this->encounter;
    }

    protected function submitClinicalOperation(CarePlan|CarePlanActivity|ServiceRequestRequest $record, array $changes, callable $send, ?string $requiredScope = null): ?array
    {
        $this->submitted = true;

        return null;
    }
}

class CarePlanRefreshFailureHarness extends CarePlanShow
{
    public bool $failRefresh = false;

    public function prepareSignedRequest(string $requestUuid): void
    {
        $this->ePrescriptionRequestIdToSign = $requestUuid;
    }

    public function signEPrescription(): void
    {
        $this->failRefresh = true;
        parent::signEPrescription();
    }

    protected function refreshCarePlan(): void
    {
        if ($this->failRefresh) {
            throw new RuntimeException('Synthetic local page refresh failure');
        }

        parent::refreshCarePlan();
    }
}
