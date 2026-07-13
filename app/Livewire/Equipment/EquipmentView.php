<?php

declare(strict_types=1);

namespace App\Livewire\Equipment;

use App\Livewire\Equipment\Traits\StatusTrait;
use App\Models\Equipment;
use App\Models\LegalEntity;
use Illuminate\View\View;
use Livewire\Attributes\Locked;

class EquipmentView extends EquipmentComponent
{
    use StatusTrait;

    #[Locked]
    public Equipment $equipment;

    public function mount(LegalEntity $legalEntity, Equipment $equipment): void
    {
        $this->baseMount($legalEntity);
        $this->loadEquipmentToForm($equipment);
        $this->form->ehealthInsertedAt = $equipment->ehealthInsertedAt;
        $this->recorderFullName = $equipment->recorder()->with('party')->first()?->fullName ?? '';
    }

    public function render(): View
    {
        return view('livewire.equipment.equipment-view');
    }
}
