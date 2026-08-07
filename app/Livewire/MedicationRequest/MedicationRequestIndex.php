<?php

declare(strict_types=1);

namespace App\Livewire\MedicationRequest;

use App\Models\LegalEntity;
use Livewire\Component;

class MedicationRequestIndex extends Component
{
    public LegalEntity $legalEntity;

    public function render()
    {
        return view('livewire.medication-request.medication-request-index')->layout('layouts.app');
    }
}
