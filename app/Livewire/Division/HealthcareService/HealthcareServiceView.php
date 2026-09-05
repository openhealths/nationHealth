<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use Illuminate\View\View;

class HealthcareServiceView extends HealthcareServiceComponent
{
    public function mount(LegalEntity $legalEntity, Division $division, HealthcareService $healthcareService): void
    {
        $this->isDisabled = true;
        $this->baseMount($legalEntity, $division);

        $healthcareService->loadMissing(['category.coding', 'type.coding']);
        $this->form->fillFromModel($healthcareService);
    }

    public function render(): View
    {
        return view('livewire.division.healthcare-service.healthcare-service-view');
    }
}
