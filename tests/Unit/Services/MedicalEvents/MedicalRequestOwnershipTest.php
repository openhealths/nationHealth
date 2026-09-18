<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class MedicalRequestOwnershipTest extends TestCase
{
    use DatabaseTransactions;

    public function test_encounter_ownership_uses_identifier_uuid_instead_of_its_database_id(): void
    {
        [$person, $employee] = $this->personAndEmployee();
        $context = Identifier::create(['value' => (string) Str::uuid()]);
        $request = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'person_id' => $person->id,
            'context_id' => $context->id,
            'status' => 'new',
            'medication_id' => 'INN-1',
            'medication_qty' => 1,
        ]);
        $encounter = (new Encounter())->forceFill([
            'id' => $context->id + 100,
            'uuid' => $context->value,
            'person_id' => $person->id,
        ]);

        $this->assertTrue(app(MedicalRequestOwnership::class)
            ->medicationForEncounter($request->uuid, $encounter)->is($request));

        // An accidental numeric ID match must not authorize a different encounter of the same patient.
        $encounter->id = $context->id;
        $encounter->uuid = (string) Str::uuid();
        $this->expectException(ModelNotFoundException::class);
        app(MedicalRequestOwnership::class)->medicationForEncounter($request->uuid, $encounter);
    }

    public function test_encounter_ownership_rejects_a_request_without_context(): void
    {
        [$person, $employee] = $this->personAndEmployee();
        $request = ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'person_id' => $person->id,
            'status' => 'new',
            'service_id' => '37003-00',
        ]);
        $encounter = new Encounter(['uuid' => (string) Str::uuid(), 'person_id' => $person->id]);

        $this->expectException(ModelNotFoundException::class);
        app(MedicalRequestOwnership::class)->serviceForEncounter($request->uuid, $encounter);
    }

    public function test_it_returns_a_request_that_belongs_to_the_person(): void
    {
        [$person, $employee] = $this->personAndEmployee();

        $request = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'person_id' => $person->id,
            'status' => 'new',
            'medication_id' => 'INN-1',
            'medication_qty' => 1,
            'intent' => 'order',
        ]);

        $found = app(MedicalRequestOwnership::class)
            ->medicationForPerson($request->uuid, $person->id);

        $this->assertTrue($found->is($request));
    }

    public function test_it_rejects_a_request_from_another_person(): void
    {
        [$person, $employee] = $this->personAndEmployee();
        $stranger = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Other',
            'last_name' => 'Patient',
            'birth_date' => '1992-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $request = ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'person_id' => $stranger->id,
            'status' => 'new',
            'service_id' => '37003-00',
            'intent' => 'order',
        ]);

        $this->expectException(ModelNotFoundException::class);

        app(MedicalRequestOwnership::class)->serviceForPerson($request->uuid, $person->id);
    }

    /**
     * @return array{0: Person, 1: Employee}
     */
    private function personAndEmployee(): array
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Owned',
            'last_name' => 'Patient',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr Owned',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
        ]);

        return [$person, $employee];
    }
}
