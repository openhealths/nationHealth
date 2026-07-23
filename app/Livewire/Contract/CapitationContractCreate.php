<?php

declare(strict_types=1);

namespace App\Livewire\Contract;

use App\Enums\Status;
use App\Livewire\Contract\Forms\CapitationContractRequestForm as Form;
use App\Models\LegalEntity;
use Carbon\Carbon;
use Illuminate\View\View;

class CapitationContractCreate extends ContractComponent
{
    public Form $form;

    public array $legalEntities;

    /**
     * List of related divisions
     *
     * @var array
     */
    public array $divisions;

    protected array $dictionaryNames = [
        'CONTRACT_TYPE',
        'CAPITATION_CONTRACT_CONSENT_TEXT',
        'MEDICAL_SERVICE',
    ];

    public function mount(LegalEntity $legalEntity): void
    {
        $this->baseMount($legalEntity);

        $this->legalEntities = LegalEntity::get(['id', 'edr'])->toArray();

        $this->divisions = $legalEntity->divisions->where('status', Status::ACTIVE)->toArray();
    }

    protected function getContractType(): string
    {
        return 'capitation';
    }

    /**
     * Maps form data to the Capitation eHealth payload.
     */
    protected function collectPayload(array $validatedData): array
    {

        $payload = [
            'contractor_owner_id' => $this->form->contractorOwnerId,
            'contractor_base' => $validatedData['contractorBase'],
            'contractor_payment_details' => [
                'bank_name' => $validatedData['bankName'] ?? '',
                'mfo' => $validatedData['mfo'] ?? '',
                'payer_account' => $validatedData['payerAccount'] ?? '',
            ],
            // Capitation specific: Divisions are required
            'contractor_divisions' => array_filter($validatedData['contractorDivisions'] ?? []),
            'start_date' => Carbon::parse($validatedData['startDate'])->format('Y-m-d'),
            'end_date' => Carbon::parse($validatedData['endDate'])->format('Y-m-d'),
            'id_form' => $validatedData['idForm'],
            'contract_number' => $validatedData['contractNumber'] ?? null, // Empty for new contracts
            'statute_md5' => $validatedData['statuteMd5'] ?? null,
            'additional_document_md5' => $validatedData['additionalDocumentMd5'] ?? null,
            'consent_text' => $validatedData['consentText'] ?? '',
        ];

        // Handle External Contractors (Specific to Capitation)
        $externalContractors = $this->mapExternalContractors($validatedData);

        $payload['external_contractor_flag'] = !empty($externalContractors);
        if (!empty($externalContractors)) {
            $payload['external_contractors'] = $externalContractors;
        }

        // Add previous_request_id only if it exists (for updates)
        if (!empty($validatedData['previousRequestId'])) {
            $payload['previous_request_id'] = $validatedData['previousRequestId'];
        }

        return $payload;
    }

    /**
     * Helper to map external contractors structure.
     */
    private function mapExternalContractors(array $data): array
    {
        if (empty($data['externalContractors']) || !($data['externalContractorFlag'] ?? false)) {
            return [];
        }

        $mapped = [];
        foreach ($data['externalContractors'] as $item) {
            // Skip empty rows if any
            if (empty($item['legalEntityId'])) continue;

            $mapped[] = [
                'legal_entity_id' => $item['legalEntityId'],
                'contract' => [
                    'number' => $item['contract']['number'],
                    'issued_at' => Carbon::parse($item['contract']['issuedAt'])->format('Y-m-d'),
                    'expires_at' => Carbon::parse($item['contract']['expiresAt'])->format('Y-m-d'),
                ],
                'divisions' => [
                    [
                        'id' => $item['divisions']['id'],
                        'medical_service' => $item['divisions']['medicalService']
                    ]
                ]
            ];
        }
        return $mapped;
    }

    public function render(): View
    {
        return view('livewire.contract.capitation-contract-create');
    }
}
