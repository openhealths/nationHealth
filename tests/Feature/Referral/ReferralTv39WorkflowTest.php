<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\Encounter as EncounterApi;
use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Enums\User\Role;
use App\Livewire\EncounterPackage\EncounterPackageCreate;
use App\Livewire\EncounterPackage\Forms\ConditionForm;
use App\Livewire\EncounterPackage\Forms\EncounterForm;
use App\Livewire\Person\Records\PatientReferrals;
use App\Livewire\Referral\Forms\ServiceRequestForm;
use App\Livewire\Referral\SpecimenReferral;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Services\SignatureService;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\Support\NativeClinicalFixtures;
use Tests\TestCase;

class ReferralTv39WorkflowTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;
    use UsesCompositionDictionaries;

    private const string SERVICE = '10050000-0000-4000-8000-000000000011';

    private array $context;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->context = $this->compositionFixture(scopes: ['service_request:read', 'service_request:write', 'service_request:use', 'service_request:complete', 'specimen:read', 'encounter:read', 'condition:read', 'observation:read', 'diagnostic_report:read', 'episode:read']);
        $this->grantReferralEmployeeRole($this->context['user'], $this->context['employee']);
        Cache::forever(ServiceDictionary::KEY, [['id' => self::SERVICE, 'code' => 'TEST', 'name' => 'Laboratory', 'is_active' => true, 'request_allowed' => true, 'category' => 'laboratory_procedure']]);
        Cache::forever(ServiceDictionary::KEY.':fresh', true);
        Cache::forever(MedicalProgramDictionary::KEY, []);
        Cache::forever(MedicalProgramDictionary::KEY.':fresh', true);
    }

    public function test_hospitalization_refusal_presets_a_new_ambulatory_episode_and_rejects_an_existing_one(): void
    {
        $component = new EncounterPackageCreate();
        $component->form = new EncounterForm($component, 'form');
        $component->conditionForm = new ConditionForm($component, 'conditionForm');
        $component->encounterClassTypes = ['AMB' => ['service_delivery_location']];
        $component->dictionaries = ['eHealth/encounter_classes' => ['AMB' => 'Ambulatory']];
        $component->employeeType = 'SPECIALIST';
        $component->form->encounter['periodDate'] = now()->format(config('app.date_format'));
        $component->episodeType = 'existing';
        (new ReflectionMethod($component, 'presetHospitalizationRefusal'))->invoke($component);
        $this->assertTrue($component->isHospitalizationRefusal);
        $this->assertSame('AMB', $component->form->encounter['classCode']);
        $this->assertSame('service_delivery_location', $component->form->encounter['typeCode']);
        $this->assertSame('new', $component->episodeType);

        // Exercise the actual server rule: a forged existing episode cannot bypass the locked wizard fields.
        $rules = (new ReflectionMethod($component->form, 'rules'))->invoke($component->form);
        $validator = Validator::make(['episode' => ['id' => (string) Str::uuid()]], ['episode.id' => $rules['episode.id']]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('episode.id', $validator->errors()->toArray());
        $this->assertFalse(Validator::make(['episode' => ['id' => null, 'typeCode' => 'treatment', 'name' => 'New episode']], ['episode.id' => $rules['episode.id']])->fails());
    }

    private function reference(string $value, string $type): array
    {
        return ['identifier' => ['value' => $value, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]]], 'displayValue' => 'Synthetic'];
    }

    private function resource(Person|Preperson|null $patient = null): array
    {
        $patient ??= $this->context['person'];

        return ['id' => (string) Str::uuid(), 'status' => 'active', 'requisition' => 'TEST-1234-5678-9012', 'intent' => 'order', 'category' => ['coding' => [['system' => 'eHealth/SNOMED/service_request_categories', 'code' => 'counselling']]], 'code' => $this->reference(self::SERVICE, 'service'), 'subject' => $this->reference($patient->uuid, 'patient'), 'requesterEmployee' => $this->reference($this->context['employee']->uuid, 'employee'), 'requesterLegalEntity' => $this->reference($this->context['legalEntity']->uuid, 'legal_entity'), 'usedByEmployee' => $this->reference($this->context['employee']->uuid, 'employee'), 'usedByLegalEntity' => $this->reference($this->context['legalEntity']->uuid, 'legal_entity'), 'reasonReference' => [], 'note' => '', 'patientInstruction' => '', 'statusHistory' => [], 'expirationDate' => '2027-11-11T00:00:00Z', 'occurrencePeriod' => ['start' => '2026-11-11T00:00:00Z'], 'programProcessingStatus' => 'in_progress'];
    }

    private function response(array $data): EHealthResponse
    {
        return new EHealthResponse(new HttpResponse(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR)), static fn (EHealthResponse $response): array => ServiceRequestApi::validateResourceData($response->getData()));
    }

    private function specimen(Person|Preperson $patient): Specimen
    {
        return Specimen::create(['uuid' => (string) Str::uuid(), $patient instanceof Preperson ? 'preperson_id' : 'person_id' => $patient->id, 'status' => 'available', 'type_id' => CodeableConcept::create()->id, 'managing_organization_id' => Identifier::create(['value' => $this->context['legalEntity']->uuid])->id, 'registered_by_id' => Identifier::create(['value' => $this->context['employee']->uuid])->id]);
    }

    private function specimenData(): array
    {
        return ['service_id' => self::SERVICE, 'quantity' => 1.25, 'started_at' => now()->addHour()->toIso8601String(), 'ended_at' => now()->addDay()->toIso8601String()];
    }

    public function test_hospitalization_specialty_survives_draft_and_signed_content(): void
    {
        $encounter = Encounter::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->context['person']->id, 'status' => 'finished', 'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id, 'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id, 'type_id' => CodeableConcept::create()->id]);
        $employee = $this->context['employee'];
        $data = $this->specimenData() + ['kind' => 'service_request', 'category' => 'hospitalization', 'performer_type' => 'SURGERY'];
        $uuid = app(ReferralRequestLifecycleService::class)->createEncounterDraft($encounter, $data, ['employee_id' => $employee->id, 'employee_uuid' => $employee->uuid, 'legal_entity_uuid' => $this->context['legalEntity']->uuid]);
        $resource = Repository::serviceRequest()->getResource(Repository::serviceRequest()->findByUuid($uuid));
        $this->assertSame('SURGERY', data_get($resource, 'performerType.coding.0.code'));
        $this->assertSame('SURGERY', data_get(ServiceRequestForm::buildCreateContent($resource), 'performer_type.coding.0.code'));
        Http::assertNothingSent();
    }

    public function test_hospitalization_without_specialty_is_rejected_before_persistence(): void
    {
        $encounter = Encounter::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->context['person']->id, 'status' => 'finished', 'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id, 'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id, 'type_id' => CodeableConcept::create()->id]);
        $count = ServiceRequestRequest::count();
        try {
            app(ReferralRequestLifecycleService::class)->createEncounterDraft($encounter, $this->specimenData() + ['category' => 'hospitalization'], ['employee_id' => $this->context['employee']->id]);
            $this->fail('Hospitalization requires the selected specialty.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('encounterReferralForm.performer_type', $exception->errors());
            $this->assertSame($count, ServiceRequestRequest::count());
            Http::assertNothingSent();
        }
    }

    public function test_permitted_resources_include_owned_reason_episodes_and_reports_only(): void
    {
        $person = $this->context['person'];
        $approval = Approval::create(['uuid' => (string) Str::uuid(), 'approvable_type' => Person::class, 'approvable_id' => $person->id, 'granted_to_id' => Identifier::create(['value' => $this->context['employee']->uuid])->id, 'granted_to_type' => 'employee', 'status' => 'active', 'is_verified' => true, 'expires_at' => now()->addHour()]);
        $episode = Episode::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'active', 'name' => 'Reason episode']);
        $episodeIdentifier = Identifier::create(['value' => $episode->uuid]);
        $episodeIdentifier->type()->create()->coding()->create(['system' => 'eHealth/resources', 'code' => 'episode_of_care']);
        $encounter = Encounter::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'finished', 'episode_id' => $episodeIdentifier->id, 'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id, 'type_id' => CodeableConcept::create()->id]);
        $encounterIdentifier = Identifier::create(['value' => $encounter->uuid]);
        $encounterIdentifier->type()->create()->coding()->create(['system' => 'eHealth/resources', 'code' => 'encounter']);
        $condition = Condition::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'context_id' => $episodeIdentifier->id, 'primary_source' => true, 'code_id' => CodeableConcept::create()->id, 'clinical_status' => 'active', 'verification_status' => 'confirmed', 'onset_date' => now()]);
        $observation = Observation::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'context_id' => $encounterIdentifier->id, 'status' => 'valid', 'code_id' => CodeableConcept::create()->id, 'issued' => now(), 'primary_source' => true]);
        $report = DiagnosticReport::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'final', 'code_id' => Identifier::create(['value' => self::SERVICE])->id, 'recorded_by_id' => Identifier::create(['value' => $this->context['employee']->uuid])->id, 'issued' => now(), 'primary_source' => true]);
        $references = [['uuid' => $condition->uuid, 'type' => 'condition'], ['uuid' => $observation->uuid, 'type' => 'observation'], ['uuid' => $report->uuid, 'type' => 'diagnostic_report']];
        $this->assertSame([['uuid' => $episode->uuid, 'type' => 'episode_of_care'], ['uuid' => $report->uuid, 'type' => 'diagnostic_report']], Repository::serviceRequest()->permittedResources($person, $references));
        // Patient identity alone cannot authorize an unreadable clinical reason.
        $approval->update(['status' => 'terminated']);
        try {
            Repository::serviceRequest()->permittedResources($person, $references);
            $this->fail('An unreadable reason must not become a permitted resource.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason_reference', $exception->errors());
            Http::assertNothingSent();
        }
        $condition->personId = null;
        $condition->prepersonId = $this->compositionPreperson()->id;
        $condition->save();
        try {
            Repository::serviceRequest()->permittedResources($person, $references);
            $this->fail('Another patient reason must not grant access.');
        } catch (ModelNotFoundException) {
            Http::assertNothingSent();
        }
    }

    private function specimenPage(array $rows, int $page): EHealthResponse
    {
        return new EHealthResponse(new HttpResponse(200, [], json_encode(['data' => $rows, 'paging' => ['page' => $page, 'page_size' => 1, 'total_entries' => 2, 'total_pages' => 2]], JSON_THROW_ON_ERROR)), static fn (EHealthResponse $response): array => (new ReflectionMethod(ServiceRequestApi::class, 'validateMany'))->invoke(new ServiceRequestApi(), $response, false));
    }

    public function test_specimen_read_validates_all_pages_and_projects_no_patient_identity(): void
    {
        $specimen = $this->specimen($this->context['person']);
        $first = Arr::toSnakeCase($this->resource());
        $first['identity'] = ['last_name' => 'Private identity'];
        $second = Arr::toSnakeCase($this->resource());
        $api = $this->mock(ServiceRequestApi::class);
        $api->shouldReceive('getBySpecimen')->once()->with($specimen->uuid, ['page' => 1])->andReturn($this->specimenPage([$first], 1));
        $api->shouldReceive('getBySpecimen')->once()->with($specimen->uuid, ['page' => 2])->andReturn($this->specimenPage([$second], 2));
        $count = ServiceRequestRequest::count();
        $component = Livewire::test(SpecimenReferral::class, ['specimenId' => $specimen->id, 'personId' => $this->context['person']->id])->call('loadReferrals')->assertSee(__('referrals.messages.specimen_loaded'));
        $rows = $component->get('rows');
        $this->assertSame([$first['id'], $second['id']], array_column($rows, 'id'));
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('subject', $row);
            $this->assertArrayNotHasKey('identity', $row);
        }
        $this->assertSame($count, ServiceRequestRequest::count());
    }

    public function test_malformed_later_specimen_page_preserves_previous_ui_and_storage(): void
    {
        $specimen = $this->specimen($this->context['person']);
        $first = Arr::toSnakeCase($this->resource());
        $bad = Arr::toSnakeCase($this->resource());
        unset($bad['requester_employee']);
        $api = $this->mock(ServiceRequestApi::class);
        $api->shouldReceive('getBySpecimen')->once()->with($specimen->uuid, ['page' => 1])->andReturn($this->specimenPage([$first], 1));
        $api->shouldReceive('getBySpecimen')->once()->with($specimen->uuid, ['page' => 2])->andReturn($this->specimenPage([$bad], 2));
        $count = ServiceRequestRequest::count();
        Livewire::test(SpecimenReferral::class, ['specimenId' => $specimen->id, 'personId' => $this->context['person']->id])->call('loadReferrals')->assertSet('rows', [])->assertSee(__('referrals.messages.specimen_failed'));
        $this->assertSame($count, ServiceRequestRequest::count());
    }

    public function test_specimen_read_denial_has_visible_feedback_and_no_api_calls(): void
    {
        $specimen = $this->specimen($this->context['person']);
        $this->mock(ServiceRequestApi::class)->shouldNotReceive('getBySpecimen');
        $this->context['user']->syncPermissionsParent([]);
        $this->context['user']->syncRoles([]);
        $this->actingAs($this->context['user']->fresh());
        Livewire::test(SpecimenReferral::class, ['specimenId' => $specimen->id, 'personId' => $this->context['person']->id])->call('loadReferrals')->assertSet('rows', [])->assertSee(__('referrals.messages.use_forbidden'));
    }

    public function test_specimen_prequalification_contract_error_is_visible_before_persistence_or_signature(): void
    {
        $specimen = $this->specimen($this->context['person']);
        $record = app(ReferralRequestLifecycleService::class)->createSpecimenDraft($specimen, $this->context['person'], $this->specimenData(), $this->context['employee']);
        $original = Repository::serviceRequest()->getResource($record);
        $validator = new ReflectionMethod(ServiceRequestApi::class, 'validateQualification');
        $response = new EHealthResponse(
            new HttpResponse(200, [], json_encode(['data' => [['program_id' => (string) Str::uuid(), 'program_name' => 'Synthetic program']]], JSON_THROW_ON_ERROR)),
            static fn (EHealthResponse $response): array => $validator->invoke(new ServiceRequestApi(), $response)
        );
        $this->mock(ServiceRequestApi::class)->shouldReceive('prequalify')->once()->andReturn($response);
        $signature = $this->mock(SignatureService::class);
        $signature->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $signature->shouldNotReceive('signData');
        $count = ServiceRequestRequest::count();
        $component = Livewire::test(SpecimenReferral::class, ['specimenId' => $specimen->id, 'personId' => $this->context['person']->id])
            ->set('showSignatureModal', true)->set('actionType', 'sign_referral')->call('sign')
            ->assertSet('draftUuid', $record->uuid)->assertSet('showSignatureModal', true)->assertHasErrors();
        $component->assertSee($component->instance()->getErrorBag()->first());
        $this->assertSame($count, ServiceRequestRequest::count());
        $this->assertSame($original, Repository::serviceRequest()->getResource($record->fresh()));
        Http::assertNothingSent();
    }

    public function test_patient_signature_field_errors_stay_visible_in_each_signature_modal(): void
    {
        $this->context['user']->givePermissionToParent('service_request:recall', 'service_request:cancel');
        $person = $this->context['person'];
        $employee = $this->context['employee'];
        $episode = Episode::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id,
            'status' => 'active', 'name' => 'Referral signing episode',
            'managing_organization_id' => Identifier::create(['value' => $this->context['legalEntity']->uuid])->id,
        ]);
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'finished',
            'episode_id' => Identifier::create(['value' => $episode->uuid])->id,
            'performer_id' => Identifier::create(['value' => $employee->uuid])->id,
            'class_id' => Coding::create(['system' => 'eHealth/encounter_classes', 'code' => 'AMB'])->id,
            'type_id' => CodeableConcept::create()->id,
        ]);
        Approval::create([
            'uuid' => (string) Str::uuid(), 'approvable_type' => Person::class, 'approvable_id' => $person->id,
            'granted_to_id' => Identifier::create(['value' => $employee->uuid])->id,
            'granted_to_type' => 'employee', 'status' => 'active', 'is_verified' => true,
            'expires_at' => now()->addHour(),
        ]);
        $signature = $this->mock(SignatureService::class);
        $signature->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $signature->shouldNotReceive('signData');
        $this->mock(ServiceRequestApi::class)->shouldNotReceive('getById', 'createSigned', 'recall', 'cancel');
        foreach (['signDraft' => 'sign_referral', 'signRecallReferral' => 'recall_referral', 'signCancelReferral' => 'cancel_referral'] as $method => $action) {
            $data = $this->resource();
            if ($method === 'signDraft') {
                $data['status'] = 'draft';
                $data['category']['coding'][0]['code'] = 'laboratory_procedure';
                $data['context'] = $this->reference($encounter->uuid, 'encounter');
                unset($data['requisition']);
            }
            $record = Repository::serviceRequest()->importResource($data, $this->context['person'], $this->context['employee']->id);
            $original = Repository::serviceRequest()->getResource($record);
            $component = Livewire::test(PatientReferrals::class, ['legalEntity' => $this->context['legalEntity'], 'personId' => $this->context['person']->id])
                ->set('requestIdToSign', $record->uuid)->set('actionType', $action)->set('showSignatureModal', true)
                ->set('referralExplanatoryLetter', 'Synthetic explanation')->call($method)
                ->assertSet('showSignatureModal', true)->assertHasErrors(['form.password', 'form.knedp', 'form.keyContainerUpload']);
            $component->assertSee($component->instance()->getErrorBag()->first('form.password'));
            $this->assertSame($original, Repository::serviceRequest()->getResource($record->fresh()));
        }
        Http::assertNothingSent();
    }

    public function test_specimen_status_denial_is_visible_before_polling_or_http(): void
    {
        $specimen = $this->specimen($this->context['person']);
        app(ReferralRequestLifecycleService::class)->createSpecimenDraft($specimen, $this->context['person'], $this->specimenData(), $this->context['employee']);
        $this->fakeSignatureService();
        $this->mock(ReferralRequestLifecycleService::class)->shouldNotReceive('checkCompletion');
        $this->context['user']->syncPermissionsParent([]);
        $this->context['user']->syncRoles([]);
        $this->actingAs($this->context['user']->fresh());
        Livewire::test(SpecimenReferral::class, ['specimenId' => $specimen->id, 'personId' => $this->context['person']->id])
            ->call('checkStatus')->assertSee(__('referrals.messages.use_forbidden'));
        Http::assertNothingSent();
    }

    public function test_execution_and_completion_references_survive_full_storage_round_trip(): void
    {
        $data = $this->resource();
        $data['completedWith'] = $this->reference((string) Str::uuid(), 'encounter');
        $record = Repository::serviceRequest()->importResource($data, $this->context['person']);
        $roundTrip = Repository::serviceRequest()->getResource($record->fresh());
        foreach (['usedByEmployee', 'usedByLegalEntity', 'completedWith'] as $field) {
            $this->assertSame($data[$field], $roundTrip[$field]);
        }
        $this->assertSame([], $roundTrip['statusHistory']);
        $this->assertSame($data['expirationDate'], $roundTrip['expirationDate']);
        $this->assertTrue(Auth::user()->can('completeRecord', [ServiceRequestRequest::class, $record->fresh()]));
    }

    public function test_programless_use_persists_selected_executor_and_clears_previous_program(): void
    {
        $data = $this->resource();
        $data['program'] = $this->reference((string) Str::uuid(), 'medical_program');
        $record = Repository::serviceRequest()->importResource($data, $this->context['person']);
        Repository::serviceRequest()->applyExecutionResult($record, ['used_by_employee' => Arr::toSnakeCase($data['usedByEmployee']), 'used_by_legal_entity' => Arr::toSnakeCase($data['usedByLegalEntity'])], null);
        $this->assertNull($record->fresh()->programId);
        $this->assertNull($record->fresh()->programIdentifierId);
        $this->assertSame($this->context['employee']->uuid, $record->fresh()->usedByEmployee->value);
    }

    public function test_specimen_draft_has_uuid_v1_focus_and_no_encounter(): void
    {
        $specimen = $this->specimen($this->context['person']);
        $record = app(ReferralRequestLifecycleService::class)->createSpecimenDraft($specimen, $this->context['person'], $this->specimenData(), $this->context['employee']);
        $this->assertSame(1, Uuid::fromString($record->uuid)->getVersion());
        $this->assertNull($record->contextId);
        $this->assertSame($specimen->uuid, $record->focusReferences()->first()->value);
        $this->assertSame($specimen->uuid, $record->specimenReferences()->first()->value);
        $this->assertSame('laboratory_procedure', $record->category->coding->first()->code);
    }

    public function test_specimen_edit_preserves_existing_uuid_and_restores_scalar_quantity(): void
    {
        $specimen = $this->specimen($this->context['person']);
        $record = app(ReferralRequestLifecycleService::class)->createSpecimenDraft($specimen, $this->context['person'], $this->specimenData(), $this->context['employee']);
        $uuid = (string) Str::uuid();
        $record->uuid = $uuid;
        $record->save();
        $edited = app(ReferralRequestLifecycleService::class)->createSpecimenDraft($specimen, $this->context['person'], $this->specimenData(), $this->context['employee'], $uuid);
        $this->assertSame($uuid, $edited->uuid);
        Livewire::test(SpecimenReferral::class, ['specimenId' => $specimen->id, 'personId' => $this->context['person']->id])->assertSet('quantity', 1.25);
    }

    public function test_preperson_specimen_draft_is_isolated_from_person_with_other_subject(): void
    {
        $patient = $this->compositionPreperson();
        $record = app(ReferralRequestLifecycleService::class)->createSpecimenDraft($this->specimen($patient), $patient, $this->specimenData(), $this->context['employee']);
        $this->assertNull($record->personId);
        $this->assertSame($patient->id, $record->prepersonId);
        $this->assertFalse(ServiceRequestRequest::forPatient($this->context['person'])->whereKey($record->id)->exists());
        $this->assertSame($patient->uuid, Repository::serviceRequest()->getResource($record)['subject']['identifier']['value']);
    }

    public function test_foreign_specimen_cannot_create_a_draft(): void
    {
        $specimen = $this->specimen($this->compositionPreperson());
        $count = ServiceRequestRequest::count();
        try {
            app(ReferralRequestLifecycleService::class)->createSpecimenDraft($specimen, $this->context['person'], $this->specimenData(), $this->context['employee']);
            $this->fail('Another patient specimen must be refused.');
        } catch (ModelNotFoundException) {
            $this->assertSame($count, ServiceRequestRequest::count());
            Http::assertNothingSent();
        }
    }

    public function test_non_laboratory_catalog_service_cannot_be_spoofed_as_specimen_referral(): void
    {
        Cache::forever(ServiceDictionary::KEY, [['id' => self::SERVICE, 'code' => 'TEST', 'name' => 'Counselling', 'is_active' => true, 'request_allowed' => true, 'category' => 'counselling']]);
        $this->expectException(ValidationException::class);
        app(ReferralRequestLifecycleService::class)->createSpecimenDraft($this->specimen($this->context['person']), $this->context['person'], $this->specimenData(), $this->context['employee']);
    }

    public function test_doctor_cannot_execute_counselling_but_specialist_can(): void
    {
        $this->assertTrue(Auth::user()->can('use', [ServiceRequestRequest::class, $this->context['employee'], 'counselling']));
        $employee = $this->context['employee'];
        $employee->employeeType = Role::DOCTOR->value;
        $employee->save();
        $this->grantReferralEmployeeRole($this->context['user'], $employee);
        $this->actingAs($this->context['user']->fresh());
        $this->assertFalse(Auth::user()->can('use', [ServiceRequestRequest::class, $employee, 'counselling']));
        $this->assertTrue(Auth::user()->can('use', [ServiceRequestRequest::class, $employee, 'laboratory_procedure']));
    }

    public function test_assistant_is_checked_against_actual_catalog_category(): void
    {
        $employee = $this->context['employee'];
        $employee->employeeType = Role::ASSISTANT->value;
        $employee->save();
        $this->grantReferralEmployeeRole($this->context['user'], $employee);
        $this->actingAs($this->context['user']->fresh());
        config()->set('ehealth.referrals.assistant_allowed_categories', ['laboratory_procedure']);
        $this->assertTrue(Auth::user()->can('createCode', [ServiceRequestRequest::class, $employee, self::SERVICE]));
        config()->set('ehealth.referrals.assistant_allowed_categories', ['diagnostic_procedure']);
        $this->assertFalse(Auth::user()->can('createCode', [ServiceRequestRequest::class, $employee, self::SERVICE]));
    }

    public function test_inpatient_preperson_allows_transfer_and_lab_and_refuses_counselling(): void
    {
        $type = CodeableConcept::create();
        $type->coding()->create(['code' => 'discharge', 'system' => 'eHealth/encounter_types']);
        $patient = $this->compositionPreperson();
        $encounter = Encounter::create(['uuid' => (string) Str::uuid(), 'preperson_id' => $patient->id, 'status' => 'finished', 'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id, 'class_id' => Coding::create(['code' => 'INPATIENT', 'system' => 'eHealth/encounter_classes'])->id, 'type_id' => $type->id]);
        foreach (['transfer_of_care', 'laboratory_procedure'] as $category) {
            $this->assertTrue(Auth::user()->can('create', [ServiceRequestRequest::class, $this->context['employee'], $category, $encounter]));
        }
        $this->assertFalse(Auth::user()->can('create', [ServiceRequestRequest::class, $this->context['employee'], 'counselling', $encounter]));
        $personEncounter = Encounter::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->context['person']->id, 'status' => 'finished', 'episode_id' => $encounter->getAttribute('episode_id'), 'class_id' => $encounter->getAttribute('class_id'), 'type_id' => $encounter->getAttribute('type_id')]);
        $this->assertTrue(Auth::user()->can('create', [ServiceRequestRequest::class, $this->context['employee'], 'counselling', $personEncounter]));
    }

    public function test_foreign_reuse_requires_the_confirmed_configuration_interval(): void
    {
        $foreign = (string) Str::uuid();
        $referral = ['status' => 'active', 'program_processing_status' => 'new', 'used_by_legal_entity' => ['identifier' => ['value' => $foreign]], 'used_by_legal_entity_history' => [['used_by_legal_entity' => ['identifier' => ['value' => $foreign]], 'inserted_at' => now()->subMinutes(59)->toIso8601String()]]];
        config()->set('ehealth.referrals.reuse_after_minutes', null);
        $this->assertFalse(Auth::user()->can('takeIntoWork', [ServiceRequestRequest::class, $referral]));
        config()->set('ehealth.referrals.reuse_after_minutes', 60);
        $this->assertFalse(Auth::user()->can('takeIntoWork', [ServiceRequestRequest::class, $referral]));
        $referral['used_by_legal_entity_history'][0]['inserted_at'] = now()->subMinutes(61)->toIso8601String();
        $this->assertTrue(Auth::user()->can('takeIntoWork', [ServiceRequestRequest::class, $referral]));
        unset($referral['used_by_legal_entity_history']);
        $this->assertFalse(Auth::user()->can('takeIntoWork', [ServiceRequestRequest::class, $referral]));
    }

    public function test_issuer_can_view_completed_foreign_emz_without_importing_it(): void
    {
        $data = $this->resource();
        $data['status'] = 'completed';
        $data['completedWith'] = $this->reference((string) Str::uuid(), 'encounter');
        $record = Repository::serviceRequest()->importResource($data, $this->context['person']);
        $native = Arr::toSnakeCase($data);
        $this->mock(ServiceRequestApi::class)->shouldReceive('getById')->once()->andReturn($this->response($native));
        $resource = $this->compositionEncounter(data_get($native, 'completed_with.identifier.value'), (string) Str::uuid()) + ['reasons' => [], 'actions' => [], 'explanatory_letter' => 'Foreign clinical result'];
        foreach (['performer', 'visit', 'episode'] as $field) {
            $resource[$field]['display_value'] = 'Synthetic';
        }
        $encounterResponse = NativeClinicalFixtures::response('GET /api/patients/{patient_id}/encounters/{encounter_id}', $resource);
        $this->assertSame($resource, $encounterResponse->validate());
        $this->mock(EncounterApi::class)->shouldReceive('getById')->once()->andReturn($encounterResponse);
        $count = Encounter::count();
        Livewire::test(PatientReferrals::class, ['legalEntity' => $this->context['legalEntity'], 'personId' => $this->context['person']->id])->call('viewCompletedResource', $record->uuid)->assertSet('showCompletedResourceModal', true)->assertSee('Foreign clinical result');
        $this->assertSame($count, Encounter::count());
    }

    public function test_foreign_issuer_and_wrong_patient_completed_result_are_refused_without_read(): void
    {
        $data = $this->resource();
        $data['status'] = 'completed';
        $data['requesterLegalEntity'] = $this->reference((string) Str::uuid(), 'legal_entity');
        $record = Repository::serviceRequest()->importResource($data, $this->context['person']);
        $this->mock(ServiceRequestApi::class)->shouldNotReceive('getById');
        $this->mock(EncounterApi::class)->shouldNotReceive('getById');
        Livewire::test(PatientReferrals::class, ['legalEntity' => $this->context['legalEntity'], 'personId' => $this->context['person']->id])->call('viewCompletedResource', $record->uuid)->assertSet('showCompletedResourceModal', false)->assertSee(__('referrals.messages.completed_resource_forbidden'));
    }

    public function test_mismatched_remote_completed_patient_does_not_change_ui_or_clinical_storage(): void
    {
        $data = $this->resource();
        $data['status'] = 'completed';
        $data['completedWith'] = $this->reference((string) Str::uuid(), 'encounter');
        $record = Repository::serviceRequest()->importResource($data, $this->context['person']);
        $data['subject'] = $this->reference((string) Str::uuid(), 'patient');
        $this->mock(ServiceRequestApi::class)->shouldReceive('getById')->once()->andReturn($this->response(Arr::toSnakeCase($data)));
        $this->mock(EncounterApi::class)->shouldNotReceive('getById');
        Livewire::test(PatientReferrals::class, ['legalEntity' => $this->context['legalEntity'], 'personId' => $this->context['person']->id])->call('viewCompletedResource', $record->uuid)->assertSet('showCompletedResourceModal', false)->assertSet('completedResource', []);
        $this->assertSame($this->context['person']->uuid, $record->fresh()->subjectReference->value);
    }
}
