{{-- Medications Drawer (single teleport root — Alpine moves only firstElementChild) --}}
<template x-teleport="body">
    <div x-show="showMedicationDrawer"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         x-cloak
         class="fixed inset-0"
         style="z-index: 39;"
         role="dialog"
         aria-modal="true"
         aria-labelledby="medications-drawer-label"
    >
        <div class="absolute inset-0 bg-gray-900/50"
             aria-hidden="true"
             @click="showMedicationDrawer = false"
        ></div>

        <div id="medications-drawer-right"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="absolute top-0 right-0 z-10 h-screen pt-20 p-4 overflow-y-auto bg-white w-4/5 dark:bg-gray-800 shadow-2xl"
             tabindex="-1"
        >
        <h3 class="modal-header" id="medications-drawer-label">
            {{ __('care-plan.new_medication_prescription') }}
        </h3>

        {{-- Content --}}
        <form>
            {{-- Program Selection Section --}}
            <fieldset class="fieldset">
                <legend class="legend">
                    {{ __('care-plan.program_selection') }}
                </legend>

                <div class="form-row-3">
                    <div class="form-group group">
                        <label for="medication_program" class="label">
                            {{ __('care-plan.program') }}*
                        </label>
                        <select id="medication_program"
                                name="medication_program"
                                class="input-select peer"
                        >
                            <option selected value="">{{ __('care-plan.prescription_medication') }}</option>
                        </select>
                    </div>
                </div>
            </fieldset>

            <div class="mt-6 flex justify-start gap-3">
                <button type="button"
                        class="button-minor"
                        aria-controls="medications-drawer-right"
                        @click="showMedicationDrawer = false"
                >
                    {{ __('forms.cancel') }}
                </button>

                <button type="button"
                        class="button-primary"
                        aria-controls="medication-search-drawer-right"
                        @click="showMedicationSearchDrawer = true"
                >
                    {{ __('forms.continue') }}
                </button>
            </div>
        </form>
        </div>
    </div>
    </div>
</template>
