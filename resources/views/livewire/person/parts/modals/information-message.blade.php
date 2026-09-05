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
                    @include('livewire.person.parts.patient-leaflet')

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
                            <label class="default-p" for="isInformed"> {{ __('patients.informed') }} </label>
                        </div>
                    </div>

                    {{-- Action buttons --}}
                    <div class="mt-16 flex justify-center gap-8.5">
                        <button type="button" @click="showInformationMessageModal = false" class="button-minor">
                            {{ __('forms.cancel') }}
                        </button>
                        <button
                            wire:click="create"
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
