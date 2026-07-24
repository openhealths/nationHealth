<?php

declare(strict_types=1);

namespace App\Livewire\Contract\Forms;

use App\Enums\Contract\IdForm;
use App\Models\Contracts\ContractRequest;
use App\Rules\ContractRules\ValidReimbursementPeriod;
use App\Rules\InDictionary;

class ReimbursementContractRequestForm extends BaseContractRequestForm
{
    protected const int REIMBURSEMENT_CONTRACT_MAX_PERIOD_DAY = 1096;

    public ?string $previousRequestId = null;

    public ?array $medicalPrograms;

    public bool $consentText;

    public function rules(): array
    {
        $parentRules = parent::rules();

        return array_merge($parentRules, [
            'idForm' => ['required', new InDictionary('REIMBURSEMENT_CONTRACT_TYPE')],

            'previousRequestId' => ['nullable', 'uuid', 'exists:contracts,uuid'],

            'medicalPrograms' => ['nullable', 'array'],
            'consentText' => ['accepted'],
        ]);
    }

    /**
     * Get validation rules for the end date.
     *
     * @return array
     */
    protected function getEndDateRules(): array
    {
        return [
            'required',
            'date_format:' . config('app.date_format'),
            'after_or_equal:startDate',
            new ValidReimbursementPeriod(
                $this->startDate,
                $this->previousRequestId,
                config('app.date_format')
            ),
        ];
    }

    /**
     * Fill the form properties from the existing ContractRequest model.
     */
    public function hydrate(ContractRequest $request): void
    {
        // 1. Base fields
        $this->contractorLegalEntityId = $request->contractorLegalEntityId;
        $this->contractorOwnerId = $request->contractorOwnerId;
        $this->contractorBase = $request->contractorBase ?? '';
        $this->contractNumber = $request->contractNumber ?? '';
        // id_form is a CONTRACT_TYPE / REIMBURSEMENT_CONTRACT_TYPE dictionary code (e.g. GENERAL), never a UUID.
        $this->idForm = $request->idForm
            ?? data_get($request->data, 'id_form')
            ?? IdForm::GENERAL->value;

        // 2.Dates (Carbon -> d.m.Y string conversion)
        // We use optional() or check, because dates can be null in drafts
        $this->startDate = $request->startDate ? $request->startDate->format('d.m.Y') : '';
        $this->endDate = $request->endDate ? $request->endDate->format('d.m.Y') : '';

        // 3. Payment details (Mapping from snake_case array in camelCase)
        $paymentDetails = $request->contractorPaymentDetails ?? [];
        $this->contractorPaymentDetails = [
            'payerAccount' => $paymentDetails['payer_account'] ?? '',
            'bankName' => $paymentDetails['bank_name'] ?? '',
            'MFO' => $paymentDetails['MFO'] ?? $paymentDetails['mfo'] ?? '',
        ];

        // 4. Medical applications (UUID array)
        $this->medicalPrograms = $request->medicalPrograms ?? [];

        // 5.Pre-Enquiry
        $this->previousRequestId = $request->previousRequestId;

        // 6.Consent (if the record exists, we assume that there was consent)
        $this->consentText = true;
    }
}
