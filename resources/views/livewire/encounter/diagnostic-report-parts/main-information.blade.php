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
                <select
                    x-model="modalDiagnosticReport.categoryCode"
                    id="diagnosticCategory"
                    class="input-select peer"
                    type="text"
                    required
                >
                    <option value="" selected>
                        {{ __('forms.select') }} {{ mb_strtolower(__('forms.category')) }} *
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
                <x-select2
                    modelPath="modalDiagnosticReport.codeValue"
                    dictionaryName="custom/services"
                    id="codeValue"
                    name="codeValue"
                    class="input peer"
                />
                <label for="codeValue" class="label">
                    {{ __('forms.select') }} {{ mb_strtolower(__('forms.services')) }} *
                </label>

                @error($diagnosticReportErrorPath . '.codeValue')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
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
                        {{ __('encounters.referral_available') }}
                    </label>
                </div>
            </div>

            {{-- When referral available --}}
            <template x-if="modalDiagnosticReport.isReferralAvailable">
                <div class="form-group group">
                    <div class="form-row-2" x-cloak>
                        <div>
                            <select
                                id="referralType"
                                class="input-select peer"
                                type="text"
                                x-model="modalDiagnosticReport.referralType"
                                required
                            >
                                <option value="" selected>
                                    {{ __('forms.select') }} {{ mb_strtolower(__('patients.requisition_type')) }}
                                </option>
                                <option value="electronic">{{ __('patients.electronic') }}</option>
                                <option value="paper">{{ __('patients.paper') }}</option>
                            </select>
                        </div>

                        {{-- Electronic referral --}}
                        <template x-if="modalDiagnosticReport.referralType === 'electronic'" x-transition>
                            <div class="form-group group">
                                <input
                                    x-model="modalDiagnosticReport.basedOnIdentifier"
                                    type="text"
                                    name="basedOnIdentifier"
                                    id="basedOnIdentifier"
                                    class="input-select peer"
                                    placeholder=" "
                                    required
                                    autocomplete="off"
                                />

                                <label for="basedOnIdentifier" class="label">
                                    {{ __('encounters.electronic_referral_id') }}
                                </label>

                                @error($diagnosticReportErrorPath . '.basedOnIdentifier')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </template>
                    </div>

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
                                    <label for="requisition" class="label"> {{ __('forms.number') }} </label>

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
                                        {{ __('patients.author') }} *
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
                                        {{ __('patients.edrpou_of_the_issuing_institution') }}
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
                                        {{ __('patients.name_of_the_institution_that_issued_it') }}
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
                                            {{ __('forms.date') }}
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
                                        id="note"
                                        class="input peer"
                                        placeholder=" "
                                        autocomplete="off"
                                    />
                                    <label for="note" class="label"> {{ __('patients.notes') }} </label>

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
                <label for="conclusion" class="label-modal"> {{ __('medical-events.conclusion') }} </label>
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
