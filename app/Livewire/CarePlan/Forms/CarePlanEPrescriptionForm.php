<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Forms;

use Livewire\Form;

class CarePlanEPrescriptionForm extends Form
{
    public string|int|null $activity_id = '';
    public string|int|float|null $container_dosage = '';
    public string|null $dose_ingredient_id = '';
    public string|null $dose_input_unit = '';
    public bool $dose_warning_confirmed = false;
    public string|int|null $duration = '';
    public string|int|null $encounter_id = '';
    public string|null $ended_at = '';
    public string|null $inform_with = '';
    public string|int|float|null $max_dose_per_administration = '';
    public string|int|float|null $max_dose_per_period = '';
    public string|null $medication_id = '';
    public string|int|float|null $medication_qty = '';
    public string|null $medication_unit = '';
    public string|null $note = '';
    public bool $phone_confirmed = false;
    public string|null $program_id = '';
    public bool $request_notification_disabled = false;
    public string|null $route = '';
    public string|null $signature_text = '';
    public string|null $started_at = '';

    protected function rules(): array
    {
        return [
            'encounter_id' => 'required|integer|min:1',
            'medication_id' => 'required|string|uuid',
            'inform_with' => 'nullable|string',
            'signature_text' => 'required|string',
            'max_dose_per_administration' => 'required|numeric|min:0.01',
            'max_dose_per_period' => 'required|numeric|min:0.01',
            'medication_qty' => 'required|numeric|min:0.01',
            'started_at' => 'required|date',
            'duration' => 'required|integer|min:1',
            'ended_at' => 'required|date|after_or_equal:started_at',
        ];
    }

    protected function messages(): array
    {
        return [
            'encounter_id.required' => __('care-plan.eprescription_encounter_required'),
            'signature_text.required' => __('care-plan.eprescription_signature_required'),
            'ended_at.after_or_equal' => __('errors.ehealth.messages.period_end_before_start'),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'encounter_id' => __('care-plan.encounter'),
            'medication_id' => __('care-plan.medication'),
            'inform_with' => __('encounters.eprescription.patient_authentication'),
            'signature_text' => __('medication-requests.signatura'),
            'max_dose_per_administration' => __('encounters.eprescription.max_dose_per_administration'),
            'max_dose_per_period' => __('encounters.eprescription.max_dose_per_period'),
            'medication_qty' => __('medication-requests.kil_kist'),
            'started_at' => __('encounters.eprescription.course_start'),
            'duration' => __('medication-requests.treatment_duration'),
            'ended_at' => __('encounters.eprescription.course_end'),
        ];
    }
}
