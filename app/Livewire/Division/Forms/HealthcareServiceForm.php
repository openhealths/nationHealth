<?php

declare(strict_types=1);

namespace App\Livewire\Division\Forms;

use App\Core\Arr;
use App\Enums\License\Type;
use App\Enums\Status;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Rules\InDictionary;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class HealthcareServiceForm extends Form
{
    public string $divisionId;

    public array $category = [
        'coding' => [['system' => 'HEALTHCARE_SERVICE_CATEGORIES']]
    ];

    public ?string $specialityType = '';

    public string $providingCondition = '';

    public ?array $type = [
        'coding' => [['system' => 'HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES']]
    ];

    public ?string $licenseId = null;

    public ?string $comment;

    public ?array $availableTime = [];
    public ?array $notAvailable = [];

    /**
     * Rules based on: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17089101853/Create+healthcare+service#Request-data-validation
     *
     * @return array
     */
    public function rules(): array
    {
        $categoriesConfigKey = 'healthcare_service_' . strtolower(legalEntity()->type->name) . '_categories';
        $providingConditionConfigKey = 'legal_entity_' . strtolower(legalEntity()->type->name) . '_providing_conditions';

        $categoryCode = Arr::get($this->category, 'coding.0.code');
        // Check for HEALTHCARE_SERVICE_<$.category>_LICENSE_TYPE, see: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17088643146/Configurations+for+Healthcare+services
        $isLicenseRequiredForType = in_array($categoryCode, [Type::PHARMACY->value, Type::PHARMACY_DRUGS->value], true);

        return array_merge([
            'divisionId' => ['required', 'uuid', Rule::exists('divisions', 'uuid')->where('status', Status::ACTIVE)],
            'category' => ['array', 'required'],
            'category.coding.*.system' => ['required', 'string', Rule::in('HEALTHCARE_SERVICE_CATEGORIES')],
            'category.coding.*.code' => [
                'required',
                'string',
                new InDictionary('HEALTHCARE_SERVICE_CATEGORIES'),
                Rule::in(config("ehealth.$categoriesConfigKey", []))
            ],
            'specialityType' => [
                'nullable',
                'string',
                new InDictionary('SPECIALITY_TYPE'),
                'required_if:category.coding.0.code,' . Type::MSP->value,
                'prohibited_unless:category.coding.0.code,' . Type::MSP->value
            ],
            'providingCondition' => [
                'required',
                'string',
                new InDictionary('PROVIDING_CONDITION'),
                Rule::in(config("ehealth.$providingConditionConfigKey", []))
            ],
            'type' => ['array', 'nullable'],
            'type.coding.*.system' => ['nullable', 'string', Rule::in('HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES')],
            'type.coding.*.code' => [
                'nullable',
                'string',
                'required_if:category.coding.0.code,' . Type::PHARMACY_DRUGS->value,
                'prohibited_unless:category.coding.0.code,' . Type::PHARMACY_DRUGS->value,
                new InDictionary(['HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES', 'LEGAL_ENTITY_TYPE_V2']),
            ],
            'licenseId' => [
                'nullable',
                'uuid',
                Rule::exists('licenses', 'uuid')->where('is_active', true)
                    ->where(function (QueryBuilder $query) {
                        $query->where('expiry_date', '>=', now())->orWhereNull('expiry_date');
                    })->when($isLicenseRequiredForType, fn (Exists $rule) => $rule->where('type', $categoryCode)),
                'required_if:category.coding.0.code,' . Type::PHARMACY->value . ',' . Type::PHARMACY_DRUGS->value,
                'prohibited_if:category.coding.0.code,' . Type::MSP->value
            ]
        ], $this->rulesForUpdating());
    }

    /**
     * List of rules for update(times and comment).
     *
     * @return array[]
     */
    public function rulesForUpdating(): array
    {
        return [
            'comment' => ['nullable', 'string'],
            'availableTime' => ['array', 'nullable'],
            'availableTime.*.daysOfWeek' => ['required', 'array', 'min:1', 'max:7'],
            'availableTime.*.allDay' => ['required', 'boolean'],
            'availableTime.*.availableStartTime' => [
                'nullable',
                'required_unless:availableTime.*.allDay,true',
                'date_format:H:i:s'
            ],
            'availableTime.*.availableEndTime' => [
                'nullable',
                'required_unless:availableTime.*.allDay,true',
                'date_format:H:i:s',
                'after:availableTime.*.availableStartTime'
            ],
            'notAvailable' => ['array', 'nullable'],
            'notAvailable.*.during.startDate' => ['required', 'date_format:' . config('app.date_format')],
            'notAvailable.*.during.startTime' => ['required', 'date_format:H:i'],
            'notAvailable.*.during.endDate' => [
                'required',
                'date_format:' . config('app.date_format'),
                'after_or_equal:notAvailable.*.during.startDate'
            ],
            'notAvailable.*.during.endTime' => ['required', 'date_format:H:i'],
            'notAvailable.*.description' => ['required', 'string']
        ];
    }

    /**
     * Redefine field names for error messages.
     *
     * @return array
     */
    protected function validationAttributes(): array
    {
        return [
            'divisionId' => __('forms.division_name'),
            'availableTime.*.availableStartTime' => mb_strtolower(__('forms.start_time')),
            'availableTime.*.availableEndTime' => mb_strtolower(__('forms.end')),
            'notAvailable.*.during.startDate' => mb_strtolower(__('forms.date')),
            'notAvailable.*.during.startTime' => mb_strtolower(__('healthcare-services.start_non_working_time')),
            'notAvailable.*.during.endDate' => mb_strtolower(__('forms.date')),
            'notAvailable.*.during.endTime' => mb_strtolower(__('healthcare-services.end_non_working_time')),
            'notAvailable.*.description' => mb_strtolower(__('healthcare-services.comment_non_working_hours')),
        ];
    }

    /**
     * Custom validation messages for nested values translations.
     *
     * @return array
     */
    protected function messages(): array
    {
        return [
            'specialityType.required_if' => __('healthcare-services.validation.speciality_type.required_if'),
            'specialityType.prohibited_unless' => __('healthcare-services.validation.speciality_type.prohibited_unless'),
            'type.coding.*.code.required_if' => __('healthcare-services.validation.type_coding.required_if'),
            'type.coding.*.code.prohibited_unless' => __('healthcare-services.validation.type_coding.prohibited_unless'),
            'licenseId.required_if' => __('healthcare-services.validation.license_id.required_if'),
            'licenseId.prohibited_if' => __('healthcare-services.validation.license_id.prohibited_if'),
        ];
    }

    /**
     * Do form's validation (correctness of filling the form fields)
     *
     * @return array
     * @throws ValidationException
     */
    public function doValidation(): array
    {
        $validated = $this->validate();

        $this->validateConstraint();

        if (empty($validated['type']['coding'][0]['code'])) {
            unset($validated['type']);
        }

        return $validated;
    }

    /**
     * Convert date to ISO 8601 and format to snake case.
     */
    public function formatForApi(array $data): array
    {
        // format notAvailable
        if (isset($data['notAvailable'])) {
            $data['notAvailable'] = collect($data['notAvailable'])
                ->map(static function (array $item) {
                    if (isset($item['during'])) {
                        $during = $item['during'];

                        $item['during'] = [
                            'start' => convertToISO8601("{$during['startDate']} {$during['startTime']}"),
                            'end' => convertToISO8601("{$during['endDate']} {$during['endTime']}")
                        ];
                    }

                    return $item;
                })
                ->all();
        }

        return removeEmptyKeys(Arr::toSnakeCase($data));
    }

    /**
     * Validate constraint based on: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17089101853/Create+healthcare+service#Validate-constraint
     *
     * @return void
     */
    protected function validateConstraint(): void
    {
        $divisionId = Division::whereUuid($this->divisionId)->value('id');
        $categoryCode = data_get($this->category, 'coding.0.code');
        $typeCode = data_get($this->type, 'coding.0.code');

        if (!empty($this->specialityType) && !empty($this->providingCondition)) {
            $firstCheck = HealthcareService::whereDivisionId($divisionId)
                ->whereSpecialityType($this->specialityType)
                ->whereProvidingCondition($this->providingCondition)
                ->whereNotNull('uuid')
                ->exists();

            if ($firstCheck) {
                throw ValidationException::withMessages([
                    'unique_combination' => __('validation.attributes.healthcareService.constraint.typeAndCondition')
                ]);
            }
        }

        if (!empty($categoryCode) && !empty($typeCode)) {
            $secondCheck = HealthcareService::whereDivisionId($divisionId)
                ->whereHas('category.coding', fn (EloquentBuilder $query) => $query->where('code', $categoryCode))
                ->whereHas('type.coding', fn (EloquentBuilder $query) => $query->where('code', $typeCode))
                ->exists();

            if ($secondCheck) {
                throw ValidationException::withMessages([
                    'unique_combination' => __('validation.attributes.healthcareService.constraint.categoryAndType')
                ]);
            }
        }

        $thirdCheck = HealthcareService::whereDivisionId($divisionId)
            ->whereHas('category.coding', fn (EloquentBuilder $query) => $query->where('code', Type::PHARMACY))
            ->exists();

        if ($thirdCheck) {
            throw ValidationException::withMessages([
                'unique_combination' => __('validation.attributes.healthcareService.constraint.categoryPharmacy')
            ]);
        }
    }
}
