<?php

declare(strict_types=1);

namespace App\Livewire\Contract;

use App\Classes\eHealth\EHealth;
use App\Models\Contracts\Contract;
use App\Models\LegalEntity;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ContractShow extends Component
{
    public Contract $contract;

    public array $data = [];

    public array $medicalProgramNames = [];

    public function mount(LegalEntity $legalEntity, Contract $contract): void
    {
        $this->contract = $contract;

        if ($this->contract->uuid) {
            $this->syncDetailsFromEHealth();
        }

        $this->data = $this->normalizeContractData($this->contract->data ?? []);
        $this->medicalProgramNames = $this->resolveMedicalProgramNames();
    }

    private function syncDetailsFromEHealth(): void
    {
        try {
            $response = EHealth::contract()->getDetails($this->contract->uuid);

            $ehealthData = $response->getData();

            if (!empty($ehealthData)) {
                $this->contract->update([
                    'contractor_base' => $ehealthData['contractor_base'] ?? $this->contract->contractor_base,
                    'contractor_payment_details' => $ehealthData['contractor_payment_details'] ?? null,
                    'contractor_divisions' => $ehealthData['contractor_divisions'] ?? null,
                    'external_contractors' => $ehealthData['external_contractors'] ?? null,
                    'external_contractor_flag' => $ehealthData['external_contractor_flag'] ?? $this->contract->external_contractor_flag,
                    'nhs_signer_id' => $ehealthData['nhs_signer']['id'] ?? null,
                    'nhs_signer_base' => $ehealthData['nhs_signer_base'] ?? null,
                    'nhs_contract_price' => $ehealthData['nhs_contract_price'] ?? null,
                    'nhs_payment_method' => $ehealthData['nhs_payment_method'] ?? null,
                    'medical_programs' => $ehealthData['medical_programs'] ?? $this->contract->medical_programs,
                    'data' => $ehealthData,
                ]);

                $this->contract->refresh();
            }
        } catch (\Exception $exception) {
            Log::warning('Failed to fetch Contract details: '.$exception->getMessage());
        }
    }

    /**
     * @return array<string, string>
     */
    private function resolveMedicalProgramNames(): array
    {
        try {
            return dictionary()->medicalPrograms()
                ->pluck('name', 'id')
                ->all();
        } catch (\Throwable $exception) {
            Log::warning('Failed to load medical program dictionary: '.$exception->getMessage());

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeContractData(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function render(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\View\View
    {
        return view('livewire.contract.contract-show', [
            'contract' => $this->contract,
            'data' => $this->data,
            'medicalProgramNames' => $this->medicalProgramNames,
        ]);
    }
}
