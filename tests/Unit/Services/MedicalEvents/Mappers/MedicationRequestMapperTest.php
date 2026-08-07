<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents\Mappers;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\MedicalEvents\Sql\Medications\DosageInstruction;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MedicationRequestMapperTest extends TestCase
{
    use DatabaseTransactions;

    public function test_to_create_request_payload_maps_correctly()
    {
        $carePlan = CarePlan::factory()->create(['uuid' => 'cp-123']);
        $activity = CarePlanActivity::factory()->create([
            'care_plan_id' => $carePlan->id,
            'uuid' => 'act-123'
        ]);

        $request = MedicationRequestRequest::factory()->create([
            'uuid' => 'req-123',
            'person_id' => 'pat-123',
            'employee_id' => 'emp-123',
            'division_id' => 'div-123',
            'started_at' => '2025-01-01',
            'ended_at' => '2025-02-01',
            'medication_id' => 'med-123',
            'medication_qty' => 10,
            'medication_program_id' => 'prog-123',
            'intent' => 'order',
            'category' => 'inpatient',
            'based_on_id' => $activity->id,
            'context_id' => 'enc-123',
        ]);

        $request->dosageInstructions()->save(DosageInstruction::factory()->make([
            'sequence' => 1,
            'text' => 'Take 1 pill',
            'timing_duration' => 10,
            'timing_duration_unit' => 'd',
            'timing_frequency' => 1,
            'timing_period' => 1,
            'timing_period_unit' => 'd',
            'route_id' => 'route-1',
            'dose_and_rate_type_id' => 'type-1',
            'dose_range_low_value' => 1,
            'dose_range_high_value' => 2,
            'max_dose_per_period_numerator_value' => 2,
            'max_dose_per_period_numerator_unit' => 'mg',
            'max_dose_per_period_denominator_value' => 1,
            'max_dose_per_period_denominator_unit' => 'd',
            'max_dose_per_administration_value' => 1,
            'max_dose_per_administration_unit' => 'mg',
        ]));

        $request->load('dosageInstructions');

        $mapper = new MedicationRequestMapper();
        $payload = $mapper->toCreateRequestPayload($request, $carePlan);

        $this->assertEquals('req-123', $payload['id']);
        $this->assertEquals('pat-123', $payload['person_id']);
        $this->assertEquals('emp-123', $payload['employee_id']);
        $this->assertEquals('order', $payload['intent']);
        $this->assertEquals('inpatient', $payload['category']);

        $this->assertCount(2, $payload['based_on']);
        $this->assertEquals('cp-123', $payload['based_on'][0]['identifier']['value']);
        $this->assertEquals('act-123', $payload['based_on'][1]['identifier']['value']);

        $this->assertCount(1, $payload['dosage_instruction']);
        $dosage = $payload['dosage_instruction'][0];
        $this->assertEquals('Take 1 pill', $dosage['text']);
        $this->assertEquals(2, $dosage['max_dose_per_period']['numerator']['value']);
        $this->assertEquals(1, $dosage['max_dose_per_administration']['value']);
    }

    public function test_to_fhir_maps_correctly_with_inform_with()
    {
        $request = MedicationRequestRequest::factory()->create([
            'uuid' => 'req-123',
            'inform_with' => 'SMS',
        ]);

        $mapper = new MedicationRequestMapper();
        $fhir = $mapper->toFhir($request, 'Dr. Smith');

        $this->assertEquals('req-123', $fhir['id']);
        $this->assertEquals('MedicationRequest', $fhir['resourceType']);
        $this->assertEquals('Dr. Smith', $fhir['author']['identifier']['value']);

        // Ensure inform_with extension is mapped in FHIR
        $hasInformWith = false;
        foreach ($fhir['extension'] as $ext) {
            if ($ext['url'] === 'https://ehealth.gov.ua/fhir/StructureDefinition/ehealth-medicationrequest-informWith') {
                $hasInformWith = true;
                $this->assertEquals('SMS', $ext['valueCodeableConcept']['coding'][0]['code']);
            }
        }
        $this->assertTrue($hasInformWith);
    }
}
