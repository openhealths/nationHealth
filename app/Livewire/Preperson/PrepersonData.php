<?php

declare(strict_types=1);

namespace App\Livewire\Preperson;

use App\Models\LegalEntity;
use App\Models\Preperson;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PrepersonData extends Component
{
    protected Preperson $preperson;

    /**
     * Initialize the component from the route-bound preperson.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Preperson  $preperson
     * @return void
     */
    public function mount(LegalEntity $legalEntity, Preperson $preperson): void
    {
        $this->preperson = $preperson;
    }

    /**
     * Render the preperson data screen.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.preperson.preperson-data')->with(['preperson' => $this->preperson]);
    }
}
