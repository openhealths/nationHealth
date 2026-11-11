<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Classes\eHealth\Api\Drug as DrugApi;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\EncounterPackage\EncounterEPrescriptionCreate;
use App\Livewire\Person\Records\PatientMedicationRequests;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Policies\EncounterPolicy;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Services\Dictionary\Dictionaries\DrugDictionary;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class ClinicalPrescriptionUiTest extends TestCase
{
    use CompositionTestFixtures;
    use CreatesFhirCarePlans;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('uk');
        Http::preventStrayRequests();
        foreach ([MedicalProgramDictionary::KEY, DrugDictionary::KEY] as $key) {
            Cache::forever($key, []);
            Cache::forever($key.':fresh', true);
        }
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData', 'validate')->andReturn([]);
        $this->mock(PersonApi::class)->shouldReceive('getAuthMethods')->andReturn($response);
        $this->partialMock(EncounterPolicy::class)->shouldReceive('createMedicationRequest')->andReturn(Response::allow());
    }

    private function context(): array
    {
        $context = $this->compositionFixture(scopes: ['encounter:read', 'medication_request_request:write', 'medication_request:read']);
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $context['person']->id, 'status' => 'finished',
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'class_id' => Coding::firstOrCreate(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create()->id,
        ]);

        return $context + compact('encounter');
    }

    private function categories(): void
    {
        $dictionaries = Cache::get(BasicDictionary::KEY);
        $dictionaries[] = ['name' => 'MEDICATION_REQUEST_CATEGORY', 'values' => [
            ['code' => 'community', 'description' => 'Амбулаторно', 'is_active' => true],
            ['code' => 'discharge', 'description' => 'При виписці', 'is_active' => true],
            ['code' => 'retired', 'description' => 'Неактивна категорія', 'is_active' => false],
        ]];
        Cache::forever(BasicDictionary::KEY, $dictionaries);
    }

    private function form(array $context): array
    {
        return ['legalEntity' => $context['legalEntity'], 'person' => $context['person'], 'encounter' => $context['encounter'], 'preperson' => null];
    }

    public function test_active_dictionary_options_are_visible_and_the_selected_category_reaches_creation(): void
    {
        $context = $this->context();
        $this->categories();
        $drug = json_decode(file_get_contents(base_path('tests/Fixtures/medication-official-responses.json')), true, flags: JSON_THROW_ON_ERROR)['drugs'][0];
        $drugResponse = Mockery::mock(EHealthResponse::class);
        $drugResponse->shouldReceive('validate')->andReturn([$drug]);
        $this->mock(DrugApi::class)->shouldReceive('getMany')->once()->andReturn($drugResponse);
        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class)->makePartial();
        $lifecycle->shouldReceive('resolveEncounterEmployeeContext')->andReturn([]);
        $lifecycle->shouldReceive('createEncounterDraft')->once()->with(Mockery::type(Encounter::class), Mockery::on(
            static fn (array $form): bool => $form['category'] === 'discharge'
        ), [])->andReturn((string) Str::uuid());
        $this->instance(MedicationRequestLifecycleService::class, $lifecycle);
        Livewire::test(EncounterEPrescriptionCreate::class, $this->form($context))
            ->assertSee('Амбулаторно')->assertSee('При виписці')->assertDontSee('Неактивна категорія')
            ->set('encounterEPrescriptionForm.category', 'discharge')
            ->set('encounterEPrescriptionSearchQuery', 'Аміодарон')
            ->call('searchEncounterEPrescriptionMedications')
            ->call('selectEncounterEPrescriptionMedication', $drug['id'])
            ->set('encounterEPrescriptionForm.signature_text', 'Один раз на день')
            ->set('encounterEPrescriptionForm.inform_with', 'synthetic-auth')
            ->set('encounterEPrescriptionForm.medication_qty', 30)
            ->set('encounterEPrescriptionForm.phone_confirmed', true)
            ->call('validateEncounterEPrescription')->assertHasNoErrors()->assertSet('showSignatureModal', true);
    }

    public function test_inactive_or_unknown_category_is_rejected_before_creation(): void
    {
        $context = $this->context();
        $this->categories();
        $this->mock(MedicationRequestLifecycleService::class)->shouldNotReceive('createEncounterDraft');
        Livewire::test(EncounterEPrescriptionCreate::class, $this->form($context))
            ->set('encounterEPrescriptionForm.category', 'retired')->call('validateEncounterEPrescription')
            ->assertHasErrors('encounterEPrescriptionForm.category')->assertSet('showSignatureModal', false);
        $this->assertDatabaseCount('medication_request_requests', 0);
    }

    public function test_missing_category_dictionary_has_visible_feedback_instead_of_a_server_error(): void
    {
        $context = $this->context();
        Livewire::test(EncounterEPrescriptionCreate::class, $this->form($context))->assertOk()
            ->assertSee(__('medication-requests.category_dictionary_unavailable'));
    }

    #[DataProvider('resourceKinds')]
    public function test_both_registry_tabs_link_to_their_actual_local_basis(string $kind, string $tab): void
    {
        $context = $this->context();
        $plan = $this->createFhirCarePlan([
            'person_id' => $context['person']->id, 'legal_entity_id' => $context['legalEntity']->id,
            'author_id' => $context['employee']->id, 'status' => 'active',
        ]);
        $activity = CarePlanActivity::create(['uuid' => (string) Str::uuid(), 'care_plan_id' => $plan->id, 'status' => 'scheduled', 'kind' => 'medication_request']);
        foreach (['encounter' => $context['encounter']->uuid, 'care_plan_activity' => $activity->uuid] as $type => $uuid) {
            MedicationRequestRequest::create([
                'uuid' => (string) Str::uuid(), 'person_id' => $context['person']->id, 'status' => 'active',
                'medication_id' => (string) Str::uuid(), 'resource_type' => $kind,
                $type === 'encounter' ? 'context_id' : 'based_on_id' => Identifier::create(['value' => $uuid])->id,
            ]);
        }
        $component = Livewire::test(PatientMedicationRequests::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id]);
        if ($tab === 'prescriptions') {
            $component->call('switchTab', $tab);
        }
        $component->assertSeeHtml('href="'.route('persons.encounters.package.show', [$context['legalEntity'], 'person' => $context['person']->id, 'encounter' => $context['encounter']->id]).'"')
            ->assertSeeHtml('href="'.route('care-plans.show', [$context['legalEntity'], $plan->id]).'"');
    }

    public static function resourceKinds(): array
    {
        return [[MedicationRequestRequest::TYPE_REQUEST, 'requests'], [MedicationRequestRequest::TYPE_PRESCRIPTION, 'prescriptions']];
    }
}
