<?php

declare(strict_types=1);

namespace App\Livewire\Declaration;

use App\Enums\Declaration\Channel;
use App\Models\LegalEntity;
use App\Models\DeclarationRequest;
use App\Models\Person\Person;

class DeclarationEdit extends DeclarationComponent
{
    public function mount(LegalEntity $legalEntity, Person $person, DeclarationRequest $declarationRequest): void
    {
        $this->baseMount($person->id);
        $this->declarationRequestId = $declarationRequest->id;

        if (session('showSignModal')) {
            $this->showSignModal = true;
        }

        if ($declarationRequest->dataToBeSigned) {
            $this->printableContent = $declarationRequest->dataToBeSigned['content'];
            $this->dataToBeSigned = $declarationRequest->dataToBeSigned;
        }

        // Kept from the answer to creating the request, which is the only place the upload URLs come from
        $this->uploadedDocuments = $declarationRequest->documents ?? [];

        // Set form data
        $this->form->employeeId = $declarationRequest->load('employee:id,uuid')->employee->uuid;
        $this->form->authorizeWith = $declarationRequest->authorizeWith;

        $this->declarationRequestUuid = $declarationRequest->uuid ?? '';

        $this->status = $declarationRequest->status;

        // The patient has already signed the request created in their own cabinet
        $this->isCreatedByPatient = $declarationRequest->channel === Channel::PIS->value;

        if ($this->isCreatedByPatient) {
            $this->isSigned = true;
        }
    }
}
