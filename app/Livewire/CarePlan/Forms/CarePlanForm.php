<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Forms;

use Illuminate\Support\Facades\Validator;

use App\Core\Arr;
use App\Core\BaseForm;
use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Dto\CarePlan\Ehealth as EhealthData;
use App\Dto\CarePlan\EhealthUpdate as EhealthUpdateData;

use Carbon\CarbonImmutable;

class CarePlanForm extends BaseForm
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

    /**
     * Validation rules for signing.
     */
    public function rulesForSigning(): array
    {
        return array_merge($this->rules(), $this->signingRules());
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
        $resource = Arr::toCamelCase($resource);
        Validator::make($resource, [
            'id' => ['required', 'uuid'], 'title' => ['required', 'string'],
            'category.coding' => ['required', 'array', 'min:1'],
            'period.start' => ['required', 'date'],
            'encounter.identifier.value' => ['required', 'uuid'],
            'author.identifier.value' => ['required', 'uuid'],
            'addresses' => ['required', 'array', 'min:1'],
            'termsOfService.coding' => ['required', 'array', 'min:1'],
        ])->validate();
        $data = objectMapper()->map(new CarePlanDetails(Arr::toSnakeCase($resource)), EhealthData::class);
        $data->id = $resource['id'];
        $data->addresses = $resource['addresses'];
        $data->informWith = $resource['informWith'] ?? null;

        return $data->toArray();
    }

    public static function buildCancelContent(array $snapshot, array $statusReason): array
    {
        Validator::make($snapshot, ['id' => ['required', 'uuid']])->validate();

        return new EhealthUpdateData(new CarePlanDetails($snapshot), $statusReason)->toArray();
    }
}
