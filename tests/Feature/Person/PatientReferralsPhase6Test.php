<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

        $party = \App\Models\Relations\Party::create([
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

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

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

        $identifierId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;

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
            'context_id' => $this->encounter->id,
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
            'category' => 'diagnostic_procedure',
            'context_id' => $this->encounter->id,
        ]);

        $repo = app(ServiceRequestRequestRepository::class);

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
            'category' => 'diagnostic_procedure',
            'context_id' => $this->encounter->id,
            'priority' => 'routine',
            'note' => 'обстеження',
        ]);

        $rows = app(ServiceRequestRequestRepository::class)->searchByPersonId($this->person->id);

        $this->assertCount(1, $rows);
        $this->assertSame($uuid, $rows[0]['uuid']);
        $this->assertTrue($rows[0]['canSign']);
        $this->assertFalse($rows[0]['canOperate']);
        $this->assertFalse($rows[0]['canRecall']);
        $this->assertFalse($rows[0]['canCancel']);
        $this->assertSame('37003-00', $rows[0]['itemName']);
        $this->assertSame('Діагностична процедура', $rows[0]['categoryLabel']);
        $this->assertSame('12.08.2026 — 12.11.2026', $rows[0]['periodLabel']);
        $this->assertSame('service_request', $rows[0]['kind']);
    }
}
