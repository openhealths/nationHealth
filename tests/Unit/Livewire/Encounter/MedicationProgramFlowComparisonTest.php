<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Encounter;

use App\Classes\eHealth\Api\Drug;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Person\EncounterStatus;
use App\Enums\User\Role;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\User;
use App\Models\LegalEntity;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Support\Collection;
use Illuminate\Support\ViewErrorBag;
use DOMDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanActivities;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterEPrescription;
use App\Services\Dictionary\DictionaryManager;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MedicationProgramFlowComparisonTest extends TestCase
{
    private Drug $drugApi;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldNotReceive('medicalPrograms');
        $this->instance(DictionaryManager::class, $manager);
        Http::preventStrayRequests();
        $this->drugApi = Mockery::mock(Drug::class);
        $this->instance(Drug::class, $this->drugApi);
    }

    private function expectDrugSearchForProgram(string $programId): void
    {
        $this->drugApi->shouldReceive('getMany')->once()
            ->with(Mockery::on(static fn (array $filters): bool => $filters['medical_program_id'] === $programId
                && $filters['innm_name'] === 'Metformin'))
            ->andReturn(new EHealthResponse(new Response(200, [], json_encode(['data' => [[
                'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                'name' => 'Metformin',
                'innm_name' => 'Metformin',
            ]]], JSON_THROW_ON_ERROR))));
    }

    public function test_care_plan_search_uses_default_program_without_a_program_selection(): void
    {
        $this->expectDrugSearchForProgram('1318eabc-1a1a-42f6-8450-61e11c19eede');
        $component = new CarePlanMedicationProgramHarness();
        $component->searchQuery = 'Metformin';

        $component->searchMedications();

        $this->assertCount(1, $component->searchResults);
        Http::assertNothingSent();
    }

    public function test_care_plan_search_honors_an_explicit_program(): void
    {
        $this->expectDrugSearchForProgram('11111111-2222-4333-8444-555555555555');
        $component = new CarePlanMedicationProgramHarness();
        $component->searchQuery = 'Metformin';
        $component->selectedProgram = '11111111-2222-4333-8444-555555555555';

        $component->searchMedications();

        $this->assertCount(1, $component->searchResults);
        Http::assertNothingSent();
    }

    public function test_encounter_search_uses_the_same_default_as_care_plan_without_a_program_selection(): void
    {
        $this->expectDrugSearchForProgram('1318eabc-1a1a-42f6-8450-61e11c19eede');
        $component = new EncounterMedicationProgramHarness();
        $component->encounterEPrescriptionSearchQuery = 'Metformin';

        $component->searchEncounterEPrescriptionMedications();

        $this->assertCount(1, $component->encounterEPrescriptionSearchResults);
        $this->assertSame('', $component->encounterEPrescriptionWarningMessage);
        Http::assertNothingSent();
    }

    public function test_encounter_search_uses_the_same_drug_client_with_a_selected_program(): void
    {
        $this->expectDrugSearchForProgram('11111111-2222-4333-8444-555555555555');
        $component = new EncounterMedicationProgramHarness();
        $component->encounterEPrescriptionSearchQuery = 'Metformin';
        $component->encounterEPrescriptionForm['program_id'] = '11111111-2222-4333-8444-555555555555';

        $component->searchEncounterEPrescriptionMedications();

        $this->assertCount(1, $component->encounterEPrescriptionSearchResults);
        Http::assertNothingSent();
    }
    public function test_encounter_search_keeps_v3_ingredient_matches_and_uses_the_catalog_dosage_unit(): void
    {
        $this->drugApi->shouldReceive('getMany')->once()
            ->andReturn(new EHealthResponse(new Response(200, [], json_encode(['data' => [[
                'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                'name' => 'Synthetic brand',
                'form' => 'SOLUTION',
                'ingredients' => [['name' => 'Metformin', 'dosage' => ['denumerator_unit' => 'ML']]],
            ]]], JSON_THROW_ON_ERROR))));
        $component = new EncounterMedicationProgramHarness();
        $component->encounterEPrescriptionSearchQuery = 'Metformin';

        $component->searchEncounterEPrescriptionMedications();
        $component->selectEncounterEPrescriptionMedication('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');

        $this->assertSame('Synthetic brand', $component->encounterEPrescriptionSelectedMedication['name']);
        $this->assertSame('Metformin', $component->encounterEPrescriptionSelectedMedication['innm_name']);
        $this->assertSame('ML', $component->encounterEPrescriptionForm['medication_unit']);
        Http::assertNothingSent();
    }

    public function test_encounter_programs_exclude_inactive_disallowed_and_care_plan_only_programs(): void
    {
        $programs = collect([
            ['id' => 'default', 'name' => 'Default', 'type' => 'MEDICATION', 'is_active' => false],
            ['id' => 'allowed', 'name' => 'Allowed', 'type' => 'medication', 'is_active' => true, 'medication_request_allowed' => true],
            ['id' => 'plan-only', 'name' => 'Plan only', 'type' => 'MEDICATION', 'is_active' => true, 'medical_program_settings' => ['care_plan_required' => true]],
            ['id' => 'no-prescribing', 'name' => 'No prescribing', 'type' => 'MEDICATION', 'is_active' => true, 'medication_request_allowed' => false],
            ['id' => 'service', 'name' => 'Service', 'type' => 'SERVICE', 'is_active' => true],
            ['id' => '1318eabc-1a1a-42f6-8450-61e11c19eede', 'name' => 'Prescription medication', 'type' => 'MEDICATION', 'is_active' => true],
        ]);
        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldReceive('medicalPrograms')->once()->andReturn($programs);
        $this->instance(DictionaryManager::class, $manager);
        $component = new EncounterMedicationProgramHarness();

        $component->loadProgramsForTest();

        $this->assertSame([['id' => 'allowed', 'name' => 'Allowed']], $component->encounterEPrescriptionPrograms);
        Http::assertNothingSent();
    }

    public function test_unavailable_dictionary_does_not_block_default_program_search(): void
    {
        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldReceive('medicalPrograms')->once()->andThrow(new RuntimeException('MIS authorization unavailable'));
        $this->instance(DictionaryManager::class, $manager);
        $this->expectDrugSearchForProgram('1318eabc-1a1a-42f6-8450-61e11c19eede');
        $component = new EncounterMedicationProgramHarness();
        $component->encounterEPrescriptionSearchQuery = 'Metformin';

        $component->loadProgramsForTest();
        $component->searchEncounterEPrescriptionMedications();

        $this->assertSame([], $component->encounterEPrescriptionPrograms);
        $this->assertCount(1, $component->encounterEPrescriptionSearchResults);
        Http::assertNothingSent();
    }

    public function test_encounter_and_care_plan_use_the_same_speciality_filter(): void
    {
        $this->app->instance('legalEntity', new LegalEntity());
        $user = Mockery::mock(User::class);
        $user->shouldReceive('getAttribute')->with('allowedRoles')->twice()->andReturn(collect(['SPECIALIST']));
        $user->shouldReceive('getMainSpeciality')->twice()->andReturn(collect(['CARDIOLOGY']));
        $user->shouldReceive('hasAllowedRole')->with(Role::SPECIALIST)->twice()->andReturn(true);
        Auth::shouldReceive('user')->twice()->andReturn($user);
        $programs = collect([
            ['id' => 'allowed', 'name' => 'Allowed', 'type' => 'MEDICATION', 'is_active' => true, 'medical_program_settings' => ['speciality_types_request_allowed' => ['CARDIOLOGY'], 'speciality_types_care_plan_activity_allowed' => ['CARDIOLOGY']]],
            ['id' => 'other-speciality', 'name' => 'Other', 'type' => 'MEDICATION', 'is_active' => true, 'medical_program_settings' => ['speciality_types_request_allowed' => ['ENDOCRINOLOGY'], 'speciality_types_care_plan_activity_allowed' => ['ENDOCRINOLOGY']]],
        ]);
        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldReceive('medicalPrograms')->once()->andReturn($programs);
        $this->instance(DictionaryManager::class, $manager);
        $carePlan = new CarePlanMedicationProgramHarness();
        $encounter = new EncounterMedicationProgramHarness();

        $carePlanPrograms = $carePlan->filterProgramsForTest($programs);
        $encounter->loadProgramsForTest();

        $this->assertSame(['allowed'], $carePlanPrograms->pluck('id')->all());
        $this->assertSame(['allowed'], array_column($encounter->encounterEPrescriptionPrograms, 'id'));
    }

    public function test_encounter_draft_receives_the_default_program_id_without_requiring_a_dropdown_selection(): void
    {
        $component = new EncounterMedicationProgramHarness();
        $component->encounterEPrescriptionForm = $this->validEncounterForm();
        $service = Mockery::mock(MedicationRequestLifecycleService::class);
        $service->shouldReceive('resolveEncounterEmployeeContext')->once()->andReturn([]);
        $service->shouldReceive('createEncounterDraft')->once()
            ->with(Mockery::type(Encounter::class), Mockery::on(static fn (array $form): bool => $form['program_id'] === '1318eabc-1a1a-42f6-8450-61e11c19eede'), [])
            ->andReturn('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
        $this->instance(MedicationRequestLifecycleService::class, $service);

        $component->validateEncounterEPrescription();

        $this->assertTrue($component->showSignatureModal);
        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $component->encounterEPrescriptionRequestIdToSign);
        Http::assertNothingSent();
    }

    public function test_encounter_draft_rejects_an_invalid_explicit_program_id(): void
    {
        $component = new EncounterMedicationProgramHarness();
        $component->encounterEPrescriptionForm = $this->validEncounterForm();
        $component->encounterEPrescriptionForm['program_id'] = 'not-a-uuid';
        $service = Mockery::mock(MedicationRequestLifecycleService::class);
        $service->shouldNotReceive('createEncounterDraft');
        $this->instance(MedicationRequestLifecycleService::class, $service);

        try {
            $component->validateEncounterEPrescription();
            $this->fail('An invalid explicit program must not be replaced with the default.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('encounterEPrescriptionForm.program_id', $exception->errors());
        }

        Http::assertNothingSent();
    }

    public function test_form_offers_the_default_and_keeps_search_enabled_when_the_program_dictionary_is_empty(): void
    {
        $html = view('livewire.encounter-package.parts.encounter-eprescription-form', [
            'encounterReturnUrl' => '/dashboard/1/persons/1/encounter/21',
            'showEncounterEPrescriptionDrawer' => true,
            'encounterUuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'encounterId' => 21,
            'encounterEPrescriptionForm' => $this->validEncounterForm(),
            'encounterEPrescriptionPrograms' => [],
            'encounterEPrescriptionWarningMessage' => '',
            'encounterEPrescriptionSearchResults' => [],
            'encounterEPrescriptionSelectedMedication' => null,
            'encounterEPrescriptionAuthMethods' => [],
            'errors' => new ViewErrorBag(),
        ])->render();
        $document = new DOMDocument();
        $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $search = $document->getElementById('encounterEPrescriptionSearch');
        $program = $document->getElementById('encounterEPrescriptionProgram');

        foreach (['StartedAt', 'EndedAt'] as $dateField) {
            $dateInput = $document->getElementById('encounterEPrescription'.$dateField);
            $this->assertSame('text', $dateInput->getAttribute('type'));
            $this->assertStringContainsString('datepicker-input', $dateInput->getAttribute('class'));
            $this->assertSame(frontendDateFormat(), $dateInput->getAttribute('datepicker-format'));
        }
        $this->assertNotNull($search);
        $this->assertFalse($search->hasAttribute('disabled'));
        $searchButtons = (new \DOMXPath($document))->query("//button[@*[name()='wire:click' and .='searchEncounterEPrescriptionMedications']]");
        $this->assertCount(1, $searchButtons);
        $this->assertFalse($searchButtons->item(0)->hasAttribute('disabled'));
        $this->assertNotNull($program);
        $this->assertSame('', $program->getElementsByTagName('option')->item(0)->getAttribute('value'));
        $this->assertSame(__('care-plan.prescription_medication'), $program->getElementsByTagName('option')->item(0)->textContent);
        $this->assertStringContainsString(__('encounters.messages.medication_programs_unavailable'), $html);
        Http::assertNothingSent();
    }

    /** @return array<string, string> */
    private function validEncounterForm(): array
    {
        return [
            'medication_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'program_id' => '',
            'category' => 'community',
            'medication_qty' => '1',
            'signature_text' => 'Take as prescribed',
            'max_dose_per_administration' => '1',
            'max_dose_per_period' => '1',
            'started_at' => now()->toDateString(),
            'ended_at' => now()->addDays(10)->toDateString(),
            'inform_with' => 'patient-auth-method',
        ];
    }
}

class CarePlanMedicationProgramHarness extends CarePlanComponent
{
    use ManagesCarePlanActivities;

    public function filterProgramsForTest(Collection $programs): Collection
    {
        return $this->filterMedicationPrograms($programs);
    }

    protected function renderCarePlan(): mixed
    {
        return null;
    }
}

class EncounterMedicationProgramHarness extends Component
{
    use ManagesEncounterEPrescription;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    public function loadProgramsForTest(): void
    {
        $this->loadEncounterEPrescriptionPrograms();
    }

    protected function resolveEncounterModelForStandalone(): ?Encounter
    {
        $encounter = new Encounter();
        $encounter->status = EncounterStatus::FINISHED;

        return $encounter;
    }
}
