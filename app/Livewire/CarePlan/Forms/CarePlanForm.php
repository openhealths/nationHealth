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
    public string $clinicalProtocol = '';
    public string $context = '';
    public string $title = '';
    public string $intent = 'order';
    public string $periodStart = '';
    public string $periodEnd = '';
    public string $encounter = '';
    public string $description = '';
    public string $note = '';
    public string $informWith = '';
    public string $termsOfService = '';
    public array $episodes = [];
    public array $medicalRecords = [];
    public string $knedp = '';
    public mixed $keyContainerUpload = null;
    public string $password = '';

    /**
     * Get the default validation rules.
     */
    public function rules(): array
    {
        return [
            'category' => 'required|string',
            'clinicalProtocol' => 'nullable|string',
            'context' => 'nullable|string',
            'title' => 'required|string',
            'periodStart' => 'required|string',
            'periodEnd' => 'nullable|string',
            'encounter' => 'nullable|string',
            'description' => 'nullable|string',
            'note' => 'nullable|string',
            'informWith' => 'nullable|string',
            'termsOfService' => 'required|string',
            'episodes' => 'nullable|array',
            'medicalRecords' => 'nullable|array',
        ];
    }

    /**
     * Validation rules for signing.
     */
    public function rulesForSigning(): array
    {
        return array_merge($this->rules(), [
            'knedp' => 'required|string',
            'keyContainerUpload' => 'required|file|max:1024',
            'password' => 'required|string',
        ]);
    }

    /**
     * Localized attribute names for validation errors.
     */
    public function validationAttributes(): array
    {
        return [
            'category' => __('care-plan.category'),
            'clinicalProtocol' => __('care-plan.clinical_protocol'),
            'context' => __('care-plan.context'),
            'title' => __('care-plan.name_care_plan'),
            'periodStart' => __('care-plan.date_and_time_start'),
            'periodEnd' => __('care-plan.date_and_time_end'),
            'encounter' => __('care-plan.encounter'),
            'description' => __('care-plan.extended_description'),
            'note' => __('care-plan.notes'),
            'informWith' => __('care-plan.inform_with'),
            'termsOfService' => __('care-plan.terms_of_service') ?? 'Умови надання послуг',
            'knedp' => __('forms.knedp') ?? 'КНЕДП',
            'keyContainerUpload' => __('forms.key_container') ?? 'Ключ-контейнер',
            'password' => __('forms.password'),
        ];
    }
}
