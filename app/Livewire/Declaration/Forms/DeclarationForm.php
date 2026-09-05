<?php

declare(strict_types=1);

namespace App\Livewire\Declaration\Forms;

use App\Core\BaseForm;
use App\Enums\Declaration\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\Person\Person;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

class DeclarationForm extends BaseForm
{
    public string $personId;

    public string $employeeId = '';

    public string $divisionId = '';

    public ?string $authorizeWith = null;

    /**
     * Identifiers of the patient's authentication methods that can be chosen to authorize the declaration.
     *
     * @var array
     */
    #[Locked]
    public array $authMethodIds = [];

    public ?string $parentDeclarationId = null;

    public ?int $verificationCode = null;

    /**
     * Mark 'information from the patient leaflet was communicated to the patient or their legal representative'.
     *
     * @var bool
     */
    public bool $processDisclosureDataConsent = false;

    public array $uploadedDocuments = [];

    /**
     * List of rules for creating.
     *
     * @return array
     */
    public function rulesForCreating(): array
    {
        return [
            'personId' => [
                'required',
                'uuid',
                Rule::exists('persons', 'uuid')
                    ->where(fn (QueryBuilder $query) => $query->whereNot('verification_status', 'NOT_VERIFIED')),
                // Match with age and document type
                $this->validateDocumentTypeForPatientAge()
            ],
            'employeeId' => [
                'required',
                'uuid',
                Rule::exists('employees', 'uuid')
                    ->where(fn (QueryBuilder $query) => $query->where('employee_type', Role::DOCTOR)),
                // Match with age and doctor speciality
                $this->validateDoctorSpecialityForPatientAge()
            ],
            'divisionId' => ['required', 'uuid', Rule::exists('divisions', 'uuid')],
            'authorizeWith' => ['nullable', 'uuid', Rule::in($this->authMethodIds)],
            'parentDeclarationId' => [
                'nullable',
                'uuid',
                Rule::exists('declarations', 'uuid')->where('status', Status::ACTIVE->value)
            ]
        ];
    }

    /**
     * Messages for the checks whose rules hide a business condition behind a plain existence check.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'personId.exists' => __('declarations.validation.person_not_verified'),
            'employeeId.exists' => __('declarations.validation.employee_not_doctor'),
            'parentDeclarationId.exists' => __('declarations.validation.parent_declaration_not_active')
        ];
    }

    /**
     * List of rules for approving.
     *
     * @return array[]
     */
    public function rulesForApproving(): array
    {
        return [
            'verificationCode' => ['required', 'digits_between:1,4'],
            'processDisclosureDataConsent' => ['required', 'boolean:strict', Rule::in([true])]
        ];
    }

    /**
     * List of rules for uploading documents.
     *
     * @return array[]
     */
    public function rulesForUploadingDocuments(): array
    {
        return [
            'uploadedDocuments.*' => ['required', 'file', 'mimes:jpeg,jpg', 'max:10000'],
            'processDisclosureDataConsent' => ['required', 'boolean:strict', Rule::in([true])]
        ];
    }

    /**
     * Validate employee speciality vs patient age.
     *
     * @return Closure
     */
    protected function validateDoctorSpecialityForPatientAge(): Closure
    {
        return function (string $attribute, string $value, Closure $fail) {
            $speciality = Employee::whereUuid($this->employeeId)
                ->whereHas('specialities', fn (EloquentBuilder $query) => $query->where('speciality_officio', true))
                ->firstOrFail()
                ->specialities()
                ->where('speciality_officio', true)
                ->value('speciality');
            $patient = Person::whereUuid($this->personId)->firstOrFail();
            $adultAge = config('ehealth.adult_age');

            // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/18000740491/RC_+CSI-1323+_Create+declaration+request+v3#Check-that-doctor-speciality-meets-the-patient-age-requirements
            if ($speciality === 'THERAPIST' && $patient->age < $adultAge) {
                $fail(__('declarations.validation.therapist_patient_age', ['age' => $adultAge]));
            }

            if ($speciality === 'PEDIATRICIAN' && $patient->age >= $adultAge) {
                $fail(__('declarations.validation.pediatrician_patient_age', ['age' => $adultAge]));
            }
        };
    }

    /**
     * Validate that the patient holds a valid document type allowed for a declaration at their age.
     *
     * @return Closure
     */
    protected function validateDocumentTypeForPatientAge(): Closure
    {
        return static function (string $attribute, string $value, Closure $fail): void {
            $patient = Person::whereUuid($value)->first();

            if ($patient === null) {
                return;
            }

            $allowedTypes = $patient->age < config('ehealth.no_self_auth_age')
                ? config('ehealth.declaration_no_self_auth_age_document_types')
                : config('ehealth.declaration_self_auth_age_document_types');

            if (!$patient->documents()->whereIn('type', $allowedTypes)->exists()) {
                $fail(__('declarations.validation.document_type_not_allowed', [
                    'types' => collect($allowedTypes)
                        ->map(static fn (string $type): string => __("patients.documents.$type"))
                        ->implode(', ')
                ]));

                return;
            }

            $hasValidDocument = $patient->documents()
                ->whereIn('type', $allowedTypes)
                ->where(static function (EloquentBuilder $query): void {
                    $query->whereNull('expiration_date')
                        ->orWhereDate('expiration_date', '>=', today());
                })
                ->exists();

            if (!$hasValidDocument) {
                $fail(__('declarations.validation.document_expired'));
            }
        };
    }
}
