<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use Illuminate\View\View;
use Livewire\Component;

class XMessage extends Component
{
    public bool $listenAsync = false;

    public function render(): View
    {
        return view('livewire.components.x-message');
    }
}
