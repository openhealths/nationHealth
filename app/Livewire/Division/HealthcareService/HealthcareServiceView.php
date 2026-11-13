<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use App\Classes\eHealth\EHealth;
use App\Dto\HealthcareService\Form as FormData;
use App\Dto\HealthcareService\Model as HealthcareServiceData;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Throwable;

class HealthcareServiceView extends HealthcareServiceComponent
{
    public function mount(LegalEntity $legalEntity, Division $division, HealthcareService $healthcareService): void
    {
        $this->isDisabled = true;
        $this->baseMount($legalEntity, $division);

        $this->healthcareServiceId = $healthcareService->id;

        $healthcareService->loadMissing(['category.coding', 'type.coding']);
        $this->form->fill(objectMapper()->map($healthcareService, FormData::class)->toArray());
    }

    /**
     * Get the healthcare service by ID from eHealth, save it and refresh the displayed form.
     *
     * @return void
     */
    public function sync(): void
    {
        if (Auth::user()->cannot('sync', $this->healthcareService)) {
            Session::flash('error', __('healthcare-services.policy.sync'));

            return;
        }

        try {
            $response = EHealth::healthcareService()->getById($this->healthcareService->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle("Error when getting {$this->healthcareService->uuid} healthcare service");

            return;
        }

        try {
            $healthcareServiceData = objectMapper()->map($response->validate(), HealthcareServiceData::class);

            Repository::healthcareService()->save($healthcareServiceData, $this->healthcareService);

            $this->healthcareService->load(['category.coding', 'type.coding']);
            $this->form->fill(objectMapper()->map($this->healthcareService, FormData::class)->toArray());

            Session::flash('success', __('healthcare-services.success.sync_updated'));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, "Failed to sync {$this->healthcareService->uuid} healthcare service");

            return;
        }
    }

    public function render(): View
    {
        return view('livewire.division.healthcare-service.healthcare-service-view');
    }
}
