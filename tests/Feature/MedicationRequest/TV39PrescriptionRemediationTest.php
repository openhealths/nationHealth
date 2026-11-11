<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Classes\eHealth\Api\Drug as DrugApi;
use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Classes\eHealth\Api\Patient\MedicationRequestRequest as MedicationRequestRequestApi;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Enums\User\Role;
use App\Livewire\CarePlan\CarePlanApprovals;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterEPrescription;
use App\Livewire\EncounterPackage\Concerns\ResolvesEncounterStandaloneContext;
use App\Livewire\Person\Records\PatientMedicationRequests;
use App\Livewire\Person\Records\PatientMedicationRequestView;
use App\Livewire\Person\Records\PatientPrescriptionRequests;
use App\Livewire\Person\Records\PatientPrescriptionRequestView;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Policies\EncounterPolicy;
use App\Repositories\CarePlanRepository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class TV39PrescriptionRemediationTest extends TestCase
{
    use CompositionTestFixtures;
    use CreatesFhirCarePlans;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    private array $context;
    private array $fixtures;
    private Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('uk');
        $this->context = $this->compositionFixture(role: Role::SPECIALIST, scopes: [
            'encounter:read', 'medication_request_request:read', 'medication_request_request:write',
            'medication_request_request:sign', 'medication_request_request:reject',
            'medication_request:read', 'medication_request:reject', 'medication_request:resend',
            'medication_request:details', 'medication_dispense:read',
        ]);
        $this->fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/medication-official-responses.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->fakeSignatureService();
        Cache::forever(MedicalProgramDictionary::KEY, []);
        Cache::forever(MedicalProgramDictionary::KEY.':fresh', true);
        Http::preventStrayRequests();
        $this->encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->context['person']->id, 'status' => 'finished',
            'performer_id' => Identifier::create(['value' => $this->context['employee']->uuid])->id,
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'class_id' => Coding::firstOrCreate(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create()->id,
        ]);
        $this->encounter->period()->create(['start' => now()->startOfDay()->utc(), 'end' => now()->utc()]);
        $this->partialMock(EncounterPolicy::class)->shouldReceive('view')->andReturn(Response::allow());
    }

    private function native(bool $request = true): array
    {
        $native = $this->fixtures[$request ? 'request_detail' : 'detail'];
        $native['id'] = (string) Str::uuid();
        $native['person']['id'] = $this->context['person']->uuid;
        $native['employee']['id'] = $this->context['employee']->uuid;
        $native['legal_entity']['id'] = $this->context['legalEntity']->uuid;
        $native['context']['identifier']['value'] = $this->encounter->uuid;

        return $native;
    }

    private function store(array $native, bool $request = true): MedicationRequestRequest
    {
        return MedicalEventsRepository::medicationRequest()->storeResource(
            (new MedicationRequestMapper())->fromEHealth($native),
            $this->context['person']->id,
            employeeId: $this->context['employee']->id,
            resourceType: $request ? MedicationRequestRequest::TYPE_REQUEST : MedicationRequestRequest::TYPE_PRESCRIPTION,
            native: $native
        );
    }

    private function parameters(string $id): array
    {
        return ['legalEntity' => $this->context['legalEntity'], 'person' => $this->context['person'], 'preperson' => null, 'requestId' => $id];
    }

    private function fakeApis(array $routes): void
    {
        Http::fake($routes);
        $factory = Http::getFacadeRoot();
        $callbacks = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        foreach ([MedicationRequestApi::class, MedicationRequestRequestApi::class, PersonApi::class, DrugApi::class] as $class) {
            $api = new $class($factory);
            $api->stub($callbacks);
            $api->preventStrayRequests();
            $this->instance($class, $api);
        }
    }

    private function response(array $data, ?int $page = null, int $totalPages = 1): mixed
    {
        $body = ['data' => $data];
        if ($page !== null) {
            $body['paging'] = ['page_number' => $page, 'page_size' => 1, 'total_entries' => $totalPages, 'total_pages' => $totalPages];
        }

        return Http::response(json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    #[DataProvider('validityStates')]
    public function test_validity_uses_native_resource_status_without_certifying_a_pending_request(bool $request, string $status, string $message): void
    {
        $native = $this->native($request);
        $record = $this->store($native, $request);
        $native['status'] = $status;
        $this->fakeApis(['*' => $this->response($native)]);

        Livewire::test($request ? PatientPrescriptionRequestView::class : PatientMedicationRequestView::class, $this->parameters($record->uuid))
            ->call('checkValidity')->assertSee(__('medication-requests.'.$message))
            ->assertSet('detail.status', $status);

        $this->assertSame($status, $record->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $http): bool => $http->method() === 'GET');
    }

    public static function validityStates(): array
    {
        return [
            'new request' => [true, 'NEW', 'validity_unconfirmed'],
            'signed request' => [true, 'SIGNED', 'validity_unconfirmed'],
            'rejected request' => [true, 'REJECTED', 'validity_rejected'],
            'active prescription' => [false, 'ACTIVE', 'validity_active'],
            'rejected prescription' => [false, 'REJECTED', 'validity_rejected'],
        ];
    }

    public function test_denied_validity_check_has_visible_feedback_without_http_or_storage_changes(): void
    {
        $native = $this->native(false);
        $record = $this->store($native, false);
        $component = Livewire::test(PatientMedicationRequestView::class, $this->parameters($record->uuid));
        $this->denyScope('medication_request:read');

        $component->call('checkValidity')->assertSee(__('medication-requests.permission_denied'));
        $this->assertEquals($native, $record->fresh()->ehealthPayload);
        Http::assertNothingSent();
    }

    public function test_validity_rejects_malformed_or_other_patient_data_before_storage(): void
    {
        $native = $this->native(false);
        $record = $this->store($native, false);
        foreach (['malformed', 'other_patient'] as $case) {
            $bad = $native;
            if ($case === 'malformed') {
                unset($bad['medication_info']['medication_qty']);
            } else {
                $bad['person']['id'] = (string) Str::uuid();
            }
            $this->fakeApis(['*' => $this->response($bad)]);
            Livewire::test(PatientMedicationRequestView::class, $this->parameters($record->uuid))
                ->call('checkValidity')->assertSee(__('medication-requests.detail_unavailable'));
            $this->assertEquals($native, $record->fresh()->ehealthPayload);
        }
    }

    public function test_legacy_prescription_route_serves_the_same_two_tab_registry(): void
    {
        Livewire::test(PatientPrescriptionRequests::class, $this->parameters(''))
            ->assertSee('Заявки на електронний рецепт')->assertSee('Електронні рецепти')
            ->assertSet('activeTab', 'requests')->call('switchTab', 'prescriptions')
            ->assertSet('activeTab', 'prescriptions');
    }

    public function test_new_request_survives_navigation_and_opens_signing_without_a_second_create(): void
    {
        $native = $this->native();
        $record = $this->store($native);
        $this->mock(MedicationRequestLifecycleService::class)->shouldNotReceive('createEncounterDraft');
        Livewire::test(PatientPrescriptionRequestView::class, $this->parameters($record->uuid))
            ->assertSee($native['request_number'])->assertSee($native['medication_info']['medication_name'])
            ->call('openSigning')->assertSet('showSignatureModal', true)
            ->call('sign')->assertHasErrors('form.password')->assertSet('showSignatureModal', true)
            ->assertSee(__('forms.validation_error'));
        Livewire::test(PatientPrescriptionRequestView::class, $this->parameters($record->uuid))
            ->call('openSigning')->assertSet('requestId', $record->uuid)->assertSet('showSignatureModal', true);
        $this->assertDatabaseCount('medication_request_requests', 1);
        Http::assertNothingSent();
    }

    public function test_new_reject_after_open_signing_uses_unsigned_patch_and_documented_new_echo(): void
    {
        $native = $this->native();
        $record = $this->store($native);
        $echo = $this->fixtures['request_reject'];
        $echo['id'] = $record->uuid;
        $this->fakeApis(['*medication_request_requests/*' => $this->response($echo)]);
        Livewire::test(PatientPrescriptionRequestView::class, $this->parameters($record->uuid))
            ->call('openSigning')->assertSet('showSignatureModal', true)
            ->call('reject')->assertSet('showSignatureModal', false)
            ->assertSet('detail.status', 'rejected');
        $this->assertSame('rejected', $record->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/api/medication_request_requests/'.$record->uuid.'/actions/reject'));
    }

    public function test_clinical_uuid1_and_existing_external_identity_survive_storage_and_refresh(): void
    {
        $native = $this->native();
        $existingId = $native['id'];
        $record = $this->store($native);
        $clinical = $this->native();
        $clinical['id'] = (string) Uuid::uuid1();
        $newRecord = $this->store($clinical);
        $this->assertSame(1, Uuid::fromString($newRecord->fresh()->uuid)->getVersion());
        $this->fakeApis(['*' => $this->response($native)]);
        Livewire::test(PatientPrescriptionRequestView::class, $this->parameters($record->uuid))->call('refreshDetail');
        $this->assertSame($existingId, $record->fresh()->uuid);
        $this->assertSame($clinical['id'], $newRecord->fresh()->uuid);
    }

    public function test_denied_sign_and_sms_leave_state_and_api_untouched(): void
    {
        $native = $this->native();
        $record = $this->store($native);
        $this->denyScope('medication_request_request:sign');
        Livewire::test(PatientPrescriptionRequestView::class, $this->parameters($record->uuid))
            ->call('openSigning')->assertSet('showSignatureModal', false)
            ->assertSee(__('medication-requests.permission_denied'));
        $record->update(['status' => 'ACTIVE', 'resource_type' => MedicationRequestRequest::TYPE_PRESCRIPTION]);
        $this->denyScope('medication_request:resend');
        Livewire::test(PatientMedicationRequestView::class, $this->parameters($record->uuid))
            ->set('smsConfirmed', true)->call('resendSms')->assertSet('smsConfirmed', true)
            ->assertSee(__('medication-requests.permission_denied'));
        $this->assertSame('ACTIVE', $record->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_malformed_detail_refresh_cannot_replace_clinical_data(): void
    {
        $native = $this->native();
        $record = $this->store($native);
        $bad = $native;
        unset($bad['medication_info']['medication_qty']);
        $this->fakeApis(['*' => $this->response($bad)]);
        Livewire::test(PatientPrescriptionRequestView::class, $this->parameters($record->uuid))
            ->call('refreshDetail')->assertSet('detail.medication_info.medication_qty', $native['medication_info']['medication_qty'])
            ->assertSee(__('medication-requests.detail_unavailable'));
        $this->assertEquals($native, $record->fresh()->ehealthPayload);
    }

    public function test_search_reads_every_page_and_applies_mrr_filters_absent_from_the_endpoint(): void
    {
        $native = $this->native();
        $second = array_replace($native, ['id' => (string) Str::uuid(), 'request_number' => 'MATCH']);
        $this->fakeApis(['*medication_request_requests*' => function (Request $request) use ($native, $second) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $this->response([(int) $query['page'] === 1 ? $native : $second], (int) $query['page'], 2);
        }]);
        Livewire::test(PatientMedicationRequests::class, $this->parameters(''))
            ->set('searchRequestNumber', 'MATCH')->set('additionalFilters.medical_program_id', $native['medical_program']['id'])
            ->call('searchInEHealth')->assertCount('eHealthResults', 1)->assertSet('eHealthResults.0.id', $second['id']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('medication_request_requests', 0);
    }

    public function test_bad_later_page_exposes_no_partial_search_result_or_import(): void
    {
        $native = $this->native();
        $this->fakeApis(['*medication_request_requests*' => function (Request $request) use ($native) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $this->response((int) $query['page'] === 1 ? [$native] : [null], (int) $query['page'], 2);
        }]);
        Livewire::test(PatientMedicationRequests::class, $this->parameters(''))
            ->call('searchInEHealth')->assertSet('eHealthResults', [])->assertSet('searchLoading', false)
            ->assertSet('searchError', __('medication-requests.search_failed'));
        $this->assertDatabaseCount('medication_request_requests', 0);
    }

    public function test_care_plan_import_stores_external_mr_and_mrr_without_local_links_and_is_patient_atomic(): void
    {
        $plan = $this->createFhirCarePlan(['person_id' => $this->context['person']->id, 'legal_entity_id' => $this->context['legalEntity']->id, 'author_id' => $this->context['employee']->id]);
        $request = $this->native();
        $prescription = $this->native(false);
        $repo = MedicalEventsRepository::medicationRequest();
        $this->assertSame(2, $repo->importCarePlanDocuments($plan, [$prescription], [$request]));
        $this->assertDatabaseHas('medication_request_requests', ['uuid' => $request['id'], 'resource_type' => MedicationRequestRequest::TYPE_REQUEST]);
        $this->assertDatabaseHas('medication_request_requests', ['uuid' => $prescription['id'], 'resource_type' => MedicationRequestRequest::TYPE_PRESCRIPTION]);
        $request['id'] = (string) Str::uuid();
        $prescription['id'] = (string) Str::uuid();
        $prescription['person']['id'] = (string) Str::uuid();
        try {
            $repo->importCarePlanDocuments($plan, [$prescription], [$request]);
            $this->fail('A foreign patient import was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseMissing('medication_request_requests', ['uuid' => $request['id']]);
            $this->assertDatabaseCount('medication_request_requests', 2);
        }
    }

    public function test_all_dispenses_have_real_dates_and_statuses_in_the_detail_view(): void
    {
        $native = $this->native(false);
        $record = $this->store($native, false);
        $dispense = $this->fixtures['dispenses'][0];
        $dispense['medication_request']['id'] = $record->uuid;
        $dispense['medication_request']['person']['id'] = $this->context['person']->uuid;
        $second = array_replace($dispense, ['id' => (string) Str::uuid(), 'dispensed_at' => '2026-11-10']);
        $this->fakeApis(['*medication_dispenses*' => function (Request $request) use ($dispense, $second) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $this->response([(int) $query['page'] === 1 ? $dispense : $second], (int) $query['page'], 2);
        }, '*medication_requests/*' => $this->response($native)]);
        Livewire::test(PatientMedicationRequestView::class, $this->parameters($record->uuid))
            ->call('loadDispenses')->assertCount('dispenses', 2)->assertSee($dispense['dispensed_at'])->assertSee('2026-11-10')->assertSee($dispense['status']);
        Http::assertSentCount(3);
    }

    public function test_sms_requires_explicit_confirmation_before_any_request(): void
    {
        $native = $this->native(false);
        $native['status'] = 'ACTIVE';
        $record = $this->store($native, false);
        Livewire::test(PatientMedicationRequestView::class, $this->parameters($record->uuid))
            ->call('resendSms')->assertHasErrors('smsConfirmed')->assertSee(__('medication-requests.sms_confirmation'));
        Http::assertNothingSent();
    }

    public function test_notification_disabled_blocks_sms_before_authentication_or_resend(): void
    {
        $native = $this->native(false);
        $native['status'] = 'ACTIVE';
        $record = $this->store($native, false);
        Cache::forever(MedicalProgramDictionary::KEY, [['id' => $native['medical_program']['id'], 'name' => 'Test program', 'settings' => ['request_notification_disabled' => true]]]);
        $this->fakeApis(['*medication_requests/*' => $this->response($native)]);
        $this->expectException(InvalidArgumentException::class);
        try {
            app(MedicationRequestLifecycleService::class)->resendSms($this->context['person']->uuid, $record->uuid);
        } finally {
            Http::assertSentCount(1);
            Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'PATCH' || str_contains($request->url(), 'authentication_methods'));
        }
    }

    public function test_price_catalogue_uses_the_official_endpoint_and_rejects_wrong_identity(): void
    {
        $native = $this->native(false);
        $record = $this->store($native, false);
        $this->fakeApis(['*price_catalog_link' => $this->response(['id' => (string) Str::uuid(), 'price_catalog_link' => 'https://ecatalog.moz.gov.ua/']), '*medication_requests/*' => $this->response($native)]);
        Livewire::test(PatientMedicationRequestView::class, $this->parameters($record->uuid))
            ->call('loadPriceCatalogue')->assertSet('priceCatalogueUrl', '')->assertSee(__('medication-requests.detail_unavailable'));
        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/medication_requests/'.$record->uuid.'/price_catalog_link'));
    }

    private function documentPlan(): array
    {
        $this->grantMedicalEventAbilities($this->context['user'], ['care_plan:read']);
        $this->grantCarePlanEmployeeRole($this->context['employee'], 'OUTPATIENT');
        $plan = $this->createFhirCarePlan([
            'person_id' => $this->context['person']->id, 'legal_entity_id' => $this->context['legalEntity']->id,
            'author_id' => $this->context['employee']->id, 'encounter_id' => $this->encounter->id,
            'status' => 'active', 'title' => 'BA08 Plan', 'period_end' => now()->addMonth()->toDateString(),
        ]);
        $this->grantCarePlanWriteApproval($plan, $this->context['employee']);
        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $plan->id, 'author_id' => $this->context['employee']->id,
            'kind' => 'medication_request', 'status' => 'scheduled', 'quantity' => 10,
            'quantity_code' => 'TAB', 'quantity_system' => 'UCUM',
            'remaining_quantity' => 0, 'remaining_quantity_code' => 'TAB', 'remaining_quantity_system' => 'UCUM',
            'product_reference' => (string) Str::uuid(), 'product_reference_type' => 'medication',
        ]);
        $resource = Repository::carePlanActivity()->getResource($activity);
        $resource['detail']['remainingQuantity'] = ['value' => 0, 'system' => 'UCUM', 'code' => 'TAB', 'unit' => 'таб.'];
        Repository::carePlanActivity()->storeResource($resource, $plan, $activity->id);

        return [$plan, $activity];
    }

    private function denyScope(string $scope): void
    {
        $user = $this->context['user'];
        // Seeded roles can inherit the scope after its direct grant is revoked.
        $user->syncRoles([]);
        $user->revokePermissionTo($scope);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertFalse($user->can($scope));
    }

    private function prescriptionHarness(): array
    {
        return ['legalEntity' => $this->context['legalEntity'], 'person' => $this->context['person'], 'encounter' => $this->encounter];
    }

    public function test_medical_coordinator_can_open_creation_for_own_finished_encounter(): void
    {
        $this->context['employee']->update(['employee_type' => 'MED_COORDINATOR']);
        Livewire::test(EncounterPrescriptionPlanHarness::class, $this->prescriptionHarness())
            ->call('openEncounterEPrescriptionDrawer')->assertSet('showEncounterEPrescriptionDrawer', true);
        Http::assertNothingSent();
    }

    public function test_denied_creation_shows_feedback_before_api_or_storage(): void
    {
        $this->denyScope('medication_request_request:write');
        $this->mock(MedicationRequestLifecycleService::class)->shouldNotReceive('createEncounterDraft')->shouldNotReceive('createCarePlanDraft');
        Livewire::test(EncounterPrescriptionPlanHarness::class, $this->prescriptionHarness())
            ->call('openEncounterEPrescriptionDrawer')->assertSet('showEncounterEPrescriptionDrawer', false)
            ->assertSee(__('medication-requests.own_finished_encounter_required'));
        $this->assertDatabaseCount('medication_request_requests', 0);
        Http::assertNothingSent();
    }

    public function test_unexpected_plan_search_error_has_translated_feedback_without_exposing_details(): void
    {
        Exceptions::fake();
        $error = new RuntimeException('Private database diagnostic');
        $this->mock(CarePlanRepository::class)->shouldReceive('documentPlanChoices')->once()->andThrow($error);
        Livewire::test(EncounterPrescriptionPlanHarness::class, $this->prescriptionHarness())
            ->call('searchEncounterEPrescriptionCarePlans')->assertSet('encounterEPrescriptionPlanChoices', [])
            ->assertSee(__('care-plan.related_plan_unavailable'))->assertDontSee($error->getMessage());
        Exceptions::assertReported(static fn (RuntimeException $reported): bool => $reported === $error);
        $this->assertDatabaseCount('medication_request_requests', 0);
        Http::assertNothingSent();
    }

    public function test_rendered_approval_uses_selected_actor_and_remounts_without_falling_back(): void
    {
        [$plan] = $this->documentPlan();
        $context = $this->context;
        $encounter = $this->encounter;
        $first = $context['employee'];
        $second = $first->replicate();
        $second->uuid = (string) Str::uuid();
        $second->save();
        $context['user']->employees()->attach($second->id);
        $this->grantCarePlanEmployeeRole($second, 'OUTPATIENT');
        $plan->approvals()->delete();
        $this->assertSame($first->uuid, $context['user']->getCarePlanWriterEmployee('OUTPATIENT')->uuid);
        $encounter->performer()->update(['value' => $second->uuid]);
        Queue::fake();
        PrescriptionApprovalActorCapture::$receivedActors = [];
        Livewire::component('care-plan.care-plan-approvals', PrescriptionApprovalActorCapture::class);

        try {
            $component = Livewire::test(EncounterPrescriptionPlanHarness::class, $this->prescriptionHarness())
                ->call('selectEncounterEPrescriptionCarePlan', $plan->id);
            $component->assertSeeHtml('data-approval-actor="'.$second->uuid.'"');
            $this->assertSame([$second->uuid], PrescriptionApprovalActorCapture::$receivedActors);

            // The plan stays selected; a different actor must mount a new approval component.
            $encounter->performer()->update(['value' => $first->uuid]);
            $component->call('$refresh')->assertSeeHtml('data-approval-actor="'.$first->uuid.'"');
            $this->assertSame([$second->uuid, $first->uuid], PrescriptionApprovalActorCapture::$receivedActors);

            // Another writer remains available, but cannot receive this encounter's consent.
            $first->update(['is_active' => false]);
            $this->assertSame($second->uuid, $context['user']->getCarePlanWriterEmployee('OUTPATIENT')->uuid);
            $component->call('searchEncounterEPrescriptionCarePlans')->assertDontSeeHtml('data-approval-actor=');
            $this->assertSame([$second->uuid, $first->uuid], PrescriptionApprovalActorCapture::$receivedActors);

            $first->update(['is_active' => true]);
            $this->denyScope('medication_request_request:write');
            $component->call('searchEncounterEPrescriptionCarePlans')->assertDontSeeHtml('data-approval-actor=')
                ->assertSee(__('medication-requests.own_finished_encounter_required'));
            $this->assertSame([$second->uuid, $first->uuid], PrescriptionApprovalActorCapture::$receivedActors);
            $this->assertDatabaseCount('medication_request_requests', 0);
            $this->assertSame(0, $plan->approvals()->count());
            Http::assertNothingSent();
            Queue::assertNothingPushed();
        } finally {
            Livewire::component('care-plan.care-plan-approvals', CarePlanApprovals::class);
        }
    }

    public function test_plan_selection_keeps_native_remaining_quantity_after_another_search(): void
    {
        [$plan, $activity] = $this->documentPlan();
        $plan->approvals()->update(['status' => 'NEW']);
        Livewire::test(EncounterPrescriptionPlanHarness::class, $this->prescriptionHarness())
            ->call('searchEncounterEPrescriptionCarePlans')->call('selectEncounterEPrescriptionCarePlan', $plan->id)
            ->call('selectEncounterEPrescriptionActivity', $activity->id)
            ->assertSet('encounterEPrescriptionActivityId', $activity->id)->assertSee('таб.')
            ->set('encounterEPrescriptionPlanSearch', 'unrelated search')->call('searchEncounterEPrescriptionCarePlans')
            ->assertSet('encounterEPrescriptionApprovalRequired', false)->assertCount('encounterEPrescriptionActivityChoices', 1);
        Http::assertNothingSent();
        $this->assertDatabaseCount('medication_request_requests', 0);
    }

    public function test_revoked_approval_stops_signing_with_visible_feedback_and_no_effects(): void
    {
        [$plan, $activity] = $this->documentPlan();
        $component = Livewire::test(EncounterPrescriptionPlanHarness::class, $this->prescriptionHarness())
            ->call('selectEncounterEPrescriptionCarePlan', $plan->id)->call('selectEncounterEPrescriptionActivity', $activity->id)
            ->call('primeSigning');
        $plan->approvals()->update(['is_verified' => false]);
        $this->mock(MedicationRequestLifecycleService::class)->shouldNotReceive('signPrescription');
        $component->call('signEncounterEPrescription')->assertSet('showSignatureModal', true)
            ->assertSee(__('care-plan.related_plan_unavailable'));
        $this->assertDatabaseCount('medication_request_requests', 0);
        Http::assertNothingSent();
    }

    public function test_old_or_other_doctor_encounter_cannot_create_a_request(): void
    {
        $lifecycle = app(MedicationRequestLifecycleService::class);
        foreach (['old', 'other'] as $case) {
            $this->encounter->period()->update(['end' => $case === 'old' ? now()->subDay()->utc() : now()->utc()]);
            if ($case === 'other') {
                $this->encounter->performer()->update(['value' => (string) Str::uuid()]);
            }
            try {
                $lifecycle->createEncounterDraft($this->encounter, [], ['employee_uuid' => $this->context['employee']->uuid]);
                $this->fail('An ineligible encounter was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(__('medication-requests.own_finished_encounter_required'), $exception->getMessage());
            }
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('medication_request_requests', 0);
    }
}

class EncounterPrescriptionPlanHarness extends Component
{
    use ManagesEncounterEPrescription;
    use ResolvesEncounterStandaloneContext;

    public int $encounterId;
    public int $personId;
    public bool $showSignatureModal = false;
    public ?string $actionType = null;

    public function mount($legalEntity, $person, $encounter): void
    {
        $this->encounterId = $encounter->id;
        $this->personId = $person->id;
    }

    protected function loadEncounterEPrescriptionAuthMethods(Encounter $encounter): void
    {
    }
    protected function loadEncounterEPrescriptionPrograms(): void
    {
    }
    #[Computed]
    public function encounterEPrescriptionCategories(): array
    {
        return ['community' => 'Community'];
    }
    #[Computed]
    public function encounterPrescriptionQuantities(): array
    {
        return [];
    }
    #[Computed]
    public function encounterPrescriptionIngredientDoses(): array
    {
        return [];
    }
    #[Computed]
    public function encounterPrescriptionContainers(): array
    {
        return [];
    }
    #[Computed]
    public function encounterPrescriptionNotificationsDisabled(): bool
    {
        return false;
    }

    public function primeSigning(): void
    {
        $this->encounterEPrescriptionRequestIdToSign = (string) Str::uuid();
        $this->encounterEPrescriptionDraftForm = $this->encounterEPrescriptionForm;
        $this->showSignatureModal = true;
    }

    public function render(): string
    {
        return <<<'BLADE'
<div>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    @include('livewire.encounter-package.parts.encounter-eprescription-form', ['encounterReturnUrl' => '/'])
</div>
BLADE;
    }
}

class PrescriptionApprovalActorCapture extends Component
{
    public static array $receivedActors = [];

    public string $actorUuid;

    public function mount(LegalEntity $legalEntity, CarePlan $carePlan, string $actingEmployeeUuid): void
    {
        $this->actorUuid = $actingEmployeeUuid;
        self::$receivedActors[] = $actingEmployeeUuid;
    }

    public function render(): string
    {
        return '<div data-approval-actor="'.$this->actorUuid.'"></div>';
    }
}
