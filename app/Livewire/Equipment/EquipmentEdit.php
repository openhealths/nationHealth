<?php

declare(strict_types=1);

namespace App\Livewire\Equipment;

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
        $this->form->ehealthInsertedAt = $equipment->ehealthInsertedAt;
    }

    public function create(): void
    {
        $equipment = Equipment::find($this->equipmentId);
        $this->form->status = Status::ACTIVE->value;

        if (Auth::user()->cannot('edit', $equipment)) {
            Session::flash('error', __('equipments.policy.edit'));

            return;
        }

        $validated = $this->validateForm();
        if (!$validated) {
            return;
        }

        $apiPayload = $this->form->formatForApi($validated);

        $response = $this->createInEHealth($apiPayload);
        if (!$response) {
            return;
        }

        try {
            $validated = $response->validate();
            $validated['id'] = $this->equipmentId;
            Repository::equipment()->update($response->map($validated));

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
