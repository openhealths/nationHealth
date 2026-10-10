<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use App\Dto\HealthcareService\Model as HealthcareServiceData;
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

        $this->form->fillFromModel($healthcareService);
    }

    public function create(): void
    {
        $healthcareService = HealthcareService::find($this->healthcareServiceId);
        if (Auth::user()->cannot('edit', $healthcareService)) {
            Session::flash('error', __('healthcare-services.policy.edit'));

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
            $healthcareServiceData = HealthcareServiceData::fromSource($response->validate());

            Repository::healthcareService()->saveMapped($healthcareServiceData, $healthcareService, legalEntity());

            Session::flash('success', __('healthcare-services.success.created'));
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
