<x-dialog-drawer id="medical-devices-drawer-right" x-model="showMedicalDeviceDrawer" maxWidth="4/5" topClass="top-[57px]" zIndex="40" overlayWidth="100%" onCloseClick="showMedicalDeviceDrawer = false">
    <x-slot name="title"><span class="block"><span class="care-plan-service-context block">{{ $carePlan->person?->full_name }} — {{ __('care-plan.care_plan') }} №{{ $carePlan->requisition ?? $carePlan->id }}</span>{{ __('care-plan.new_medical_device_prescription') }}</span></x-slot>
    @if (($activityForm['kind'] ?? null) === 'device_request' && empty($activityForm['id']))
        @include('livewire.care-plan.parts.activity.device-fields', ['inputPrefix' => 'device-new', 'drawerState' => 'showMedicalDeviceDrawer'])
    @endif
</x-dialog-drawer>
