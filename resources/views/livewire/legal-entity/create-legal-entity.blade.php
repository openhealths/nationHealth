<div>
    <x-header-navigation x-data="{ showFilter: false }">
        <x-slot name="title">{{ __('forms.create_legal_entity') }}</x-slot>
    </x-header-navigation>

    <section class="section-form shift-content">
        <div
            x-data="{
                activeStep: {{ $activeStep }},
                isEdit: @json($isEdit),
                headers: [],
                openModal: false,
                isTermDisabled: @entangle('legalEntityForm.publicOffer.consent').defer,
                cleanHeaders() {
                    this.headers = [];
                },

                addHeader(title, index) {
                    const stepData = {
                        title,
                        index,
                        complete: index < this.activeStep,
                    };

                    this.headers.push(stepData);

                    this.headers.sort((a, b) => a.index - b.index);
                },

                isLastStep(stepNum = null) {
                    return stepNum ? this.headers.length === stepNum : this.headers.length === this.activeStep;
                }
            }"
            x-init="cleanHeaders()"
            wire:key="active-{{ $activeStep }}"
            class="steps"
        >
            <div>
                {{-- Steps Header --}}
                <ol class="steps-header">
                    <template x-for="header in headers" :key="`step-header-${header.index}-${activeStep}`">
                        <li
                            x-data="{
                                isActive: activeStep === header.index,
                                isValidationError: false
                            }"
                            @click="if (header.index <= {{ $currentStep }}) { activeStep = header.index; }"
                            x-init="
                                $watch('$wire.validationErrorStep', value => {
                                    isValidationError = value === header.title;
                                });
                            "
                            class="flex md:w-max-content items-center"
                            :class="{ 'cursor-pointer': header.index <= {{ $currentStep }} }"
                        >
                            {{-- Prepend part to the title --}}
                            <template x-if="!isActive">
                                <span x-text="header.index"
                                      class="steps-header_index"
                                      :class="{
                                          'step-completed-color': header.complete && !isActive && !isValidationError,
                                          'step-incomplete-color': !header.complete && !isActive && !isValidationError,
                                          'text-red-500': isValidationError,
                                          'scale-110 ring ring-green-300 dark:ring-green-700': isActive
                                        }"
                                ></span>
                            </template>

                            <template x-if="isActive">
                                <g transform="scale(1.3)" class="hidden sm:inline" :class="{ 'text-blue-600': isActive }">
                                    <svg class="w-4 h-4 sm:w-4 sm:h-4 mx-2.5" aria-hidden="true"
                                         xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                                        <path
                                            d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 8.207-4 4a1 1 0 0 1-1.414 0l-2-2a1 1 0 0 1 1.414-1.414L9 10.586l3.293-3.293a1 1 0 0 1 1.414 1.414Z" />
                                    </svg>
                                </g>
                            </template>

                            {{-- Title itself --}}
                            <span
                                x-text="header.title"
                                class="steps-header_title"
                                :class="{
                                    'step-completed-color': header.complete && !isActive && !isValidationError,
                                    'step-active-color': isActive && !isValidationError,
                                    'text-red-500': isValidationError,
                                    'after:content-[\'/\']': !isLastStep(header.index)
                                }"
                            ></span>

                            {{-- Last Step --}}
                            <template x-if="!isLastStep(header.index)">
                                <svg
                                    fill="none"
                                    aria-hidden="true"
                                    viewBox="0 0 12 10"
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="w-3 h-3 ms-2 sm:ms-4 hidden sm:inline 3rtl:rotate-180"
                                >
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m7 9 4-4-4-4M1 9l4-4-4-4" />
                                </svg>
                            </template>
                        </li>
                    </template>
                </ol>
            </div>

            <div class="form-row">
                {{-- Step Body --}}
                <form id="legal_entity_form">
                    @include('livewire.legal-entity.step._step_edrpou')
                    @include('livewire.legal-entity.step._step_owner')
                    @include('livewire.legal-entity.step._step_contact')
                    @include('livewire.legal-entity.step._step_residence_address')
                    @include('livewire.legal-entity.step._step_accreditation')
                    @include('livewire.legal-entity.step._step_license')
                    @include('livewire.legal-entity.step._step_additional_information')
                    @include('livewire.legal-entity.step._step_public_offer')

                    <div class="steps-footer pt-6">
                        <div class="flex items-center">
                            {{-- Agreement checkbox --}}
                            <div class="xl:w-1/2" x-show="isLastStep()" x-cloak>
                                <div class="flex items-center">
                                    <input type="checkbox" value="isTermDisabled" id="public_offer_consent"
                                           x-model="isTermDisabled"
                                           wire:model="legalEntityForm.publicOffer.consent"
                                           :checked="isTermDisabled"
                                           class="steps-agreement_checkbox"
                                    />
                                    <label
                                        for="public_offer_consent"
                                        class="steps-agreement_label !text-xs cursor-pointer"
                                    >
                                        {{ __(dictionary()->basics()->byName('LE_CONSENT_TEXT')->value('description')) }}
                                    </label>
                                </div>

                                @error('legalEntityForm.publicOffer.consent')
                                <div class='validation-error'>
                                    {{ $message }}
                                </div>
                                @enderror
                            </div>

                            <div class="ml-auto shrink-0 flex items-center gap-2 whitespace-nowrap">
                                <template x-if="isLastStep()">
                                    <button
                                        type="button"
                                        id="submit_button"
                                        class="button-primary cursor-pointer"
                                        wire:click="createLegalEntity"
                                        :disabled="!isTermDisabled"
                                    >
                                        {{ __('forms.sendRequest') }}
                                    </button>
                                </template>

                                <template x-if="!isLastStep()">
                                    <button
                                        type="button"
                                        id="next_button"
                                        class="default-button cursor-pointer"
                                        @click="$wire.nextStep(activeStep).then(result => result ? activeStep={{ $currentStep }} : activeStep)"
                                    >
                                        {{ __('forms.next') }}
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <x-forms.loading />

        </div>
    </section>
</div>
