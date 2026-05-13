<?php

declare(strict_types=1);

namespace App\Livewire\Procedure\Forms;

use App\Rules\InDictionary;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

class ProcedureForm extends Form
{
    public array $procedures;

    public string $knedp;

    public TemporaryUploadedFile $keyContainerUpload;

    public string $password;

    protected function rules(): array
    {
        return [
            'procedures.referralType' => ['required', 'string'],
            'procedures.primarySource' => ['required', 'boolean'],
            'procedures.paperReferral' => ['required_if:procedures.referralType,paper', 'array'],
            'procedures.paperReferral.requisition' => ['nullable', 'string', 'max:255'],
            'procedures.paperReferral.requesterEmployeeName' => ['nullable', 'string', 'max:255'],
            'procedures.paperReferral.requesterLegalEntityEdrpou' => [
                Rule::requiredIf(data_get($this->procedures, 'referralType') === 'paper'),
                'regex:/^[0-9]{8,10}$/',
                'string',
                'max:255'
            ],
            'procedures.paperReferral.requesterLegalEntityName' => [
                Rule::requiredIf(data_get($this->procedures, 'referralType') === 'paper'),
                'string',
                'max:255'
            ],
            'procedures.paperReferral.serviceRequestDate' => [
                Rule::requiredIf(data_get($this->procedures, 'referralType') === 'paper'),
                'date'
            ],
            'procedures.paperReferral.note' => ['nullable', 'string', 'max:255'],
            'procedures.category.coding.*.system' => ['required', 'string'],
            'procedures.category.coding.*.code' => [
                'required',
                'string',
                new InDictionary('eHealth/procedure_categories')
            ],
            'procedures.code' => ['required', 'array'],
            'procedures.code.identifier.value' => ['required', 'uuid', 'max:255'],
            'procedures.code.identifier.type.text' => ['nullable', 'string', 'max:255'],
            'procedures.code.identifier.type.coding.*.system' => ['required', 'string', 'max:255'],
            'procedures.code.identifier.type.coding.*.code' => [
                'required',
                'string',
                new InDictionary('eHealth/resources')
            ],
            'procedures.division' => ['nullable', 'array'],
            'procedures.division.identifier.value' => ['required', 'uuid'],
            'procedures.division.identifier.type.coding.*.system' => ['required', 'string', 'max:255'],
            'procedures.division.identifier.type.coding.*.code' => [
                'required',
                'string',
                new InDictionary('eHealth/resources')
            ],
            'procedures.outcome.coding.*.code' => [
                'nullable',
                'string',
                new InDictionary('eHealth/procedure_outcomes')
            ],
            'procedures.recordedBy' => ['required', 'array'],
            'procedures.performer' => ['array', 'required'],
            'procedures.performedPeriodStartTime' => ['required', 'date_format:H:i'],
            'procedures.performedPeriodStartDate' => ['required', 'date', 'before_or_equal:now'],
            'procedures.performedPeriodEndTime' => [
                'required',
                'date_format:H:i',
                'after:procedures.performedPeriodStartTime'
            ],
            'procedures.performedPeriodEndDate' => [
                'required',
                'date',
                'before_or_equal:now',
                'after_or_equal:procedures.performedPeriodStartDate'
            ],
            'procedures.note' => ['nullable', 'string', 'max:255'],
            'procedures.reasonReferences' => ['array', 'nullable'],
            'procedures.reasonReferences.*.id' => ['required', 'uuid'],
            'procedures.reasonReferences.*.code.coding.*.code' => [
                'required',
                'string',
                new InDictionary('eHealth/ICPC2/condition_codes')
            ],
            'procedures.reasonReferences.*.code.coding.*.system' => ['required', 'string'],
            'procedures.usedCodes' => ['nullable', 'array'],
            'procedures.usedCodes.*.code' => [
                'required',
                'string',
                new InDictionary('eHealth/assistive_products')
            ]
        ];
    }

    /**
     * List of rules for signing Cipher form.
     *
     * @return array[]
     */
    public function rulesForSigning(): array
    {
        return [
            'knedp' => ['required', 'string'],
            'password' => ['required', 'string'],
            'keyContainerUpload' => ['required', 'file', 'extensions:dat,pfx,pk8,zs2,jks,p7s']
        ];
    }
}
