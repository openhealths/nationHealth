<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use App\Core\Arr;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Throwable;

class HealthcareServiceEdit extends HealthcareServiceComponent
{
    public function mount(LegalEntity $legalEntity, Division $division, HealthcareService $healthcareService): void
    {
        $this->baseMount($legalEntity, $division);

        $this->healthcareServiceId = $healthcareService->id;
        $healthcareService->loadMissing(['category.coding', 'type.coding']);

        $this->form->fill(Arr::except($healthcareService->toArray(), ['divisionId']));

        if ($this->form->availableTime) {
            $this->form->availableTime = Arr::toCamelCase($this->form->availableTime);
        }
    }

    public function create(): void
    {
        $healthcareService = HealthcareService::find($this->healthcareServiceId);
        if (Auth::user()->cannot('edit', $healthcareService)) {
            Session::flash('error', 'У вас немає дозволу на редагування цієї послуги');

            return;
        }

        $validated = $this->validateForm();
        if (!$validated) {
            return;
        }

        $response = $this->createInEHealth($validated);
        if (!$response) {
            return;
        }

        try {
            $validated = $response->validate();
            $validated['id'] = $this->healthcareServiceId;
            Repository::healthcareService()->update($response->map($validated));

            Session::flash('success', 'Послугу успішно створено.');
            $this->redirectRoute('healthcare-service.index', [legalEntity(), $this->divisionId], navigate: true);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store healthcare service');

            return;
        }
    }

    public function render(): View
    {
        return view('livewire.division.healthcare-service.healthcare-service-edit');
    }
}
