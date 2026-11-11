<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Person as ApiPerson;
use App\Livewire\CarePlan\CarePlanApprovals;
use App\Livewire\CarePlan\CarePlanShow;
use App\Livewire\CarePlan\Forms\CarePlanActivityForm;
use App\Livewire\Components\XMessage;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Permission;
use App\Models\Person\Person;
use App\Models\Role as RoleModel;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Services\MedicalEvents\CarePlanActivityValidationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\TestCase;

class CarePlanBusinessRulesTest extends TestCase
{
    use CompositionTestFixtures;
    use CreatesFhirCarePlans;
    use DatabaseTransactions;

    private CarePlan $plan;
    private Employee $employee;
    private User $user;
    private Person $person;
    private LegalEntity $legalEntity;
    private Encounter $encounter;
    private string $serviceUuid;

    protected function setUp(): void
    {
        parent::setUp();
        ['employee' => $this->employee, 'user' => $this->user, 'person' => $this->person, 'legalEntity' => $this->legalEntity] = $this->compositionFixture();
        $this->grantMedicalEventAbilities($this->user, ['encounter:read']);
        $this->grantCarePlanEmployeeRole($this->employee, 'OUTPATIENT');
        $episode = Episode::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'active', 'name' => 'Rehabilitation episode',
            'managing_organization_id' => Identifier::create(['value' => $this->legalEntity->uuid])->id]);
        $this->encounter = Encounter::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'finished',
            'episode_id' => Identifier::create(['value' => $episode->uuid])->id,
            'performer_id' => Identifier::create(['value' => $this->employee->uuid])->id,
            'type_id' => CodeableConcept::create()->id,
            'class_id' => Coding::create(['system' => 'eHealth/encounter_classes', 'code' => 'AMB'])->id]);
        $this->plan = $this->createFhirCarePlan(['person_id' => $this->person->id, 'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id, 'encounter_id' => $this->encounter->id, 'status' => 'new',
            'category' => 'class_23', 'title' => 'Rehabilitation plan', 'period_end' => now()->addMonth()->toDateString()]);
        $this->grantCarePlanWriteApproval($this->plan, $this->employee);
        $this->serviceUuid = (string) Str::uuid();
        Http::preventStrayRequests();
        Cache::put('knedp_certificate_authority', [], 60);
    }

    public function test_approval_form_preserves_explicit_actor_and_never_substitutes_an_unavailable_actor(): void
    {
        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $this->plan,
            'actingEmployeeUuid' => $this->employee->uuid])
            ->assertSet('newApproval.employee_uuid', $this->employee->uuid);
        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $this->plan,
            'actingEmployeeUuid' => (string) Str::uuid()])
            ->assertSet('newApproval.employee_uuid', '');
        Http::assertNothingSent();
    }

    public function test_approval_form_mount_denial_is_visible_before_loading_context(): void
    {
        $this->withoutVite();
        $foreign = LegalEntity::create(['uuid' => (string) Str::uuid(), 'status' => 'ACTIVE',
            'legal_entity_type_id' => $this->legalEntity->legalEntityTypeId]);
        $this->plan->update(['legal_entity_id' => $foreign->id]);
        Queue::fake();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $component = Livewire::test(CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $this->plan,
            'actingEmployeeUuid' => $this->employee->uuid])
            ->assertSee(__('care-plan.approval_access_denied'))->assertSet('carePlanId', 0)
            ->assertSet('employees', [])->assertSet('approvals', [])->assertSet('isReadOnly', true)
            ->assertDontSeeHtml('wire:init="loadAuthMethods"');
        $this->assertSame([], array_filter($queries, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)\b/i', $sql) === 1));

        $queries = [];
        $component->call('loadAuthMethods')->assertStatus(200)
            ->assertSet('carePlanId', 0)->assertSet('authMethods', [])
            ->assertSet('employees', [])->assertSet('approvals', [])
            ->assertDontSeeHtml('wire:init="loadAuthMethods"');
        $this->assertSame([], $queries);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_allowed_auth_method_initialization_preserves_the_selected_actor(): void
    {
        $methodUuid = (string) Str::uuid();
        $requestedUrls = [];
        $api = new ApiPerson();
        $api->preventStrayRequests();
        $api->stub(static function (Request $request) use (&$requestedUrls, $methodUuid) {
            $requestedUrls[] = $request->url();

            // GENERAL MIS 9.15.9 requires provenance on each authentication method.
            return Http::response(['data' => [[
                'id' => $methodUuid, 'type' => 'OTP', 'phone_number' => '+38093*****85',
                'inserted_by' => 'af0264f5-438c-4773-9960-1c3e49a9a0ed',
                'inserted_at' => '2026-01-01T00:00:00Z',
            ]]]);
        });
        $this->instance(ApiPerson::class, $api);
        Queue::fake();

        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $this->plan,
            'actingEmployeeUuid' => $this->employee->uuid])
            ->assertSeeHtml('wire:init="loadAuthMethods"')->call('loadAuthMethods')->assertStatus(200)
            ->assertSet('selectedAuthMethodUuid', $methodUuid)
            ->assertSet('newApproval.employee_uuid', $this->employee->uuid);
        $this->assertCount(1, $requestedUrls);
        $this->assertStringEndsWith('/api/persons/'.$this->person->uuid.'/authentication_methods', $requestedUrls[0]);
        Queue::assertNothingPushed();
    }

    public function test_auth_method_initialization_rechecks_permission_after_mount(): void
    {
        $component = Livewire::test(CarePlanApprovals::class, ['legalEntity' => $this->legalEntity, 'carePlan' => $this->plan,
            'actingEmployeeUuid' => $this->employee->uuid])->assertSet('isReadOnly', false);
        RoleModel::findByName($this->employee->employeeType, 'web')->revokePermissionTo('care_plan:write');
        $this->user->revokePermissionTo('care_plan:write');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();
        $this->actingAs($this->user);
        $this->assertFalse($this->user->can('manage', $this->plan));
        Queue::fake();
        $before = $this->plan->fresh()->getAttributes();

        $component->call('loadAuthMethods')->assertStatus(200)
            ->assertSee(__('care-plan.approval_access_denied'))
            ->assertSet('authMethods', [])->assertSet('selectedAuthMethodUuid', null)
            ->assertSet('newApproval.employee_uuid', $this->employee->uuid)
            ->assertNotDispatched('care-plan-approvals-changed');
        $this->assertSame($before, $this->plan->fresh()->getAttributes());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_registered_referral_modals_use_referral_permissions_after_the_plan_closes(): void
    {
        $activity = Repository::carePlanActivity()->saveForm($this->draftData(), $this->plan);
        $request = $this->confirmedReferral($activity);
        $this->plan->update(['status' => 'completed']);
        $this->useRegisteredReferralPermissions(['service_request:recall', 'service_request:cancel']);
        $this->assertFalse($this->user->can('care_plan:write'));
        Queue::fake();

        foreach (['recall_referral', 'cancel_referral'] as $action) {
            $this->assertTrue($this->user->can($action === 'recall_referral' ? 'recall' : 'cancel', $request));
            $component = new CarePlanShow();
            $component->carePlan = $this->plan->fresh();
            $component->openSignatureModal($action, null, $request->uuid);
            $this->assertTrue($component->showSignatureModal);
            $this->assertSame($action, $component->actionType);
            $this->assertSame($request->uuid, $component->referralRequestIdToSign);
        }
        $this->assertSame('active', $request->fresh()->status);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_registered_referral_modal_denial_is_visible_before_changing_form_state(): void
    {
        $activity = Repository::carePlanActivity()->saveForm($this->draftData(), $this->plan);
        $request = $this->confirmedReferral($activity);
        $this->useRegisteredReferralPermissions([]);
        Queue::fake();

        $component = new CarePlanShow();
        $component->carePlan = $this->plan;
        $component->statusReason = 'existing-reason';
        $component->referralExplanatoryLetter = 'Existing letter';
        $component->openSignatureModal('recall_referral', null, $request->uuid);
        $this->assertFalse($component->showSignatureModal);
        $this->assertSame('', $component->actionType);
        $this->assertSame('existing-reason', $component->statusReason);
        $this->assertSame('Existing letter', $component->referralExplanatoryLetter);
        Livewire::test(XMessage::class, ['consumeMessages' => true])
            ->assertSee(__('referrals.messages.action_unavailable'));
        $this->assertSame('active', $request->fresh()->status);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_registered_referral_modal_rejects_another_patients_record(): void
    {
        $activity = Repository::carePlanActivity()->saveForm($this->draftData(), $this->plan);
        $request = $this->confirmedReferral($activity);
        $other = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
        $request->update(['person_id' => $other->id]);
        $this->useRegisteredReferralPermissions(['service_request:recall']);
        Queue::fake();

        $component = new CarePlanShow();
        $component->carePlan = $this->plan;
        $component->openSignatureModal('recall_referral', null, $request->uuid);
        $this->assertFalse($component->showSignatureModal);
        $this->assertSame('', $component->actionType);
        Livewire::test(XMessage::class, ['consumeMessages' => true])
            ->assertSee(__('care-plan.document_context_unavailable'));
        $this->assertSame('active', $request->fresh()->status);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function useRegisteredReferralPermissions(array $scopes): void
    {
        $this->withoutVite();
        $permissions = ['care_plan:read', 'service_request:read', ...$scopes];
        foreach ($permissions as $scope) {
            Permission::findOrCreate($scope, 'web')->legalEntityTypes()
                ->syncWithoutDetaching([$this->legalEntity->legalEntityTypeId]);
        }
        RoleModel::findByName($this->employee->employeeType, 'web')->syncPermissions($permissions);
        Cache::memo()->forget('allowed_permissions:'.$this->legalEntity->id.':web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();
        $this->actingAs($this->user);
        $this->user->syncPermissionsParent($permissions);
        $dictionaries = Cache::get(BasicDictionary::KEY, []);
        foreach (['recall', 'cancel'] as $operation) {
            $dictionaries[] = ['name' => 'eHealth/service_request_'.$operation.'_reasons',
                'values' => [['code' => 'incorrect_data', 'description' => 'Помилкові дані', 'is_active' => true]]];
        }
        Cache::forever(BasicDictionary::KEY, $dictionaries);
        Cache::forever(BasicDictionary::KEY.':fresh', true);
    }

    private function draftData(string $unit = 'PIECE'): array
    {
        return ['kind' => 'service_request', 'status' => 'draft', 'quantity' => 45, 'quantity_system' => 'SERVICE_UNIT', 'quantity_code' => $unit,
            'product_reference' => $this->serviceUuid, 'product_reference_type' => 'service', 'author_id' => $this->employee->id,
            'scheduled_period_start' => now()->toDateString(), 'scheduled_period_end' => now()->addDay()->toDateString()];
    }

    private function confirmedReferral(CarePlanActivity $activity, ?string $uuid = null, ?string $serviceUuid = null): ServiceRequestRequest
    {
        $ref = static fn (string $type, string $value): array => ['identifier' => ['value' => $value,
            'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]]]];

        return MedicalEventsRepository::serviceRequest()->storeResource([
            'id' => $uuid ?? (string) Str::uuid(), 'status' => 'active', 'intent' => 'order', 'requisition' => '1234-5678-9012-3456',
            'code' => $ref('service', $serviceUuid ?? $this->serviceUuid), 'subject' => $ref('patient', $this->person->uuid),
            'requesterEmployee' => $ref('employee', $this->employee->uuid), 'requesterLegalEntity' => $ref('legal_entity', $this->legalEntity->uuid),
            'basedOn' => [$ref('care_plan_activity', $activity->uuid)],
            'quantity' => ['value' => 999, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE'],
        ], $this->person->id, $this->employee->id);
    }

    public function test_five_activities_are_allowed_and_sixth_is_denied_even_after_cancellation_and_completion(): void
    {
        $repository = Repository::carePlanActivity();
        for ($i = 0; $i < 5; $i++) {
            $activity = $repository->saveForm($this->draftData(), $this->plan);
            if ($i < 2) {
                // Historical terminal records still consume one created activity each.
                $activity->update(['status' => $i === 0 ? 'cancelled' : 'completed']);
            }
        }
        $this->assertSame(['limit' => 5, 'created' => 5, 'confirmedReferrals' => 0, 'remaining' => 0], $repository->serviceCreationCapacity($this->plan, $this->serviceUuid));
        try {
            $repository->saveForm($this->draftData(), $this->plan);
            $this->fail('The sixth activity must not be persisted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('activityForm.product_reference', $exception->errors());
        }
        $this->assertSame(5, CarePlanActivity::whereCarePlanId($this->plan->id)->count());
        Http::assertNothingSent();
    }

    public function test_each_distinct_confirmed_referral_on_the_same_activity_adds_one_slot_and_sync_is_idempotent(): void
    {
        $repository = Repository::carePlanActivity();
        $activity = $repository->saveForm($this->draftData(), $this->plan);
        $first = $this->confirmedReferral($activity);
        $this->confirmedReferral($activity, $first->uuid);
        $this->confirmedReferral($activity);
        $this->assertSame(2, $repository->serviceCreationCapacity($this->plan, $this->serviceUuid)['confirmedReferrals']);
        $this->assertSame(7, $repository->serviceCreationCapacity($this->plan, $this->serviceUuid)['limit']);
        for ($i = 1; $i < 7; $i++) {
            $repository->saveForm($this->draftData(), $this->plan);
        }
        $this->assertSame(0, $repository->serviceCreationCapacity($this->plan, $this->serviceUuid)['remaining']);
        $first->update(['status' => 'recalled', 'clinical_status' => 'recalled']);
        $this->assertSame(7, $repository->serviceCreationCapacity($this->plan, $this->serviceUuid)['limit']);
    }

    public function test_local_or_unconfirmed_referrals_and_other_services_or_plans_add_no_slot(): void
    {
        $repository = Repository::carePlanActivity();
        $activity = $repository->saveForm($this->draftData(), $this->plan);
        foreach (['draft', 'pending', 'processing', 'failed'] as $status) {
            $request = $this->confirmedReferral($activity);
            $request->forceFill(['status' => $status, 'clinical_status' => $status])->save();
        }
        $local = $this->confirmedReferral($activity);
        $local->update(['details_synced_at' => null]);
        $this->confirmedReferral($activity, serviceUuid: (string) Str::uuid());
        $otherPlan = $this->createFhirCarePlan(['person_id' => $this->person->id, 'author_id' => $this->employee->id, 'legal_entity_id' => $this->legalEntity->id]);
        $otherActivity = $repository->saveForm($this->draftData(), $otherPlan);
        $this->confirmedReferral($otherActivity);
        $this->assertSame(0, $repository->serviceCreationCapacity($this->plan, $this->serviceUuid)['confirmedReferrals']);
    }

    public function test_minutes_require_exact_officio_speciality_and_the_encounter_author_and_survive_signed_payload_conversion(): void
    {
        $validator = app(CarePlanActivityValidationService::class);
        $this->assertFalse($validator->allowsServiceMinutes($this->plan));
        $speciality = $this->employee->specialities()->create(['speciality' => 'PHYSICAL_MEDICINE_AND_REHABILITATION', 'speciality_officio' => true,
            'level' => 'SPECIALIST', 'attestation_name' => 'Synthetic certificate', 'attestation_date' => today()->toDateString(), 'certificate_number' => 'TEST-BA01']);
        $this->assertTrue($validator->allowsServiceMinutes($this->plan));
        $repository = Repository::carePlanActivity();
        $activity = $repository->saveForm($this->draftData('MINUTE'), $this->plan);
        $payload = CarePlanActivityForm::buildCreateContent($repository->getResource($activity));
        $this->assertSame(['value' => 45, 'system' => 'SERVICE_UNIT', 'code' => 'MINUTE'], $payload['detail']['quantity']);
        $speciality->update(['speciality_officio' => false]);
        $this->assertFalse($validator->allowsServiceMinutes($this->plan));
        $speciality->update(['speciality_officio' => true, 'speciality' => 'PHYSICAL_THERAPY']);
        $this->assertFalse($validator->allowsServiceMinutes($this->plan));
        $speciality->update(['speciality' => 'PHYSICAL_MEDICINE_AND_REHABILITATION']);
        $speciality->update(['valid_to_date' => today()->subDay()->toDateString()]);
        $this->assertFalse($validator->allowsServiceMinutes($this->plan));
        $speciality->update(['valid_to_date' => null]);
        $this->encounter->update(['performer_id' => Identifier::create(['value' => (string) Str::uuid()])->id]);
        $this->assertFalse($validator->allowsServiceMinutes($this->plan));
        $this->expectException(ValidationException::class);
        $validator->assertServiceUnit($this->plan, 'MINUTE');
    }

    public function test_forged_minutes_are_visible_and_never_persisted_for_an_ineligible_author(): void
    {
        $this->plan = $this->createFhirCarePlan(['person_id' => $this->person->id, 'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id, 'encounter_id' => $this->encounter->id, 'status' => 'new',
            'category' => 'TEST', 'period_end' => now()->addMonth()->toDateString()]);
        $this->grantCarePlanWriteApproval($this->plan, $this->employee);
        Livewire::test(CarePlanShow::class, ['carePlan' => $this->plan])
            ->call('initActivityForm', 'service_request')->assertDontSeeHtml('<option value="MINUTE">')
            ->set('activityForm.product_reference', $this->serviceUuid)
            ->set('activityForm.quantity_code', 'MINUTE')->call('saveActivity')
            ->assertHasErrors('activityForm.quantity_code')->assertSee(__('care-plan.service_minutes_unavailable'));
        $this->assertSame(0, CarePlanActivity::whereCarePlanId($this->plan->id)->count());
        Http::assertNothingSent();
    }

    public function test_encounter_document_search_rechecks_approval_and_preserves_zero_native_remaining_quantity_and_units(): void
    {
        $repository = Repository::carePlan();
        $activityRepository = Repository::carePlanActivity();
        $activity = $activityRepository->saveForm($this->draftData(), $this->plan);
        $resource = $activityRepository->getResource($activity);
        $resource['detail']['status'] = 'scheduled';
        $resource['detail']['remainingQuantity'] = ['value' => 0, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE', 'unit' => 'шт.'];
        $activityRepository->storeResource($resource, $this->plan, $activity->id);
        $plans = $repository->documentPlanChoices($this->person->id, $this->encounter->uuid, $this->user, 'Rehabilitation');
        $this->assertSame($this->plan->id, $plans[0]['id']);
        $this->assertFalse($plans[0]['approvalRequired']);
        $choices = $repository->documentActivityChoices($this->plan->id, $this->person->id, $this->encounter->uuid, $this->user, 'service_request');
        $this->assertSame($activity->id, $choices[0]['id']);
        $this->assertEquals($resource['detail']['remainingQuantity'], $choices[0]['remainingQuantity']);
        $this->assertSame(0.0, $choices[0]['remainingQuantity']['value']);
        $this->plan->approvals()->update(['is_verified' => false]);
        $this->assertTrue($repository->documentPlanChoices($this->person->id, $this->encounter->uuid, $this->user)[0]['approvalRequired']);
        $this->assertSame([], $repository->documentActivityChoices($this->plan->id, $this->person->id, $this->encounter->uuid, $this->user, 'service_request'));
        $this->assertSame([], $repository->documentPlanChoices($this->person->id, (string) Str::uuid(), $this->user));
        Http::assertNothingSent();
    }

    public function test_outpatient_service_period_is_required_before_draft_persistence(): void
    {
        $data = $this->draftData();
        unset($data['scheduled_period_end']);
        try {
            Repository::carePlanActivity()->saveForm($data, $this->plan);
            $this->fail('A service without a bounded period must not be saved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('activityForm.scheduled_period_end', $exception->errors());
        }
        $this->assertSame(0, CarePlanActivity::whereCarePlanId($this->plan->id)->count());
        Http::assertNothingSent();
    }

    public function test_other_encounter_and_activity_authors_do_not_hide_a_plan_with_write_approval(): void
    {
        $repository = Repository::carePlanActivity();
        $activity = $repository->saveForm($this->draftData(), $this->plan);
        $resource = $repository->getResource($activity);
        $resource['detail']['status'] = 'scheduled';
        $resource['author'][0]['identifier']['value'] = (string) Str::uuid();
        $repository->storeResource($resource, $this->plan, $activity->id);
        $this->encounter->update(['performer_id' => Identifier::create(['value' => (string) Str::uuid()])->id]);
        $plans = Repository::carePlan()->documentPlanChoices($this->person->id, $this->encounter->uuid, $this->user, actingEmployeeUuid: $this->employee->uuid);
        $this->assertFalse($plans[0]['approvalRequired']);
        $choices = Repository::carePlan()->documentActivityChoices($this->plan->id, $this->person->id, $this->encounter->uuid, $this->user, 'service_request', $this->employee->uuid);
        $this->assertSame($activity->id, $choices[0]['id']);
        $this->assertSame([], Repository::carePlan()->documentPlanChoices($this->person->id, $this->encounter->uuid, $this->user, actingEmployeeUuid: (string) Str::uuid()));
        Http::assertNothingSent();
    }
}
