<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\BaseForm;
use App\Enums\JobStatus;
use App\Enums\Person\EncounterStatus;
use App\Livewire\EncounterPackage\EncounterReferralCreate;
use App\Livewire\Person\Records\PatientReferrals;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
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
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Services\SignatureService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\TestCase;

class EncounterReferralPlanLinkTest extends TestCase
{
    use CompositionTestFixtures;
    use CreatesFhirCarePlans;
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;
    use UsesCompositionDictionaries;

    private const string SERVICE = '10050000-0000-4000-8000-000000000011';

    private array $context;
    private Encounter $encounter;
    private CarePlan $plan;
    private CarePlanActivity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->context = $this->compositionFixture(scopes: ['service_request:read', 'service_request:write', 'encounter:read', 'care_plan:read', 'care_plan:write']);
        $this->grantReferralEmployeeRole($this->context['user'], $this->context['employee']);
        $this->grantCarePlanEmployeeRole($this->context['employee'], 'OUTPATIENT');
        $episode = Episode::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->context['person']->id, 'status' => 'active', 'name' => 'Linked encounter', 'managing_organization_id' => Identifier::create(['value' => $this->context['legalEntity']->uuid])->id]);
        $this->encounter = Encounter::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->context['person']->id, 'status' => 'finished', 'episode_id' => Identifier::create(['value' => $episode->uuid])->id, 'performer_id' => Identifier::create(['value' => $this->context['employee']->uuid])->id, 'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id, 'type_id' => CodeableConcept::create()->id]);
        Approval::create(['uuid' => (string) Str::uuid(), 'approvable_type' => $this->context['person']::class, 'approvable_id' => $this->context['person']->id, 'granted_to_id' => Identifier::create(['value' => $this->context['employee']->uuid])->id, 'granted_to_type' => 'employee', 'status' => 'active', 'is_verified' => true, 'expires_at' => now()->addHour()]);
        $this->plan = $this->createFhirCarePlan(['person_id' => $this->context['person']->id, 'author_id' => $this->context['employee']->id, 'legal_entity_id' => $this->context['legalEntity']->id, 'encounter_id' => $this->encounter->id, 'status' => 'new', 'category' => 'class_23', 'period_end' => now()->addMonth()->toDateString()]);
        $this->grantCarePlanWriteApproval($this->plan, $this->context['employee']);
        $this->activity = $this->createActivity();
        Cache::forever(ServiceDictionary::KEY, [['id' => self::SERVICE, 'code' => 'TEST', 'name' => 'Laboratory', 'is_active' => true, 'request_allowed' => true, 'category' => 'laboratory_procedure']]);
        Cache::forever(ServiceDictionary::KEY.':fresh', true);
        Cache::forever(MedicalProgramDictionary::KEY, []);
        Cache::forever(MedicalProgramDictionary::KEY.':fresh', true);
        Cache::forever('knedp_certificate_authority', []);
        $dictionaries = Cache::get(BasicDictionary::KEY, []);
        $dictionaries[] = ['name' => 'SERVICE_REQUEST_PRIORITY', 'values' => [['code' => 'routine', 'description' => 'Планове', 'is_active' => true]]];
        Cache::forever(BasicDictionary::KEY, $dictionaries);
        Cache::forever(BasicDictionary::KEY.':fresh', true);
    }

    private function createActivity(): CarePlanActivity
    {
        $repository = Repository::carePlanActivity();
        $activity = $repository->saveForm(['kind' => 'service_request', 'status' => 'draft', 'quantity' => 10, 'quantity_system' => 'SERVICE_UNIT', 'quantity_code' => 'PIECE', 'product_reference' => self::SERVICE, 'product_reference_type' => 'service', 'author_id' => $this->context['employee']->id, 'scheduled_period_start' => now()->toDateString(), 'scheduled_period_end' => now()->addDay()->toDateString()], $this->plan);
        $resource = $repository->getResource($activity);
        $resource['detail']['status'] = 'scheduled';
        $resource['detail']['remainingQuantity'] = ['value' => 5, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE', 'unit' => 'шт.'];

        return $repository->storeResource($resource, $this->plan, $activity->id);
    }

    private function formData(): array
    {
        return ['care_plan_id' => $this->plan->id, 'care_plan_activity_id' => $this->activity->id, 'based_on_uuid' => $this->activity->uuid, 'service_id' => self::SERVICE, 'service_type' => 'service', 'category' => 'laboratory_procedure', 'quantity' => 2, 'started_at' => now()->addHour()->toIso8601String(), 'ended_at' => now()->addDay()->toIso8601String()];
    }

    private function save(?string $uuid = null, ?array $data = null): ServiceRequestRequest
    {
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $employeeContext = $lifecycle->resolveEncounterEmployeeContext($this->encounter, $this->context['employee']->id);
        $uuid = $lifecycle->createEncounterDraft($this->encounter, $data ?? $this->formData(), $employeeContext, $uuid);

        return MedicalEventsRepository::serviceRequest()->findByUuid($uuid);
    }

    public function test_saved_basis_native_units_and_encounter_survive_reopening_switch_and_explicit_detachment(): void
    {
        $record = $this->save();
        $resource = MedicalEventsRepository::serviceRequest()->getResource($record);
        $this->assertSame([$this->plan->uuid, $this->activity->uuid], array_column(array_column($resource['basedOn'], 'identifier'), 'value'));
        $this->assertSame($this->encounter->uuid, data_get($resource, 'context.identifier.value'));
        $this->assertSame(['value' => 2.0, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE', 'unit' => 'шт.'], $resource['quantity']);
        $data = app(ReferralRequestLifecycleService::class)->buildSignDbData($record, null, $this->encounter, ['employee_id' => $this->context['employee']->id]);
        $reopened = $this->save($record->uuid, $data);
        $this->assertSame($resource['basedOn'], MedicalEventsRepository::serviceRequest()->getResource($reopened)['basedOn']);
        $next = $this->createActivity();
        $switched = $this->save($record->uuid, array_replace($this->formData(), ['care_plan_activity_id' => $next->id, 'based_on_uuid' => $next->uuid]));
        $this->assertSame($next->uuid, $switched->basedOn->value);
        $detached = $this->save($record->uuid, array_replace($this->formData(), ['care_plan_id' => 0, 'care_plan_activity_id' => 0, 'based_on_uuid' => null]));
        $this->assertSame([], MedicalEventsRepository::serviceRequest()->getResource($detached)['basedOn']);
        Http::assertNothingSent();
    }

    public static function staleLinks(): array
    {
        return array_map(static fn (string $case): array => [$case], ['expired_approval', 'missing_activity', 'terminal_plan', 'depleted_quantity', 'unknown_quantity', 'wrong_product', 'wrong_actor', 'wrong_patient', 'wrong_legal_entity', 'pending_activity']);
    }

    #[DataProvider('staleLinks')]
    public function test_unavailable_basis_is_rejected_before_prequalification_persistence_or_signing(string $case): void
    {
        $record = $this->save();
        $before = MedicalEventsRepository::serviceRequest()->getResource($record);
        $data = $this->formData();
        match ($case) {
            'expired_approval' => $this->plan->approvals()->update(['expires_at' => now()->subMinute()]),
            'missing_activity' => $this->activity->delete(),
            'terminal_plan' => $this->plan->update(['status' => 'completed']),
            'depleted_quantity', 'unknown_quantity' => $this->setRemaining($case === 'depleted_quantity' ? 0 : null),
            'wrong_product' => $data['service_id'] = (string) Str::uuid(),
            'wrong_actor' => $this->plan->approvals()->update(['granted_to_id' => Identifier::create(['value' => (string) Str::uuid()])->id]),
            'wrong_patient' => $this->plan->update(['person_id' => Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE', 'first_name' => 'Інша', 'last_name' => 'Пацієнтка', 'birth_date' => '1985-05-05'])->id]),
            'wrong_legal_entity' => $this->plan->update(['legal_entity_id' => LegalEntity::create(['uuid' => (string) Str::uuid()])->id]),
            'pending_activity' => $this->activity->ehealthLinks()->create(['entity' => 'job', 'href' => 'https://ehealth.invalid/jobs/pending', 'status' => JobStatus::PENDING->value]),
        };
        $this->mock(ServiceRequestApi::class)->shouldNotReceive('prequalify');
        $this->mock(SignatureService::class)->shouldNotReceive('signData');
        try {
            $lifecycle = app(ReferralRequestLifecycleService::class);
            $lifecycle->prepareEncounterDraftForSigning($this->encounter, $data, $lifecycle->resolveEncounterEmployeeContext($this->encounter, $this->context['employee']->id), $record->uuid);
            $this->fail('Unavailable activity must not be silently detached.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
            $this->assertSame($before, MedicalEventsRepository::serviceRequest()->getResource($record->fresh()));
            Http::assertNothingSent();
        }
    }

    private function setRemaining(?int $value): void
    {
        $repository = Repository::carePlanActivity();
        $resource = $repository->getResource($this->activity);
        $resource['detail']['remainingQuantity'] = $value === null ? null : ['value' => $value, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE'];
        $repository->storeResource($resource, $this->plan, $this->activity->id);
    }

    public function test_patient_registry_checks_the_current_link_before_signing_and_retains_the_signed_native_context(): void
    {
        $record = $this->save();
        $component = $this->patientComponent($record);
        $signature = $this->mock(SignatureService::class);
        $signature->shouldReceive('signData')->once()->with(Mockery::on(function (array $payload): bool {
            $this->assertSame([$this->plan->uuid, $this->activity->uuid], array_column(array_column($payload['based_on'], 'identifier'), 'value'));
            $this->assertSame($this->encounter->uuid, data_get($payload, 'context.identifier.value'));
            $this->assertSame('PIECE', data_get($payload, 'quantity.code'));

            return true;
        }), 'synthetic', 'synthetic', null, $this->context['user']->party->taxId)->andThrow(new RuntimeException('Synthetic signature boundary'));
        $before = MedicalEventsRepository::serviceRequest()->getResource($record);
        $component->signDraft();
        $this->assertStringContainsString('Synthetic signature boundary', Session::get('error'));
        $this->assertSame($before, MedicalEventsRepository::serviceRequest()->getResource($record->fresh()));
        Http::assertNothingSent();
    }

    public function test_encounter_form_offers_the_provider_choices_and_preserves_an_unavailable_saved_basis(): void
    {
        $record = $this->save();
        $api = new PersonApi(Http::getFacadeRoot());
        $validator = (function (EHealthResponse $response): array {
            return $this->validateAuthMethods($response);
        })->bindTo($api, PersonApi::class);
        $response = new EHealthResponse(new Response(200, [], json_encode(['data' => []])), $validator);
        $response->validate();
        $this->mock(PersonApi::class)->shouldReceive('getAuthMethods')->twice()->andReturn($response);
        $component = $this->encounterComponent($record);
        $this->assertContains($this->plan->id, array_column($component->encounterReferralPlanChoices(), 'id'));
        $this->assertContains($this->activity->id, array_column($component->encounterReferralActivityChoices(), 'id'));
        $this->plan->approvals()->update(['expires_at' => now()->subMinute()]);
        $this->assertSame([], $component->encounterReferralActivityChoices());
        $this->assertSame($this->activity->uuid, $component->encounterReferralForm['based_on_uuid']);
        $this->activity->delete();
        $reopened = $this->encounterComponent($record);
        $this->assertSame($this->activity->uuid, $reopened->encounterReferralForm['based_on_uuid']);
        $this->assertSame(0, $reopened->encounterReferralForm['care_plan_activity_id']);
        $before = MedicalEventsRepository::serviceRequest()->getResource($record);
        try {
            $reopened->validateEncounterReferral(false);
            $this->fail('The reopened form must retain and reject its unavailable saved basis.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('encounterReferralForm.care_plan_activity_id', $exception->errors());
        }
        $this->assertNotEmpty(Session::get('error'));
        $this->assertSame($before, MedicalEventsRepository::serviceRequest()->getResource($record->fresh()));
        Http::assertNothingSent();
    }

    private function encounterComponent(ServiceRequestRequest $record): EncounterReferralCreate
    {
        $component = new EncounterReferralCreate();
        $component->setId((string) Str::uuid());
        $component->setName('encounter-package.encounter-referral-create');
        $component->mount($this->context['legalEntity'], $this->context['person'], null, $this->encounter, $record->id);

        return $component;
    }

    public static function staleRegistryLinks(): array
    {
        return array_slice(self::staleLinks(), 0, 5);
    }

    #[DataProvider('staleRegistryLinks')]
    public function test_patient_registry_rejects_stale_or_foreign_saved_basis_before_signing(string $case): void
    {
        $record = $this->save();
        $component = $this->patientComponent($record);
        match ($case) {
            'expired_approval' => $this->plan->approvals()->update(['expires_at' => now()->subMinute()]),
            'missing_activity' => $this->activity->delete(),
            'terminal_plan' => $this->plan->update(['status' => 'completed']),
            'depleted_quantity', 'unknown_quantity' => $this->setRemaining($case === 'depleted_quantity' ? 0 : null),
        };
        $before = MedicalEventsRepository::serviceRequest()->getResource($record);
        $this->mock(SignatureService::class)->shouldNotReceive('signData');
        try {
            $component->signDraft();
            $this->fail('A saved stale link must not reach signing.');
        } catch (ValidationException) {
            $this->assertNotEmpty(Session::get('error'));
            $this->assertSame($before, MedicalEventsRepository::serviceRequest()->getResource($record->fresh()));
            Http::assertNothingSent();
        }
    }

    public function test_patient_registry_denial_precedes_credentials_api_and_storage(): void
    {
        $record = $this->save();
        $component = $this->patientComponent($record);
        $this->context['employee']->update(['end_date' => now()->subDay()->toDateString()]);
        $this->mock(SignatureService::class)->shouldNotReceive('signData');
        $before = MedicalEventsRepository::serviceRequest()->getResource($record);
        $component->signDraft();
        $this->assertSame(__('referrals.messages.action_unavailable'), Session::get('error'));
        $this->assertSame($before, MedicalEventsRepository::serviceRequest()->getResource($record->fresh()));
        Http::assertNothingSent();
    }

    public function test_patient_registry_rechecks_changed_inpatient_rules_before_credentials_and_signing(): void
    {
        $record = $this->save();
        $component = $this->patientComponent($record);
        $inpatient = Coding::create(['code' => 'INPATIENT', 'system' => 'eHealth/encounter_classes']);
        $this->encounter->update(['class_id' => $inpatient->id]);
        $this->mock(SignatureService::class)->shouldNotReceive('signData');
        $before = MedicalEventsRepository::serviceRequest()->getResource($record);
        $component->signDraft();
        $this->assertSame(__('referrals.messages.action_unavailable'), Session::get('error'));
        $this->assertSame($before, MedicalEventsRepository::serviceRequest()->getResource($record->fresh()));
        Http::assertNothingSent();
    }

    public static function changedSourceDuringSigning(): array
    {
        return [
            'patient source entered in error' => ['patient', 'status'],
            'encounter source entered in error' => ['encounter', 'status'],
            'patient source became inpatient' => ['patient', 'class'],
            'encounter source became inpatient' => ['encounter', 'class'],
        ];
    }

    #[DataProvider('changedSourceDuringSigning')]
    public function test_source_permissions_are_rechecked_after_signing_before_storage_or_submission(string $surface, string $change): void
    {
        $record = $this->save();
        if ($surface === 'encounter') {
            $api = new PersonApi(Http::getFacadeRoot());
            $validator = (function (EHealthResponse $response): array {
                return $this->validateAuthMethods($response);
            })->bindTo($api, PersonApi::class);
            $response = new EHealthResponse(new Response(200, [], json_encode(['data' => []])), $validator);
            $this->mock(PersonApi::class)->shouldReceive('getAuthMethods')->once()->andReturn($response);
            $component = $this->encounterComponent($record);
            $component->showSignatureModal = true;
            $form = Mockery::mock(BaseForm::class, [$component, 'form'])->makePartial();
            $form->shouldReceive('signingRules')->andReturn([]);
            $form->shouldReceive('validate')->andReturn(['password' => 'synthetic', 'knedp' => 'synthetic', 'keyContainerUpload' => null]);
            $component->form = $form;
        } else {
            $component = $this->patientComponent($record);
        }
        $before = MedicalEventsRepository::serviceRequest()->getResource($record);
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)/i', $query->sql) && str_contains($query->sql, 'service_request')) {
                $writes[] = $query->sql;
            }
        });
        $this->mock(SignatureService::class)->shouldReceive('signData')->once()->andReturnUsing(function () use ($change): string {
            if ($change === 'status') {
                $this->encounter->update(['status' => EncounterStatus::ENTERED_IN_ERROR]);
            } else {
                $inpatient = Coding::create(['code' => 'INPATIENT', 'system' => 'eHealth/encounter_classes']);
                $this->encounter->update(['class_id' => $inpatient->id]);
            }

            return 'synthetic-signed-content';
        });
        $this->mock(ServiceRequestApi::class)->shouldNotReceive('createSigned');
        $component->{$surface === 'patient' ? 'signDraft' : 'signEncounterReferral'}();
        $this->assertSame(__('referrals.messages.action_unavailable'), Session::get('error'));
        $this->assertSame([], $writes);
        $this->assertSame($before, MedicalEventsRepository::serviceRequest()->getResource($record->fresh()));
        Http::assertNothingSent();
    }

    private function patientComponent(ServiceRequestRequest $record): PatientReferrals
    {
        $component = new PatientReferrals();
        $component->setId((string) Str::uuid());
        $component->setName('person.records.patient-referrals');
        $component->personId = $this->context['person']->id;
        $component->uuid = $this->context['person']->uuid;
        $component->requestIdToSign = $record->uuid;
        $component->showSignatureModal = true;
        $form = Mockery::mock(BaseForm::class, [$component, 'form'])->makePartial();
        $form->shouldReceive('signingRules')->andReturn([]);
        $form->shouldReceive('validate')->andReturn(['password' => 'synthetic', 'knedp' => 'synthetic', 'keyContainerUpload' => null]);
        $component->form = $form;

        return $component;
    }
}
