@php
    $diagnosticReportErrorPath = $diagnosticReportErrorPath
        ?? (($context ?? null) === 'diagnostic-report'
            ? 'form.diagnosticReport'
            : 'diagnosticReportForm.diagnosticReports.*');
@endphp

<fieldset class="fieldset">
    <legend class="legend">{{ __('forms.main_information') }}</legend>

    <div>
        {{-- Category --}}
        <div class="form-row-2">
            <div class="form-group group">
                <label for="diagnosticCategory" class="sr-only">{{ __('diagnostic-reports.category') }}</label>
                <select
                    x-model="modalDiagnosticReport.categoryCode"
                    @unless ($isReadonly ?? false)
                        :disabled="modalDiagnosticReport.referralType === 'electronic'"
                        :class="modalDiagnosticReport.referralType === 'electronic' ? 'cursor-not-allowed pointer-events-none' : ''"
                        :style="modalDiagnosticReport.referralType === 'electronic' ? 'color: rgb(107 114 128) !important; -webkit-text-fill-color: rgb(107 114 128) !important;' : ''"
                    @endunless
                    id="diagnosticCategory"
                    class="input-select peer"
                    type="text"
                    required
                >
                    <option value="" selected>
                        {{ __('forms.select') }} {{ mb_strtolower(__('diagnostic-reports.category')) }} *
                    </option>
                    @foreach ($this->dictionaries['eHealth/diagnostic_report_categories'] as $key => $category)
                        <option value="{{ $key }}">{{ $category }}</option>
                    @endforeach
                </select>

                @error($diagnosticReportErrorPath . '.categoryCode')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Services --}}
        <div class="form-row-2 relative z-1">
            <div class="form-group group">
                <template x-if="!selectedServiceFromCatalog">
                    <div>
                        <x-select2
                            modelPath="modalDiagnosticReport.codeValue"
                            dictionaryName="custom/services"
                            id="codeValue"
                            name="codeValue"
                            class="input peer"
                            ::disabled="modalDiagnosticReport.referralType === 'electronic'"
                            ::class="modalDiagnosticReport.referralType === 'electronic' ? 'text-gray-400 cursor-not-allowed pointer-events-none dark:text-gray-500' : ''"
                        />

                        <label for="codeValue" class="label">
                            {{ __('forms.select') }} {{ mb_strtolower(__('forms.services')) }} *
                        </label>
                    </div>
                </template>

                <template x-if="selectedServiceFromCatalog">
                    <div class="relative">
                        <input
                            type="text"
                            id="diagnosticReportServiceCodeFromCatalog"
                            :value="`[${selectedServiceFromCatalog.code}] – ${selectedServiceFromCatalog.name}`"
                            readonly
                            :class="modalDiagnosticReport.referralType === 'electronic' ? 'text-gray-400 cursor-not-allowed pointer-events-none dark:text-gray-500' : ''"
                            class="input"
                        >

                        <label for="diagnosticReportServiceCodeFromCatalog" class="label">
                            {{ __('forms.select') }} {{ mb_strtolower(__('forms.services')) }} *
                        </label>

                        <button
                            x-show="modalDiagnosticReport.referralType !== 'electronic'"
                            type="button"
                            @click="
                                modalDiagnosticReport.codeValue = '';
                                selectedServiceFromCatalog = null;
                            "
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                </template>

                @error($diagnosticReportErrorPath . '.codeValue')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>

            @unless ($isReadonly ?? false)
                <button
                    x-show="modalDiagnosticReport.referralType !== 'electronic'"
                    type="button"
                    @click.prevent="openServiceCatalog = true"
                    class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                >
                    <svg
                        class="h-5 w-5 shrink-0"
                        viewBox="0 0 16 16"
                        fill="none"
                        aria-hidden="true"
                    >
                        <path
                            d="M8 4.667C8 3.96 7.719 3.281 7.219 2.781 6.719 2.281 6.041 2 5.333 2H1.333V12H6c.53 0 1.039.21 1.414.586.375.375.586.884.586 1.414M8 4.667V14m0-9.333c0-.707.281-1.386.781-1.886.5-.5 1.179-.781 1.886-.781h4V12h-4.667c-.53 0-1.039.21-1.414.586-.375.375-.586.884-.586 1.414"
                            stroke="currentColor"
                            stroke-width="1.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>

                    <span>{{ __('dictionaries.service_catalog.choose_from_catalog') }}</span>
                </button>
            @endunless
        </div>

        {{-- Is referral available --}}
        <div>
            <div class="form-row-3">
                <div class="form-group group">
                    <input
                        x-model="modalDiagnosticReport.isReferralAvailable"
                        type="checkbox"
                        name="isDiagnosticReferralAvailable"
                        id="isDiagnosticReferralAvailable"
                        class="default-checkbox mb-1"
                    />
                    <label class="default-p" for="isDiagnosticReferralAvailable">
                        {{ __('medical-events.referral.available') }}
                    </label>
                </div>
            </div>

            {{-- When referral available --}}
            <template x-if="modalDiagnosticReport.isReferralAvailable">
                <div class="form-group group">
                    <div class="form-row-2" x-cloak>
                        <div>
                            <label for="diagnosticReportReferralType" class="sr-only">
                                {{ __('medical-events.referral.requisition_type') }}
                            </label>
                            <select
                                id="diagnosticReportReferralType"
                                class="input-select peer"
                                type="text"
                                x-model="modalDiagnosticReport.referralType"
                                @change="
                                    if (modalDiagnosticReport.referralType === 'electronic') {
                                        modalDiagnosticReport.paperReferralRequisition = '';
                                        modalDiagnosticReport.paperReferralRequesterEmployeeName = '';
                                        modalDiagnosticReport.paperReferralRequesterLegalEntityEdrpou = '';
                                        modalDiagnosticReport.paperReferralRequesterLegalEntityName = '';
                                        modalDiagnosticReport.paperReferralServiceRequestDate = '';
                                        modalDiagnosticReport.paperReferralNote = '';
                                    }

                                    if (modalDiagnosticReport.referralType === 'paper') {
                                        modalDiagnosticReport.basedOnIdentifier = '';
                                    }
                                "
                                required
                            >
                                <option value="" selected>
                                    {{ __('forms.select') }} {{ mb_strtolower(__('medical-events.referral.requisition_type')) }}
                                </option>
                                <option value="electronic">{{ __('medical-events.referral.electronic') }}</option>
                                <option value="paper">{{ __('medical-events.referral.paper') }}</option>
                            </select>
                        </div>

                        {{-- Electronic referral --}}
                        <template x-if="modalDiagnosticReport.referralType === 'electronic'" x-transition>
                            <div
                                class="form-group group"
                                x-data="{
                                    showReferrals: false,
                                    referralExhausted: false,
                                    referralNumber: modalDiagnosticReport.referralNumber ?? '',
                                    referrals: @js($context === 'encounter' && !($isReadonly ?? false) ? [] : ($availableReferrals ?? [])),

                                    init() {
                                        const selectedReferral = this.referrals.find(
                                            referral => referral.id === modalDiagnosticReport.basedOnIdentifier
                                        );

                                        if (selectedReferral) {
                                            this.referralNumber = selectedReferral.requisition;

                                            @unless ($isReadonly ?? false)
                                                if (selectedReferral.service) {
                                                    this.applyReferral(selectedReferral);
                                                }
                                            @endunless
                                        }
                                    },

                                    normalize(value) {
                                        return value
                                            .replaceAll('-', '')
                                            .replaceAll(' ', '')
                                            .toUpperCase();
                                    },

                                    async searchReferrals() {
                                        const value = this.normalize(this.referralNumber);

                                        this.referralExhausted = false;
                                        modalDiagnosticReport.basedOnIdentifier = '';
                                        modalDiagnosticReport.categoryCode = '';
                                        modalDiagnosticReport.codeValue = '';
                                        selectedServiceFromCatalog = null;

                                        if (!value) {
                                            this.referrals = [];
                                            this.showReferrals = false;
                                            return;
                                        }

                                        const referrals = await $wire.searchElectronicReferrals(value);

                                        if (value !== this.normalize(this.referralNumber)) {
                                            return;
                                        }

                                        this.referrals = referrals;
                                        this.showReferrals = true;
                                    },

                                    get filteredReferrals() {
                                        const value = this.normalize(this.referralNumber);

                                        if (!value) {
                                            return this.referrals;
                                        }

                                        return this.referrals.filter(
                                            referral => this.normalize(referral.requisition).includes(value)
                                        );
                                    },

                                    applyReferral(referral) {
                                        this.referralExhausted = referral?.isExhausted === true;

                                        if (this.referralExhausted || !referral?.isDiagnosticReportAllowed) {
                                            modalDiagnosticReport.basedOnIdentifier = '';
                                            modalDiagnosticReport.categoryCode = '';
                                            modalDiagnosticReport.codeValue = '';
                                            selectedServiceFromCatalog = null;

                                            return;
                                        }

                                        modalDiagnosticReport.basedOnIdentifier = referral.id;
                                        modalDiagnosticReport.categoryCode = referral.service.category;
                                        modalDiagnosticReport.codeValue = referral.service.id;
                                        selectedServiceFromCatalog = referral.service;
                                    },

                                    selectReferral(referral) {
                                        this.referralNumber = referral.requisition;
                                        modalDiagnosticReport.referralNumber = referral.requisition;
                                        this.applyReferral(referral);
                                        this.showReferrals = false;
                                    },

                                    clearReferral() {
                                        this.referralNumber = '';
                                        this.referralExhausted = false;
                                        modalDiagnosticReport.referralNumber = '';
                                        modalDiagnosticReport.basedOnIdentifier = '';
                                        modalDiagnosticReport.categoryCode = '';
                                        modalDiagnosticReport.codeValue = '';
                                        selectedServiceFromCatalog = null;
                                        this.referrals = [];
                                        this.showReferrals = false;
                                    }
                                }"
                                @click.outside="showReferrals = false"
                            >
                                <div class="relative">
                                    <input
                                        x-model="referralNumber"
                                        @unless ($isReadonly ?? false)
                                        @focus="if (referrals.length > 0) showReferrals = true"
                                        @input.debounce.1000ms="
                                            referralNumber = $el.value.toUpperCase();
                                            searchReferrals();
                                        "
                                        @endunless
                                        type="text"
                                        id="diagnosticReportBasedOnIdentifier"
                                        class="input !pr-7 peer uppercase"
                                        placeholder=" "
                                        autocomplete="off"
                                        x-mask="****-****-****-****"
                                        required
                                    />

                                    <label for="diagnosticReportBasedOnIdentifier" class="label">
                                        {{ __('medical-events.referral.number') }}
                                    </label>

                                    <div class="absolute inset-y-0 end-0 flex items-center">
                                        <button
                                            type="button"
                                            @click="clearReferral()"
                                            class="text-gray-400 hover:text-gray-600"
                                        >
                                            @icon('close', 'w-4 h-4')
                                        </button>
                                    </div>

                                    <div
                                        x-show="showReferrals && {{ ($isReadonly ?? false) ? 'false' : 'true' }}"
                                        x-cloak
                                        class="absolute top-full left-0 z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
                                    >
                                        <template x-for="referral in filteredReferrals" :key="referral.id">
                                            <button
                                                type="button"
                                                @click="selectReferral(referral)"
                                                :disabled="referral.isExhausted || !referral.isDiagnosticReportAllowed"
                                                :class="!referral.isExhausted && referral.isDiagnosticReportAllowed ? 'hover:bg-gray-100 dark:hover:bg-gray-700' : 'cursor-not-allowed'"
                                                class="w-full rounded-md px-3 py-2 text-left"
                                            >
                                                <div
                                                    :class="!referral.isExhausted && referral.isDiagnosticReportAllowed ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500'"
                                                    class="font-medium"
                                                    x-text="referral.requisition"
                                                ></div>

                                                <div
                                                    x-show="referral.isExhausted"
                                                    class="mt-1 text-xs text-gray-400 dark:text-gray-500"
                                                >
                                                    {{ __('medical-events.referral.exhausted') }}
                                                </div>

                                                <div
                                                    x-show="!referral.isExhausted && !referral.isDiagnosticReportAllowed"
                                                    class="mt-1 text-xs text-gray-400 dark:text-gray-500"
                                                >
                                                    {{ __('diagnostic-reports.validation.referral_not_allowed') }}
                                                </div>
                                            </button>
                                        </template>

                                        <div
                                            x-show="filteredReferrals.length === 0"
                                            class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('encounters.messages.referral_not_found') }}
                                        </div>
                                    </div>
                                </div>

                                @error($diagnosticReportErrorPath . '.basedOnIdentifier')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </template>

                    {{-- Paper referral --}}
                    <template x-if="modalDiagnosticReport.referralType === 'paper'" x-transition>
                        <div>
                            <div class="form-row-2">
                                <div class="form-group group">
                                    <input
                                        x-model="modalDiagnosticReport.paperReferralRequisition"
                                        type="text"
                                        name="requisition"
                                        id="requisition"
                                        class="input peer"
                                        placeholder=" "
                                        autocomplete="off"
                                    />
                                    <label for="requisition" class="label">
                                        {{ __('medical-events.referral.number') }}
                                    </label>

                                    @error($diagnosticReportErrorPath . '.paperReferralRequisition')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="form-group group">
                                    <input
                                        x-model="modalDiagnosticReport.paperReferralRequesterEmployeeName"
                                        type="text"
                                        name="requesterEmployeeName"
                                        id="requesterEmployeeName"
                                        class="input peer"
                                        placeholder=" "
                                        autocomplete="off"
                                        required
                                    />
                                    <label for="requesterEmployeeName" class="label">
                                        {{ __('medical-events.referral.author') }} *
                                    </label>

                                    @error($diagnosticReportErrorPath . '.paperReferralRequesterEmployeeName')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="form-row-2">
                                <div class="form-group group">
                                    <input
                                        x-model="modalDiagnosticReport.paperReferralRequesterLegalEntityEdrpou"
                                        type="text"
                                        name="requesterLegalEntityEdrpou"
                                        id="requesterLegalEntityEdrpou"
                                        class="input peer"
                                        placeholder=" "
                                        autocomplete="off"
                                        maxlength="10"
                                        required
                                    />
                                    <label for="requesterLegalEntityEdrpou" class="label">
                                        {{ __('medical-events.referral.edrpou_of_the_issuing_institution') }}
                                    </label>

                                    @error($diagnosticReportErrorPath . '.paperReferralRequesterLegalEntityEdrpou')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="form-group group">
                                    <input
                                        x-model="modalDiagnosticReport.paperReferralRequesterLegalEntityName"
                                        type="text"
                                        name="requesterLegalEntityName"
                                        id="requesterLegalEntityName"
                                        class="input peer"
                                        placeholder=" "
                                        autocomplete="off"
                                    />
                                    <label for="requesterLegalEntityName" class="label">
                                        {{ __('medical-events.referral.name_of_the_institution_that_issued_it') }}
                                    </label>

                                    @error($diagnosticReportErrorPath . '.paperReferralRequesterLegalEntityName')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="form-row-2">
                                <div class="form-group group">
                                    <div class="datepicker-wrapper">
                                        <input
                                            x-model="modalDiagnosticReport.paperReferralServiceRequestDate"
                                            type="text"
                                            name="serviceRequestDate"
                                            id="serviceRequestDate"
                                            class="datepicker-input with-leading-icon input peer"
                                            placeholder=" "
                                            required
                                            autocomplete="off"
                                        />
                                        <label for="serviceRequestDate" class="wrapped-label">
                                            {{ __('medical-events.referral.date') }}
                                        </label>

                                        @error($diagnosticReportErrorPath . '.paperReferralServiceRequestDate')
                                            <p class="text-error">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>

                                <div class="form-group group">
                                    <input
                                        x-model="modalDiagnosticReport.paperReferralNote"
                                        type="text"
                                        name="note"
                                        id="diagnosticReportPaperNote"
                                        class="input peer"
                                        placeholder=" "
                                        autocomplete="off"
                                    />
                                    <label for="diagnosticReportPaperNote" class="label">
                                        {{ __('medical-events.referral.notes') }}
                                    </label>

                                    @error($diagnosticReportErrorPath . '.paperReferralNote')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        {{-- Conclusion code by ICD-10 --}}
        <div
            x-data="{
                selected: null,
                results: $wire.entangle('results'),
                showResults: false,
                conclusionCodeLabel: '',
            }"
            x-effect="
                const code = modalDiagnosticReport.conclusionCode || '';
                const description = $wire.dictionaries['eHealth/ICD10_AM/condition_codes']?.[code] || '';
                conclusionCodeLabel = code ? [code, description].filter(Boolean).join(' - ') : '';
            "
            class="form-row-2 relative"
        >
            <div class="form-group group">
                <input
                    type="text"
                    @input.debounce.1000ms="
                        let value = $event.target.value;
                        let isEnglish = /^[a-zA-Z0-9.]+$/.test(value);

                        if ((isEnglish && value.length >= 1) || (! isEnglish && value.length >= 3)) {
                            $wire.searchICD10(value);
                            showResults = true;
                        } else {
                            showResults = false;
                        }
                    "
                    @focus="if (conclusionCodeLabel.length >= 1) showResults = true;"
                    @click.away="showResults = false"
                    x-model="conclusionCodeLabel"
                    id="conclusionCode"
                    name="conclusionCode"
                    class="input-select peer"
                    placeholder=""
                    autocomplete="off"
                />
                <label for="conclusionCode" class="label"> {{ __('diagnostic-reports.conclusion_code') }} </label>

                @error($diagnosticReportErrorPath . '.conclusionCode')
                    <p class="text-error">{{ $message }}</p>
                @enderror

                <div
                    x-show="showResults && results.length > 0"
                    class="absolute top-full left-0 z-10 max-h-80 w-full overflow-auto overscroll-contain rounded-lg border border-gray-200 bg-white p-1.5 shadow-lg dark:bg-gray-800"
                >
                    <ul>
                        <template x-for="(result, index) in results" :key="index">
                            <li
                                class="group flex w-full cursor-pointer items-center rounded-md px-2 py-1.5 transition-colors dark:bg-gray-800 dark:text-white"
                                @click="
                                    selected = result;
                                    conclusionCodeLabel = result.code + ' - ' + result.description;
                                    modalDiagnosticReport.conclusionCode = result.code;
                                    modalDiagnosticReport.conclusionCodeLabel = conclusionCodeLabel;
                                    showResults = false;
                                "
                            >
                                <span x-text="result.code + ' - ' + result.description"></span>
                            </li>
                        </template>
                    </ul>
                </div>

                <p x-show="showResults && results.length == 0" class="px-2 py-1.5 text-gray-600">
                    {{ __('forms.nothing_found') }}
                </p>

                <x-forms.loading />
            </div>
        </div>

        {{-- Conclusion --}}
        <div class="form-row">
            <div>
                <label for="conclusion" class="label-modal"> {{ __('diagnostic-reports.conclusion') }} </label>
                <textarea
                    rows="4"
                    x-model="modalDiagnosticReport.conclusion"
                    id="conclusion"
                    name="conclusion"
                    class="textarea"
                    placeholder="{{ __('forms.write_comment_here') }}"
                    maxlength="1000"
                ></textarea>

                @error($diagnosticReportErrorPath . '.conclusion')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>
</fieldset>
