<div>
    @include('livewire.encounter-package.encounter-package')

    @include('livewire.encounter-package.parts.referral-redeem-modal')
    @include('livewire.encounter-package.parts.referral-status-modal', ['incomingReferral' => $this->incomingReferral])
</div>
