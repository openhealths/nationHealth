<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use Illuminate\Contracts\View\View;

class PatientEpisodes extends BasePatientComponent
{
    public function render(): View
    {
        return view('livewire.person.records.episodes');
    }
}
