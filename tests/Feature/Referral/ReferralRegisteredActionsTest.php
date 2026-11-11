<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\BaseForm;
use App\Enums\JobStatus;
use App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow;
use App\Livewire\Person\Records\PatientReferrals;
use App\Livewire\Referral\ReferralIndex;
use App\Models\CarePlanActivity;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Services\SignatureService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use Livewire\Livewire;

use function Livewire\store;

use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\Support\NativeClinicalFixtures;

use Tests\TestCase;

class ReferralRegisteredActionsTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;
    use UsesCompositionDictionaries;

    private array $context;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->context = $this->compositionFixture(scopes: [
            'service_request:read', 'service_request:recall', 'service_request:cancel',
            'care_plan:read', 'encounter:read',
        ]);
        $this->grantReferralEmployeeRole($this->context['user'], $this->context['employee']);
        $dictionaries = Cache::get(BasicDictionary::KEY, []);
        foreach (['recall', 'cancel'] as $operation) {
            $dictionaries[] = [
                'name' => 'eHealth/service_request_'.$operation.'_reasons',
                'values' => [['code' => 'incorrect_data', 'description' => 'Помилкові дані', 'is_active' => true]],
            ];
        }
        Cache::forever(BasicDictionary::KEY, $dictionaries);
        Cache::forever(BasicDictionary::KEY.':fresh', true);
    }

    public function test_registered_view_and_both_source_print_actions_need_only_read_scope(): void
    {
        $native = $this->native();
        $native['requester_legal_entity'] = NativeClinicalFixtures::reference('legal_entity', (string) Str::uuid());
        $record = $this->persist($native);
        $this->retainScopes(['service_request:read', 'care_plan:read', 'encounter:read']);
        $this->assertFalse(Auth::user()->can('service_request:write'));
        $this->assertFalse(Auth::user()->can('care_plan:write'));
        foreach (['view', 'print'] as $action) {
            $this->assertTrue(Auth::user()->can('registeredAction', [ServiceRequestRequest::class, $native, $action]));
        }
        $this->assertTrue(Auth::user()->can('viewRecord', $record));
        $this->refuseRemoteSideEffects();
        $this->mock(ReferralRequestLifecycleService::class)->shouldReceive('buildPrintoutHtml')->twice()
            ->with(Mockery::on(static fn (ServiceRequestRequest $request): bool => $request->id === $record->id), $record->uuid)
            ->andReturn('<p>Registered referral</p>');
        $patient = $this->patientComponent();
        $plan = $this->carePlanComponent();
        $original = MedicalEventsRepository::serviceRequest()->getResource($record);
        $writes = $this->captureWrites();
        foreach ([$patient, $plan] as $component) {
            $this->assertSame('<p>Registered referral</p>', $component->loadReferralPrintoutForm($record->uuid));
        }
        foreach (['view', 'print'] as $action) {
            $index = $this->index($native);
            $index->openRegisteredAction($record->uuid, $action);
            $this->assertSame($this->patientRoute($record, $action), store($index)->get('redirect'));
        }
        $this->assertUnchanged($record, $original, $writes);
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_registered_menu_exposes_read_only_view_and_print(string $status): void
    {
        $native = $this->native();
        $native['status'] = $status;
        $this->retainScopes(['service_request:read', 'care_plan:read', 'encounter:read']);
        $this->refuseRemoteSideEffects();
        Livewire::test(RegisteredReferralMenuHarness::class, ['legalEntity' => $this->context['legalEntity'], 'native' => $this->response($native)->validate()])
            ->assertSee('data-referral-menu="'.$native['id'].'"', false)
            ->assertSee("openRegisteredAction('".$native['id']."', 'view')", false)
            ->assertSee("openRegisteredAction('".$native['id']."', 'print')", false)
            ->assertDontSee("openRegisteredAction('".$native['id']."', 'recall')", false)
            ->assertDontSee("openRegisteredAction('".$native['id']."', 'error')", false);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function terminalStatuses(): array
    {
        return [['completed'], ['recalled'], ['entered_in_error']];
    }

    public function test_registered_menu_without_read_scope_has_no_view_or_print_action(): void
    {
        $native = $this->native();
        $native['status'] = 'completed';
        $this->retainScopes(['care_plan:read', 'encounter:read']);
        $this->context['user']->syncRoles([]);
        $this->actingAs($this->context['user']->fresh());
        $this->assertFalse(Auth::user()->can('service_request:read'));
        $this->refuseRemoteSideEffects();
        Livewire::test(RegisteredReferralMenuHarness::class, ['legalEntity' => $this->context['legalEntity'], 'native' => $this->response($native)->validate()])
            ->assertDontSee('data-referral-menu="'.$native['id'].'"', false)
            ->assertDontSee("openRegisteredAction('".$native['id']."', 'view')", false)
            ->assertDontSee("openRegisteredAction('".$native['id']."', 'print')", false);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_global_view_routes_to_the_authorized_encounter_source_without_api_or_writes(): void
    {
        $episode = Episode::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->context['person']->id,
            'status' => 'active', 'name' => 'Referral episode',
            'managing_organization_id' => Identifier::create(['value' => $this->context['legalEntity']->uuid])->id,
            'care_manager_id' => Identifier::create(['value' => $this->context['employee']->uuid])->id,
            'episode_type_id' => Coding::create(['system' => 'eHealth/episode_types', 'code' => 'treatment'])->id,
        ]);
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->context['person']->id, 'status' => 'finished',
            'episode_id' => Identifier::create(['value' => $episode->uuid])->id,
            'class_id' => Coding::create(['system' => 'eHealth/encounter_classes', 'code' => 'AMB'])->id,
            'type_id' => CodeableConcept::create()->id,
        ]);
        $native = $this->native();
        $native['context'] = NativeClinicalFixtures::reference('encounter', $encounter->uuid);
        $record = $this->persist($native);
        $this->assertTrue(Auth::user()->can('view', [$encounter, $this->context['person']]));
        $this->refuseRemoteSideEffects();
        $writes = $this->captureWrites();
        $index = $this->index($native);
        $index->openRegisteredAction($record->uuid, 'view');
        $this->assertSame(route('persons.encounters.referrals.show', [
            'legalEntity' => $this->context['legalEntity']->id, 'person' => $this->context['person']->id,
            'encounter' => $encounter->id, 'referral' => $record->id,
        ]), store($index)->get('redirect'));
        $this->assertSame([], $writes->all());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_global_view_routes_to_its_care_plan_activity_source_without_api_or_writes(): void
    {
        $component = $this->carePlanComponent();
        $activity = CarePlanActivity::forceCreate([
            'uuid' => (string) Str::uuid(), 'carePlanId' => $component->carePlan->id,
            'kind' => 'service_request', 'status' => 'scheduled', 'detailsSyncedAt' => now(),
        ]);
        $native = $this->native();
        $native['based_on'] = [
            NativeClinicalFixtures::reference('care_plan', $component->carePlan->uuid),
            NativeClinicalFixtures::reference('activity', $activity->uuid),
        ];
        $record = $this->persist($native);
        $this->refuseRemoteSideEffects();
        $writes = $this->captureWrites();
        $index = $this->index($native);
        $index->openRegisteredAction($record->uuid, 'view');
        $this->assertSame(route('care-plans.activities.show', [
            'legalEntity' => $this->context['legalEntity']->id,
            'carePlan' => $component->carePlan->id, 'activity' => $activity->id,
        ]), store($index)->get('redirect'));
        $this->assertSame([], $writes->all());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('statusActions')]
    public function test_another_active_linked_employee_of_the_author_legal_entity_can_open_each_status_action(string $action): void
    {
        $native = $this->native();
        $record = $this->persist($native);
        // TV39 accepts a current colleague; the historical requester UUID is deliberately different.
        $this->assertNotSame($this->context['employee']->uuid, $native['requester_employee']['identifier']['value']);
        $this->assertFalse(Auth::user()->can('service_request:write'));
        $this->assertFalse(Auth::user()->can('care_plan:write'));
        $this->assertTrue(Auth::user()->can('registeredAction', [ServiceRequestRequest::class, $native, $action]));
        $this->assertTrue(Auth::user()->can($action === 'recall' ? 'recall' : 'cancel', $record));
        $this->refuseRemoteSideEffects();
        $patient = $this->patientComponent();
        $plan = $this->carePlanComponent();
        $original = MedicalEventsRepository::serviceRequest()->getResource($record);
        $writes = $this->captureWrites();
        foreach ([$patient, $plan] as $component) {
            $component->{$action === 'recall' ? 'recallReferral' : 'cancelReferral'}($record->uuid, 'service_request');
            $this->assertTrue($component->showSignatureModal);
            $this->assertSame($action === 'recall' ? 'recall_referral' : 'cancel_referral', $component->actionType);
        }
        $index = $this->index($native);
        $index->openRegisteredAction($record->uuid, $action);
        $this->assertSame($this->patientRoute($record, $action), store($index)->get('redirect'));
        $this->assertUnchanged($record, $original, $writes);
    }

    public static function statusActions(): array
    {
        return ['recall' => ['recall'], 'entered in error' => ['error']];
    }

    #[DataProvider('statusActions')]
    public function test_status_actions_use_clinical_status_after_execution_changes_the_display_status(string $action): void
    {
        $record = $this->persist($this->native());
        MedicalEventsRepository::serviceRequest()->applyExecutionResult($record, ['program_processing_status' => 'in_progress'], null);
        $record = $record->fresh();
        $this->assertSame('in_progress', $record->status);
        $this->assertSame('active', $record->clinicalStatus);
        $this->assertTrue(Auth::user()->can($action === 'recall' ? 'recall' : 'cancel', $record));
        $this->refuseRemoteSideEffects();
        $patient = $this->patientComponent();
        $plan = $this->carePlanComponent();
        $before = MedicalEventsRepository::serviceRequest()->getResource($record);
        $writes = $this->captureWrites();
        foreach ([$patient, $plan] as $component) {
            $component->{$action === 'recall' ? 'recallReferral' : 'cancelReferral'}($record->uuid, 'service_request');
            $this->assertTrue($component->showSignatureModal);
        }
        $this->assertUnchanged($record, $before, $writes);
    }

    public static function deniedStatusActions(): array
    {
        $cases = [];
        foreach (['recall', 'error'] as $action) {
            foreach (['foreign_requester', 'missing_scope', 'pending', 'ended', 'future', 'inactive', 'unlinked', 'completed'] as $reason) {
                $cases[$action.' '.$reason] = [$action, $reason];
            }
        }

        return $cases;
    }

    #[DataProvider('deniedStatusActions')]
    public function test_global_patient_and_care_plan_status_guards_refuse_before_api_signing_or_storage(string $action, string $reason): void
    {
        $native = $this->native();
        if ($reason === 'foreign_requester') {
            $native['requester_legal_entity'] = NativeClinicalFixtures::reference('legal_entity', (string) Str::uuid());
        } elseif ($reason === 'completed') {
            $native['status'] = 'completed';
        }
        $record = $this->persist($native);
        $this->denyContext($record, $action, $reason);
        $this->refuseRemoteSideEffects();
        $patient = $this->patientComponent();
        $plan = $this->carePlanComponent();
        $original = MedicalEventsRepository::serviceRequest()->getResource($record);
        $writes = $this->captureWrites();
        $index = $this->index($native);
        $index->openRegisteredAction($record->uuid, $action);
        $this->assertNull(store($index)->get('redirect'));
        $this->assertSame(__('referrals.messages.action_unavailable'), session('error'));
        foreach ([$patient, $plan] as $component) {
            session()->forget('error');
            $component->{$action === 'recall' ? 'recallReferral' : 'cancelReferral'}($record->uuid, 'service_request');
            $this->assertFalse($component->showSignatureModal);
            $this->assertSame(__('referrals.messages.action_unavailable'), session('error'));
            $this->prepareSignature($component, $record, $action);
            session()->forget('error');
            $component->{$action === 'recall' ? 'signRecallReferral' : 'signCancelReferral'}();
            $this->assertSame(__('referrals.messages.action_unavailable'), session('error'));
        }
        $this->assertUnchanged($record, $original, $writes);
    }

    public function test_source_print_and_global_handoff_refuse_a_request_of_another_patient_without_side_effects(): void
    {
        $other = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1999-01-01', 'gender' => 'MALE']);
        $native = $this->native();
        $native['subject'] = NativeClinicalFixtures::reference('patient', $other->uuid);
        $record = $this->persist($native, $other);
        $this->refuseRemoteSideEffects();
        $this->mock(ReferralRequestLifecycleService::class)->shouldNotReceive('buildPrintoutHtml');
        $patient = $this->patientComponent();
        $plan = $this->carePlanComponent();
        $original = MedicalEventsRepository::serviceRequest()->getResource($record);
        $writes = $this->captureWrites();
        try {
            $patient->loadReferralPrintoutForm($record->uuid);
            $this->fail('A forged patient print argument must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertNull($patient->registryPrintUuid);
        }
        $this->assertSame('', $plan->loadReferralPrintoutForm($record->uuid));
        $native['subject'] = NativeClinicalFixtures::reference('patient', $this->context['person']->uuid);
        foreach (['view', 'print', 'recall', 'error'] as $action) {
            $index = $this->index($native);
            $index->openRegisteredAction($record->uuid, $action);
            $this->assertNull(store($index)->get('redirect'));
        }
        $this->assertUnchanged($record, $original, $writes);
    }

    public function test_missing_read_scope_refuses_print_and_global_view_without_side_effects(): void
    {
        $native = $this->native();
        $record = $this->persist($native);
        $this->context['user']->syncRoles([]);
        $this->retainScopes([]);
        $this->refuseRemoteSideEffects();
        $this->mock(ReferralRequestLifecycleService::class)->shouldNotReceive('buildPrintoutHtml');
        $patient = $this->patientComponent();
        $plan = $this->carePlanComponent();
        $original = MedicalEventsRepository::serviceRequest()->getResource($record);
        $writes = $this->captureWrites();
        foreach ([$patient, $plan] as $component) {
            $this->assertSame('', $component->loadReferralPrintoutForm($record->uuid));
        }
        foreach (['view', 'print'] as $action) {
            $index = $this->index($native);
            $index->openRegisteredAction($record->uuid, $action);
            $this->assertNull(store($index)->get('redirect'));
        }
        $this->assertUnchanged($record, $original, $writes);
    }

    public static function refusedFreshSnapshots(): array
    {
        $cases = [];
        foreach (['patient', 'care_plan'] as $source) {
            foreach (['recall', 'error'] as $action) {
                foreach (['malformed', 'wrong_id', 'wrong_subject', 'foreign_requester', 'completed'] as $fault) {
                    $cases[$source.' '.$action.' '.$fault] = [$source, $action, $fault];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('refusedFreshSnapshots')]
    public function test_fresh_snapshot_is_validated_and_reauthorized_before_either_source_can_store_or_sign(string $source, string $action, string $fault): void
    {
        $native = $this->native();
        $record = $this->persist($native);
        $snapshot = $this->faultySnapshot($native, $fault);
        $api = $this->mock(ServiceRequestApi::class);
        $api->shouldReceive('getById')->once()->with($this->context['person']->uuid, $record->uuid, [], true)
            ->andReturn($this->response($snapshot));
        $api->shouldNotReceive('recall', 'cancel');
        $this->mock(SignatureService::class)->shouldNotReceive('signData');
        $component = $source === 'patient' ? $this->patientComponent() : $this->carePlanComponent();
        $this->prepareSignature($component, $record, $action);
        $original = MedicalEventsRepository::serviceRequest()->getResource($record);
        $writes = $this->captureWrites();
        try {
            $component->{$action === 'recall' ? 'signRecallReferral' : 'signCancelReferral'}();
        } catch (ValidationException $exception) {
            // Patient actions keep local validation errors visible; CP actions flash the refusal.
            $this->assertNotEmpty($exception->errors());
        }
        if ($fault === 'foreign_requester') {
            $this->assertSame(__('referrals.messages.action_unavailable'), session('error'));
        }
        $this->assertUnchanged($record, $original, $writes);
    }

    #[DataProvider('freshHandoffFaults')]
    public function test_global_handoff_cannot_import_a_malformed_or_mismatched_fresh_snapshot(string $fault): void
    {
        $native = $this->native();
        $snapshot = $this->faultySnapshot($native, $fault);
        $api = $this->mock(ServiceRequestApi::class);
        $api->shouldReceive('getById')->once()->with($this->context['person']->uuid, $native['id'], [], true)
            ->andReturn($this->response($snapshot));
        $api->shouldNotReceive('recall', 'cancel');
        $this->mock(SignatureService::class)->shouldNotReceive('signData');
        $writes = $this->captureWrites();
        $index = $this->index($native);
        $index->openRegisteredAction($native['id'], 'recall');
        $this->assertNull(store($index)->get('redirect'));
        $this->assertNull(MedicalEventsRepository::serviceRequest()->findByUuid($native['id']));
        $this->assertSame([], $writes->all());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function freshHandoffFaults(): array
    {
        return array_map(static fn (string $fault): array => [$fault], ['malformed', 'wrong_id', 'wrong_subject', 'foreign_requester', 'completed']);
    }

    private function native(): array
    {
        $native = [
            'id' => (string) Str::uuid(), 'requisition' => '1234-5678-9012-3456', 'status' => 'active',
            'status_history' => [], 'intent' => 'order', 'priority' => 'routine',
            'category' => ['coding' => [['system' => 'eHealth/SNOMED/service_request_categories', 'code' => 'counselling']]],
            'code' => NativeClinicalFixtures::reference('service'),
            'subject' => NativeClinicalFixtures::reference('patient', $this->context['person']->uuid),
            'requester_employee' => NativeClinicalFixtures::reference('employee', (string) Str::uuid()),
            'requester_legal_entity' => NativeClinicalFixtures::reference('legal_entity', $this->context['legalEntity']->uuid),
            'used_by_employee' => ['display_value' => ''], 'used_by_legal_entity' => ['display_value' => ''],
            'reason_reference' => [], 'note' => '', 'patient_instruction' => '',
            'expiration_date' => now()->addYear()->toIso8601String(),
            'occurrence_period' => ['start' => now()->toIso8601String()],
        ];

        return $this->response($native)->validate();
    }

    private function response(array $native): EHealthResponse
    {
        return new EHealthResponse(
            new Response(200, [], json_encode(['data' => $native], JSON_THROW_ON_ERROR)),
            static fn (EHealthResponse $response): array => ServiceRequestApi::validateResourceData($response->getData())
        );
    }

    private function persist(array $native, ?Person $person = null): ServiceRequestRequest
    {
        $native = $this->response($native)->validate();

        return MedicalEventsRepository::serviceRequest()->importResource(
            (new ServiceRequestMapper())->normalize($native),
            $person ?? $this->context['person'],
            $this->context['employee']->id
        );
    }

    private function patientComponent(): PatientReferrals
    {
        $component = new PatientReferrals();
        $component->setId((string) Str::uuid());
        $component->setName('person.records.patient-referrals');
        $component->personId = $this->context['person']->id;
        $component->uuid = $this->context['person']->uuid;
        // Credentials are already validated here so these tests reach the action's authorization boundary.
        $form = Mockery::mock(BaseForm::class, [$component, 'form'])->makePartial();
        $form->shouldReceive('signingRules')->andReturn([]);
        $form->shouldReceive('validate')->andReturn(['password' => 'synthetic', 'knedp' => 'synthetic', 'keyContainerUpload' => 'synthetic']);
        $component->form = $form;

        return $component;
    }

    private function carePlanComponent(): CarePlanActivityShow
    {
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['id'] = (string) Str::uuid();
        $plan = Repository::carePlan()->storeResource($resource, $this->context['person']->id, $this->context['legalEntity']->id);
        $component = new CarePlanActivityShow();
        $component->setId((string) Str::uuid());
        $component->setName('care-plan.activity.show.care-plan-activity-show');
        $component->carePlan = $plan;

        return $component;
    }

    private function index(array $native): ReferralIndex
    {
        $component = new ReferralIndex();
        $component->setId((string) Str::uuid());
        $component->setName('referral.referral-index');
        $component->mount($this->context['legalEntity']);
        $component->searchResults = [$native];

        return $component;
    }

    private function prepareSignature(PatientReferrals|CarePlanActivityShow $component, ServiceRequestRequest $record, string $action): void
    {
        $component->showSignatureModal = true;
        $component->actionType = $action === 'recall' ? 'recall_referral' : 'cancel_referral';
        $component->referralExplanatoryLetter = 'Synthetic status correction';
        if ($component instanceof PatientReferrals) {
            $component->requestIdToSign = $record->uuid;
            $component->referralStatusReason = 'incorrect_data';
        } else {
            $component->referralRequestIdToSign = $record->uuid;
            $component->statusReason = 'incorrect_data';
        }
    }

    private function denyContext(ServiceRequestRequest $record, string $action, string $reason): void
    {
        $employee = $this->context['employee'];
        match ($reason) {
            'missing_scope' => $this->retainScopes(['service_request:read', 'care_plan:read', 'encounter:read', $action === 'recall' ? 'service_request:cancel' : 'service_request:recall']),
            'pending' => $record->ehealthLinks()->create(['url' => 'https://ehealth.invalid/jobs/pending', 'method' => 'GET', 'status' => JobStatus::PENDING->value]),
            'ended' => $employee->update(['end_date' => today()->toDateString()]),
            'future' => $employee->update(['start_date' => today()->addDay()->toDateString()]),
            'inactive' => $employee->update(['is_active' => false]),
            'unlinked' => $this->context['user']->employees()->detach($employee->id),
            default => null,
        };
        $this->actingAs($this->context['user']->fresh());
    }

    private function retainScopes(array $scopes): void
    {
        $this->context['user']->syncPermissionsParent($scopes);
        $this->actingAs($this->context['user']->fresh());
    }

    private function faultySnapshot(array $native, string $fault): array
    {
        if ($fault === 'malformed') {
            unset($native['expiration_date']);
        } elseif ($fault === 'wrong_id') {
            $native['id'] = (string) Str::uuid();
        } elseif ($fault === 'wrong_subject') {
            $native['subject'] = NativeClinicalFixtures::reference('patient', (string) Str::uuid());
        } elseif ($fault === 'foreign_requester') {
            $native['requester_legal_entity'] = NativeClinicalFixtures::reference('legal_entity', (string) Str::uuid());
        } elseif ($fault === 'completed') {
            $native['status'] = 'completed';
        }

        return $native;
    }

    private function refuseRemoteSideEffects(): void
    {
        $this->mock(ServiceRequestApi::class)->shouldNotReceive('getById', 'recall', 'cancel');
        $this->mock(SignatureService::class)->shouldNotReceive('signData');
    }

    private function patientRoute(ServiceRequestRequest $record, string $action): string
    {
        return route('persons.referrals', [
            'legalEntity' => $this->context['legalEntity']->id, 'person' => $this->context['person']->id,
            'referral' => $record->uuid, 'referral_action' => $action,
        ]);
    }

    private function captureWrites(): Collection
    {
        $writes = collect();
        DB::listen(static function (QueryExecuted $query) use ($writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writes->push($query->sql);
            }
        });

        return $writes;
    }

    private function assertUnchanged(ServiceRequestRequest $record, array $original, Collection $writes): void
    {
        $this->assertSame([], $writes->all());
        $this->assertSame($original, MedicalEventsRepository::serviceRequest()->getResource($record->fresh()));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}

class RegisteredReferralMenuHarness extends ReferralIndex
{
    public function mount(LegalEntity $legalEntity, array $native = []): void
    {
        parent::mount($legalEntity);
        // Seed an already validated server result without updating the client's locked search state.
        $this->searchResults = [$native];
    }
}
