<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Livewire\EncounterPackage\Concerns\ManagesEncounterEPrescription;
use Illuminate\View\View;

class EncounterEPrescriptionCreate extends EncounterDocumentCreate
{
    use ManagesEncounterEPrescription;

    protected function documentAbility(): string
    {
        return 'createMedicationRequest';
    }

    protected function initializeComponent(): void
    {
        $this->openEncounterEPrescriptionDrawer();
    }

    public function sign(): void
    {
        if ($this->actionType === 'sign_eprescription') {
            $this->signEncounterEPrescription();
        }
    }

    protected function afterEncounterEPrescriptionSigned(): void
    {
        $this->redirectRoute('persons.prescription-requests.view', [legalEntity(), 'person' => $this->personId, 'requestId' => $this->encounterPrescriptionCreatedRequestId]);
    }

    public function render(): View
    {
        return view('livewire.encounter-package.encounter-e-prescription-create');
    }
}
