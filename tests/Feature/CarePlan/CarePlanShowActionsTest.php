<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use GuzzleHttp\Psr7\Response;
use Mockery;

use App\Enums\Person\ApprovalStatus;
use App\Livewire\CarePlan\CarePlanShow;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Concerns\MocksBasicDictionaries;

class CarePlanShowActionsTest extends TestCase
{
    use \Tests\Concerns\CreatesFhirCarePlans;

    use DatabaseTransactions;
    use MocksBasicDictionaries;

    protected Person $person;

    protected User $user;

    protected Employee $employee;

    protected LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockBasicDictionaries();

        config(['cipher.api.domain' => 'https://cipher.invalid']);
        \Illuminate\Support\Facades\Cache::put('knedp_certificate_authority', [], 60);

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Action',
            'last_name' => 'Patient',
            'birth_date' => '1991-02-02',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $party = \App\Models\Relations\Party::create([
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
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
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
        \Illuminate\Support\Facades\Bus::fake();

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])->call('sync')->assertHasNoErrors();

        \Illuminate\Support\Facades\Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch) use ($plan): bool {
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
        ])->map(fn (array $fixture) => \App\Models\MedicalEvents\Sql\DiagnosticReport::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $fixture[0], 'code_id' => $fixture[1],
            'status' => 'final', 'issued' => '2026-10-23 09:00:00',
            'recorded_by_id' => $author->id, 'primary_source' => true,
        ]));
        $api = Mockery::mock(\App\Classes\eHealth\Api\CarePlan::class);
        $response = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $response->shouldReceive('getData', 'validate')->andReturn([]);
        $api->shouldReceive('getDetails')->andReturn($response);
        $this->app->instance(\App\Classes\eHealth\Api\CarePlan::class, $api);
        $wasPreventingMissingAttributes = \Illuminate\Database\Eloquent\Model::preventsAccessingMissingAttributes();
        $wasPreventingLazyLoading = \Illuminate\Database\Eloquent\Model::preventsLazyLoading();
        \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes();
        \Illuminate\Database\Eloquent\Model::preventLazyLoading();

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
            \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes($wasPreventingMissingAttributes);
            \Illuminate\Database\Eloquent\Model::preventLazyLoading($wasPreventingLazyLoading);
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
        $service = Mockery::mock(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class);
        $service->shouldReceive('signPrescription')->once()->andReturn([
            'success_message' => 'Prescription accepted', 'warning_message' => 'Existing quantity warning',
        ]);
        $this->instance(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class, $service);

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
        $api = Mockery::mock(MedicationRequestApi::class);
        $response = static fn (array $data) => new EHealthResponse(new Response(200, [], json_encode(['data' => $data])));
        $api->shouldReceive('getBySearchParams')->once()->with($this->person->uuid, [])->andReturn($response([
            ['id' => $activeId, 'request_number' => 'SYNC-1', 'status' => 'ACTIVE'],
            ['id' => $records[1]->uuid, 'status' => 'COMPLETED'],
        ]));
        $api->shouldReceive('getRequestsBySearchParams')->once()->with($this->person->uuid, [])->andReturn($response([
            ['id' => $records[0]->uuid, 'status' => 'REJECTED'],
            ['id' => $records[2]->uuid, 'status' => 'REJECTED'],
        ]));
        $this->instance(MedicationRequestApi::class, $api);

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
        $draft = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
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
        \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
            'uuid' => $uuid, 'employee_id' => $this->employee->id,
            'person_id' => $this->person->id, 'status' => 'new', 'service_id' => '37003-00',
        ]);
        $otherPerson = Person::create([
            'uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE',
            'patient_signed' => true, 'process_disclosure_data_consent' => true,
        ]);
        $device = \App\Models\MedicalEvents\Sql\DeviceRequestRequest::create([
            'uuid' => $uuid, 'employee_id' => $this->employee->id,
            'person_id' => $otherPerson->id, 'status' => 'new', 'device_id' => (string) Str::uuid(),
        ]);
        $lifecycle = Mockery::mock(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('syncReferralFromRemote');
        $this->instance(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class, $lifecycle);

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
        app(\App\Repositories\CarePlanRepository::class)->update($carePlan, ['termsOfService' => 'INPATIENT']);

        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('getStatusCode')->andReturn(201);
        $response->shouldReceive('getData', 'validate')->andReturn([
            'id' => (string) Str::uuid(), 'is_verified' => true, 'status' => 'active',
        ]);

        $api = Mockery::mock(\App\Classes\eHealth\Api\Approval::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('createApproval')
            ->once()
            ->with(
                $this->person->uuid,
                Mockery::on(static fn (array $payload): bool => !array_key_exists('authorize_with', $payload))
            )
            ->andReturn($response);
        $this->instance(\App\Classes\eHealth\Api\Approval::class, $api);

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
        app(\App\Repositories\CarePlanRepository::class)->update($carePlan, ['termsOfService' => 'OUTPATIENT']);

        $authResponse = Mockery::mock(EHealthResponse::class);
        $authResponse->shouldReceive('getData', 'validate')->andReturn([
            ['id' => (string) Str::uuid(), 'type' => 'OTP', 'phone_number' => '+380000000000'],
        ]);

        $personApi = Mockery::mock(\App\Classes\eHealth\Api\Person::class);
        $personApi->shouldReceive('getAuthMethods')
            ->andReturn($authResponse);
        $this->instance(\App\Classes\eHealth\Api\Person::class, $personApi);

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
        $previous = \Illuminate\Database\Eloquent\Model::preventsLazyLoading();
        \Illuminate\Database\Eloquent\Model::preventLazyLoading();

        try {
            Livewire::test(CarePlanShow::class, ['carePlan' => $plan])->assertOk()
                ->assertSet('activePrescriptions', static fn (array $rows): bool => count($rows) === 2);
        } finally {
            \Illuminate\Database\Eloquent\Model::preventLazyLoading($previous);
        }
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
        $this->actingAs($this->user);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $encounter = new \App\Models\MedicalEvents\Sql\Encounter();
        $encounter->setRawAttributes(['id' => 99, 'person_id' => $this->person->id, 'uuid' => (string) Str::uuid()]);
        $encounter->exists = true;
        $encounter->setRelation('episode', null);
        $context = Identifier::create(['value' => $encounter->uuid]);
        $request = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id,
            'employee_id' => $this->employee->id, 'context_id' => $context->id, 'status' => 'draft',
            'service_id' => (string) Str::uuid(),
        ]);
        $component = new BrowserUatReferralSigningHarness();
        $component->encounter = $encounter;
        $component->encounterReferralRequestIdToSign = $request->uuid;
        $component->form = Mockery::mock(\App\Livewire\EncounterPackage\Forms\EncounterForm::class);
        $component->form->shouldReceive('signingRules')->andReturn([]);
        $component->form->shouldReceive('resetSigningFields')->once();
        $component->form->shouldReceive('validate')->andReturn([
            'password' => 'synthetic', 'knedp' => 'synthetic',
            'keyContainerUpload' => \Illuminate\Http\UploadedFile::fake()->create('synthetic.jks'),
        ]);
        $lifecycle = $this->mock(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class);
        $lifecycle->shouldReceive('resolveEncounterEmployeeContext')->andReturn([
            'employee_uuid' => $this->employee->uuid, 'legal_entity_uuid' => $this->legalEntity->uuid,
        ]);
        $lifecycle->shouldReceive('buildSignDbData')->andReturn([
            'uuid' => $request->uuid, 'service_id' => (string) Str::uuid(),
        ]);
        $this->mock(\App\Services\SignatureService::class)->shouldReceive('signData')->once()->andReturn('synthetic-signature');
        $repository = $this->mock(\App\Repositories\MedicalEvents\ServiceRequestRequestRepository::class);
        $repository->shouldReceive('hasPendingSubmission')->once()->with(Mockery::on(static fn ($record): bool => $record->id === $request->id && $record->personId === $request->personId))->andReturn(false);
        $repository->shouldReceive('submissionState')->once()->with(Mockery::on(static fn ($record): bool => $record->id === $request->id && $record->personId === $request->personId))->andReturn([
            'id' => 1, 'status' => \App\Enums\JobStatus::PENDING->value, 'error' => null,
        ]);
        $repository->shouldReceive('getResource')->once()
            ->with(Mockery::on(static fn ($record): bool => $record->id === $request->id && $record->personId === $request->personId))
            ->andReturn(['id' => $request->uuid]);
        $repository->shouldReceive('storeResource')->once()
            ->with(Mockery::type('array'), $this->person->id, $this->employee->id, null)->andReturn($request);
        $previous = \Illuminate\Database\Eloquent\Model::preventsAccessingMissingAttributes();
        \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes();

        try {
            $component->signEncounterReferral();
            $this->assertTrue($component->submitted, (string) session('error'));
            \Illuminate\Support\Facades\Http::assertNothingSent();
        } finally {
            \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes($previous);
        }
    }

    public function test_synced_plan_with_code_only_category_renders_under_strict_eloquent_guards(): void
    {
        $this->actingAs($this->user);
        $this->withoutExceptionHandling();
        $this->assertTrue($this->user->can('care_plan:read'));
        $plan = $this->makeSignedNewPlan();
        $plan->categoryConcept()->update(['text' => null]);
        $response = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $response->shouldReceive('getData', 'validate')->andReturn([]);
        $this->mock(\App\Classes\eHealth\Api\CarePlan::class)
            ->shouldReceive('getDetails')->andReturn($response);
        $previous = \Illuminate\Database\Eloquent\Model::preventsAccessingMissingAttributes();
        \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes();

        try {
            Livewire::test(CarePlanShow::class, ['carePlan' => $plan->fresh()])
                ->assertOk()->assertSee('Show Actions Plan')->assertSee('TEST');
        } finally {
            \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes($previous);
        }
    }

    public function test_supporting_episode_display_is_patient_scoped_and_does_not_change_fhir_references(): void
    {
        $episode = \App\Models\MedicalEvents\Sql\Episode::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id,
            'name' => 'Контроль лікування', 'status' => 'active',
        ]);
        $episode->period()->create(['start' => '2026-10-26']);
        $plan = $this->createFhirCarePlan([
            'person_id' => $this->person->id, 'legal_entity_id' => $this->legalEntity->id,
            'author_id' => $this->employee->id,
            'supporting_info' => ['episodes' => [['uuid' => $episode->uuid]]],
        ]);
        $repository = app(\App\Repositories\CarePlanRepository::class);
        $before = $repository->getResource($plan->id)['supportingInfo'];
        $presented = $repository->findById($plan->id);
        $this->assertSame('Контроль лікування', $presented->supportingInfo['episodes'][0]['name']);
        $this->assertSame('26.10.2026', $presented->supportingInfo['episodes'][0]['date']);
        $this->assertSame($before, $repository->getResource($plan->id)['supportingInfo']);
        $foreign = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $rows = $repository->resolveEpisodeLabels([['uuid' => $episode->uuid]], $foreign->id);
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
        $this->mock(\App\Classes\eHealth\Api\Person::class)->shouldReceive('getAuthMethods')->once()->with($this->person->uuid)->andReturn($response);
        $planResponse = new EHealthResponse(new Response(200, [], '{"data":[]}'));
        $this->mock(\App\Classes\eHealth\Api\CarePlan::class)->shouldReceive('getDetails')->andReturn($planResponse);

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
        $activity = $this->createFhirCarePlanActivity(['care_plan_id' => $plan->id, 'author_id' => $this->employee->id]);
        $basedOn = Identifier::create(['value' => $activity->uuid]);
        $referral = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'employee_id' => $this->employee->id,
            'based_on_id' => $basedOn->id, 'status' => 'draft', 'service_id' => (string) Str::uuid(),
        ]);
        $link = $referral->ehealthLinks()->create(['entity' => 'job', 'status' => 'PENDING']);
        $repository = app(\App\Repositories\CarePlanActivityRepository::class);
        $this->assertSame('PENDING', $repository->submissionStates($activity)[$referral->uuid]['status']);
        Livewire::test(\App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow::class, ['carePlan' => $plan, 'activity' => $activity])
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
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $plan = $this->makeSignedNewPlan();
        $serviceUuid = (string) Str::uuid();
        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id, 'product_reference' => $serviceUuid,
        ]);
        \Illuminate\Support\Facades\Cache::put('dictionaries.service', [[
            'id' => (string) Str::uuid(), 'groups' => [[
                'id' => (string) Str::uuid(), 'services' => [[
                    'id' => $serviceUuid, 'code' => 'SYNTHETIC', 'name' => 'Українська назва послуги', 'category' => 'diagnostic_procedure',
                ]],
            ]],
        ]], now()->addDay());
        \Illuminate\Support\Facades\Cache::put('dictionaries.service:fresh', true, now()->addDay());

        Livewire::test(\App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow::class, ['carePlan' => $plan, 'activity' => $activity])
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

        Livewire::test(\App\Livewire\Dictionary\ServiceCatalog::class, [
            'legalEntity' => $this->legalEntity, 'selectionMode' => true,
            'allowGroupSelection' => true, 'selectionEvent' => 'care-plan-service-selected',
        ])->set('filterCategory', 'laboratory_procedure')->call('search')
            ->assertSee('Аналіз; вітамін B12')->assertDontSee('Реабілітаційна послуга')
            ->call('resetFilters')->assertSee('Аналіз; вітамін B12')->assertSee('Реабілітаційна послуга')
            ->call('selectService', $groupId)->assertDispatched('care-plan-service-selected', function ($event, $parameters) use ($groupId): bool {
                return $parameters['service']['id'] === $groupId && $parameters['service']['type'] === 'service_group';
            });
    }

    public function test_service_selection_saves_minutes_and_keeps_group_reference_type(): void
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
            ->assertSeeLivewire(\App\Livewire\Dictionary\ServiceCatalog::class)
            ->dispatch('care-plan-service-selected', service: ['id' => $laboratoryId, 'name' => 'Untrusted label'])
            ->assertSet('selectedProduct.name', 'Аналіз; вітамін B12')
            ->set('activityForm.quantity', '45')->set('activityForm.quantity_code', 'MINUTE')
            ->call('saveActivity')->assertHasNoErrors();

        $activity = app(\App\Repositories\CarePlanActivityRepository::class)->findOwned(
            $plan->id, \App\Models\CarePlanActivity::whereCarePlanId($plan->id)->value('id')
        );
        $resource = app(\App\Repositories\CarePlanActivityRepository::class)->getResource($activity);
        $this->assertEquals(45, $resource['detail']['quantity']['value']);
        $this->assertSame('SERVICE_UNIT', $resource['detail']['quantity']['system']);
        $this->assertSame('MINUTE', $resource['detail']['quantity']['code']);

        $component->call('editActivity', $activity->id)
            ->assertSet('selectedProduct.name', 'Аналіз; вітамін B12')
            ->assertSet('activityForm.quantity_code', 'MINUTE')
            ->assertSet('activityForm.quantity_system', 'SERVICE_UNIT');

        $component->call('initActivityForm', 'service_request')
            ->call('selectCarePlanServiceFromCatalog', ['id' => $groupId])
            ->assertSet('activityForm.product_reference_type', 'service_group')
            ->assertSet('activityForm.quantity_code', 'PIECE')
            ->set('activityForm.quantity_code', 'INVALID')->call('saveActivity')
            ->assertHasErrors('activityForm.quantity_code')
            ->set('activityForm.quantity_code', 'PIECE')->call('saveActivity')->assertHasNoErrors();
        $groupActivity = \App\Models\CarePlanActivity::whereCarePlanId($plan->id)->orderByDesc('id')->firstOrFail();
        $groupResource = app(\App\Repositories\CarePlanActivityRepository::class)->getResource($groupActivity);
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
        $activity = \App\Models\CarePlanActivity::findOrFail($activityId);
        $uuid = $activity->uuid;

        $component->set('activityForm.description', 'Updated before signing')
            ->call('saveActivityAndSign')->assertHasNoErrors()
            ->assertSet('activityForm.id', $activityId)
            ->set('showSignatureModal', false)
            ->set('activityForm.description', 'Updated after closing KEP')
            ->call('saveActivity')->assertHasNoErrors()
            ->assertSet('activityForm.id', $activityId);
        $this->assertSame(1, \App\Models\CarePlanActivity::whereCarePlanId($plan->id)->count());
        $this->assertSame($uuid, $activity->fresh()->uuid);
        $this->assertSame('Updated after closing KEP', app(\App\Repositories\CarePlanActivityRepository::class)->getResource($activityId)['detail']['description']);

        $component->call('initActivityForm', 'service_request')->assertSet('activityForm.id', null)
            ->call('selectCarePlanServiceFromCatalog', ['id' => $serviceId])
            ->call('saveActivity')->assertHasNoErrors();
        $this->assertNotSame($activityId, $component->get('activityForm.id'));
        $this->assertSame(2, \App\Models\CarePlanActivity::whereCarePlanId($plan->id)->count());
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

    public function test_final_activities_enable_finalization_but_cancel_requires_the_author(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $plan->update(['status' => 'active']);
        $this->grantCarePlanWriteApproval($plan, $this->employee);
        $this->createFhirCarePlanActivity([
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id,
            'kind' => 'service_request', 'status' => 'completed',
        ]);
        $api = Mockery::mock(\App\Classes\eHealth\Api\CarePlan::class);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData', 'validate')->andReturn([]);
        $api->shouldReceive('getDetails')->andReturn($response);
        $this->instance(\App\Classes\eHealth\Api\CarePlan::class, $api);

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $plan]);
        $this->assertTrue($component->instance()->canCancelPlan);
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
        ]]);
        \Illuminate\Support\Facades\Cache::put('dictionaries.service', [[
            'id' => $groupId, 'code' => 'GROUP', 'name' => 'Група послуг',
            'is_active' => true, 'request_allowed' => $requestAllowed,
            'services' => [
                ['id' => $laboratoryId, 'code' => 'B34015', 'name' => 'Аналіз; вітамін B12',
                    'category' => 'laboratory_procedure', 'is_active' => true, 'request_allowed' => true],
                ['id' => (string) Str::uuid(), 'code' => 'REHAB', 'name' => 'Реабілітаційна послуга',
                    'category' => 'procedure', 'is_active' => true, 'request_allowed' => true],
            ],
        ]], now()->addDay());
        \Illuminate\Support\Facades\Cache::put('dictionaries.service:fresh', true, now()->addDay());
    }

    public function test_group_referral_requires_a_category_and_keeps_the_activity_group(): void
    {
        $this->actingAs($this->user);
        $groupId = (string) Str::uuid();
        $this->cacheServicePickerCatalog($groupId, (string) Str::uuid());
        $plan = $this->makeSignedNewPlan();
        $this->grantCarePlanWriteApproval($plan, $this->employee);
        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id,
            'kind' => 'service_request', 'status' => 'scheduled',
            'product_reference' => $groupId, 'product_reference_type' => 'service_group',
            'quantity' => 3, 'quantity_system' => 'SERVICE_UNIT', 'quantity_code' => 'PIECE',
        ]);
        $this->mock(\App\Services\MedicalEvents\CarePlanActivityEHealthGuard::class)
            ->shouldReceive('assertRegisteredInEHealth')->once()->andReturnNull();

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('initReferralForm', $activity->id)
            ->assertSet('showReferralDrawer', true)->assertSet('referralServiceType', 'service_group')
            ->assertSet('referralForm.code', $groupId)->assertSet('referralForm.category', '')
            ->assertSeeHtml('wire:model="referralForm.category"')
            ->set('referralForm.category', 'invented')->call('validateReferral')
            ->assertHasErrors('referralForm.category');
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
        $activity = \App\Models\CarePlanActivity::whereCarePlanId($plan->id)->firstOrFail();
        $activity->update(['status' => 'scheduled']);
        $this->mock(\App\Services\MedicalEvents\CarePlanActivityEHealthGuard::class)
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

    public function test_terminal_plan_can_revoke_an_existing_approval_but_cannot_grant_a_new_one(): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $plan->update(['status' => 'completed']);
        $this->grantApproval($plan, $this->employee, ApprovalStatus::ACTIVE->value);
        $approvalUuid = $plan->approvals()->value('uuid');
        $response = new EHealthResponse(new Response(200, [], '{"data":[]}'));
        $this->mock(\App\Classes\eHealth\Api\Person::class)->shouldReceive('getAuthMethods')->andReturn($response);
        $service = $this->mock(\App\Services\MedicalEvents\CarePlanApprovalService::class)->makePartial();
        $service->shouldReceive('skipsPatientOtp')->andReturn(false);
        $service->shouldReceive('syncForCarePlan')->andReturnNull();
        $service->shouldReceive('deactivate')->once()->with($this->person->uuid, $approvalUuid)->andReturn($response);
        Livewire::test(\App\Livewire\CarePlan\CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $plan->fresh()])
            ->assertSet('isReadOnly', true)->assertSeeHtml('wire:click="cancelApproval')
            ->call('cancelApproval', $approvalUuid)->assertSee(__('care-plan.approval_cancelled'))
            ->call('cancelApproval', (string) Str::uuid())->assertSee(__('care-plan.approval_cancel_error'));
        $plan->approvals()->whereUuid($approvalUuid)->update(['status' => ApprovalStatus::PENDING->value]);
        Livewire::test(\App\Livewire\CarePlan\CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $plan->fresh()])
            ->assertSeeHtml('wire:click="cancelApproval')
            ->assertDontSeeHtml('wire:click="recreateApproval')
            ->assertDontSeeHtml('wire:click="verifyExistingApproval')
            ->call('createApproval')->assertSee(__('care-plan.cannot_grant_terminal', ['status' => \App\Enums\CarePlanStatus::labelFor($plan->status)]));
    }
}

class BrowserUatReferralSigningHarness extends \Livewire\Component
{
    use \App\Livewire\EncounterPackage\Concerns\ManagesEncounterReferrals;

    public \App\Models\MedicalEvents\Sql\Encounter $encounter;
    public \App\Livewire\EncounterPackage\Forms\EncounterForm $form;
    public bool $submitted = false;

    protected function resolveEncounterModelForStandalone(): ?\App\Models\MedicalEvents\Sql\Encounter
    {
        return $this->encounter;
    }

    protected function submitClinicalOperation(CarePlan|\App\Models\CarePlanActivity|\App\Models\MedicalEvents\Sql\ServiceRequestRequest $record, array $changes, callable $send, ?string $requiredScope = null): ?array
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
            throw new \RuntimeException('Synthetic local page refresh failure');
        }

        parent::refreshCarePlan();
    }
}
