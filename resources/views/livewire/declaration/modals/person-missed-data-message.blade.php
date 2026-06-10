<div x-data="{ isNeedToUpdatePerson: $wire.entangle('showUpdatePersonDataModal'), isInformed: false }">
    <template x-teleport="body">
        <div x-show="isNeedToUpdatePerson"
             style="display: none"
             role="dialog"
             aria-modal="true"
             class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition class="modal-wrapper">
                <div @click.stop
                     x-trap.noscroll.inert="isNeedToUpdatePerson"
                     class="modal-content w-full max-w-4xl mx-auto"
                >
                    <h2 class="mb-12 text-2xl font-semibold text-red-700 dark:text-white text-center">
                        {{ __('patients.patient_data_incomplete') }}
                    </h2>

                    <p class="default-p mb-8">
                        {{ __('patients.missed_patient_data') }}
                    </p>

                    <p class="default-p mb-8">
                        {{ __('patients.patient_data_need_to_update') }}
                    </p>

                    {{-- Is signed by patient --}}
                    <div class="form-row">
                        <div class="form-group group">
                            <input x-model="isInformed"
                                   type="checkbox"
                                   name="isInformed"
                                   id="isInformed"
                                   class="default-checkbox"
                            />
                            <label class="default-p" for="isInformed">
                                {{ __('declarations.patient_confirm_sync_message') }}
                            </label>
                        </div>
                    </div>

                    {{-- Action buttons --}}
                    <div class="flex justify-center gap-8.5 mt-16">
                        <button type="button" @click="isNeedToUpdatePerson = false" class="button-minor">
                            {{ __('forms.cancel') }}
                        </button>
                        <button wire:click="openApproveModal"
                                type="button"
                                class="button-primary flex items-center gap-2"
                                :disabled="!isInformed"
                        >
                            {{ __('patients.patient_data_update') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
