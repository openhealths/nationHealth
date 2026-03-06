<?php

namespace App\Livewire\Dictionary;

use Livewire\Component;

class DrugList extends Component
{
    public string $search = '';

    public string $inn = '';

    public string $atcCode = '';

    public string $dosageForm = '';

    public string $prescriptionFormType = '';

    public function search(): void
    {
        // TODO: implement search logic
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'inn', 'atcCode', 'dosageForm', 'prescriptionFormType']);
    }

    public function render()
    {
        return view('livewire.dictionary.drug-list');
    }
}
