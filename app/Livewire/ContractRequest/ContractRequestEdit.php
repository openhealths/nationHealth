<?php

declare(strict_types=1);

namespace App\Livewire\ContractRequest;

use App\Livewire\Contract\ReimbursementContractCreate;
use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;

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

        // 3. Call the parent mount to initialize dictionaries
        parent::mount($legalEntity);

        // 4. Fill out the form with data from the database
        $this->form->hydrate($this->contractRequest);
    }

    //Override render to use the same template as for creating
    public function render(): \Illuminate\View\View
    {
        return view('livewire.contract.reimbursement-contract-create', [
            'isEdit' => true
        ]);
    }
}
