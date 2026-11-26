<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Forms;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class CarePlanDraftForm extends Form
{
    public string $patient = '';
    /** Read-only number displayed by the CarePlan form. */
    public string $medicalNumber = '';
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
    public string $termsOfService = '';
    public array $episodes = [];
    public array $medicalRecords = [];

    /** Validate the draft and its selections for the current patient and facility. */
    public function validateDraft(array $authorUuids, array $episodeUuids, array $medicalRecordKeys): array
    {
        $validated = $this->validate([
            'category' => 'required|string',
            'context' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'intent' => 'required|in:order',
            'periodStart' => 'required|date_format:'.config('app.date_format'),
            'periodEnd' => 'nullable|date_format:'.config('app.date_format').'|after_or_equal:periodStart',
            'encounter' => 'required|uuid',
            'description' => 'nullable|string',
            'note' => 'nullable|string',
            'termsOfService' => 'required|in:INPATIENT,OUTPATIENT',
            'periodStartTime' => 'nullable|date_format:H:i,H:i:s',
            'periodEndTime' => 'nullable|date_format:H:i,H:i:s',
            'coAuthors' => 'array|list',
            'episodes' => 'array|list',
            'medicalRecords' => 'array|list',
            'coAuthors.*' => ['nullable', 'uuid', Rule::in($authorUuids)],
            'episodes.*' => 'array',
            'episodes.*.uuid' => ['required', 'uuid', 'distinct', Rule::in($episodeUuids)],
            'medicalRecords.*' => 'array',
        ]);

        $start = $this->periodStartIsoDate().' '.str_pad($this->periodStartTime ?: '00:00', 8, ':00');
        $end = $this->periodEndIsoDate();
        if ($end !== null && $end.' '.str_pad($this->periodEndTime ?: '23:59:59', 8, ':00') < $start) {
            throw ValidationException::withMessages(['form.periodEnd' => __('care-plan.period_end_before_start')]);
        }

        $validated['episodes'] = array_column($validated['episodes'], 'uuid');
        $validated['medicalRecords'] = collect($validated['medicalRecords'])->map(static function (array $record): string {
            $type = $record['type'] ?? data_get($record, 'identifier.type.coding.0.code', '');
            $uuid = $record['uuid'] ?? data_get($record, 'identifier.value', '');

            return is_string($type) && is_string($uuid) ? $type.':'.$uuid : '';
        })->unique()->values()->all();
        if (array_diff($validated['medicalRecords'], $medicalRecordKeys) !== []) {
            throw ValidationException::withMessages(['form.medicalRecords' => __('validation.exists', [
                'attribute' => __('care-plan.medical_record'),
            ])]);
        }

        $validated['coAuthors'] = array_values(array_unique(array_filter($validated['coAuthors'])));

        return $validated;
    }

    protected function periodStartIsoDate(): string
    {
        return CarbonImmutable::createFromFormat('!'.config('app.date_format'), $this->periodStart)->format('Y-m-d');
    }

    protected function periodEndIsoDate(): ?string
    {
        return $this->periodEnd !== ''
            ? CarbonImmutable::createFromFormat('!'.config('app.date_format'), $this->periodEnd)->format('Y-m-d')
            : null;
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
            'periodStartTime' => __('care-plan.date_and_time_start'),
            'periodEndTime' => __('care-plan.date_and_time_end'),
            'coAuthors.*' => __('care-plan.co-author'),
            'episodes.*' => __('care-plan.episode'),
            'medicalRecords.*' => __('care-plan.medical_record'),
            'description' => __('care-plan.extended_description'),
            'note' => __('care-plan.notes'),
            'termsOfService' => __('forms.providing_condition'),
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
