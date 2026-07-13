<?php

declare(strict_types=1);

namespace App\Livewire\Employee\Forms;

use App\Core\Arr;
use App\Livewire\Employee\EmployeeCreate;
use App\Livewire\Party\PartyEdit;
use App\Models\Employee\BaseEmployee;
use App\Rules\Cyrillic;
use App\Rules\DateFormat;
use App\Rules\DocumentNumber;
use App\Rules\HasIdentityDocumentRule;
use App\Rules\UniquePassportRule;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\Form;
use App\Rules\Name;
use App\Rules\TaxId;
use App\Rules\BirthDate;
use App\Rules\PhoneNumber;
use App\Rules\PhoneDuplicates;
use App\Models\Relations\Party;
use Illuminate\Validation\Rule;
use App\Models\Employee\Employee;
use App\Rules\UniqueEmailInLegalEntity;
use App\Models\Employee\EmployeeRequest;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EmployeeForm extends Form
{
    public string $position = '';
    public string $employeeType = '';
    public string $startDate = '';
    public ?string $endDate = null;
    public ?int $existingPartyId = null;
    public ?string $divisionId = null;

    public ?string $knedp = null;
    public ?TemporaryUploadedFile $keyContainerUpload = null;
    public ?string $password = null;

    public array $documents = [];
    public array $party = [
        'lastName' => '',
        'firstName' => '',
        'secondName' => null,
        'gender' => '',
        'birthDate' => '',
        'phones' => [['type' => '', 'number' => '']],
        'taxId' => '',
        'noTaxId' => false,
        'email' => '',
        'workingExperience' => null,
        'aboutMyself' => '',
    ];

    public array $doctor = [
        'specialities' => [],
        'scienceDegree' => [],
        'qualifications' => [],
        'educations' => [],
    ];

    public function rulesForSave(Component $component): array
    {
        $partyRules = $this->partyRules();

        // Specific logic for EmployeeCreate
        if ($component instanceof EmployeeCreate) {
            $partyRules['party.email'][] = new UniqueEmailInLegalEntity();
        }

        //
        // If we are summoned by PartyEdit, we validate ONLY
        // personal data (party) and documents.
        if ($component instanceof PartyEdit) {
            return array_merge(
                $partyRules,
                $this->documentsRules()
            );
        }

        // Standard behavior for EmployeeCreate, EmployeeEdit, EmployeePositionAdd:
        // validate everything (position, party, documents, doctor).
        return array_merge(
            $this->rootFieldsRules(),
            $partyRules,
            $this->documentsRules(),
            $this->doctorRules()
        );
    }

    public function rulesForKepOnly(): array
    {
        return [
            'knedp' => ['required', 'string'],
            'password' => ['required', 'string'],
            'keyContainerUpload' => ['required', 'file', 'extensions:dat,pfx,pk8,zs2,jks,p7s'],
        ];
    }

    protected function rootFieldsRules(): array
    {
        $customPositionAllowed = config('ehealth.employee_type_custom_position_allowed', []);
        $isCustomPositionAllowed = in_array($this->employeeType, $customPositionAllowed, true);

        $positionRules = ['required', 'string'];
        if (!$isCustomPositionAllowed) {
            $positionRules[] = Rule::in(array_keys($this->component->dictionaries['POSITION'] ?? []));
        }

        return [
            'position' => $positionRules,
            'employeeType' => ['required', 'string', Rule::in(array_keys($this->component->dictionaries['EMPLOYEE_TYPE'] ?? []))],
            'startDate' => ['required', new DateFormat()],
            'endDate' => [
                'nullable',
                Rule::when(
                    !empty($this->endDate),
                    [
                        new DateFormat(),
                        'after_or_equal:startDate',
                    ]
                ),
            ],
            'divisionId' => [
                Rule::requiredIf(function () {
                    $pharmacyTypes = config('ehealth.pharmacy_employee_types', []);

                    return in_array($this->employeeType, $pharmacyTypes, true);
                }),
                'nullable',
                'string',
                Rule::exists('divisions', 'id')->where('legal_entity_id', legalEntity()->id)
            ],
        ];
    }

    /**
     * Custom attributes for validation errors.
     */
    public function validationAttributes(): array
    {
        return [
            'form.position' => __('forms.position'),
            'form.employeeType' => __('employees.employee_type'),
            'form.startDate' => __('forms.start_date'),
            'form.endDate' => __('forms.end_date'),
            'form.divisionId' => __('forms.division'),
            'form.party.lastName' => __('forms.party.lastName'),
            'form.party.firstName' => __('forms.party.firstName'),
            'form.party.secondName' => __('forms.party.secondName'),
            'form.party.gender' => __('forms.party.gender'),
            'form.party.birthDate' => __('forms.party.birthDate'),
            'form.party.taxId' => __('forms.party.taxId'),
            'form.party.noTaxId' => __('forms.party.noTaxId'),
            'form.party.email' => __('forms.party.email'),
            'form.party.workingExperience' => __('forms.working_experience'),
            'form.party.aboutMyself' => __('forms.about_myself'),
            'form.party.phones.*.number' => __('forms.phone_number'),
            'form.party.phones.*.type' => __('forms.phone_type'),
            'form.documents.*.number' => __('forms.document_number'),
            'form.documents.*.type' => __('forms.document_type'),
            'form.documents.*.issuedAt' => __('forms.document_issued_at'),
            'form.documents.*.expirationDate' => __('forms.document_expiration_date'),
            'form.documents.*.issuedBy' => __('forms.document_issued_by'),
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'party.workingExperience.required' => __('validation.custom.party.working_experience_required'),
            'party.workingExperience.integer' => __('validation.custom.party.working_experience_integer'),
            'party.workingExperience.gt' => __('validation.custom.party.working_experience_gt'),
        ];
    }

    protected function partyRules(): array
    {
        return [
            'party.lastName' => ['required', new Name()],
            'party.firstName' => ['required', new Name()],
            'party.secondName' => ['nullable', 'present', new Name()],
            'party.gender' => ['required', 'string', Rule::in(array_keys($this->component->dictionaries['GENDER'] ?? []))],
            'party.birthDate' => ['required', new DateFormat(), new BirthDate()],
            'party.phones' => ['required', 'array', 'min:1', new PhoneDuplicates()],
            'party.phones.*.number' => ['required', new PhoneNumber()],
            'party.phones.*.type' => ['required', 'string', Rule::in(array_keys($this->component->dictionaries['PHONE_TYPE'] ?? []))],
            'party.taxId' => ['required', 'string', new TaxId()],
            'party.noTaxId' => ['boolean'],
            'party.email' => ['required', 'present', 'email', new UniqueEmailInLegalEntity($this->existingPartyId)],
            'party.workingExperience' => ['required', 'integer', 'gt:0'],
            'party.aboutMyself' => ['nullable', 'present', 'string'],
        ];
    }

    protected function documentsRules(): array
    {
        $allowedDocumentTypes = config('ehealth.employee_identity_document_types', []);

        return [
            'documents' => [
                'required',
                'array',
                'min:1',
                new UniquePassportRule(),
                new HasIdentityDocumentRule($allowedDocumentTypes),
            ],

            'documents.*.type' => ['required', 'string', Rule::in($allowedDocumentTypes)],
            'documents.*.number' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    $index = explode('.', $attribute)[1];
                    $documentType = $this->documents[$index]['type'] ?? null;
                    if ($documentType) {
                        $validator = validator(
                            ['number' => $value],
                            ['number' => [new DocumentNumber($documentType)]]
                        );
                        if ($validator->fails()) {
                            foreach ($validator->errors()->all() as $error) {
                                $fail($error);
                            }
                        }
                    }
                }
            ],
            'documents.*.issuedBy' => ['present', 'nullable', 'string'],
            'documents.*.issuedAt' => ['required', new DateFormat(), 'before_or_equal:today'],
        ];
    }

    /**
     * Defines validation rules for doctor-related data.
     *
     * @return array
     */
    protected function doctorRules(): array
    {
        $medTypes = config('ehealth.medical_employees');
        $isMedicalType = in_array($this->employeeType, $medTypes, true);

        $educationRules = $specialitiesRules = ['nullable', 'array'];

        if ($isMedicalType) {
            $educationRules[] = $specialitiesRules[] = 'required';
            $educationRules[] = $specialitiesRules[] = 'min:1';
        }

        return [
            'doctor.educations' => $educationRules,
            'doctor.educations.*.country' => ['required', 'string', 'max:255'],
            'doctor.educations.*.city' => ['required', 'string', 'max:255', new Cyrillic()],
            'doctor.educations.*.institutionName' => ['required', 'string', 'max:255', new Cyrillic()],
            'doctor.educations.*.issuedDate' => ['nullable', 'date'],
            'doctor.educations.*.diplomaNumber' => ['required', 'string', 'max:255'],
            'doctor.educations.*.degree' => ['required', 'string', 'max:255'],
            'doctor.educations.*.speciality' => ['required', 'string', 'max:255'],

            'doctor.specialities' => $specialitiesRules,
            'doctor.specialities.*.speciality' => ['required', 'string', 'max:255'],
            'doctor.specialities.*.specialityOfficio' => ['required', 'boolean'],
            'doctor.specialities.*.level' => ['required', 'string', 'max:255'],
            'doctor.specialities.*.qualificationType' => ['required', 'string'],
            'doctor.specialities.*.attestationName' => ['required', 'string', 'max:255'],
            'doctor.specialities.*.attestationDate' => ['required', 'date'],
            'doctor.specialities.*.validToDate' => ['nullable', 'date'],
            'doctor.specialities.*.certificateNumber' => ['required', 'string', 'max:255'],

            'doctor.scienceDegree' => ['nullable', 'array'],
            'doctor.scienceDegree.country' => [Rule::requiredIf(fn () => !empty($this->doctor['scienceDegree'])), 'string', 'max:255'],
            'doctor.scienceDegree.city' => [Rule::requiredIf(fn () => !empty($this->doctor['scienceDegree'])), 'string', 'max:255'],
            'doctor.scienceDegree.degree' => [Rule::requiredIf(fn () => !empty($this->doctor['scienceDegree'])), 'string', 'max:255'],
            'doctor.scienceDegree.institutionName' => [Rule::requiredIf(fn () => !empty($this->doctor['scienceDegree'])), 'string', 'max:255'],
            'doctor.scienceDegree.diplomaNumber' => [Rule::requiredIf(fn () => !empty($this->doctor['scienceDegree'])), 'string', 'max:255'],
            'doctor.scienceDegree.speciality' => [Rule::requiredIf(fn () => !empty($this->doctor['scienceDegree'])), 'string', 'max:255'],
            'doctor.scienceDegree.issuedDate' => ['nullable', 'date'],

            'doctor.qualifications' => ['nullable', 'array'],
            'doctor.qualifications.*.type' => ['required', 'string', 'max:255'],
            'doctor.qualifications.*.institutionName' => ['required', 'string', 'max:255'],
            'doctor.qualifications.*.speciality' => ['required', 'string', 'max:255'],
            'doctor.qualifications.*.issuedDate' => ['required', 'date'],
            'doctor.qualifications.*.certificateNumber' => ['required', 'string', 'max:255'],
            'doctor.qualifications.*.validTo' => ['nullable', 'date', 'after_or_equal:doctor.qualifications.*.issuedDate'],
            'doctor.qualifications.*.additionalInfo' => ['nullable', 'string', new Cyrillic()],
        ];
    }

    /**
     * The single "smart" method to populate the form from any data source.
     */
    public function hydrate(BaseEmployee|Party|null $source = null): void
    {
        $this->reset();

        if ($source === null) {
            return;
        }

        match (get_class($source)) {
            Employee::class => $this->hydrateFromEmployee($source),
            EmployeeRequest::class => $this->hydrateFromEmployeeRequest($source),
            Party::class => $this->hydrateFromParty($source),
        };
    }

    /**
     * Resets only the fields related to a specific position/employment.
     * This is called in the 'Add Position' component.
     */
    public function resetPositionFields(): void
    {
        $this->position = '';
        $this->employeeType = '';
        $this->startDate = '';
        $this->endDate = null;
        $this->divisionId = null;
    }

    /**
     * This eliminates all code duplication.
     */
    private function populatePartyData(Party $party): void
    {
        $party->loadMissing(['phones', 'documents']);
        $this->existingPartyId = $party->id;

        $this->party['lastName'] = $party->lastName;
        $this->party['firstName'] = $party->firstName;
        $this->party['secondName'] = $party->secondName;
        $this->party['gender'] = $party->gender;
        $this->party['birthDate'] = convertToAppDateFormat($party->birthDate);
        $this->party['taxId'] = $party->taxId;
        $this->party['noTaxId'] = (bool)$party->noTaxId;
        $user = $party->users->first();
        $this->party['email'] = $user ? $user->email : null;
        $this->party['workingExperience'] = $party->workingExperience;
        $this->party['aboutMyself'] = $party->aboutMyself;

        $phones = $party->phones;
        // Only overwrite phones if the form is empty
        if ($phones->isNotEmpty() && empty($this->party['phones'][0]['number'])) {
            $this->party['phones'] = $phones->map(fn ($p) => ['type' => $p->type, 'number' => $p->number])->toArray();
        }

        $documents = $party->documents;
        // Only overwrite documents if the form is empty
        if ($documents->isNotEmpty() && empty($this->documents)) {
            $this->documents = $documents->map(function ($doc) {
                return [
                    'type' => $doc->type,
                    'number' => $doc->number,
                    'issuedBy' => $doc->issuedBy,
                    'issuedAt' => $doc->issuedAt
                ];
            })->toArray();
        }
    }

    private function hydrateFromEmployee(Employee $employee): void
    {
        $employee->loadMissing(['party.phones', 'party.documents', 'educations', 'specialities', 'qualifications', 'scienceDegree']);
        if ($employee->party) {
            $this->populatePartyData($employee->party);
        }
        $this->position = $employee->position;
        $this->employeeType = $employee->employeeType;
        $this->startDate = convertToAppDateFormat($employee->startDate);
        $this->endDate = convertToAppDateFormat($employee->endDate);
        $this->divisionId = $employee->divisionId !== null ? (string) $employee->divisionId : null;

        $this->doctor['educations'] = $employee->educations->map(function ($edu) {
            $data = Arr::toCamelCase($edu->toArray());
            $data['issuedDate'] = convertToAppDateFormat($edu->issuedDate) ?: null;

            return $data;
        })->toArray();

        $this->doctor['specialities'] = $employee->specialities->map(function ($spec) {
            $data = Arr::toCamelCase($spec->toArray());
            $data['attestationDate'] = convertToAppDateFormat($spec->attestationDate) ?: null;
            $data['validToDate'] = convertToAppDateFormat($spec->validToDate) ?: null;

            return $data;
        })->toArray();

        $this->doctor['qualifications'] = $employee->qualifications->map(function ($qual) {
            $data = Arr::toCamelCase($qual->toArray());
            $data['issuedDate'] = convertToAppDateFormat($qual->issuedDate) ?: null;
            $data['validTo'] = convertToAppDateFormat($qual->validTo) ?: null;

            return $data;
        })->toArray();

        $scienceDegreeData = $employee->scienceDegree?->toArray() ?? [];
        if (!empty($scienceDegreeData)) {
            $this->doctor['scienceDegree'] = Arr::toCamelCase($scienceDegreeData);
            if (isset($employee->scienceDegree->issuedDate)) {
                $this->doctor['scienceDegree']['issuedDate'] = convertToAppDateFormat($employee->scienceDegree->issuedDate);
            }
        }
    }

    /**
     * Hydrates the form from an EmployeeRequest model.
     * Merges original Employee data first, then applies Revision overrides.
     */
    private function hydrateFromEmployeeRequest(EmployeeRequest $request): void
    {
        $request->loadMissing(['party', 'revision', 'employee']); // Load original employee too
        $revisionData = $request->revision->data ?? [];

        // 1. BASE: Hydrate from original Employee if exists
        if ($request->employee) {
            $this->hydrateFromEmployee($request->employee);
        } elseif ($request->party) {
            $this->populatePartyData($request->party);
        }

        // 2. OVERRIDE: Apply Revision data (Draft changes)

        // --- position data ---
        if (!empty($revisionData['employee_request_data'])) {
            $reqData = $revisionData['employee_request_data'];

            if (isset($reqData['position'])) {
                $this->position = $reqData['position'];
            }

            if (isset($reqData['employee_type'])) {
                $this->employeeType = $reqData['employee_type'];
            }

            if (isset($reqData['start_date'])) {
                $this->startDate = convertToAppDateFormat($reqData['start_date']);
            }

            if (isset($reqData['end_date'])) {
                $this->endDate = convertToAppDateFormat($reqData['end_date']);
            }

            if (array_key_exists('division_id', $reqData)) {
                $this->divisionId = $reqData['division_id'] !== null
                    ? (string) $reqData['division_id']
                    : null;
            }
        }

        // --- documents ---
        if (!empty($revisionData['documents'])) {
            $docs = Arr::toCamelCase($revisionData['documents']);

            foreach ($docs as &$doc) {
                if (isset($doc['issuedAt'])) {
                    $doc['issuedAt'] = convertToAppDateFormat($doc['issuedAt']);
                }
            }
            $this->documents = $docs;
        }

        // --- doctor(specialist) data ---
        if (!empty($revisionData['doctor'])) {
            $doctorDataFromRevision = Arr::toCamelCase($revisionData['doctor']);

            // Educations
            if (!empty($doctorDataFromRevision['educations'])) {
                foreach ($doctorDataFromRevision['educations'] as &$val) {
                    if (isset($val['issuedDate'])) {
                        $val['issuedDate'] = convertToAppDateFormat($val['issuedDate']);
                    }
                }
            }
            // Specialities
            if (!empty($doctorDataFromRevision['specialities'])) {
                foreach ($doctorDataFromRevision['specialities'] as &$val) {
                    if (isset($val['attestationDate'])) {
                        $val['attestationDate'] = convertToAppDateFormat($val['attestationDate']);
                    }
                    if (isset($val['validToDate'])) {
                        $val['validToDate'] = convertToAppDateFormat($val['validToDate']);
                    }
                }
            }
            // Qualifications
            if (!empty($doctorDataFromRevision['qualifications'])) {
                foreach ($doctorDataFromRevision['qualifications'] as &$val) {
                    if (isset($val['issuedDate'])) {
                        $val['issuedDate'] = convertToAppDateFormat($val['issuedDate']);
                    }
                    if (isset($val['validTo'])) {
                        $val['validTo'] = convertToAppDateFormat($val['validTo']);
                    }
                }
            }
            // Science Degree
            if (!empty($doctorDataFromRevision['scienceDegree'])) {
                if (isset($doctorDataFromRevision['scienceDegree']['issuedDate'])) {
                    $doctorDataFromRevision['scienceDegree']['issuedDate'] = convertToAppDateFormat($doctorDataFromRevision['scienceDegree']['issuedDate']);
                }
            }

            // Merge recursive to override specific fields
            $this->doctor = array_replace_recursive($this->doctor, $doctorDataFromRevision);
        }

        // --- Personal data (Party) ---
        if (!empty($revisionData['party'])) {
            $partyDataFromRevision = Arr::toCamelCase($revisionData['party']);

            if (isset($partyDataFromRevision['birthDate'])) {
                $partyDataFromRevision['birthDate'] = convertToAppDateFormat($partyDataFromRevision['birthDate']);
            }

            $this->party = array_merge($this->party, $partyDataFromRevision);
        }

        // --- Phones ---
        if (!empty($revisionData['phones'])) {
            $this->party['phones'] = Arr::toCamelCase($revisionData['phones']);
        }
    }

    /**
     * Hydrates the form from a Party model (for "Add Position").
     */
    private function hydrateFromParty(Party $party): void
    {
        $this->populatePartyData($party);

        $needsRevisionCheck = empty($this->documents);
        if ($needsRevisionCheck) {
            $latestRequest = $party->employeeRequests()->with('revision')->latest()->first();
            if ($latestRequest && $latestRequest->revision) {
                $revisionData = $latestRequest->revision->data;

                if (empty($this->documents) && !empty($revisionData['documents'])) {
                    $docs = Arr::toCamelCase($revisionData['documents']);

                    foreach ($docs as &$doc) {
                        if (isset($doc['issuedAt'])) {
                            $doc['issuedAt'] = convertToAppDateFormat($doc['issuedAt']);
                        }
                    }
                    $this->documents = $docs;
                }

                if (empty($this->party['phones'][0]['number']) && !empty($revisionData['phones'])) {
                    $this->party['phones'] = Arr::toCamelCase($revisionData['phones']);
                }
            }
        }
    }

    /**
     * Prepares and returns a FLAT array of all form data for the repository.
     */
    public function getPreparedData(): array
    {
        $formData = $this->all();

        // 1. Create a local formatter that does not touch the global helper
        // It converts the date to the format 'YYYY-MM-DD' (without time T00:00:00Z)
        $toApiDate = static function ($value) {
            if (empty($value)) {
                return null;
            }
            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        };

        // --- 1. Root fields ---
        $formData['startDate'] = $toApiDate($formData['startDate'] ?? null);
        $formData['endDate'] = $toApiDate($formData['endDate'] ?? null);
        if (($formData['divisionId'] ?? null) === '') {
            $formData['divisionId'] = null;
        }

        // --- 2. Identity (Party) ---
        if (isset($formData['party']['birthDate'])) {
            $formData['party']['birthDate'] = $toApiDate($formData['party']['birthDate']);
        }

        // --- 3. Documents ---
        if (!empty($formData['documents'])) {
            foreach ($formData['documents'] as $key => $doc) {
                if (isset($doc['issuedAt'])) {
                    $formData['documents'][$key]['issuedAt'] = $toApiDate($doc['issuedAt']);
                }
            }
        }

        // --- 4. Doctor Data ---

        // Educations
        if (!empty($formData['doctor']['educations'])) {
            foreach ($formData['doctor']['educations'] as $key => $edu) {
                $formData['doctor']['educations'][$key]['issuedDate'] = $toApiDate($edu['issuedDate'] ?? null);
            }
        }

        // Qualifications
        if (!empty($formData['doctor']['qualifications'])) {
            foreach ($formData['doctor']['qualifications'] as $key => $qual) {
                $formData['doctor']['qualifications'][$key]['issuedDate'] = $toApiDate($qual['issuedDate'] ?? null);
                $formData['doctor']['qualifications'][$key]['validTo'] = $toApiDate($qual['validTo'] ?? null);
            }
        }

        // Science Degree
        if (!empty($formData['doctor']['scienceDegree']['issuedDate'])) {
            $formData['doctor']['scienceDegree']['issuedDate'] = $toApiDate($formData['doctor']['scienceDegree']['issuedDate']);
        }

        // Specialities
        if (!empty($formData['doctor']['specialities'])) {
            foreach ($formData['doctor']['specialities'] as $key => $spec) {
                $formData['doctor']['specialities'][$key]['attestationDate'] = $toApiDate($spec['attestationDate'] ?? null);
                $formData['doctor']['specialities'][$key]['validToDate'] = $toApiDate($spec['validToDate'] ?? null);
            }
        }

        // --- 5. Formatting & Cleanup ---
        $partyData = $formData['party'] ?? [];
        unset($formData['party']);
        $formData = array_merge($formData, $partyData);

        unset(
            $formData['existingPartyId'],
            $formData['knedp'],
            $formData['keyContainerUpload'],
            $formData['password']
        );

        return Arr::toSnakeCase($formData);
    }

    /**
     * Resets the form to its default state.
     */
    public function reset(...$properties): void
    {
        parent::reset(...$properties);
    }
}
