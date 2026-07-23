<?php

declare(strict_types=1);

namespace App\Livewire\ContractRequest;

use App\Classes\eHealth\EHealth;
use App\Livewire\Contract\ReimbursementContractCreate;
use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;
use Illuminate\Support\Facades\Log;

class ContractRequestEdit extends ReimbursementContractCreate
{
    public ContractRequest $contractRequest;

    public function mount(LegalEntity $legalEntity, ?ContractRequest $contractRequest = null): void
    {
        $resolvedRequest = $contractRequest ?? request()->route('contractRequest');

        if ($resolvedRequest instanceof ContractRequest) {
            $this->contractRequest = $resolvedRequest;
        } else {
            $this->contractRequest = ContractRequest::where('uuid', (string) $resolvedRequest)->firstOrFail();
        }

        // 2.Install savedUuid so that createLocally() knows that this update is
        $this->savedUuid = $this->contractRequest->uuid;

        // 3. Call the parent mount to initialize directories
        parent::mount($legalEntity);

        // DEBUG #522: inspect ESOZ payload when opening contract request for edit
        if ($this->contractRequest->uuid && $this->contractRequest->type) {
            $this->debugEHealthContractRequestDetails();
        }

        // 4. Fill out the form with data from the database
        $this->form->hydrate($this->contractRequest);
    }

    /**
     * Temporary debug helper for #522 — logs and dumps ESOZ getDetails response.
     */
    private function debugEHealthContractRequestDetails(): void
    {
        try {
            $contractType = strtolower((string) $this->contractRequest->type);
            $response = EHealth::contractRequest()->getDetails($contractType, $this->contractRequest->uuid);
            $ehealthData = $response->getData();

            Log::info('ContractRequestEdit ESOZ getDetails', [
                'uuid' => $this->contractRequest->uuid,
                'contract_type' => $contractType,
                'id_form' => $ehealthData['id_form'] ?? null,
                'status_reason' => $ehealthData['status_reason'] ?? null,
                'type' => $ehealthData['type'] ?? $ehealthData['contract_type'] ?? null,
                'keys' => is_array($ehealthData) ? array_keys($ehealthData) : [],
                'payload' => $ehealthData,
            ]);

            dd([
                'source' => 'ContractRequestEdit::debugEHealthContractRequestDetails',
                'uuid' => $this->contractRequest->uuid,
                'contract_type' => $contractType,
                'id_form' => $ehealthData['id_form'] ?? null,
                'status_reason' => $ehealthData['status_reason'] ?? null,
                'ehealth_data' => $ehealthData,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('ContractRequestEdit ESOZ getDetails failed: '.$exception->getMessage(), [
                'uuid' => $this->contractRequest->uuid,
            ]);
        }
    }

    //Override render to use the same template as for creating
    public function render(): \Illuminate\View\View
    {
        return view('livewire.contract.reimbursement-contract-create', [
            'isEdit' => true
        ]);
    }
}
