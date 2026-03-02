<?php

declare(strict_types=1);

namespace App\Livewire\Contract\Forms;

use App\Models\Contracts\ContractRequest;
use App\Rules\InDictionary;
use Carbon\CarbonImmutable;

class ReimbursementContractRequestForm extends BaseContractRequestForm
{
    protected const int REIMBURSEMENT_CONTRACT_MAX_PERIOD_DAY = 1096;

    public ?string $previousRequestId = null;

    public ?array $medicalPrograms;

    public bool $consentText;

    public function rules(): array
    {
        $parentRules = parent::rules();

        $parentRules['endDate'][] = function ($attribute, $value, $fail) {
            $startDate = CarbonImmutable::parse($this->startDate);
            $endDate = CarbonImmutable::parse($value);

            if ($startDate->diffInDays($endDate) > self::REIMBURSEMENT_CONTRACT_MAX_PERIOD_DAY) {
                $fail(
                    'різниця між датою закінчення договору та датою початку договору '
                    . 'не повинна перевищувати ' . self::REIMBURSEMENT_CONTRACT_MAX_PERIOD_DAY . ' днів'
                );
            }
        };

        return array_merge($parentRules, [
            'idForm' => ['required', new InDictionary('REIMBURSEMENT_CONTRACT_TYPE')],

            'previousRequestId' => ['nullable', 'uuid', 'exists:contracts,uuid'],

            'medicalPrograms' => ['nullable', 'array'],
            'consentText' => ['accepted'],
        ]);
    }

    /**
     * Fill the form properties from the existing ContractRequest model.
     */
    public function hydrate(ContractRequest $request): void
    {
        // 1. Base fields
        $this->contractorLegalEntityId = $request->contractor_legal_entity_id;
        $this->contractorOwnerId = $request->contractor_owner_id;
        $this->contractorBase = $request->contractor_base ?? '';
        $this->contractNumber = $request->contract_number ?? '';
        $this->idForm = $request->id_form ?? 'GENERAL';

        // 2.Dates (Carbon -> d.m.Y string conversion)
        // We use optional() or check, because dates can be null in drafts
        $this->startDate = $request->start_date ? $request->start_date->format('d.m.Y') : '';
        $this->endDate = $request->end_date ? $request->end_date->format('d.m.Y') : '';

        // 3. Payment details (Mapping from snake_case array in camelCase)
        $paymentDetails = $request->contractor_payment_details ?? [];
        $this->contractorPaymentDetails = [
            'payerAccount' => $paymentDetails['payer_account'] ?? '',
            'bankName' => $paymentDetails['bank_name'] ?? '',
            'MFO' => $paymentDetails['MFO'] ?? '',
        ];

        // 4. Medical applications (UUID array)
        $this->medicalPrograms = $request->medical_programs ?? [];

        // 5.Pre-Enquiry
        $this->previousRequestId = $request->previous_request_id;

        // 6.Consent (if the record exists, we assume that there was consent)
        $this->consentText = true;
    }
}
