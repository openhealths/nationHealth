<div>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    @include('livewire.care-plan.parts.modals.medication-form-drawer')
    @include('livewire.care-plan.parts.modals.medication-search-drawer')
</div>
