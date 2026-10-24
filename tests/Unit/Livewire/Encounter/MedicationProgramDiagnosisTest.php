<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Encounter;

use App\Livewire\EncounterPackage\Concerns\ManagesEncounterEPrescription;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\MedicalEvents\EncounterRepository;
use App\Services\Dictionary\DictionaryManager;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Mockery;
use Tests\TestCase;

class MedicationProgramDiagnosisTest extends TestCase
{
    private const string PROGRAM_ID = '11111111-2222-4333-8444-555555555555';

    private function buildProgramComponent(string $diagnosisCode = 'E10'): ProgramDiagnosisHarness
    {
        Http::preventStrayRequests();
        $program = ['id' => self::PROGRAM_ID, 'is_active' => true, 'medical_program_settings' => [
            'conditions_icd10_am_allowed' => ['E11'], 'conditions_icpc2_allowed' => null,
        ]];
        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldReceive('medicalPrograms')->andReturn(collect([$program]));
        $this->instance(DictionaryManager::class, $manager);
        $concept = new CodeableConcept();
        $concept->setRelation('coding', new Collection([new Coding(['system' => 'eHealth/ICD10_AM/condition_codes', 'code' => $diagnosisCode])]));
        $condition = new Condition();
        $condition->setRelation('code', $concept);
        $repository = Mockery::mock(EncounterRepository::class);
        $repository->shouldReceive('primaryDiagnosisConditions')->andReturn(collect([$condition]));
        $this->instance(EncounterRepository::class, $repository);
        $component = new ProgramDiagnosisHarness();
        $component->encounterEPrescriptionForm = [
            'medication_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'program_id' => self::PROGRAM_ID,
            'category' => 'community', 'medication_qty' => '1', 'signature_text' => 'Take as prescribed',
            'max_dose_per_administration' => '1', 'max_dose_per_period' => '1',
            'started_at' => now()->toDateString(), 'ended_at' => now()->addDays(10)->toDateString(),
            'inform_with' => 'patient-auth-method',
        ];

        return $component;
    }

    public function test_program_change_reports_primary_diagnosis_mismatch_without_http(): void
    {
        $component = $this->buildProgramComponent();
        $component->updatedEncounterEPrescriptionFormProgramId();

        $this->assertTrue($component->getErrorBag()->has('encounterEPrescriptionForm.program_id'));
        $this->assertSame(__('medication-programs.diagnosis_mismatch'), session('error'));
        $this->assertSame('', $component->encounterEPrescriptionForm['medication_id']);
        Http::assertNothingSent();
    }

    public function test_submit_checks_diagnosis_without_relying_on_the_change_hook(): void
    {
        $component = $this->buildProgramComponent();
        $service = Mockery::mock(MedicationRequestLifecycleService::class);
        $service->shouldNotReceive('createEncounterDraft');
        $this->instance(MedicationRequestLifecycleService::class, $service);

        $component->validateEncounterEPrescription();

        $this->assertTrue($component->getErrorBag()->has('encounterEPrescriptionForm.program_id'));
        $this->assertFalse($component->showSignatureModal);
        Http::assertNothingSent();
    }

    public function test_matching_primary_diagnosis_allows_draft_creation(): void
    {
        $component = $this->buildProgramComponent('E11');
        $service = Mockery::mock(MedicationRequestLifecycleService::class);
        $service->shouldReceive('resolveEncounterEmployeeContext')->once()->andReturn([]);
        $service->shouldReceive('createEncounterDraft')->once()->andReturn('accepted-draft');
        $this->instance(MedicationRequestLifecycleService::class, $service);

        $component->validateEncounterEPrescription();

        $this->assertFalse($component->getErrorBag()->has('encounterEPrescriptionForm.program_id'));
        $this->assertTrue($component->showSignatureModal);
        Http::assertNothingSent();
    }

    public function test_unknown_program_is_rejected_before_draft_creation(): void
    {
        $component = $this->buildProgramComponent('E11');
        $component->encounterEPrescriptionForm['program_id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $component->validateEncounterEPrescription();

        $this->assertSame(__('medication-programs.unavailable'), $component->getErrorBag()->first('encounterEPrescriptionForm.program_id'));
        $this->assertFalse($component->showSignatureModal);
        Http::assertNothingSent();
    }
}

class ProgramDiagnosisHarness extends Component
{
    use ManagesEncounterEPrescription;

    public bool $showSignatureModal = false;

    public string $actionType = '';

    protected function resolveEncounterModelForStandalone(): ?Encounter
    {
        return new Encounter(['person_id' => 11]);
    }
}
