<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Livewire\Referral\EReferralComponent;

class EReferralView extends EReferralComponent
{
    public function mount(int $legalEntityId, int $personId, int $referralId): void
    {
        $this->initializeReferral($legalEntityId, $personId, $referralId);
    }
}
