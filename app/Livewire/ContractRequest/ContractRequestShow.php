<?php

declare(strict_types=1);

namespace App\Livewire\ContractRequest;

use App\Classes\eHealth\EHealth;
use App\Models\Contracts\ContractRequest;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ContractRequestShow extends Component
{
    public ContractRequest $contractRequest;
    public array $contractData = [];

    public function mount(ContractRequest $contract): void
    {
        $this->contractRequest = $contract;

        // Sync details with eHealth if we have a UUID and it's not a local draft
        if ($this->contractRequest->uuid && $this->contractRequest->type) {
            $this->syncDetailsFromEHealth();
        }

        // We use camelCase for variables as requested
        $this->contractData = $this->contractRequest->data ?? [];
    }

    /**
     * Fetch full details from eHealth and update local DB
     */
    private function syncDetailsFromEHealth(): void
    {
        try {
            // Note: type must be lowercase for the URL path (capitation/reimbursement)
            $contractType = strtolower($this->contractRequest->type);

            // Token is injected automatically inside EHealthRequest
            $response = EHealth::contractRequest()->getDetails($contractType, $this->contractRequest->uuid);

            $ehealthData = $response->getData();

            if (!empty($ehealthData)) {
                // Update local DB with rich nested data
                $this->contractRequest->update([
                    'contractor_base' => $ehealthData['contractor_base'] ?? $this->contractRequest->contractor_base,
                    'contractor_payment_details' => $ehealthData['contractor_payment_details'] ?? null,
                    'contractor_divisions' => $ehealthData['contractor_divisions'] ?? null,
                    'external_contractors' => $ehealthData['external_contractors'] ?? null,
                    'nhs_signer_id' => $ehealthData['nhs_signer']['id'] ?? null,
                    'nhs_signer_base' => $ehealthData['nhs_signer_base'] ?? null,
                    'nhs_contract_price' => $ehealthData['nhs_contract_price'] ?? null,
                    'nhs_payment_method' => $ehealthData['nhs_payment_method'] ?? null,
                    'status' => $ehealthData['status'] ?? $this->contractRequest->status,
                    'status_reason' => $ehealthData['status_reason'] ?? null,
                    'data' => $ehealthData, // Store the entire raw response for UI rendering
                ]);
            }
        } catch (\Exception $exception) {
            Log::warning('Failed to fetch Contract Request details: ' . $exception->getMessage());
            // We do not abort, just show what we already have in the DB
        }
    }

    public function render()
    {
        return view('livewire.contract-request.contract-request-show');
    }
}
