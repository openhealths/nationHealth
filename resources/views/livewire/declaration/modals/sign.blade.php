<div x-data="{ showSignModal: $wire.entangle('showSignModal'), isSigned: $wire.entangle('isSigned') }">
    <template x-teleport="body">
        <div
            x-show="showSignModal"
            style="display: none"
            @keydown.escape.prevent.stop="showSignModal = false"
            role="dialog"
            aria-modal="true"
            class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition @click="showSignModal = false" class="modal-wrapper">
                <div @click.stop x-trap.noscroll.inert="showSignModal" class="modal-content mx-auto w-full max-w-4xl">
                    <h2 class="mb-8 text-center text-2xl font-semibold text-gray-900 dark:text-white">
                        {{ __('declarations.confirmation_of_patient_signature_on_declaration_application') }}
                    </h2>

                    @if ($isNeedToResign)
                        <p class="default-p mb-8">
                            {{ __('declarations.simplified_declaration_registration_warning_message-0') }} {{ __('declarations.simplified_declaration_registration_warning_message-1') }}
                        </p>

                        <p class="default-p mb-8">
                            {{ __('declarations.simplified_declaration_registration_warning_message-2') }}
                        </p>
                    @elseif ($isCreatedByPatient)
                        <p class="default-p mb-8">{{ __('declarations.patient_request_sign_note') }}</p>
                    @else
                        <ol class="mb-8 list-inside list-decimal">
                            <li class="default-p mb-4">{{ __('declarations.print_declaration_instruction') }}</li>
                            <button
                                x-data
                                @click="
                                    let printWindow = window.open('', '_blank');
                                    printWindow.document.body.innerHTML = $wire.printableContent;
                                    printWindow.focus();
                                    printWindow.print();
                                "
                                class="button-minor gap-3"
                            >
                                @icon('printer', 'w-4 h-4 text-gray-800 dark:text-white')
                                {{ __('declarations.print_application') }}
                            </button>
                            <li class="default-p mt-8">{{ __('declarations.signed_confirmation') }}</li>
                        </ol>

                        {{-- Is signed by patient --}}
                        <div class="form-row">
                            <div class="form-group group">
                                <input
                                    x-model="isSigned"
                                    type="checkbox"
                                    name="isSigned"
                                    id="isSigned"
                                    class="default-checkbox"
                                />
                                <label class="default-p" for="isSigned">
                                    {{ __('declarations.patient_signed_declaration') }}
                                </label>
                            </div>
                        </div>
                    @endif

                    {{-- Action buttons --}}
                    <div class="mt-16 flex justify-center gap-4">
                        <button type="button" @click="showSignModal = false" class="button-minor">
                            {{ __('forms.cancel') }}
                        </button>

                        <button
                            wire:click="openSignatureModal"
                            type="button"
                            class="button-primary flex items-center gap-2"
                            :disabled="! isSigned"
                        >
                            @icon('key', 'w-5 h-5')
                            {{ __('forms.sign_with_KEP') }}
                            @icon('arrow-right', 'w-6 h-6 text-white dark:text-white')
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
