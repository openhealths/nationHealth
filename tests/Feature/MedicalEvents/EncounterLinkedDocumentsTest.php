<?php

declare(strict_types=1);

namespace Tests\Feature\MedicalEvents;

use App\Enums\Person\MergedPersonStatus;
use App\Livewire\Encounter\EncounterView;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MergedPerson;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\TestCase;

class EncounterLinkedDocumentsTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;
    use UsesCompositionDictionaries;

    private array $context;

    private const array SCOPES = [
        'patient_summary:read', 'encounter:read', 'service_request:read',
        'medication_request:read', 'medication_request_request:read',
        'device_request:read', 'care_plan:read', 'composition:read', 'composition:search',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = $this->compositionFixture(scopes: self::SCOPES);
        $this->grantReferralEmployeeRole($this->context['user'], $this->context['employee']);
        $dictionaries = Cache::get(BasicDictionary::KEY);
        foreach (['eHealth/ICPC2/reasons', 'eHealth/ICPC2/actions', 'eHealth/diagnosis_roles', 'eHealth/cancellation_reasons', 'eHealth/encounter_admit_source', 'eHealth/encounter_re_admission', 'eHealth/encounter_discharge_disposition', 'eHealth/encounter_discharge_department'] as $name) {
            $dictionaries[] = ['name' => $name, 'values' => []];
        }
        Cache::forever(BasicDictionary::KEY, $dictionaries);
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_all_five_document_types_are_visible_and_foreign_owners_and_other_encounters_are_excluded(): void
    {
        $person = $this->context['person'];
        $encounter = $this->encounter($person);
        $documents = $this->documents($person, $encounter);
        $foreign = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE', 'birth_date' => '1980-01-01']);
        $this->documents($foreign, $encounter, 'Foreign');
        $this->documents($person, $this->encounter($person), 'Unrelated');

        $groups = Repository::encounter()->linkedDocuments($encounter, $person, $this->context['user']);
        $this->assertSame(['referrals', 'prescriptions', 'device-requests', 'care-plans', 'compositions'], array_keys($groups));
        foreach ($groups as $kind => $group) {
            $this->assertCount(1, $group['records'], $kind);
            $this->assertSame($documents[$kind]->uuid, $group['records'][0]['id']);
        }

        $component = Livewire::test(EncounterView::class, $this->parameters($person, $encounter))
            ->assertSee(__('encounters.linked_documents.title'))
            ->assertSee('Linked referral')->assertSee('Linked prescription')->assertSee('Linked device')
            ->assertSee('Linked plan')->assertSee($documents['compositions']->uuid)
            ->assertDontSee('Foreign')->assertDontSee('Unrelated');
        foreach (['persons.referrals', 'persons.medication-requests', 'persons.device-requests', 'persons.care-plans', 'persons.compositions'] as $route) {
            $component->assertSeeHtml(route($route, [ $this->context['legalEntity'], 'person' => $person->id]));
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_requests_based_on_the_encounter_plan_activity_are_included_without_a_direct_context(): void
    {
        $person = $this->context['person'];
        $encounter = $this->encounter($person);
        $plan = $this->documents($person, $encounter)['care-plans'];
        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(), 'care_plan_id' => $plan->id,
            'author_id' => $this->context['employee']->id, 'kind' => 'service_request', 'status' => 'scheduled',
        ]);
        $basis = Identifier::create(['value' => $activity->uuid]);
        $referral = ServiceRequestRequest::create(['service_id' => (string) Str::uuid(), 'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'active']);
        $referral->basedOnReferences()->attach($basis->id, ['position' => 0]);
        $prescription = MedicationRequestRequest::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'active']);
        $prescription->references()->attach($basis->id, ['field' => 'basedOn', 'position' => 0]);
        $device = DeviceRequestRequest::create(['device_id' => (string) Str::uuid(), 'employee_id' => $this->context['employee']->id, 'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'active', 'based_on_id' => $basis->id]);

        $groups = Repository::encounter()->linkedDocuments($encounter, $person, $this->context['user']);
        foreach (['referrals' => $referral, 'prescriptions' => $prescription, 'device-requests' => $device] as $kind => $record) {
            $this->assertContains($record->uuid, array_column($groups[$kind]['records'], 'id'));
        }
    }

    public function test_incoming_referral_is_visible_even_when_it_was_issued_under_another_encounter(): void
    {
        $person = $this->context['person'];
        $encounter = $this->encounter($person);
        $incoming = ServiceRequestRequest::create([
            'service_id' => (string) Str::uuid(), 'uuid' => (string) Str::uuid(),
            'person_id' => $person->id, 'status' => 'active', 'request_number' => 'Incoming referral',
        ]);
        $encounter->update(['incoming_referral_id' => Identifier::create(['value' => $incoming->uuid])->id]);
        $groups = Repository::encounter()->linkedDocuments($encounter->fresh(), $person, $this->context['user']);
        $this->assertSame([$incoming->uuid], array_column($groups['referrals']['records'], 'id'));

        // Legacy identifiers may contain display text; they must never enter UUID columns as a filter.
        $encounter->update(['incoming_referral_id' => Identifier::create(['value' => 'Unknown referral'])->id]);
        $groups = Repository::encounter()->linkedDocuments($encounter->fresh(), $person, $this->context['user']);
        $this->assertSame([], $groups['referrals']['records']);
    }

    public function test_preperson_documents_keep_the_preperson_owner_and_reject_a_dual_owner(): void
    {
        $preperson = $this->compositionPreperson();
        $encounter = $this->encounter($preperson);
        $reference = Identifier::create(['value' => $encounter->uuid]);
        $referral = ServiceRequestRequest::create(['service_id' => (string) Str::uuid(), 'uuid' => (string) Str::uuid(), 'preperson_id' => $preperson->id, 'context_id' => $reference->id, 'status' => 'active']);
        $composition = Composition::create(['uuid' => (string) Str::uuid(), 'preperson_id' => $preperson->id, 'encounter_id' => $reference->id, 'status' => 'FINAL']);
        Composition::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->context['person']->id, 'preperson_id' => $preperson->id, 'encounter_id' => $reference->id, 'status' => 'FINAL']);
        $groups = Repository::encounter()->linkedDocuments($encounter, $preperson, $this->context['user']);

        $this->assertSame([$referral->uuid], array_column($groups['referrals']['records'], 'id'));
        $this->assertSame([$composition->uuid], array_column($groups['compositions']['records'], 'id'));
        $this->assertSame(route('prepersons.compositions', [$this->context['legalEntity'], 'preperson' => $preperson->id]), $groups['compositions']['url']);
        $this->assertSame([], $groups['prescriptions']['records']);
        $encounter->update(['person_id' => $this->context['person']->id]);
        $this->assertSame([], Repository::encounter()->linkedDocuments($encounter, $preperson, $this->context['user']));
    }

    public function test_merged_preperson_documents_and_validated_sync_keep_the_original_owner(): void
    {
        $person = $this->context['person'];
        $preperson = $this->compositionPreperson();
        MergedPerson::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id,
            'merged_uuid' => $preperson->uuid, 'merged_preperson_id' => $preperson->id,
            'status' => MergedPersonStatus::MERGED, 'ehealth_inserted_at' => now(), 'ehealth_updated_at' => now(),
        ]);
        Approval::create([
            'uuid' => (string) Str::uuid(), 'approvable_type' => Person::class, 'approvable_id' => $person->id,
            'granted_to_id' => Identifier::create(['value' => $this->context['employee']->uuid])->id,
            'granted_to_type' => 'employee', 'status' => 'active', 'is_verified' => true,
            'expires_at' => now()->addHour(), 'access_level' => 'read',
        ]);
        $encounter = $this->encounter($preperson);
        $composition = Composition::create([
            'uuid' => (string) Str::uuid(), 'preperson_id' => $preperson->id,
            'encounter_id' => Identifier::create(['value' => $encounter->uuid])->id, 'status' => 'FINAL',
        ]);
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/shared-official-responses.json')), true, flags: JSON_THROW_ON_ERROR);
        $response = $fixtures['GET /api/patients/{patient_id}/encounters/{encounter_id}'];
        $response['data']['id'] = $encounter->uuid;
        // The archived example misspells finished; the native enum contract remains unchanged.
        $response['data']['status'] = 'finished';
        $this->fakeEHealth(['*/patients/'.$preperson->uuid.'/encounters/'.$encounter->uuid => Http::response($response, 200)]);

        Livewire::test(EncounterView::class, $this->parameters($person, $encounter))
            ->assertSee($composition->uuid)->call('sync')
            ->assertSee(__('encounters.messages.record_synced_successfully'));

        $this->assertSame($preperson->id, $encounter->fresh()->preperson_id);
        $this->assertNull($encounter->fresh()->person_id);
        $this->assertSame('finished', $encounter->fresh()->status->value);
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/patients/'.$preperson->uuid.'/encounters/'.$encounter->uuid));
        Http::assertSentCount(1);
    }

    public function test_revoked_module_permissions_hide_documents_on_the_open_encounter(): void
    {
        $person = $this->context['person'];
        $encounter = $this->encounter($person);
        $this->documents($person, $encounter);
        foreach (['medication_request:read', 'medication_request_request:read', 'device_request:read', 'care_plan:read', 'composition:read'] as $scope) {
            $this->context['user']->revokePermissionTo($scope);
        }

        $groups = Repository::encounter()->linkedDocuments($encounter, $person, $this->context['user']);
        foreach (['prescriptions', 'device-requests', 'care-plans', 'compositions'] as $kind) {
            $this->assertSame([], $groups[$kind]['records']);
        }
        $this->assertCount(1, $groups['referrals']['records']);
    }

    public function test_revoked_encounter_access_has_visible_feedback_without_http_queue_or_database_changes(): void
    {
        $person = $this->context['person'];
        $encounter = $this->encounter($person);
        $component = Livewire::test(EncounterView::class, $this->parameters($person, $encounter));
        $original = $encounter->fresh()->getRawOriginal();
        $this->context['user']->revokePermissionTo('encounter:read');

        $component->call('sync')->assertSee(__('encounters.sync_access_denied'))->assertDontSee($encounter->uuid);
        $this->assertSame($original, $encounter->fresh()->getRawOriginal());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_malformed_native_encounter_response_shows_feedback_and_preserves_the_local_record(): void
    {
        $person = $this->context['person'];
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*/patients/*/encounters/*' => Http::response(['data' => ['id' => $encounter->uuid]], 200)]);
        $original = $encounter->fresh()->getRawOriginal();

        Livewire::test(EncounterView::class, $this->parameters($person, $encounter))->call('sync')
            ->assertSee(__('errors.ehealth.messages.request_error'));
        $this->assertSame($original, $encounter->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
    }

    public static function prescriptionRoutes(): array
    {
        return [['persons.medication-requests'], ['persons.prescription-requests'], ['persons.prescription-requests.view']];
    }

    #[DataProvider('prescriptionRoutes')]
    public function test_prescription_registry_aliases_share_the_active_patient_navigation_tab(string $name): void
    {
        $parameters = ['legalEntity' => $this->context['legalEntity']->id, 'person' => $this->context['person']->id, 'requestId' => (string) Str::uuid()];
        $url = route($name, $parameters);
        $request = Request::create($url);
        $route = Route::getRoutes()->getByName($name)->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        $view = $this->blade('<x-layouts.patient :personId="$personId" patientFullName="Patient" title="Documents" :showLegacyMessages="false"><x-slot name="description">Patient</x-slot></x-layouts.patient>', ['personId' => $this->context['person']->id]);
        $html = (string) $view;
        $href = preg_quote(route('persons.medication-requests', [$this->context['legalEntity'], 'person' => $this->context['person']->id]), '~');
        $this->assertMatchesRegularExpression('~href="'.$href.'"\s+class="summary-tab summary-tab-active"~', $html);
    }

    private function encounter(Person|Preperson $patient): Encounter
    {
        return Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $patient instanceof Person ? $patient->id : null,
            'preperson_id' => $patient instanceof Preperson ? $patient->id : null, 'status' => 'draft',
            'performer_id' => Identifier::create(['value' => $this->context['employee']->uuid])->id,
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create()->id,
        ]);
    }

    private function documents(Person $person, Encounter $encounter, string $label = 'Linked'): array
    {
        $reference = Identifier::create(['value' => $encounter->uuid]);

        return [
            'referrals' => ServiceRequestRequest::create(['service_id' => (string) Str::uuid(), 'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'context_id' => $reference->id, 'status' => 'active', 'request_number' => $label.' referral']),
            'prescriptions' => MedicationRequestRequest::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'context_id' => $reference->id, 'status' => 'active', 'request_number' => $label.' prescription']),
            'device-requests' => DeviceRequestRequest::create(['device_id' => (string) Str::uuid(), 'employee_id' => $this->context['employee']->id, 'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'context_id' => $reference->id, 'status' => 'active', 'request_number' => $label.' device']),
            'care-plans' => CarePlan::create(['author_id' => $this->context['employee']->id, 'period_start' => today(), 'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'encounter_id' => $encounter->id, 'legal_entity_id' => $this->context['legalEntity']->id, 'status' => 'active', 'title' => $label.' plan']),
            'compositions' => Composition::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'encounter_id' => $reference->id, 'status' => 'FINAL']),
        ];
    }

    private function parameters(Person|Preperson $patient, Encounter $encounter): array
    {
        return [
            'legalEntity' => $this->context['legalEntity'], 'encounter' => $encounter,
            'person' => $patient instanceof Person ? $patient : null,
            'preperson' => $patient instanceof Preperson ? $patient : null,
        ];
    }
}
