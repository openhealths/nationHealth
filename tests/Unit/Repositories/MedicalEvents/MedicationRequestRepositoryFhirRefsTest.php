<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories\MedicalEvents;

use App\Enums\Person\MedicationRequestStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class MedicationRequestRepositoryFhirRefsTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;

    private Person $person;

    private LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Repo',
            'last_name' => 'Tester',
            'tax_id' => '9988776655',
            'birth_date' => '1988-01-01',
            'gender' => 'MALE',
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
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
    }

    public function test_store_resolves_based_on_uuid_to_identifier(): void
    {
        $activityUuid = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();

        $id = app(MedicationRequestRepository::class)->store([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'status' => MedicationRequestStatus::DRAFT->value,
            'medication_id' => (string) Str::uuid(),
            'medication_qty' => 2,
            'intent' => 'order',
            'category' => 'community',
            'based_on_uuid' => $activityUuid,
            'context_uuid' => $encounterUuid,
        ], $this->person->id);

        $request = MedicationRequestRequest::query()->with(['basedOn', 'context', 'intent', 'category'])->findOrFail($id);

        $this->assertNotNull($request->basedOnId);
        $this->assertInstanceOf(Identifier::class, $request->basedOn);
        $this->assertSame($activityUuid, $request->basedOn->value);
        $this->assertSame($encounterUuid, $request->context?->value);
        $this->assertSame('order', $request->intent?->code);
        $this->assertSame('community', $request->category?->text);
        $this->assertSame(MedicationRequestRequest::SOURCE_LOCAL, $request->source);
    }

    public function test_sum_issued_quantity_by_activity_uses_uuid(): void
    {
        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->toDateString(),
            'title' => 'Qty plan',
            'status' => 'active',
        ]);

        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'quantity' => 10,
        ]);

        $repo = app(MedicationRequestRepository::class);
        $repo->store([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'status' => MedicationRequestStatus::ACTIVE->value,
            'medication_id' => (string) Str::uuid(),
            'medication_qty' => 3,
            'intent' => 'order',
            'based_on_uuid' => $activity->uuid,
        ], $this->person->id);
        $repo->store([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'status' => MedicationRequestStatus::ENTERED_IN_ERROR->value,
            'medication_id' => (string) Str::uuid(),
            'medication_qty' => 9,
            'intent' => 'order',
            'based_on_uuid' => $activity->uuid,
        ], $this->person->id);

        $this->assertSame(3.0, $repo->sumIssuedQuantityByActivity((string) $activity->uuid));
    }
}
