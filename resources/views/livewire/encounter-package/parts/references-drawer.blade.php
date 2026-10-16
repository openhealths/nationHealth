<x-dialog-drawer x-model="showReferencesDrawer" maxWidth="4/5" zIndex="44" onCloseClick="cancelSelection()">
    <x-slot name="title">{{ __('encounters.search_medical_records') }}</x-slot>

    <div class="mb-6 min-h-0 flex-1 overflow-y-auto pr-1">
        <livewire:encounter-package.supporting-info-search
            :patient-uuid="$patientUuid"
            selection-event="encounter-supporting-info-selected"
            is-added-check="isReferenceAdded"
            :record-types="['condition', 'observation', 'diagnosticReport']"
            :key="'encounter-supporting-info-search'"
        />
    </div>

    <div class="mt-6">
        <button type="button" class="button-minor" @click="cancelSelection()">{{ __('forms.cancel') }}</button>
    </div>
</x-dialog-drawer>
