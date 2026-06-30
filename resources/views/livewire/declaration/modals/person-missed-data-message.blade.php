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

                    @if(!$isSyncing)
                        <p class="default-p mb-8">
                            {{ __('patients.missed_patient_data') }}
                        </p>

                        <p class="default-p mb-8 font-semibold italic dark:text-white">
                            {{ __('patients.patient_auth_method_is_obsolete') }}
                        </p>

                    @else
                        <p class="default-p mb-8">
                            {{ __('patients.syncng_patient_data') }}
                        </p>
                    @endif

                    {{-- Action buttons --}}
                    <div class="flex justify-center gap-8.5 mt-16">
                        <button type="button" @click="isNeedToUpdatePerson = false; isInformed = false" class="button-minor">
                            {{ __('forms.close') }}
                        </button>

                        <button type="button"
                            @click="isNeedToUpdatePerson = false; isInformed = false; $wire.goToPatientData()"
                            class="button-primary flex items-center gap-2"
                        >
                            {{ __('patients.patient_record') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
