<div>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    @php($incomingReferral = $this->incomingReferral)
    @include('livewire.encounter-package.parts.incoming-referral-card')
    @include('livewire.encounter-package.parts.referral-redeem-modal')
    @include('livewire.encounter-package.parts.referral-status-modal')
</div>
