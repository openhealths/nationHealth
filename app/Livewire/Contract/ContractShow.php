<?php

declare(strict_types=1);

namespace App\Livewire\Contract;

use App\Classes\eHealth\Api\Contract as ApiContract;
use App\Models\Contracts\Contract;
use App\Models\LegalEntity;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ContractShow extends Component
{
    public Contract $contract;
    public array $data = [];

    //Laravel will automatically "inject" the already found Contract model
    public function mount(LegalEntity $legalEntity, Contract $contract): void
    {
        $this->contract = $contract;

        if ($this->contract->uuid) {
            $this->syncDetailsFromEHealth();
        }

        $this->data = $this->contract->data ?? [];
    }

    private function syncDetailsFromEHealth(): void
    {
        try {
            $apiClient = app(ApiContract::class);

            $response = $apiClient
                ->withToken(session()->get(config('ehealth.api.oauth.bearer_token')))
                ->getDetails($this->contract->uuid);

            $ehealthData = $response->getData();

            if (!empty($ehealthData)) {
                $this->contract->update([
                    'contractor_base' => $ehealthData['contractor_base'] ?? $this->contract->contractor_base,
                    'contractor_payment_details' => $ehealthData['contractor_payment_details'] ?? null,
                    'contractor_divisions' => $ehealthData['contractor_divisions'] ?? null,
                    'external_contractors' => $ehealthData['external_contractors'] ?? null,
                    'nhs_signer_id' => $ehealthData['nhs_signer']['id'] ?? null,
                    'nhs_signer_base' => $ehealthData['nhs_signer_base'] ?? null,
                    'nhs_contract_price' => $ehealthData['nhs_contract_price'] ?? null,
                    'nhs_payment_method' => $ehealthData['nhs_payment_method'] ?? null,
                    'data' => $ehealthData,
                ]);
            }
        } catch (\Exception $exception) {
            Log::warning('Failed to fetch Contract details: ' . $exception->getMessage());
        }
    }

    public function render(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\View\View
    {
        return view('livewire.contract.contract-show', [
            'contract' => $this->contract,
            'data' => $this->data,
        ]);
    }
}
