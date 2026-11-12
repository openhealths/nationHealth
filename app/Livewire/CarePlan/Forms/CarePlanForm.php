<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Forms;

use Livewire\Form;

class CarePlanForm extends Form
{
    public string $patient = '';
    public string $medical_number = '';
    public string $author = '';
    public array $coAuthors = [];
    public string $category = '';
    public string $context = '';
    public string $title = '';
    public string $intent = 'order';
    public string $periodStart = '';
    public string $periodStartTime = '';
    public string $periodEnd = '';
    public string $periodEndTime = '';
    public string $encounter = '';
    public string $description = '';
    public string $note = '';
    public string $informWith = '';
    public string $termsOfService = '';
    public array $episodes = [];
    public array $medicalRecords = [];
    public ?string $basedOn = null;
    public ?string $partOf = null;
    public string $knedp = '';
    public mixed $keyContainerUpload = null;
    public string $keyContainerFileName = '';
    public string $password = '';

    /**
     * Get the default validation rules.
     */
    public function rules(): array
    {
        return [
            'category' => 'required|string',
            'context' => 'nullable|string',
            'title' => 'required|string',
            'intent' => 'required|in:order',
            'periodStart' => 'required|date_format:'.config('app.date_format'),
            'periodEnd' => 'nullable|date_format:'.config('app.date_format').'|after_or_equal:periodStart',
            'encounter' => 'required|uuid',
            'description' => 'nullable|string',
            'note' => 'nullable|string',
            'informWith' => 'nullable|string',
            'termsOfService' => 'required|in:INPATIENT,OUTPATIENT',
            'episodes' => 'nullable|array',
            'medicalRecords' => 'nullable|array',
            'basedOn' => 'nullable|uuid',
            'partOf' => 'nullable|uuid',
        ];
    }

    /**
     * Validation rules for signing.
     */
    public function rulesForSigning(): array
    {
        return array_merge($this->rules(), $this->signatureRules());
    }

    public function signatureRules(): array
    {
        return [
            'knedp' => 'required|string',
            'keyContainerUpload' => 'required|file|max:1024',
            'password' => 'required|string',
        ];
    }

    /**
     * Localized attribute names for validation errors.
     */
    public function validationAttributes(): array
    {
        return [
            'category' => __('care-plan.category'),
            'context' => __('care-plan.context'),
            'title' => __('care-plan.name_care_plan'),
            'intent' => __('care-plan.intention'),
            'periodStart' => __('care-plan.date_and_time_start'),
            'periodEnd' => __('care-plan.date_and_time_end'),
            'encounter' => __('care-plan.encounter'),
            'description' => __('care-plan.extended_description'),
            'note' => __('care-plan.notes'),
            'informWith' => __('care-plan.inform_with'),
            'termsOfService' => __('forms.providing_condition'),
            'basedOn' => __('care-plan.based_care_plan'),
            'partOf' => __('care-plan.part_care_plan'),
            'knedp' => __('forms.knedp') ?? 'КНЕДП',
            'keyContainerUpload' => __('forms.key_container') ?? 'Ключ-контейнер',
            'password' => __('forms.password'),
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'periodEnd.after_or_equal' => __('care-plan.period_end_before_start'),
        ];
    }

}
