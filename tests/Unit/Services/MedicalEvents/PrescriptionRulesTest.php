<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Livewire\MedicationRequest\Forms\PrescriptionRules;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PrescriptionRulesTest extends TestCase
{
    private function drug(): array
    {
        return ['name' => 'Combination', 'daily_dosage' => 2, 'max_daily_dosage' => 3, 'ingredients' => [
            ['id' => 'a', 'name' => 'A', 'dosage' => ['numerator_value' => 200, 'numerator_unit' => 'MG', 'denumerator_value' => 1, 'denumerator_unit' => 'PIECE']],
            ['id' => 'b', 'name' => 'B', 'dosage' => ['numerator_value' => 50, 'numerator_unit' => 'MG', 'denumerator_value' => 1, 'denumerator_unit' => 'PIECE']],
        ], 'packages' => [
            ['package_min_qty' => 30, 'max_request_dosage' => 60],
            ['package_min_qty' => 20, 'max_request_dosage' => 300, 'container_dosage' => ['numerator_value' => 20, 'numerator_unit' => 'PIECE']],
        ]];
    }

    private function form(): array
    {
        return ['medication_qty' => 40, 'signature_text' => 'One twice daily', 'max_dose_per_administration' => 1, 'max_dose_per_period' => 2, 'started_at' => '2026-11-11', 'duration' => 20, 'phone_confirmed' => true, 'inform_with' => 'method|OTP|+380000000000'];
    }

    public function test_all_packages_are_supported_and_quantity_selector_is_not_cut_at_ten(): void
    {
        $this->assertTrue(PrescriptionRules::isAllowedQuantity(80, $this->drug()));
        $this->assertFalse(PrescriptionRules::isAllowedQuantity(70, $this->drug()));
        $this->assertFalse(PrescriptionRules::isAllowedQuantity(320, $this->drug()));
        $this->assertContains(300.0, PrescriptionRules::quantities($this->drug()));
        $this->assertGreaterThan(10, count(PrescriptionRules::quantities($this->drug())));
    }

    public function test_full_course_and_inclusive_end_date_are_calculated(): void
    {
        $form = PrescriptionRules::normalize($this->form(), $this->drug(), ['settings' => ['request_max_period_day' => 20]]);
        $this->assertSame('2026-11-30', $form['ended_at']);
        $this->expectException(ValidationException::class);
        PrescriptionRules::normalize(array_replace($this->form(), ['duration' => 21]), $this->drug(), ['settings' => ['request_max_period_day' => 30]]);
    }

    public function test_container_choices_accept_only_a_documented_package_dosage(): void
    {
        $choices = PrescriptionRules::containers($this->drug());
        $this->assertCount(1, $choices);
        $form = PrescriptionRules::normalize(array_replace($this->form(), ['container_dosage' => $choices[0]['value']]), $this->drug());
        $this->assertSame($choices[0]['value'], $form['container_dosage']);
        try {
            PrescriptionRules::normalize(array_replace($this->form(), ['container_dosage' => '99|PIECE|PIECE']), $this->drug());
            $this->fail('An undocumented package container was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('container_dosage', $exception->errors());
        }
    }

    public function test_program_period_and_maximum_daily_dose_block_creation(): void
    {
        foreach ([['duration' => 31], ['max_dose_per_period' => 4, 'medication_qty' => 100, 'dose_warning_confirmed' => true]] as $override) {
            try {
                PrescriptionRules::normalize(array_replace($this->form(), $override), $this->drug(), ['settings' => ['request_max_period_day' => 30]]);
                $this->fail('Unsafe prescription accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_ingredient_input_converts_every_dose_to_denominator_and_other_ingredients(): void
    {
        $form = array_replace($this->form(), ['dose_input_unit' => 'numerator', 'dose_ingredient_id' => 'a', 'max_dose_per_period' => 400, 'max_dose_per_administration' => 200]);
        $normalized = PrescriptionRules::normalize($form, $this->drug());
        $mapper = new MedicationRequestMapper();
        $dose = $mapper->dosageInstructionsFromForm($normalized, $this->drug())[0];
        $this->assertSame(2.0, $dose['max_dose_per_period']['numerator']['value']);
        $this->assertSame('PIECE', $dose['max_dose_per_period']['numerator']['unit']);
        $this->assertSame(100.0, $mapper->ingredientDoses(400, $normalized, $this->drug())[1]['value']);
    }

    public function test_recommended_dose_requires_explicit_confirmation_and_signature_marker(): void
    {
        $form = array_replace($this->form(), ['max_dose_per_period' => 3, 'medication_qty' => 60]);
        try {
            PrescriptionRules::normalize($form, $this->drug());
            $this->fail('Unconfirmed dose accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dose_warning_confirmed', $exception->errors());
        }
        $normalized = PrescriptionRules::normalize(array_replace($form, ['dose_warning_confirmed' => true]), $this->drug());
        $this->assertStringStartsWith('(!)', $normalized['signature_text']);
    }

    public function test_notifications_disabled_omit_authentication_and_unconfirmed_phone_blocks_other_programs(): void
    {
        $form = array_replace($this->form(), ['inform_with' => '', 'phone_confirmed' => false]);
        $normalized = PrescriptionRules::normalize($form, $this->drug(), ['settings' => ['request_notification_disabled' => true]]);
        $this->assertNull($normalized['inform_with']);
        $this->expectException(ValidationException::class);
        PrescriptionRules::normalize(array_replace($form, ['inform_with' => 'method|OTP|+380000000000']), $this->drug());
    }

    public function test_official_program_list_and_detail_enforce_the_same_period_and_notification_rules(): void
    {
        foreach (['medical_program_settings', 'settings'] as $shape) {
            $program = [$shape => ['request_max_period_day' => 20, 'request_notification_disabled' => true]];
            $form = array_replace($this->form(), ['inform_with' => '', 'phone_confirmed' => false]);
            $normalized = PrescriptionRules::normalize($form, $this->drug(), $program);
            $this->assertTrue($normalized['request_notification_disabled']);
            $this->assertNull($normalized['inform_with']);
            try {
                PrescriptionRules::normalize(array_replace($form, ['duration' => 21, 'medication_qty' => 60]), $this->drug(), $program);
                $this->fail('The native program period must apply to either documented response shape.');
            } catch (ValidationException $exception) {
                $this->assertSame(__('medication-requests.course_period_exceeded', ['max' => 20]), $exception->errors()['duration'][0]);
            }
        }
    }

    public function test_optional_program_has_no_period_rule_and_keeps_notifications_enabled(): void
    {
        $this->assertNull(PrescriptionRules::programSetting(null, 'request_max_period_day'));
        $this->assertFalse(PrescriptionRules::programSetting(null, 'request_notification_disabled', false));
    }
}
