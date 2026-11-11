<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Enums\Person\ServiceRequestStatus;
use App\Livewire\DiagnosticReport\DiagnosticReportEdit;
use App\Livewire\EncounterPackage\EncounterPackageComponent;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use App\Services\Dictionary\Collections\ServiceCollection;
use App\Services\Dictionary\DictionaryManager;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class PatientReferralsPhase6Test extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected LegalEntity $legalEntity;

    protected Person $person;

    protected Employee $employee;

    protected Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Олена',
            'last_name' => 'Коваленко',
            'tax_id' => '1122334455',
            'birth_date' => '1985-02-02',
            'gender' => 'FEMALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'sr_p6_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'legal_entity_id' => $this->legalEntity->id,
            'party_id' => $party->id,
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'position' => 'Doctor',
            'is_active' => true,
            'start_date' => now()->toDateString(),
        ]);

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '2001-02-23',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);
        $this->person->names()->create([
            'first_name' => 'Пацієнт',
            'last_name' => 'Якийсь',
            'language' => 'uk',
        ]);

        $identifierId = Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = CodeableConcept::create()->id;

        $this->encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => 'finished',
            'episode_id' => $identifierId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);

        $this->actingAs($this->user);
    }

    public function test_upstream_procedure_and_encounter_lookups_work_with_fhir_request_references(): void
    {
        $repo = Repository::serviceRequest();
        $uuid = (string) Str::uuid();
        $resource = Fhir::serviceRequest()->toFhir([
            'uuid' => $uuid,
            'employee_id' => $this->employee->id,
            'status' => 'active',
            'request_number' => '0000-TEST-FHIR-PROCEDURE',
            'context_type' => 'encounter',
            'service_id' => '37003-00',
            'context_uuid' => $this->encounter->uuid,
            'category' => 'diagnostic_procedure',
        ], ['person_uuid' => $this->person->uuid, 'employee_uuid' => $this->employee->uuid]);
        $id = $repo->store($resource, $this->person->id, $this->employee->id);

        $record = ServiceRequestRequest::findOrFail($id);
        $this->assertSame($this->encounter->uuid, $record->context->value);
        $this->assertSame([$uuid], $repo->getByPersonIdAndStatus($this->person->id, 'active', ['uuid'])->pluck('uuid')->all());
        $this->assertTrue($repo->getByPersonIdAndStatus($this->person->id, 'draft')->isEmpty());
        $this->assertTrue($repo->getByPersonIdAndStatus($this->person->id + 1000, 'active')->isEmpty());

        $procedure = ['basedOn' => [['identifier' => ['value' => $uuid]]]];
        $encounter = ['incomingReferral' => ['identifier' => ['value' => $uuid]]];
        $this->assertSame('0000-TEST-FHIR-PROCEDURE', $repo->procedureReferralLabel($procedure));
        $numbers = $repo->requestNumbersForEncounters([$encounter]);
        $this->assertSame([$uuid => '0000-TEST-FHIR-PROCEDURE'], $numbers);
        $this->assertSame('0000-TEST-FHIR-PROCEDURE', $repo->encounterReferralLabel($encounter, $numbers));
    }

    public function test_encounter_referrals_keep_fhir_categories_and_both_catalog_permissions(): void
    {
        $this->grantMedicalEventAbilities($this->user);
        $services = [];
        $expected = [];
        foreach ([
            ['surgical_procedure', 'procedure', true, false],
            ['laboratory_procedure', 'laboratory', false, true],
            [null, null, false, false],
        ] as $index => [$category, $catalogCategory, $procedureAllowed, $reportAllowed]) {
            $uuid = (string) Str::uuid();
            $serviceId = (string) Str::uuid();
            $number = sprintf('%04d-1111-2222-3333', $index);
            $concept = $category === null ? null : CodeableConcept::create(['text' => $category]);
            $concept?->coding()->create(['system' => 'eHealth/SNOMED/service_request_categories', 'code' => $category]);
            ServiceRequestRequest::create([
                'uuid' => $uuid, 'person_id' => $this->person->id,
                'employee_id' => $this->employee->id,
                'status' => ServiceRequestStatus::ACTIVE->value,
                'service_id' => $serviceId, 'category_id' => $concept?->id, 'request_number' => $number,
            ]);
            if ($category !== null) {
                $services[] = ['id' => $serviceId, 'code' => $category, 'name' => $category, 'category' => $catalogCategory];
            }
            $expected[$uuid] = [$category, $procedureAllowed, $reportAllowed, $number];
        }
        $missingNumberUuid = (string) Str::uuid();
        ServiceRequestRequest::create([
            'uuid' => $missingNumberUuid, 'person_id' => $this->person->id,
            'status' => ServiceRequestStatus::ACTIVE->value, 'service_id' => (string) Str::uuid(),
        ]);

        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldReceive('services')->andReturn(ServiceCollection::make($services));
        $this->instance(DictionaryManager::class, $manager);

        $component = new EncounterPackageComponent();
        $component->personId = $this->person->id;
        $component->dictionaries = [
            'eHealth/procedure_categories' => ['procedure' => 'Procedure'],
            'eHealth/diagnostic_report_categories' => ['laboratory' => 'Laboratory'],
        ];
        (new ReflectionMethod(EncounterPackageComponent::class, 'loadAvailableReferrals'))->invoke($component);

        $this->assertTrue($component->referralsLoaded);
        $this->assertCount(3, $component->availableReferrals);
        $this->assertNotContains($missingNumberUuid, array_column($component->availableReferrals, 'id'));
        foreach ($component->availableReferrals as $referral) {
            [$category, $procedureAllowed, $reportAllowed, $number] = $expected[$referral['id']];
            $this->assertSame($category === null
                ? __('encounters.electronic_referral')
                : __('care-plan.referral_category.'.$category), $referral['category']);
            $this->assertSame($procedureAllowed, $referral['isProcedureAllowed']);
            $this->assertSame($reportAllowed, $referral['isDiagnosticReportAllowed']);
            $this->assertSame($number, $referral['requisition']);
            $this->assertNotSame($referral['id'], $referral['requisition']);
        }

        $edit = new DiagnosticReportEdit();
        $edit->dictionaries = [...$component->dictionaries, 'custom/services' => $services];
        foreach ($component->availableReferrals as $referral) {
            [$category, , $reportAllowed] = $expected[$referral['id']];
            $this->assertSame($category === null
                ? __('encounters.electronic_referral')
                : __('care-plan.referral_category.'.$category), $referral['category']);
            $this->assertSame($reportAllowed, $referral['isDiagnosticReportAllowed']);
            (new ReflectionMethod(DiagnosticReportEdit::class, 'loadSelectedElectronicReferral'))
                ->invoke($edit, $referral['id']);
            $this->assertCount(1, $edit->availableReferrals);
            $this->assertSame($referral['id'], $edit->availableReferrals[0]['id']);
            $this->assertSame($reportAllowed, $edit->availableReferrals[0]['isDiagnosticReportAllowed']);
        }
    }

    public function testEncounterReferralsRequireReadPermissionBeforeLoadingTheDictionary(): void
    {
        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldNotReceive('services');
        $this->instance(DictionaryManager::class, $manager);
        $component = new EncounterPackageComponent();
        $component->personId = $this->person->id;

        $component->loadAvailableReferrals();

        $this->assertFalse($component->referralsLoaded);
        $this->assertSame([], $component->availableReferrals);
        $this->assertSame(__('service-requests.policy.search'), session()->get('error'));
    }

    public function test_repository_filters_by_status_and_period(): void
    {
        ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'request_number' => '0000-TEST-SR-ACT',
            'service_id' => (string) Str::uuid(),
            'quantity' => 1,
            'started_at' => '2026-01-10',
            'ended_at' => '2026-02-10',
            'intent' => 'order',
            'category' => 'procedure',
            'context_id' => Identifier::create(['value' => $this->encounter->uuid])->id,
        ]);

        ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'draft',
            'service_id' => '37003-00',
            'quantity' => 2,
            'started_at' => '2026-03-01',
            'ended_at' => '2026-03-20',
            'intent' => 'order',
            'category_id' => CodeableConcept::create(['text' => 'diagnostic_procedure'])->id,
            'context_id' => Identifier::create(['value' => $this->encounter->uuid])->id,
        ]);

        $repo = Repository::serviceRequest();

        $active = $repo->searchByPersonId($this->person->id, [
            'status' => 'active',
            'started_at_from' => '2026-01-01',
            'started_at_to' => '2026-01-31',
        ]);

        $this->assertCount(1, $active);
        $this->assertSame('active', strtolower((string) $active[0]['status']));
        $this->assertSame('Взаємодія', $active[0]['basisLabel']);
        $this->assertSame($this->encounter->id, $active[0]['encounterId']);
        $this->assertFalse($active[0]['canSign']);
        $this->assertTrue($active[0]['canOperate']);
        $this->assertTrue($active[0]['canRecall']);
        $this->assertTrue($active[0]['canCancel']);
    }

    public function test_registry_row_marks_draft_as_signable_and_maps_category(): void
    {
        $uuid = (string) Str::uuid();
        ServiceRequestRequest::create([
            'uuid' => $uuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'draft',
            'service_id' => '37003-00',
            'quantity' => 1,
            'started_at' => '2026-08-12',
            'ended_at' => '2026-11-12',
            'intent' => 'order',
            'category_id' => CodeableConcept::create(['text' => 'diagnostic_procedure'])->id,
            'context_id' => Identifier::create(['value' => $this->encounter->uuid])->id,
            'priority' => 'routine',
            'note' => 'обстеження',
        ]);

        $rows = Repository::serviceRequest()->searchByPersonId($this->person->id);

        $this->assertCount(1, $rows);
        $this->assertSame($uuid, $rows[0]['uuid']);
        $this->assertSame('', $rows[0]['requestNumber']);
        $this->assertTrue($rows[0]['canSign']);
        $this->assertFalse($rows[0]['canOperate']);
        $this->assertFalse($rows[0]['canRecall']);
        $this->assertFalse($rows[0]['canCancel']);
        $this->assertSame('37003-00', $rows[0]['itemName']);
        $this->assertSame('Діагностична процедура', $rows[0]['categoryLabel']);
        $this->assertSame('12.08.2026 — 12.11.2026', $rows[0]['periodLabel']);
        $this->assertSame('service_request', $rows[0]['kind']);
    }

    public function test_registry_row_keeps_requisition_separate_from_uuid(): void
    {
        $uuid = (string) Str::uuid();
        ServiceRequestRequest::create([
            'uuid' => $uuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'request_number' => '0000-SMS1-NUMB-ER01',
            'service_id' => (string) Str::uuid(),
            'quantity' => 1,
            'started_at' => '2026-09-01',
            'ended_at' => '2026-12-01',
            'intent' => 'order',
            'category_id' => CodeableConcept::create(['text' => 'diagnostic_procedure'])->id,
            'context_id' => Identifier::create(['value' => $this->encounter->uuid])->id,
        ]);

        $rows = Repository::serviceRequest()->searchByPersonId($this->person->id);

        $this->assertCount(1, $rows);
        $this->assertSame('0000-SMS1-NUMB-ER01', $rows[0]['requestNumber']);
        $this->assertSame($uuid, $rows[0]['uuid']);
        $this->assertNotSame($rows[0]['requestNumber'], $rows[0]['uuid']);
    }
}
