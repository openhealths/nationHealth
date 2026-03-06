<div>
    <x-header-navigation x-data="{ showFilter: false }">
        <x-slot name="title">{{ __('forms.edit') }}</x-slot>
    </x-header-navigation>

    <livewire:components.x-message :key="now()->timestamp"/>

    <section class="section-form shift-content"
        x-data="{
            activeStep: 0,
            isEdit: @json($isEdit),
            openModal: false,
            isTermDisabled: @entangle('legalEntityForm.publicOffer.consent').defer,
            init() {
                Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => {
                        this.$nextTick(() => {
                            const firstErrorMessage = document.querySelector('.error-message')

                            if (firstErrorMessage !== null) {
                                firstErrorMessage.scrollIntoView({ block: 'center', inline: 'center' });
                                this.isTermDisabled = false;
                            }
                        })
                    })
                })
            }
        }"
    >
        <div class="form-row">
            <form
                id="edit_legal_entity_form"
                class="grid-cols-1 w-3/4"
            >
                <div class="p-5">
                    @include('livewire.legal-entity.step._step_edrpou')
                    @include('livewire.legal-entity.step._step_owner')
                    @include('livewire.legal-entity.step._step_contact')
                    @include('livewire.legal-entity.step._step_residence_address')
                    @include('livewire.legal-entity.step._step_accreditation')
                    @include('livewire.legal-entity.step._step_license')
                    @include('livewire.legal-entity.step._step_additional_information')
                    @include('livewire.legal-entity.step._step_public_offer')
                </div>

                <div class="mt-6 flex flex-col gap-6 xl:flex-row justify-between items-center">
                    {{-- Agreement checkbox --}}
                    <div class="form-group group">
                        <div class="flex items-center p-5">
                            <input
                                type="checkbox"
                                value="isTermDisabled"
                                id="public_offer_consent"
                                class="steps-agreement_checkbox"
                                x-model="isTermDisabled"
                                wire:model="legalEntityForm.publicOffer.consent"
                                :checked="isTermDisabled"
                            />
                            <label
                                for="public_offer_consent"
                                class="steps-agreement_label !text-xs cursor-pointer"
                            >
                                {{ __(dictionary()->basics()->byName('LE_CONSENT_TEXT')->value('description')) }}
                            </label>
                            <div class="xl:w-1/4 flex justify-end">
                                <button
                                    type="button"
                                    class="button-primary cursor-pointer submit-button"
                                    wire:click="updateLegalEntity"
                                    :disabled="!isTermDisabled"
                                >
                                    {{ __('forms.sendRequest') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <x-forms.loading />
    </section>
</div>
