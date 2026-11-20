<?php

declare(strict_types=1);

namespace App\Livewire\Equipment;

use App\Dto\Equipment\Model as EquipmentData;
use App\Enums\Equipment\Status;
use App\Models\Equipment;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Throwable;

class EquipmentEdit extends EquipmentComponent
{
    public function mount(LegalEntity $legalEntity, Equipment $equipment): void
    {
        $this->baseMount($legalEntity);
        $this->equipmentId = $equipment->id;

        $this->loadEquipmentToForm($equipment);
        $this->loadRecorders($legalEntity);
        $this->form->ehealthInsertedAt = $equipment->ehealthInsertedAt;
    }

    public function create(): void
    {
        $equipment = Equipment::findOrFail($this->equipmentId);
        $this->form->status = Status::ACTIVE->value;

        if (Auth::user()->cannot('edit', $equipment)) {
            Session::flash('error', __('equipments.policy.edit'));

            return;
        }

        if (!$this->validateForm()) {
            return;
        }

        $response = $this->createInEHealth();
        if (!$response) {
            return;
        }

        try {
            $data = objectMapper()->map($response->validate(), EquipmentData::class);
            Repository::equipment()->save($data, $equipment);

            Session::flash('success', __('equipments.success.created'));
            $this->redirectRoute('equipment.index', [legalEntity()], navigate: true);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store equipment');

            return;
        }
    }

    public function render(): View
    {
        return view('livewire.equipment.equipment-edit');
    }
}
