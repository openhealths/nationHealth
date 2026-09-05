<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Services\MedicalEvents\CarePlanActivityValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CarePlanActivityValidationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CarePlanActivityValidationService $service;

    private CarePlan $carePlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CarePlanActivityValidationService();

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Val',
            'last_name' => 'Doctor',
            'tax_id' => '9988776655',
            'birth_date' => '1972-01-01',
            'gender' => 'MALE',
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Val Doctor',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'party_id' => $party->id,
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Val',
            'last_name' => 'Patient',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $this->carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employee->id,
            'legal_entity_id' => $legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Validation Plan',
            'status' => 'draft',
            'terms_of_service' => 'OUTPATIENT',
            'category' => 'TREATMENT',
        ]);
    }

    public function test_providing_conditions_allows_when_list_empty(): void
    {
        $this->assertNull($this->service->providingConditionsBlockReason($this->carePlan, [
            'medical_program_settings' => [],
        ]));

        $this->assertNull($this->service->providingConditionsBlockReason($this->carePlan, null));
    }

    public function test_providing_conditions_blocks_mismatched_terms_of_service(): void
    {
        $message = $this->service->providingConditionsBlockReason($this->carePlan, [
            'medical_program_settings' => [
                'providing_conditions_allowed' => ['INPATIENT'],
            ],
        ]);

        $this->assertNotNull($message);
        $this->assertStringContainsString('INPATIENT', $message);
        $this->assertStringContainsString('OUTPATIENT', $message);
    }

    public function test_providing_conditions_allows_matching_terms_of_service(): void
    {
        $this->assertNull($this->service->providingConditionsBlockReason($this->carePlan, [
            'medical_program_settings' => [
                'providing_conditions_allowed' => ['OUTPATIENT', 'FIELD'],
            ],
        ]));
    }

    public function test_rehab_reason_reference_required_for_class_23(): void
    {
        $this->carePlan->update(['category' => 'CLASS 23']);

        $this->assertNotNull($this->service->rehabReasonReferenceBlockReason($this->carePlan, []));
        $this->assertNull($this->service->rehabReasonReferenceBlockReason($this->carePlan, [
            'Observation/' . (string) Str::uuid(),
        ]));
    }

    public function test_rehab_not_required_for_non_rehab_category(): void
    {
        $this->carePlan->update(['category' => 'TREATMENT']);

        $this->assertNull($this->service->rehabReasonReferenceBlockReason($this->carePlan, []));
    }

    public function test_is_rehab_category_normalizes_underscores(): void
    {
        $this->carePlan->update(['category' => 'CLASS_24']);

        $this->assertTrue($this->service->isRehabCategory($this->carePlan));
    }
}
