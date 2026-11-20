<div>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    @include('livewire.care-plan.parts.activity.device-fields', ['inputPrefix' => 'review-device', 'drawerState' => 'showMedicalDeviceDrawer'])
    @include('livewire.care-plan.parts.modals.services-drawer')
    @include('livewire.care-plan.parts.modals.medical-records-search-drawer', $recordLists)
</div>