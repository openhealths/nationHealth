<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Enums\Person\EncounterStatus;
use App\Livewire\Encounter\Concerns\ManagesEncounterEPrescription;
use App\Livewire\Encounter\Concerns\ManagesEncounterReferrals;
use App\Livewire\Encounter\Concerns\ResolvesEncounterStandaloneContext;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\EncounterHospitalization;
use App\Models\MedicalEvents\Sql\Identifier;
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

        $harness->openEncounterEPrescriptionDrawer();

        $this->assertTrue($harness->showEncounterEPrescriptionDrawer);
        $this->assertSame('1', $harness->encounterEPrescriptionForm['medication_qty']);
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

    public function test_transfer_discharge_defaults_category_and_performer(): void
    {
        $destination = (string) Str::uuid();
        $divisionId = (string) Str::uuid();
        $this->mockDestinationDivisions([[
            'id' => $divisionId,
            'legal_entity_id' => $destination,
            'name' => 'Приймальне відділення',
            'type' => 'CLINIC',
            'status' => 'ACTIVE',
        ]]);

        $encounter = $this->createTransferEncounter($destination);
        $harness = $this->makeHarness($encounter->id);
        $harness->openEncounterReferralDrawer();

        $this->assertTrue($harness->showEncounterReferralDrawer);
        $this->assertTrue($harness->encounterReferralIsTransfer);
        $this->assertSame('transfer_of_care', $harness->encounterReferralForm['category']);
        $this->assertSame($destination, $harness->encounterReferralForm['performer']);
        $this->assertSame([$divisionId], $harness->encounterReferralAllowedDivisionIds);

        $serviceId = (string) Str::uuid();
        $harness->encounterReferralServiceResults = [[
            'id' => $serviceId,
            'code' => '37003-00',
            'name' => 'Обстеження',
            'category' => 'diagnostic_procedure',
        ]];
        $harness->selectEncounterReferralService($serviceId);

        $this->assertSame('transfer_of_care', $harness->encounterReferralForm['category']);
    }

    public function test_transfer_of_care_is_rejected_without_transfer_general(): void
    {
        $encounter = $this->createEncounter(EncounterStatus::FINISHED->value);
        $harness = $this->makeHarness($encounter->id);
        $harness->openEncounterReferralDrawer();
        $harness->encounterReferralForm['category'] = 'transfer_of_care';
        $harness->encounterReferralForm['service_id'] = (string) Str::uuid();

        $harness->validateEncounterReferral();

        $this->assertNull($harness->encounterReferralRequestIdToSign);
        $this->assertTrue($harness->showEncounterReferralDrawer);
        $this->assertSame(
            __('Електронне направлення на переведення можна створити лише для завершеної виписки з результатом «Переведено в інший ЗОЗ».'),
            $harness->encounterReferralWarningMessage
        );
    }

    public function test_transfer_draft_is_not_created_without_a_destination_division(): void
    {
        $destination = (string) Str::uuid();
        $this->mockDestinationDivisions([]);

        $encounter = $this->createTransferEncounter($destination);
        $harness = $this->makeHarness($encounter->id);
        $harness->openEncounterReferralDrawer();
        $harness->encounterReferralForm['service_id'] = (string) Str::uuid();

        $harness->validateEncounterReferral();

        $this->assertNull($harness->encounterReferralRequestIdToSign);
        $this->assertTrue($harness->showEncounterReferralDrawer);
        $this->assertSame(
            __('Немає активного підрозділу закладу, до якого переводять пацієнта.'),
            $harness->encounterReferralWarningMessage
        );
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

    private function createTransferEncounter(string $destinationUuid): Encounter
    {
        $classId = Coding::create([
            'code' => 'INPATIENT',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $identifierId = Identifier::create(['value' => (string) Str::uuid()])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;
        $destinationId = Identifier::create(['value' => $destinationUuid])->id;
        $dispositionId = Coding::create([
            'code' => 'transfer_general',
            'system' => 'eHealth/encounter_discharge_disposition',
        ])->id;

        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => EncounterStatus::FINISHED->value,
            'episode_id' => $identifierId,
            'class_id' => $classId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);

        EncounterHospitalization::create([
            'encounter_id' => $encounter->id,
            'destination_id' => $destinationId,
            'discharge_disposition_id' => $dispositionId,
        ]);

        return $encounter;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function mockDestinationDivisions(array $rows): void
    {
        $psr = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $rows]));
        $response = new \App\Classes\eHealth\EHealthResponse(new \Illuminate\Http\Client\Response($psr));

        $api = \Mockery::mock(\App\Classes\eHealth\Api\Division::class);
        $api->shouldReceive('getMany')->andReturn($response);
        $this->instance(\App\Classes\eHealth\Api\Division::class, $api);
    }
}

/**
 * Lightweight host for encounter standalone traits (avoids full EncounterEdit mount).
 */
class EncounterStandaloneHarness
{
    use ResolvesEncounterStandaloneContext;
    use ManagesEncounterEPrescription;
    use ManagesEncounterReferrals;

    public int $encounterId;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    /** @var list<array{0: string, 1: mixed}> */
    public array $dispatched = [];

    public function dispatch(string $event, mixed ...$params): Event
    {
        $this->dispatched[] = [$event, $params[0] ?? null];

        return new Event($event, $params);
    }
}
