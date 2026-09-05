<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents\Mappers;

use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Tests\TestCase;

/**
 * The mapper is a pure transformation over arrays, so these tests need neither the
 * database nor model factories.
 */
class MedicationRequestMapperTest extends TestCase
{
    private const array UUIDS = [
        'person_uuid' => 'pat-123',
        'employee_uuid' => 'emp-123',
        'division_uuid' => 'div-123',
        'legal_entity_uuid' => 'le-123',
    ];

    public function test_create_request_payload_carries_the_core_prescription_fields(): void
    {
        $payload = (new MedicationRequestMapper())->toCreateRequestPayload([
            'uuid' => 'req-123',
            'started_at' => '2026-01-01',
            'ended_at' => '2026-02-01',
            'medication_id' => 'med-123',
            'medication_qty' => 10,
            'medication_program_id' => 'prog-123',
            'intent' => 'order',
            'category' => 'inpatient',
        ], self::UUIDS);

        $request = $payload['medication_request_request'];

        $this->assertSame('pat-123', $request['person_id']);
        $this->assertSame('emp-123', $request['employee_id']);
        $this->assertSame('div-123', $request['division_id']);
        $this->assertSame('2026-01-01', $request['started_at']);
        $this->assertSame('2026-02-01', $request['ended_at']);
        $this->assertSame('med-123', $request['medication_id']);
        $this->assertSame(10.0, $request['medication_qty']);
        $this->assertSame('prog-123', $request['medical_program_id']);
        $this->assertSame('order', $request['intent']);
        $this->assertSame('inpatient', $request['category']);
    }

    public function test_create_request_payload_defaults_the_category_to_community(): void
    {
        $payload = (new MedicationRequestMapper())->toCreateRequestPayload([
            'medication_id' => 'med-123',
            'medication_qty' => 1,
        ], self::UUIDS);

        $this->assertSame('community', $payload['medication_request_request']['category']);
        $this->assertSame('order', $payload['medication_request_request']['intent']);
    }

    public function test_a_care_plan_prescription_is_linked_to_both_the_plan_and_the_activity(): void
    {
        $payload = (new MedicationRequestMapper())->toCreateRequestPayload([
            'medication_id' => 'med-123',
            'medication_qty' => 1,
            'based_on_uuid' => 'act-123',
        ], self::UUIDS, 'cp-123');

        $basedOn = $payload['medication_request_request']['based_on'];

        $this->assertCount(2, $basedOn);
        $this->assertSame('care_plan', $basedOn[0]['identifier']['type']['coding'][0]['code']);
        $this->assertSame('cp-123', $basedOn[0]['identifier']['value']);
        $this->assertSame('activity', $basedOn[1]['identifier']['type']['coding'][0]['code']);
        $this->assertSame('act-123', $basedOn[1]['identifier']['value']);
    }

    public function test_a_standalone_prescription_is_not_linked_to_a_care_plan(): void
    {
        $payload = (new MedicationRequestMapper())->toCreateRequestPayload([
            'medication_id' => 'med-123',
            'medication_qty' => 1,
        ], self::UUIDS);

        $this->assertArrayNotHasKey('based_on', $payload['medication_request_request']);
    }

    public function test_inform_with_is_reduced_to_the_authentication_method_id(): void
    {
        $payload = (new MedicationRequestMapper())->toCreateRequestPayload([
            'medication_id' => 'med-123',
            'medication_qty' => 1,
            'inform_with' => 'auth-method-1|OTP|+380991112233',
        ], self::UUIDS);

        $this->assertSame('auth-method-1', $payload['medication_request_request']['inform_with']);
    }

    public function test_container_dosage_is_expanded_from_its_pipe_encoded_form(): void
    {
        $payload = (new MedicationRequestMapper())->toCreateRequestPayload([
            'medication_id' => 'med-123',
            'medication_qty' => 1,
            'container_dosage' => '2.5|мл|MILLILITER',
        ], self::UUIDS);

        $this->assertSame([
            'system' => 'MEDICATION_UNIT',
            'code' => 'MILLILITER',
            'value' => 2.5,
        ], $payload['medication_request_request']['container_dosage']);
    }

    public function test_dosage_instructions_are_mapped_with_dose_and_maximum_per_administration(): void
    {
        $payload = (new MedicationRequestMapper())->toCreateRequestPayload([
            'medication_id' => 'med-123',
            'medication_qty' => 1,
            'dosage_instructions' => [
                [
                    'sequence' => 1,
                    'text' => 'Приймати 1 таблетку',
                    'max_dose_per_administration' => 1,
                    'dose_and_rate' => [
                        ['dose_quantity_value' => 2, 'dose_quantity_unit' => 'мг'],
                    ],
                ],
            ],
        ], self::UUIDS);

        $dosage = $payload['medication_request_request']['dosage_instruction'][0];

        $this->assertSame('Приймати 1 таблетку', $dosage['text']);
        $this->assertSame('Приймати 1 таблетку', $dosage['patient_instruction']);
        $this->assertSame(2.0, $dosage['dose_and_rate']['dose_quantity']['value']);
        $this->assertSame('мг', $dosage['dose_and_rate']['dose_quantity']['unit']);
        $this->assertSame(1.0, $dosage['max_dose_per_administration']['value']);
        $this->assertSame('мг', $dosage['max_dose_per_administration']['unit']);
    }

    public function test_a_dosage_instruction_without_text_falls_back_to_the_doctor_wording(): void
    {
        $payload = (new MedicationRequestMapper())->toCreateRequestPayload([
            'medication_id' => 'med-123',
            'medication_qty' => 1,
            'dosage_instructions' => [['sequence' => 1]],
        ], self::UUIDS);

        $this->assertSame(
            'За призначенням лікаря',
            $payload['medication_request_request']['dosage_instruction'][0]['text']
        );
    }

    public function test_fhir_representation_identifies_the_patient_employee_and_legal_entity(): void
    {
        $fhir = (new MedicationRequestMapper())->toFhir([
            'uuid' => 'req-123',
            'medication_id' => 'med-123',
            'category' => 'community',
        ], self::UUIDS);

        $this->assertSame('req-123', $fhir['id']);
        $this->assertSame('draft', $fhir['status']);
        $this->assertSame('order', $fhir['intent']);
        $this->assertSame('med-123', $fhir['medicationCodeableConcept']['coding'][0]['code']);
        $this->assertSame('pat-123', $fhir['subject']['identifier']['value']);
        $this->assertSame('emp-123', $fhir['requester']['agent']['identifier']['value']);
        $this->assertSame('le-123', $fhir['requester']['onBehalfOf']['identifier']['value']);
    }

    public function test_fhir_representation_links_the_originating_activity(): void
    {
        $fhir = (new MedicationRequestMapper())->toFhir([
            'uuid' => 'req-123',
            'medication_id' => 'med-123',
            'based_on_uuid' => 'act-123',
        ], self::UUIDS);

        $this->assertSame('act-123', $fhir['basedOn'][0]['identifier']['value']);
        $this->assertSame(
            'care_plan_activity',
            $fhir['basedOn'][0]['identifier']['type']['coding'][0]['code']
        );
    }
}
