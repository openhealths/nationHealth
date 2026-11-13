<?php

declare(strict_types=1);

namespace App\Livewire\Contract;

use App\Classes\eHealth\EHealth;
use App\Enums\Contract\IdForm;
use App\Enums\Contract\Type;
use App\Enums\JobStatus;
use App\Models\Contracts\Contract;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class ContractShow extends Component
{
    #[Locked]
    public int $contractId;

    protected Contract $contract;

    public function boot(): void
    {
        if (isset($this->contractId)) {
            $this->contract = Contract::query()->forLegalEntity(legalEntity())->findOrFail($this->contractId);
            abort_if(Auth::user()->cannot('view', $this->contract), 403);
        }
    }

    #[Locked]
    public string $printoutContent = '';

    public array $data = [];

    public array $medicalProgramNames = [];

    public function mount(LegalEntity $legalEntity, Contract $contract): void
    {
        abort_if(Auth::user()->cannot('view', $contract), 403);
        $this->contractId = $contract->id;
        $this->contract = $contract;
        $this->medicalProgramNames = $this->resolveMedicalProgramNames();

        if ($this->contract->uuid) {
            $this->syncDetailsFromEHealth();
        }

        $this->data = $this->normalizeContractData($this->contract->data ?? []);
    }

    private function syncDetailsFromEHealth(): void
    {
        try {
            $data = EHealth::contract()->getDetails($this->contract->uuid, strtolower((string) $this->contract->type))->validate();
            $data['sync_status'] = JobStatus::COMPLETED->value;
            $this->contract = Repository::contract()->saveFromEHealth($data, details: true);
            try {
                $printout = EHealth::contract()->getPrintoutContent($this->contract->uuid, strtolower($this->contract->type))->validate();
                $this->printoutContent = $printout['printout_content'];
            } catch (Throwable $exception) {
                Log::warning('Failed to read contract printout', ['exception' => $exception::class]);
                Session::flash('warning', __('contracts.printout_unavailable'));
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to read contract details', ['exception' => $exception::class]);
            Session::flash('error', __('contracts.details_unavailable'));
        }
    }

    /**
     * @return array<string, string>
     */
    private function resolveMedicalProgramNames(): array
    {
        if ($this->contract->type !== Type::REIMBURSEMENT->value) {
            return [];
        }

        try {
            return dictionary()->medicalPrograms()
                ->pluck('name', 'id')
                ->all();
        } catch (Throwable $exception) {
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

    public function render(): View
    {
        $idFormCode = $this->contract->idForm
            ?? data_get($this->data, 'id_form');

        return view('livewire.contract.contract-show', [
            'contract' => $this->contract,
            'data' => $this->data,
            'medicalProgramNames' => $this->medicalProgramNames,
            'idFormName' => IdForm::resolveLabel($idFormCode, $this->contract->type),
        ]);
    }
}
