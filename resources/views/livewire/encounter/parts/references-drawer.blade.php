@props([
    'patientUuid',
    'selectionEvent' => 'encounter-supporting-info-selected',
    'showProperty' => 'showReferencesDrawer',
    'cancelMethod' => 'cancelSelection',
    'isAddedMethod' => 'isReferenceAdded',
    'componentKey' => 'encounter-supporting-info-search',
    'recordTypes' => ['condition', 'observation', 'diagnosticReport'],
    'title' => null
])

<x-dialog-drawer 
    x-model="{{ $showProperty }}" 
    maxWidth="4/5" 
    onCloseClick="{{ $cancelMethod }}()"
>
    <x-slot name="title">{{ $title ?? __('encounters.search_medical_records') }}</x-slot>

    <div class="mb-6 min-h-0 flex-1 overflow-y-auto pr-1">
        <livewire:encounter.supporting-info-search
            :patient-uuid="$patientUuid"
            :selection-event="$selectionEvent"
            :is-added-check="$isAddedMethod"
            :record-types="$recordTypes"
            :key="$componentKey"
        />
    </div>

    <div class="mt-6">
        <button type="button" class="button-minor" @click="{{ $cancelMethod }}()">
            {{ __('forms.cancel') }}
        </button>
    </div>
</x-dialog-drawer>
