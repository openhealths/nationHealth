<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Livewire\EncounterPackage\Concerns\ManagesEncounterReferrals;
use Illuminate\View\View;

class EncounterReferralCreate extends EncounterDocumentCreate
{
    use ManagesEncounterReferrals;

    protected function documentPermission(): string
    {
        return 'service_request:write';
    }

    protected function initializeComponent(): void
    {
        $this->openEncounterReferralDrawer();
    }

    public function sign(): void
    {
        if ($this->actionType === 'sign_referral') {
            $this->signEncounterReferral();
        }
    }

    protected function afterEncounterReferralSigned(): void
    {
        $this->returnToEncounter();
    }

    public function render(): View
    {
        return view('livewire.encounter-package.encounter-referral-create');
    }
}
