<x-dialog-drawer id="medical-device-form-drawer-right" x-model="showMedicalDeviceFormDrawer" maxWidth="4/5" topClass="top-[57px]" zIndex="40" overlayWidth="100%" onCloseClick="showMedicalDeviceFormDrawer = false">
    <x-slot name="title"><span class="block"><span class="care-plan-service-context block">{{ $carePlan->person?->full_name }} — {{ __('care-plan.care_plan') }} №{{ $carePlan->requisition ?? $carePlan->id }}</span>{{ !empty($activityForm['id']) ? __('care-plan.edit_medical_device_prescription') : __('care-plan.new_medical_device_prescription') }}</span></x-slot>
    @if ($showMedicalDeviceFormDrawer)
        @include('livewire.care-plan.parts.activity.device-fields', ['inputPrefix' => 'device-edit', 'drawerState' => 'showMedicalDeviceFormDrawer'])
    @endif
</x-dialog-drawer>
