<section class="section-form px-4 py-8">
    <x-header-navigation class="breadcrumb-form mb-6">
        <x-slot name="title">Редагування призначення</x-slot>
    </x-header-navigation>

    <div x-data="{
        showServiceDrawer: @entangle('showServiceDrawer'),
        showServiceSearchDrawer: @entangle('showServiceSearchDrawer'),
        showMedicationDrawer: @entangle('showMedicationDrawer'),
        showMedicationSearchDrawer: @entangle('showMedicationSearchDrawer'),
        showMedicationFormDrawer: @entangle('showMedicationFormDrawer'),
        showMedicalDeviceDrawer: @entangle('showMedicalDeviceDrawer'),
        showMedicalDeviceSearchDrawer: @entangle('showMedicalDeviceSearchDrawer'),
        showMedicalDeviceFormDrawer: @entangle('showMedicalDeviceFormDrawer'),
    }"
    @close-drawers.window="showServiceDrawer = false; showServiceSearchDrawer = false; showMedicationDrawer = false; showMedicationSearchDrawer = false; showMedicationFormDrawer = false; showMedicalDeviceDrawer = false; showMedicalDeviceSearchDrawer = false; showMedicalDeviceFormDrawer = false;"
    >
        <div class="flex gap-4 mb-6">
            <a href="{{ route('care-plans.activities.show', [legalEntity(), $carePlan->id, $activity->id]) }}" class="button-minor" wire:navigate>{{ __('forms.back') }}</a>
            <button type="button" class="button-primary" wire:click="saveActivity">Зберегти</button>
        </div>

        @include('livewire.care-plan.parts.modals.services-drawer')
        @include('livewire.care-plan.parts.modals.service-search-drawer')
        @include('livewire.care-plan.parts.modals.medications-drawer')
        @include('livewire.care-plan.parts.modals.medication-search-drawer')
        @include('livewire.care-plan.parts.modals.medication-form-drawer')
        @include('livewire.care-plan.parts.modals.medical-devices-drawer')
        @include('livewire.care-plan.parts.modals.medical-device-search-drawer')
        @include('livewire.care-plan.parts.modals.medical-device-form-drawer')
    </div>

    <livewire:components.x-message :key="time()" />
</section>
