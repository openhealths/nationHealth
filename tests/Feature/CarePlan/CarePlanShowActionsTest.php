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
        $api->shouldReceive('getRawDetails')->andReturn([]);
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
        $message = __('care-plan.document_context_unavailable');

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $plan]);
        $component->call('openSignatureModal', 'cancel_activity', PHP_INT_MAX)
            ->assertSet('showSignatureModal', false)
            ->assertSee($message)
            ->assertNotDispatched('flashMessage');
        $this->assertNull(session('error'));
        $this->assertFalse(session()->has('success'));
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
        $api = Mockery::mock(\App\Classes\eHealth\Api\Patient\DeviceRequest::class);
        $api->shouldNotReceive('getById');
        $this->instance(\App\Classes\eHealth\Api\Patient\DeviceRequest::class, $api);

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('syncReferralFromEHealth', $uuid, 'device_request')
            ->assertSee(__('care-plan.document_context_unavailable'))
            ->assertNotDispatched('flashMessage');
        $this->assertSame('new', $device->fresh()->status);
        $this->assertSame($otherPerson->id, $device->fresh()->personId);
    }

    public function test_new_plan_with_active_approval_for_current_doctor_shows_cancel_and_complete(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();
        $this->grantApproval($carePlan, $this->employee, ApprovalStatus::ACTIVE->value);

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()]);

        $this->assertFalse($component->instance()->canRequestPatientApproval);
        $this->assertTrue($component->instance()->canChangePlanLifecycle);
        $component
            ->assertDontSee(__('care-plan.activate_plan_patient_approval'))
            ->assertSee(__('care-plan.cancel_care_plan'))
            ->assertSee(__('care-plan.complete_care_plan'));
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
            ->assertDontSee(__('care-plan.activate_plan_patient_approval'))
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

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getStatusCode')->andReturn(201);
        $response->shouldReceive('getData')->andReturn([
            'id' => (string) Str::uuid(),
        ]);

        $api = Mockery::mock(\App\Classes\eHealth\Api\Approval::class);
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
        $authResponse->shouldReceive('getData')->andReturn([
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

    public static function prescriptionBlockActions(): iterable
    {
        yield 'block success' => ['blockPrescription', 'block', 'active', 'blocked', true];
        yield 'unblock success' => ['unblockPrescription', 'unblock', 'blocked', 'active', true];
        yield 'block failure' => ['blockPrescription', 'block', 'active', 'active', false];
        yield 'unblock failure' => ['unblockPrescription', 'unblock', 'blocked', 'blocked', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('prescriptionBlockActions')]
    public function test_block_actions_use_active_uuid_and_persist_only_after_api_success(string $action, string $endpoint, string $before, string $after, bool $succeeds): void
    {
        $this->actingAs($this->user);
        $plan = $this->makeSignedNewPlan();
        $this->grantApproval($plan, $this->employee, ApprovalStatus::ACTIVE->value);
        $activeId = (string) Str::uuid();
        $record = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'employee_id' => $this->employee->id,
            'person_id' => $this->person->id, 'status' => $before,
            'ehealth_payload' => ['active_id' => $activeId, 'unknown' => ['raw' => true]],
        ]);
        $api = Mockery::mock(MedicationRequestApi::class);
        $api->shouldNotReceive('getBySearchParams');
        $expected = $api->shouldReceive($endpoint)->once()->with($this->person->uuid, $activeId, $endpoint === 'block'
            ? ['status_reason' => 'Призупинення або блокування призначення'] : []);
        if ($succeeds) {
            $expected->andReturn(new EHealthResponse(new Response(200, [], json_encode(['data' => ['status' => $after]]))));
        } else {
            $expected->andThrow(new \RuntimeException('eHealth unavailable'));
        }
        $this->instance(MedicationRequestApi::class, $api);

        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])->call($action, $record->uuid);

        $this->assertSame($after, $record->fresh()->status);
        $this->assertSame(['active_id' => $activeId, 'unknown' => ['raw' => true]], $record->fresh()->ehealthPayload);
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
        ]);
    }
}
