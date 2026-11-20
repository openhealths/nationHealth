<?php

declare(strict_types=1);

namespace App\Livewire\Equipment;

use App\Dto\Equipment\Model as EquipmentData;
use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Equipment\Status;
use App\Models\Equipment;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Throwable;

class EquipmentCreate extends EquipmentComponent
{
    public function mount(LegalEntity $legalEntity): void
    {
        $this->baseMount($legalEntity);
        $this->loadRecorders($legalEntity);

        $this->form->status = Status::ACTIVE->value;
        $this->form->availabilityStatus = AvailabilityStatus::AVAILABLE->value;
    }

    public function create(): void
    {
        if (Auth::user()->cannot('create', Equipment::class)) {
            Session::flash('error', __('equipments.policy.create'));

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
            Repository::equipment()->save($data, new Equipment());

            Session::flash('success', __('equipments.success.created'));
            $this->redirectRoute('equipment.index', [legalEntity()], navigate: true);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store equipment');

            return;
        }
    }

    public function createLocally(): void
    {
        if (Auth::user()->cannot('create', Equipment::class)) {
            Session::flash('error', __('equipments.policy.create'));

            return;
        }

        if (!$this->validateForm()) {
            return;
        }

        try {
            $data = objectMapper()->map($this->form, EquipmentData::class);
            Repository::equipment()->save($data, new Equipment(['legal_entity_id' => legalEntity()->id]));

            Session::flash('success', __('equipments.success.draft_created'));
            $this->redirectRoute('equipment.index', [legalEntity()], navigate: true);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store equipment');

            return;
        }
    }

    public function render(): View
    {
        return view('livewire.equipment.equipment-create');
    }
}
