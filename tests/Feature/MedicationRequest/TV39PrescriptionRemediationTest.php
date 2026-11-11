<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Classes\eHealth\Api\Drug as DrugApi;
use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Classes\eHealth\Api\Patient\MedicationRequestRequest as MedicationRequestRequestApi;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Enums\User\Role;
use App\Livewire\Person\Records\PatientMedicationRequests;
use App\Livewire\Person\Records\PatientMedicationRequestView;
use App\Livewire\Person\Records\PatientPrescriptionRequestView;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Policies\EncounterPolicy;
use App\Repositories\MedicalEvents\Repository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;
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
        return Repository::medicationRequest()->storeResource(
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
        $this->context['user']->revokePermissionTo('medication_request_request:sign');
        Livewire::test(PatientPrescriptionRequestView::class, $this->parameters($record->uuid))
            ->call('openSigning')->assertSet('showSignatureModal', false)
            ->assertSee(__('medication-requests.permission_denied'));
        $record->update(['status' => 'ACTIVE', 'resource_type' => MedicationRequestRequest::TYPE_PRESCRIPTION]);
        $this->context['user']->revokePermissionTo('medication_request:resend');
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
        $repo = Repository::medicationRequest();
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
