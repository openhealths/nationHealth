<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Livewire\Referral\EReferralComponent;
use Illuminate\Support\Facades\Auth;

class EReferralView extends EReferralComponent
{
    protected function actionAbility(): string
    {
        return 'view';
    }

    public function edit(): void
    {
        $record = $this->referral();
        if (Auth::user()->cannot('edit', $record)) {
            session()->flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        $this->dispatch('edit-referral', referralId: $record->id);
    }
}
