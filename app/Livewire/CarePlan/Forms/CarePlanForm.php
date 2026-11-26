<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Forms;

use App\Core\Arr;
use App\Core\BaseForm;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CarePlanForm extends BaseForm
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
    public string $informWith = '';
    public string $termsOfService = '';
    public array $episodes = [];
    public array $medicalRecords = [];
    public ?string $based_on = null;
    public ?string $part_of = null;

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
        ];
    }

    /** Validate the draft fields and the choices resolved for the current patient and facility. */
    public function validateDraft(array $authorUuids, array $episodeUuids, array $medicalRecordKeys): array
    {
        $validated = $this->validate([
            ...$this->rules(),
            'title' => 'required|string|max:255',
            'context' => 'nullable|string|max:255',
            'informWith' => 'nullable|string|max:255',
            'periodStartTime' => 'nullable|date_format:H:i',
            'periodEndTime' => 'nullable|date_format:H:i',
            'coAuthors' => 'array|list',
            'episodes' => 'array|list',
            'medicalRecords' => 'array|list',
            'coAuthors.*' => ['nullable', 'uuid', Rule::in($authorUuids)],
            'episodes.*' => 'array',
            'episodes.*.uuid' => ['required', 'uuid', 'distinct', Rule::in($episodeUuids)],
            'medicalRecords.*' => 'array',
        ]);

        $start = $this->periodStartIsoDate().' '.($this->periodStartTime ?: '00:00');
        $end = $this->periodEndIsoDate();
        if ($end !== null && ($end.' '.($this->periodEndTime ?: '00:00')) < $start) {
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

    public function rulesForSigning(): array
    {
        return array_merge($this->rules(), $this->signingRules());
    }

    /** Validate the editable fields against the draft revision locked for this save. */
    public function validateForUpdate(string $expectedRevision, string $currentRevision): array
    {
        if (!hash_equals($expectedRevision, $currentRevision)) {
            throw ValidationException::withMessages(['submission' => __('care-plan.document_context_unavailable')]);
        }

        return $this->validate($this->rulesForDraftEdit());
    }

    /** Validate the editable values and reference shapes of an already saved draft. */
    public function rulesForDraftEdit(): array
    {
        return [
            ...$this->rules(),
            'periodStartTime' => 'nullable|date_format:H:i,H:i:s',
            'periodEndTime' => 'nullable|date_format:H:i,H:i:s',
            'coAuthors' => 'array',
            'coAuthors.*' => 'nullable|uuid',
            'episodes.*' => 'array',
            'episodes.*.uuid' => 'required|uuid',
            'medicalRecords.*' => 'array',
            'medicalRecords.*.identifier' => 'sometimes|array',
            'medicalRecords.*.displayValue' => 'sometimes|nullable|string',
            'medicalRecords.*.uuid' => 'required_without:medicalRecords.*.identifier|uuid',
            'medicalRecords.*.type' => 'required_without:medicalRecords.*.identifier|in:procedure,diagnostic_report',
        ];
    }

    public function periodStartIsoDate(): string
    {
        return CarbonImmutable::createFromFormat('!'.config('app.date_format'), $this->periodStart)->format('Y-m-d');
    }

    public function periodEndIsoDate(): ?string
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
            'informWith' => __('care-plan.inform_with'),
            'termsOfService' => __('forms.providing_condition'),
            'knedp' => __('forms.knedp') ?? 'КНЕДП',
            'keyContainer' => __('forms.key_container') ?? 'Ключ-контейнер',
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

    public static function buildCreateContent(array $resource): array
    {
        Validator::make($resource, [
            'id' => ['required', 'uuid'], 'title' => ['required', 'string'],
            'category.coding' => ['required', 'array', 'min:1'],
            'period.start' => ['required', 'date'],
            'encounter.identifier.value' => ['required', 'uuid'],
            'author.identifier.value' => ['required', 'uuid'],
            'addresses' => ['required', 'array', 'min:1'],
            'termsOfService.coding' => ['required', 'array', 'min:1'],
        ])->validate();
        $content = array_intersect_key($resource, array_flip(['id', 'category', 'title', 'description', 'period', 'supportingInfo', 'note', 'encounter', 'addresses', 'author', 'contributor', 'termsOfService', 'informWith']));
        $content['intent'] = 'order';
        $content['status'] = 'new';

        return self::resourcePayload($content);
    }

    public static function buildCancelContent(array $snapshot, array $statusReason): array
    {
        Validator::make($snapshot, ['id' => ['required', 'uuid']])->validate();
        unset($snapshot['activities']);
        $snapshot['status_reason'] = $statusReason;

        return $snapshot;
    }

    private static function resourcePayload(array $data): array
    {
        $result = [];
        foreach (Arr::toSnakeCase($data) as $key => $value) {
            if (is_array($value)) {
                $value = self::resourcePayload($value);
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $result[$key] = $value;
            }
        }

        return array_is_list($data) ? array_values($result) : $result;
    }
}
