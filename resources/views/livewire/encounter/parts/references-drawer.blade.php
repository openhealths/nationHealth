{{-- References Selection Drawer Teleport Root --}}
<template x-teleport="body">
    <div
        x-show="showReferencesDrawer"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        class="fixed inset-0"
        style="z-index: 44"
        role="dialog"
        aria-modal="true"
    >
        <div class="absolute inset-0 bg-gray-900/50" aria-hidden="true" @click="cancelSelection()"></div>

        <div
            id="references-selection-drawer-right"
            x-show="showReferencesDrawer"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="absolute top-0 right-0 flex h-screen flex-col justify-between border-l border-gray-100 bg-white p-6 pt-20 shadow-2xl dark:border-gray-700 dark:bg-gray-800"
            style="width: calc(80% - 30px)"
            tabindex="-1"
        >
            <div class="flex min-h-0 flex-1 flex-col">
                <div class="mb-6 flex items-center pb-5">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ __('encounters.search_medical_records') }}
                    </h2>
                </div>

                <div class="mb-6 min-h-0 flex-1 overflow-y-auto pr-1">
                    <livewire:encounter.supporting-info-search
                        :patient-uuid="$patientUuid"
                        selection-event="encounter-supporting-info-selected"
                        is-added-check="isReferenceAdded"
                        :record-types="['condition', 'observation', 'diagnosticReport']"
                        :key="'encounter-supporting-info-search'"
                    />
                </div>

                <div class="mt-auto flex justify-start border-t border-gray-100 pt-6 dark:border-gray-700">
                    <button type="button" class="button-minor" @click="cancelSelection()">
                        {{ __('forms.cancel') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
