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

    public function sign(string $keyContainer = '', string $password = ''): void
    {
        $this->form->useSigningSecrets($keyContainer, $password);

        if ($this->actionType === 'sign_eprescription') {
            $this->signEncounterEPrescription();
        }
    }

    protected function afterEncounterEPrescriptionSigned(): void
    {
        $this->returnToEncounter();
    }

    public function render(): View
    {
        return view('livewire.encounter-package.encounter-e-prescription-create');
    }
}
