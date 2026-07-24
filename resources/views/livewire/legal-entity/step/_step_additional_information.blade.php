@php
    $hasAdditionalInformationReceiverFundsCodeError = $errors->has('legalEntityForm.receiverFundsCode');
    $hasAdditionalInformationBeneficiaryError = $errors->has('legalEntityForm.beneficiary');
@endphp

<fieldset
    class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]"
    xmlns="http://www.w3.org/1999/html"
    x-data="{
        title: '{{ __('forms.additional_information') }}',
        index: 7,
    }"
    x-init="typeof addHeader !== 'undefined' && addHeader(title, index)"
    x-show="activeStep === index || isEdit"
    x-cloak
    :key="`step-${index}`"
>
    <template x-if="isEdit">
        <legend x-text="title" class="legend"></legend>
    </template>

    <div class='form-row-3'>

        <div class="form-group group">
            <input
            type="text"
            placeholder=" "
            id="additionalInformationReceiverFundsCode"
            wire:model="legalEntityForm.receiverFundsCode"
            aria-describedby="{{ $hasAdditionalInformationReceiverFundsCodeError ? 'additionalInformationReceiverFundsCodeErrorHelp' : '' }}"
            class="input peer"
        />

            @if($hasAdditionalInformationReceiverFundsCodeError)
                <p id="additionalInformationReceiverFundsCodeErrorHelp" class="text-error">
                    {{ $errors->first('legalEntityForm.receiverFundsCode') }}
                </p>
            @endif

            <p id="additionalInformationReceiverFundsCoderHelp" class="text-note">
                {{ __('forms.receiver_funds_code') }}
            </p>

            <label for="additionalInformationReceiverFundsCode" class="label z-10">
                {{ __('forms.treasury_registration_code') }}
            </label>
        </div>

        <div class="form-group group">
            <input
                type="text"
                placeholder=" "
                id="additionalInformationBeneficiary"
                wire:model="legalEntityForm.beneficiary"
                aria-describedby="{{ $hasAdditionalInformationBeneficiaryError ? 'additionalInformationBeneficiaryErrorHelp' : '' }}"
                class="input peer"
            />

            @if($hasAdditionalInformationBeneficiaryError)
                <p id="additionalInformationBeneficiaryErrorHelp" class="text-error">
                    {{ $errors->first('legalEntityForm.beneficiary') }}
                </p>
            @endif

            <p id="additionalInformationBeneficiaryHelp" class="text-note">
                {{ __('forms.beneficiary_info') }}
            </p>

            <label for="additionalInformationBeneficiary" class="label z-10">
                {{ __('forms.beneficiary') }}
            </label>
        </div>
    </div>

    <div x-data="{ showArchivation: $wire.entangle('legalEntityForm.archivationShow') }">
        <div class='form-row-3'>
            <div class="form-group group">
                <input
                    type="checkbox"
                    id="archivationShow"
                    class="default-checkbox text-blue-500 focus:ring-blue-300"
                    x-model="showArchivation"
                    :checked="showArchivation"
                >
                <label for="archivationShow" class="ms-2 text-sm font-medium text-gray-500 dark:text-gray-300">{{ __('forms.archivation') }}</label>
            </div>
        </div>

        {{-- A R CH I V A T I O N --}}
        <template x-if="showArchivation">
            <div
                class='form-row mt-6'
                x-data="{ archives: $wire.entangle('legalEntityForm.archive') }"
                x-init="archives = archives.length > 0 ? archives : [{ date: '', place: '' }];"
                x-id="['archive']"
            >
                <template x-for="(archive, index) in archives" :key="index">
                    <div
                        class='form-row-3'
                        x-data="{errors: [] }"
                        x-init="errors =@js($errors->getMessages())"
                        :class="{ 'mb-2': index == archives.length - 1 }"
                    >
                        <div class="form-group group">
                            <svg class="svg-input" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M20 4a2 2 0 0 0-2-2h-2V1a1 1 0 0 0-2 0v1h-3V1a1 1 0 0 0-2 0v1H6V1a1 1 0 0 0-2 0v1H2a2 2 0 0 0-2 2v2h20V4ZM0 18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8H0v10Zm5-8h10a1 1 0 0 1 0 2H5a1 1 0 0 1 0-2Z"/>
                            </svg>

                            <input
                                required
                                type="text"
                                placeholder=" "
                                x-model="archives[index].date"
                                datepicker-format="{{ frontendDateFormat() }}"
                                class="input datepicker-input peer"
                                :id="$id('archive', '_date' + index)"
                                :class="{ 'input-error border-red-500 focus:border-red-500' : errors[`legalEntityForm.archive.${index}.date`] }"
                            />

                            <template x-if="errors[`legalEntityForm.archive.${index}.date`]">
                                <p class="text-error" x-text="errors[`legalEntityForm.archive.${index}.date`]"></p>
                            </template>

                            <label :for="$id('archive', '_date' + index)" class="label z-10">
                                {{ __("forms.archive_date") }}
                            </label>
                        </div>

                        <div class="form-group group">
                            <input
                                required
                                type="text"
                                placeholder=" "
                                x-model="archives[index].place"
                                class="input peer"
                                :id="$id('archive', '_place' + index)"
                                :class="{ 'input-error border-red-500 focus:border-red-500' : errors[`legalEntityForm.archive.${index}.date`] }"
                            />

                            <p id="additionalInformationArchivePlaceHelp" class="text-note">
                                {{ __('forms.archive_place') }}
                            </p>

                            <template x-if="errors[`legalEntityForm.archive.${index}.place`]">
                                <p class="text-error" x-text="errors[`legalEntityForm.archive.${index}.place`]"></p>
                            </template>

                            <label :for="$id('archive', '_place' + index)" class="label z-10">
                                {{ __('forms.address') }}
                            </label>
                        </div>

                        {{-- Remove an archive data --}}
                        <template x-if="archives.length > 1 && index > 0">
                            <button
                                x-cloak
                                x-show="!@json($isDetails ?? false)"
                                x-on:click.prevent="archives.splice(index, 1)"
                                class="item-remove justify-self-start text-xs"
                            >
                                {{__('forms.delete')}}
                            </button>
                        </template>
                    </div>
                </template>

                {{-- Add new archive data --}}
                <button
                    x-cloak
                    x-show="!@json($isDetails ?? false)"
                    x-on:click.prevent="archives.push({ date: '', place: '' })"
                    class="item-add"
                    :class="{ 'lg:justify-self-start': index > 0 }" {{-- Apply this style only if it's not a first archive data group --}}
                >
                    {{ __('forms.archive_add') }}
                </button>
            </div>
        </template>
    </div>
</fieldset>
