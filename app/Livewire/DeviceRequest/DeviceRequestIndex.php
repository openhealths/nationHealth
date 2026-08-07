<?php

declare(strict_types=1);

namespace App\Livewire\DeviceRequest;

use App\Models\LegalEntity;
use Livewire\Component;

class DeviceRequestIndex extends Component
{
    public LegalEntity $legalEntity;

    public function render()
    {
        return view('livewire.device-request.device-request-index')->layout('layouts.app');
    }
}
