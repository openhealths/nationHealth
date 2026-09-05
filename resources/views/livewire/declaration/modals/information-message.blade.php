<div
    x-data="{
        showInformationMessageModal: $wire.entangle('showInformationMessageModal'),
        isInformed: $wire.entangle('form.processDisclosureDataConsent'),
    }"
>
    <template x-teleport="body">
        <div x-show="showInformationMessageModal" style="display: none" role="dialog" aria-modal="true" class="modal">
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition class="modal-wrapper">
                <div
                    @click.stop
                    x-trap.noscroll.inert="showInformationMessageModal"
                    class="modal-content mx-auto w-full max-w-4xl"
                >
                    <h2 class="mb-12 text-center text-2xl font-semibold text-gray-900 dark:text-white">
                        {{ __('declarations.confirmation_of_application_for_registration_of_declaration') }}
                    </h2>

                    <div class="mb-8">
                        <p class="default-p">{{ __('declarations.medical_worker_confirmation') }}</p>
                        <ul class="list-inside list-disc">
                            <li class="default-p pl-2">{{ __('declarations.patient_identified') }}</li>
                            <li class="default-p pl-2">{{ __('declarations.informed_about_data_processing') }}</li>
                        </ul>

                        <p class="default-p mt-4">{{ __('declarations.patient_memo') }}</p>
                        <p class="default-p">{{ __('declarations.sms_or_documents_note') }}</p>
                        <ul class="list-inside list-disc">
                            <li class="default-p pl-2">{{ __('declarations.consent_data_processing') }}</li>
                            <li class="default-p pl-2">{{ __('declarations.consent_declaration_submission') }}</li>
                        </ul>
                    </div>

                    {{-- Is signed by patient --}}
                    <div class="form-row">
                        <div class="form-group group">
                            <input
                                x-model="isInformed"
                                type="checkbox"
                                name="isInformed"
                                id="isInformed"
                                class="default-checkbox"
                            />
                            <label class="default-p" for="isInformed">
                                {{ __('declarations.patient_confirm_information_message') }}
                            </label>
                        </div>
                    </div>

                    {{-- Action buttons --}}
                    <div class="mt-16 flex justify-center gap-8.5">
                        <button type="button" @click="showInformationMessageModal = false" class="button-minor">
                            {{ __('forms.cancel') }}
                        </button>
                        <button
                            wire:click="openApproveModal"
                            type="button"
                            class="button-primary flex items-center gap-2"
                            :disabled="! isInformed"
                        >
                            {{ __('forms.confirm') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
