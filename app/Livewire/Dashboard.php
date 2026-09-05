<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Component;
use App\Models\LegalEntity;
use Illuminate\Contracts\View\View;

class Dashboard extends Component
{
    public function mount(LegalEntity $legalEntity): void
    {
    }

    public function render(): View
    {
        return view('dashboard');
    }
}
