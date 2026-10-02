<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Enums\Person\EncounterStatus;
use App\Livewire\Encounter\Concerns\ManagesEncounterEPrescription;
use App\Livewire\Encounter\Concerns\ManagesEncounterReferrals;
use App\Livewire\Encounter\Concerns\ResolvesEncounterStandaloneContext;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Features\SupportEvents\Event;
use Tests\TestCase;

class EncounterStandalonePhase6Test extends TestCase
{
    use DatabaseTransactions;

    protected Person $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '1990-05-05',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);
    }

    public function test_referral_drawer_blocked_for_draft_encounter(): void
    {
        $encounter = $this->createEncounter(EncounterStatus::DRAFT->value);
        $harness = $this->makeHarness($encounter->id);

        $harness->openEncounterReferralDrawer();

        $this->assertFalse($harness->showEncounterReferralDrawer);
        $this->assertSame(__('Виписати направлення можна лише для завершеної взаємодії.'), session('error'));
        $this->assertSame([], $harness->dispatched);
    }

    public function test_eprescription_drawer_opens_for_finished_encounter(): void
    {
        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('medicalPrograms')->andReturn(collect());
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);

        $encounter = $this->createEncounter(EncounterStatus::FINISHED->value);
        $harness = $this->makeHarness($encounter->id);
        $harness->addError('encounterEPrescriptionForm.medication_id', 'Old medication error');
        $harness->addError('encounterReferralForm.service_id', 'Unrelated referral error');

        $harness->openEncounterEPrescriptionDrawer();

        $this->assertTrue($harness->showEncounterEPrescriptionDrawer);
        $this->assertSame('1', $harness->encounterEPrescriptionForm['medication_qty']);
        $this->assertFalse($harness->getErrorBag()->has('encounterEPrescriptionForm.medication_id'));
        $this->assertTrue($harness->getErrorBag()->has('encounterReferralForm.service_id'));
    }

    public function test_eprescription_validation_errors_clear_only_for_the_updated_field(): void
    {
        $harness = new EncounterStandaloneHarness();
        $harness->addError('encounterEPrescriptionForm.signature_text', 'Required');
        $harness->addError('encounterEPrescriptionForm.medication_id', 'Required');
        $harness->encounterEPrescriptionWarningMessage = 'Old warning';

        $harness->updatedEncounterEPrescriptionForm('Take daily', 'signature_text');

        $this->assertFalse($harness->getErrorBag()->has('encounterEPrescriptionForm.signature_text'));
        $this->assertTrue($harness->getErrorBag()->has('encounterEPrescriptionForm.medication_id'));
        $this->assertSame('', $harness->encounterEPrescriptionWarningMessage);
    }

    public function test_eprescription_closing_and_medication_selection_clear_stale_errors(): void
    {
        $harness = new EncounterStandaloneHarness();
        $medicationId = (string) Str::uuid();
        $harness->encounterEPrescriptionSearchResults = [[
            'id' => $medicationId, 'packages' => [['package_min_qty' => 10]],
        ]];
        $harness->addError('encounterEPrescriptionForm.medication_id', 'Required');
        $harness->addError('encounterEPrescriptionForm.medication_qty', 'Invalid');
        $harness->addError('encounterEPrescriptionForm.signature_text', 'Required');
        $harness->addError('encounterReferralForm.service_id', 'Required');

        $harness->selectEncounterEPrescriptionMedication($medicationId);

        $this->assertFalse($harness->getErrorBag()->has('encounterEPrescriptionForm.medication_id'));
        $this->assertFalse($harness->getErrorBag()->has('encounterEPrescriptionForm.medication_qty'));
        $this->assertTrue($harness->getErrorBag()->has('encounterEPrescriptionForm.signature_text'));
        $harness->closeEncounterEPrescriptionDrawer();
        $this->assertSame(['encounterReferralForm.service_id'], $harness->getErrorBag()->keys());
    }

    public function test_referral_drawer_opens_for_finished_encounter(): void
    {
        $encounter = $this->createEncounter(EncounterStatus::FINISHED->value);
        $harness = $this->makeHarness($encounter->id);

        $harness->openEncounterReferralDrawer();

        $this->assertTrue($harness->showEncounterReferralDrawer);
        $this->assertSame('', $harness->encounterReferralForm['service_id']);
        $this->assertIsArray($harness->encounterReferralPrograms);
    }

    public function test_referral_drawer_defaults_to_state_guarantees_program(): void
    {
        $pmgId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $otherId = '11111111-2222-3333-4444-555555555555';

        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('medicalPrograms')->andReturn(collect([
            [
                'id' => $otherId,
                'name' => 'Інша програма',
                'type' => 'SERVICE',
                'is_active' => true,
            ],
            [
                'id' => $pmgId,
                'name' => 'Програма державних фінансових гарантій медичного обслуговування населення',
                'type' => 'SERVICE',
                'is_active' => true,
            ],
        ]));
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);

        $encounter = $this->createEncounter(EncounterStatus::FINISHED->value);
        $harness = $this->makeHarness($encounter->id);
        $harness->openEncounterReferralDrawer();

        $this->assertTrue($harness->showEncounterReferralDrawer);
        $this->assertSame($pmgId, $harness->encounterReferralForm['program_id']);
    }

    public function test_selecting_a_service_copies_its_catalog_category(): void
    {
        $encounter = $this->createEncounter(EncounterStatus::FINISHED->value);
        $harness = $this->makeHarness($encounter->id);
        $harness->openEncounterReferralDrawer();

        $this->assertSame('diagnostic_procedure', $harness->encounterReferralForm['category']);

        $serviceId = (string) Str::uuid();
        $harness->encounterReferralServiceResults = [[
            'id' => $serviceId,
            'code' => '37003-00',
            'name' => 'Обстеження',
            'category' => 'diagnostic_procedure',
        ]];

        $harness->selectEncounterReferralService($serviceId);

        $this->assertSame($serviceId, $harness->encounterReferralForm['service_id']);
        $this->assertSame('diagnostic_procedure', $harness->encounterReferralForm['category']);
    }

    public function test_legacy_drafts_without_context_show_an_error_and_remain_unchanged(): void
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);
        $legalEntity = \App\Models\LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE', 'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId, 'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);
        $employee = \App\Models\Employee\Employee::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $legalEntity->id,
            'employee_type' => 'DOCTOR', 'status' => 'APPROVED', 'is_active' => true,
            'position' => 'Doctor', 'start_date' => now()->toDateString(),
        ]);
        $encounter = $this->createEncounter(EncounterStatus::FINISHED->value);

        foreach (['EPrescription', 'Referral'] as $kind) {
            $class = $kind === 'EPrescription'
                ? \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::class
                : \App\Models\MedicalEvents\Sql\ServiceRequestRequest::class;
            $attributes = $kind === 'EPrescription'
                ? ['medication_id' => 'INN-1', 'medication_qty' => 1]
                : ['service_id' => '37003-00'];
            $draft = $class::create(array_merge($attributes, [
                'uuid' => (string) Str::uuid(), 'employee_id' => $employee->id,
                'person_id' => $this->person->id, 'status' => 'new',
            ]));
            $harness = $this->makeHarness($encounter->id);
            $harness->{'encounter'.$kind.'RequestIdToSign'} = $draft->uuid;
            $harness->showSignatureModal = true;
            $harness->{'signEncounter'.$kind}();

            $this->assertSame(__('care-plan.document_context_unavailable'), session('error'));
            $this->assertFalse($harness->showSignatureModal);
            $this->assertSame([], $harness->dispatched);
            $this->assertSame('new', $draft->fresh()->status);
            $this->assertNull($draft->fresh()->contextId);
            $this->assertFalse(session()->has('success'));
        }
    }

    private function makeHarness(int $encounterId): EncounterStandaloneHarness
    {
        $harness = new EncounterStandaloneHarness();
        $harness->encounterId = $encounterId;

        return $harness;
    }

    private function createEncounter(string $status): Encounter
    {
        $identifierId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;

        return Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => $status,
            'episode_id' => $identifierId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);
    }
}

/**
 * Lightweight host for encounter standalone traits (avoids full EncounterEdit mount).
 */
class EncounterStandaloneHarness extends \Livewire\Component
{
    use ResolvesEncounterStandaloneContext;
    use ManagesEncounterEPrescription;
    use ManagesEncounterReferrals;

    public int $encounterId;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    /** @var list<array{0: string, 1: mixed}> */
    public array $dispatched = [];

    public function dispatch($event, ...$params): Event
    {
        $this->dispatched[] = [$event, $params[0] ?? null];

        return new Event($event, $params);
    }
}
