<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PrimarySourceRequiredForAssistant;
use App\Enums\DetectedIssue\Status;
use App\Enums\Status as EmployeeStatus;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DetectedIssue;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Livewire\Form;

class DetectedIssueForm extends Form
{
    public array $detectedIssues = [];

    protected function rules(): array
    {
        return [
            'detectedIssues' => [
                'nullable',
                'array',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value && Auth::user()->cannot('create', DetectedIssue::class)) {
                        $fail(__('detected-issues.policy.create'));
                    }
                }
            ],
            'detectedIssues.*.uuid' => ['nullable', 'uuid'],
            'detectedIssues.*.subjectId' => [
                'required_with:detectedIssues',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->validateDeviceReference($value, $fail);
                }
            ],
            'detectedIssues.*.status' => [
                'required_with:detectedIssues',
                'string',
                new InDictionary('detected_issue_statuses'),
                Rule::in([
                    Status::PRELIMINARY->value,
                    Status::MITIGATED->value
                ])
            ],
            'detectedIssues.*.code' => [
                'required_with:detectedIssues',
                'string',
                new InDictionary('detected_issue_codes')
            ],
            'detectedIssues.*.detail' => [
                'nullable',
                'string',
                'max:3000'
            ],
            'detectedIssues.*.implicatedId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!$value) {
                        return;
                    }

                    $this->validateDeviceReference($value, $fail);
                }
            ],
            'detectedIssues.*.basedOnId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!$value) {
                        return;
                    }

                    $this->validateBasedOn((int) explode('.', $attribute)[1], $value, $fail);
                }
            ],
            'detectedIssues.*.primarySource' => [
                'required_with:detectedIssues',
                'boolean',
                new PrimarySourceRequiredForAssistant()
            ],
            'detectedIssues.*.authorEmployeeId' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $isPrimarySource = ($this->detectedIssues[(int) explode('.', $attribute)[1]]['primarySource'] ?? true) === true;

                    return [
                        Rule::requiredIf($isPrimarySource),
                        Rule::prohibitedIf(!$isPrimarySource),
                        'nullable',
                        'uuid',
                        Rule::exists(Employee::class, 'uuid')
                            ->where('legal_entity_id', legalEntity()->id)
                            ->where('status', EmployeeStatus::APPROVED->value)
                            ->where('is_active', true)
                    ];
                }
            ),
            'detectedIssues.*.reportOriginCode' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $primarySource = $this->detectedIssues[$index]['primarySource'] ?? true;

                    return [
                        Rule::requiredIf($primarySource === false),
                        $primarySource === true ? 'prohibited' : 'nullable',
                        'string',
                        new InDictionary('eHealth/report_origins')
                    ];
                }
            ),
            'detectedIssues.*.identifiedDate' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $detectedIssue = $this->detectedIssues[$index] ?? [];
                    $primarySource = $detectedIssue['primarySource'] ?? true;
                    $encounterDate = ($this->component->form->encounter['periodDate'] ?? '') ?: 'today';
                    $rules = [Rule::requiredIf(!empty($detectedIssue['identifiedTime'])), 'nullable', 'date'];

                    if ($primarySource === true) {
                        $rules[] = 'date_equals:' . $encounterDate;
                    } else {
                        $rules[] = 'before_or_equal:' . $encounterDate;
                    }

                    return $rules;
                }
            ),
            'detectedIssues.*.identifiedTime' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $detectedIssue = $this->detectedIssues[$index] ?? [];
                    $date = $detectedIssue['identifiedDate'] ?? '';
                    $primarySource = $detectedIssue['primarySource'] ?? true;
                    $encounter = $this->component->form->encounter ?? [];
                    $rules = [
                        Rule::requiredIf(!empty($date)),
                        'nullable',
                        'date_format:H:i',
                        $this->notAfterEncounterEnd($date)
                    ];

                    if ($primarySource === true) {
                        $rules[] = new AfterOrEqualDateTime(
                            $date,
                            $encounter['periodDate'] ?? '',
                            $encounter['periodStart'] ?? '',
                            'encounter_period_start'
                        );
                    }

                    return $rules;
                }
            )
        ];
    }

    /**
     * Name detected issue fields the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        $names = __('detected-issues.attributes');
        // A field nested deeper than one record keeps the name it carries for every index
        $attributes = collect($names)
            ->mapWithKeys(static fn (string $name, string $field): array => ["detectedIssues.*.$field" => $name])
            ->all();

        // Each name carries the detected issue number, so an error points to the card it belongs to
        foreach ($this->detectedIssues as $index => $detectedIssue) {
            $number = __('detected-issues.position', ['position' => $index + 1]);

            foreach ($names as $field => $name) {
                if (!str_contains($field, '.*.')) {
                    $attributes["detectedIssues.$index.$field"] = "$name, $number";

                    continue;
                }

                [$nestedProperty, $nestedField] = explode('.*.', $field, 2);

                foreach (array_keys($detectedIssue[$nestedProperty] ?? []) as $nestedIndex) {
                    $attributes["detectedIssues.$index.$nestedProperty.$nestedIndex.$nestedField"] = "$name, $number";
                }
            }
        }

        return $attributes;
    }

    /**
     * Ensure that a device is either registered in the current
     * encounter package or already belongs to the patient.
     *
     * @param  string  $deviceId
     * @param  Closure  $fail
     * @return void
     */
    private function validateDeviceReference(string $deviceId, Closure $fail): void
    {
        $isPackageDevice = collect($this->component->deviceForm->devices)->contains(
            static fn (array $device): bool => ($device['uuid'] ?? '') === $deviceId
        );

        $isExistingDevice = collect($this->component->patientDevices)->contains(
            static fn (array $device): bool => ($device['uuid'] ?? '') === $deviceId
        );

        if (!$isPackageDevice && !$isExistingDevice) {
            $fail(__('detected-issues.validation.device_not_found'));
        }
    }

    /**
     * Validate the reference to a previous detected issue.
     *
     * @param  int  $index
     * @param  string  $basedOnId
     * @param  Closure  $fail
     * @return void
     */
    private function validateBasedOn(int $index, string $basedOnId, Closure $fail): void
    {
        $currentIssue = $this->detectedIssues[$index] ?? [];
        $currentUuid = $currentIssue['uuid'] ?? '';
        $subjectId = $currentIssue['subjectId'] ?? '';

        if ($currentUuid === $basedOnId) {
            $fail(__('detected-issues.validation.based_on_self'));

            return;
        }

        $packageIssue = collect($this->detectedIssues)->first(static fn (array $issue): bool => ($issue['uuid'] ?? '') === $basedOnId);

        if ($packageIssue !== null) {
            if (($packageIssue['subjectId'] ?? '') !== $subjectId) {
                $fail(__('detected-issues.validation.based_on_subject_mismatch'));
            }

            return;
        }

        $query = DetectedIssue::query()
            ->whereUuid($basedOnId)
            ->whereNot('status', Status::ENTERED_IN_ERROR->value)
            ->with('subject');

        if ($this->component->prepersonId !== null) {
            $query->wherePrepersonId($this->component->prepersonId);
        } else {
            $query->wherePersonId($this->component->personId);
        }

        $existingIssue = $query->first();

        if ($existingIssue === null) {
            $fail(__('detected-issues.validation.based_on_not_found'));

            return;
        }

        if ($existingIssue->subject?->value !== $subjectId) {
            $fail(__('detected-issues.validation.based_on_subject_mismatch'));
        }
    }

    /**
     * Fail when the detected issue datetime is later
     * than the end of the encounter.
     *
     * @param  string  $date
     * @return Closure
     */
    private function notAfterEncounterEnd(string $date): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($date): void {
            $encounter = $this->component->form->encounter ?? [];

            if (empty($date) || empty($value) || empty($encounter['periodDate']) || empty($encounter['periodEnd'])) {
                return;
            }

            $format = config('app.date_format') . ' H:i';
            $dateTime = CarbonImmutable::createFromFormat($format, $date . ' ' . $value);
            $periodEnd = CarbonImmutable::createFromFormat($format, $encounter['periodDate'] . ' ' . $encounter['periodEnd']);

            if ($dateTime->greaterThan($periodEnd)) {
                $fail(__('validation.before_or_equal', ['date' => __('validation.attributes.encounter_period_end')]));
            }
        };
    }
}
