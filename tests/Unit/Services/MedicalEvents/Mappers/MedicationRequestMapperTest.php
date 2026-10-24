<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents\Mappers;

use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MedicationRequestMapperTest extends TestCase
{
    private function detail(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/medication-request-detail.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_complete_native_detail_survives_form_round_trip(): void
    {
        $mapper = new MedicationRequestMapper();
        $resource = $mapper->fromEHealth($this->detail());
        $this->assertEquals($resource, $mapper->toFhir($mapper->fromFhir($resource), ['resource' => $resource]));
        $this->assertFalse($resource['dosageInstruction'][0]['asNeededBoolean']);
        $this->assertSame(0, $resource['dosageInstruction'][0]['doseAndRate'][0]['doseRange']['low']['value']);
        $this->assertArrayNotHasKey('seed', $resource);
        $this->assertSame('88888888-8888-4888-8888-888888888888', $mapper->fromFhir($resource)['based_on_uuid']);
        $this->assertSame('33333333-3333-4333-8333-333333333333', $resource['requester']['identifier']['value']);
        $this->assertSame(0.123456789, $resource['quantity']['value']);
    }

    public function test_native_create_preserves_all_dosage_datatypes_and_uses_object_dose_and_rate(): void
    {
        $mapper = new MedicationRequestMapper();
        $detail = $this->detail();
        $resource = $mapper->fromEHealth($detail);
        $wire = $mapper->toCreateRequestPayload($mapper->fromFhir($resource), ['resource' => $resource])['medication_request_request'];
        $this->assertEquals($detail['dosage_instruction'], $wire['dosage_instruction']);
        $this->assertSame($detail['based_on'], $wire['based_on']);
        $this->assertSame($detail['container_dosage'], $wire['container_dosage']);
        $this->assertSame($detail['person']['id'], $wire['person_id']);
        $this->assertSame($detail['medication_info']['medication_id'], $wire['medication_id']);
        $this->assertArrayNotHasKey('id', $wire);
        $this->assertArrayNotHasKey('seed', $wire);
        $this->assertArrayNotHasKey('medicationReference', $wire);
    }

    public function test_multiple_instructions_and_as_needed_concept_are_preserved(): void
    {
        $mapper = new MedicationRequestMapper();
        $detail = $this->detail();
        $second = $detail['dosage_instruction'][0];
        unset($second['as_needed_boolean']);
        $second['as_needed_codeable_concept'] = ['coding' => [['system' => 'eHealth/SNOMED/medication_as_needed_reason_codes', 'code' => '144008']]];
        $second['sequence'] = 2;
        $second['dose_and_rate'] = ['dose_quantity' => ['value' => 0.01, 'unit' => 'mg', 'system' => 'eHealth/ucum/units', 'code' => 'mg'], 'rate_range' => ['low' => ['value' => 0, 'unit' => 'mg'], 'high' => ['value' => 1, 'unit' => 'mg']]];
        $detail['dosage_instruction'][] = $second;
        $resource = $mapper->fromEHealth($detail);
        $wire = $mapper->toCreateRequestPayload($mapper->fromFhir($resource), ['resource' => $resource])['medication_request_request'];
        $this->assertEquals($detail['dosage_instruction'], $wire['dosage_instruction']);
    }

    public function test_editing_note_preserves_unrepresented_timing_and_reference_codings(): void
    {
        $mapper = new MedicationRequestMapper();
        $resource = $mapper->fromEHealth($this->detail());
        $edited = $mapper->toFhir(['note' => 'Updated'], ['resource' => $resource]);
        $this->assertSame('Updated', $edited['note']);
        unset($edited['note'], $resource['note']);
        $this->assertSame($resource, $edited);
    }

    public function test_mapping_does_not_allocate_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MedicationRequestMapper())->toFhir(['medication_id' => 'medication']);
    }

    public function test_create_does_not_invent_date_text_route_or_units(): void
    {
        $mapper = new MedicationRequestMapper();
        $wire = $mapper->toCreateRequestPayload(['medication_id' => 'medication', 'medication_qty' => 0], []);
        $this->assertArrayNotHasKey('created_at', $wire['medication_request_request']);
        $this->assertArrayNotHasKey('dosage_instruction', $wire['medication_request_request']);
        $this->assertSame(0.0, $wire['medication_request_request']['medication_qty']);
    }

    public function test_scalar_daily_dose_reports_a_ukrainian_message_with_the_field_name(): void
    {
        $this->app->setLocale('uk');
        $mapper = new MedicationRequestMapper();
        $resource = $mapper->fromEHealth($this->detail());
        $resource['dosageInstruction'][0]['maxDosePerPeriod'] = 1;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('care-plan.medication_dosage_structure_required', [
            'field' => 'Максимальна добова доза',
        ]));

        $mapper->toCreateRequestPayload($mapper->fromFhir($resource), ['resource' => $resource]);
    }

    public function test_scalar_form_doses_use_catalog_quantity_units_and_a_one_day_ratio(): void
    {
        $mapper = new MedicationRequestMapper();
        $form = [
            'signature_text' => 'Synthetic instruction',
            'patient_instruction' => '',
            'max_dose_per_administration' => '0.5',
            'max_dose_per_period' => '1.5',
            'medication_unit' => 'untrusted display unit',
            'route' => 'oral',
        ];
        $medication = [
            'form' => 'SOLUTION',
            'ingredients' => [['name' => 'Synthetic ingredient', 'dosage' => ['denumerator_unit' => 'ML']]],
        ];
        $form['dosage_instructions'] = $mapper->dosageInstructionsFromForm($form, $medication);
        $wire = $mapper->toCreateRequestPayload($form, [])['medication_request_request']['dosage_instruction'][0];

        $this->assertSame(0.5, $wire['max_dose_per_administration']['value']);
        $this->assertSame('ML', $wire['max_dose_per_administration']['unit']);
        $this->assertSame(1.5, $wire['max_dose_per_period']['numerator']['value']);
        $this->assertSame('ML', $wire['max_dose_per_period']['numerator']['unit']);
        $this->assertSame(['value' => 1, 'unit' => 'd', 'system' => 'eHealth/ucum/units', 'code' => 'd'], $wire['max_dose_per_period']['denominator']);
        $this->assertFalse($wire['as_needed_boolean']);
        $this->assertArrayNotHasKey('patient_instruction', $wire);
        $this->assertSame('Synthetic instruction', $wire['text']);
    }

    public function test_medication_unit_can_use_the_catalog_package_numerator_unit(): void
    {
        $this->assertSame('ML', (new MedicationRequestMapper())->medicationUnit([
            'form' => 'SOLUTION',
            'packages' => [['container_dosage' => ['numerator_unit' => 'ML']]],
        ]));
    }

    public function test_dosage_without_catalog_units_is_rejected_instead_of_using_the_form_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('care-plan.medication_dosage_unit_unavailable'));
        (new MedicationRequestMapper())->dosageInstructionsFromForm([
            'signature_text' => 'Synthetic instruction',
            'medication_unit' => 'SOLUTION',
            'max_dose_per_administration' => '1',
            'max_dose_per_period' => '1',
        ], ['form' => 'SOLUTION']);
    }

    public static function unsafeDosages(): array
    {
        return [
            'missing required instruction text' => ['text', null],
            'scalar maximum without quantity units' => ['maxDosePerAdministration', 1],
            'scalar maximum without ratio denominator' => ['maxDosePerPeriod', 1],
            'two incompatible asNeeded choices' => ['asNeededCodeableConcept', ['text' => 'reason']],
            'two dose choices' => ['doseAndRate', [['doseQuantity' => ['value' => 1], 'doseRange' => ['low' => ['value' => 1]]]]],
            'multiple dose objects cannot be collapsed' => ['doseAndRate', [['doseQuantity' => ['value' => 1]], ['doseQuantity' => ['value' => 2]]]],
        ];
    }

    #[DataProvider('unsafeDosages')]
    public function test_ambiguous_dosage_is_rejected_before_api(string $field, mixed $value): void
    {
        $mapper = new MedicationRequestMapper();
        $resource = $mapper->fromEHealth($this->detail());
        if ($value === null) {
            unset($resource['dosageInstruction'][0][$field]);
        } else {
            $resource['dosageInstruction'][0][$field] = $value;
        }
        $this->expectException(InvalidArgumentException::class);
        $mapper->toCreateRequestPayload(['uuid' => $resource['id']], ['resource' => $resource]);
    }

    public static function invalidDates(): array
    {
        return [['2026-02-30'], ['2026-10-19T23:00:00-07:00'], ['19.10.2026']];
    }

    #[DataProvider('invalidDates')]
    public function test_calendar_dates_are_not_guessed_or_timezone_shifted(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MedicationRequestMapper())->toCreateRequestPayload(['created_at' => $value], []);
    }

    public function test_local_payload_cannot_be_reconstructed_for_signing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MedicationRequestMapper())->toCreateSignedContent([], []);
    }

    public function test_prequalify_moves_only_program_id_outside_request(): void
    {
        $mapper = new MedicationRequestMapper();
        $resource = $mapper->fromEHealth($this->detail());
        $wire = $mapper->toPrequalifyPayload($mapper->fromFhir($resource), ['resource' => $resource]);
        $this->assertSame([['id' => '66666666-6666-4666-8666-666666666666']], $wire['programs']);
        $this->assertArrayNotHasKey('medical_program_id', $wire['medication_request_request']);
    }
}
