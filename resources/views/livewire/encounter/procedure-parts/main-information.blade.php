@php
    use App\Enums\Person\ProcedureStatus;

    $procedureErrorPath = $context === 'encounter' ? 'procedureForm.procedures.*' : 'form.procedure';
@endphp

<fieldset class="fieldset">
    <legend class="legend">{{ __('forms.main_information') }}</legend>

    <div>
        {{-- Is referral available, show only in encounter. For single procedure referral is neccessary. --}}
        @if ($context === 'encounter')
            <div class="form-row-2">
                <div class="form-group group">
                    <input
                        x-model="modalProcedure.isReferralAvailable"
                        @click="modalProcedure.isReferralAvailable = ! modalProcedure.isReferralAvailable"
                        type="checkbox"
                        name="isDiagnosticReferralAvailable"
                        id="procedureReferralAvailable"
                        class="default-checkbox mb-1"
                        tabindex="-1"
                    />
                    <label class="default-p" for="procedureReferralAvailable">
                        {{ __('medical-events.referral.available') }}
                    </label>
                </div>
            </div>
        @endif

        {{-- When referral available --}}
        <template x-if="modalProcedure.isReferralAvailable">
            <div class="form-group group">
                <div class="form-row-2" x-cloak>
                    <div>
                        <label
                            for="procedureReferralType"
                            class="sr-only"
                        >{{ __('medical-events.referral.requisition_type') }}</label>
                        <select
                            x-model="modalProcedure.referralType"
                            @change="
                                if (modalProcedure.referralType === 'electronic') {
                                    modalProcedure.paperReferralRequisition = '';
                                    modalProcedure.paperReferralRequesterEmployeeName = '';
                                    modalProcedure.paperReferralRequesterLegalEntityEdrpou = '';
                                    modalProcedure.paperReferralRequesterLegalEntityName = '';
                                    modalProcedure.paperReferralServiceRequestDate = '';
                                    modalProcedure.paperReferralNote = '';
                                }

                                if (modalProcedure.referralType === 'paper') {
                                    modalProcedure.basedOnIdentifier = '';
                                }
                            "
                            id="procedureReferralType"
                            class="input-select peer"
                            type="text"
                            required
                        >
                            <option selected value="">
                                {{ __('forms.select') }} {{ mb_strtolower(__('medical-events.referral.requisition_type')) }} *
                            </option>
                            <option value="electronic">{{ __('medical-events.referral.electronic') }}</option>
                            <option value="paper">{{ __('medical-events.referral.paper') }}</option>
                        </select>

                        @error($procedureErrorPath . '.referralType')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Electronic referral --}}
                    <template x-if="modalProcedure.referralType === 'electronic'" x-transition>
                        <div
                            class="form-group group"
                            x-data="{
                                showReferrals: false,
                                referralExhausted: false,
                                referralNumber: modalProcedure.referralNumber ?? '',
                                referrals: @js($context === 'encounter' && !($isReadonly ?? false) ? [] : ($availableReferrals ?? [])),

                                init() {
                                    const selectedReferral = this.referrals.find(
                                        referral => referral.id === modalProcedure.basedOnIdentifier
                                    );

                                    if (selectedReferral) {
                                        this.referralNumber = selectedReferral.requisition;

                                        @unless ($isReadonly ?? false)
                                            this.applyReferral(selectedReferral);
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

                                    modalProcedure.basedOnIdentifier = '';
                                    modalProcedure.categoryCode = '';
                                    modalProcedure.codeValue = '';
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

                                    if (this.referralExhausted || !referral?.isProcedureAllowed) {
                                        modalProcedure.basedOnIdentifier = '';
                                        modalProcedure.categoryCode = '';
                                        modalProcedure.codeValue = '';
                                        selectedServiceFromCatalog = null;

                                        return;
                                    }

                                    modalProcedure.basedOnIdentifier = referral.id;
                                    modalProcedure.categoryCode = referral.service.category;
                                    modalProcedure.codeValue = referral.service.id;
                                    selectedServiceFromCatalog = referral.service;
                                },

                                selectReferral(referral) {
                                    this.referralNumber = referral.requisition;
                                    modalProcedure.referralNumber = referral.requisition;
                                    this.applyReferral(referral);
                                    this.showReferrals = false;
                                },

                                clearReferral() {
                                    this.referralNumber = '';
                                    modalProcedure.referralNumber = '';
                                    modalProcedure.basedOnIdentifier = '';
                                    modalProcedure.categoryCode = '';
                                    modalProcedure.codeValue = '';
                                    selectedServiceFromCatalog = null;
                                    this.referrals = [];
                                    this.showReferrals = false;
                                    this.referralExhausted = false;
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
                                    name="basedOnIdentifier"
                                    id="procedureBasedOnIdentifier"
                                    class="input !pr-7 peer uppercase"
                                    placeholder=" "
                                    autocomplete="off"
                                    x-mask="****-****-****-****"
                                    required
                                />

                                <label for="procedureBasedOnIdentifier" class="label">
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
                                            :disabled="referral.isExhausted || !referral.isProcedureAllowed"
                                            :class="!referral.isExhausted && referral.isProcedureAllowed ? 'hover:bg-gray-100 dark:hover:bg-gray-700' : 'cursor-not-allowed'"
                                            class="w-full rounded-md px-3 py-2 text-left"
                                        >
                                            <div
                                                :class="!referral.isExhausted && referral.isProcedureAllowed ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500'"
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
                                                x-show="!referral.isExhausted && !referral.isProcedureAllowed"
                                                class="mt-1 text-xs text-gray-400 dark:text-gray-500"
                                            >
                                                {{ __('procedures.validation.referral_not_allowed') }}
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

                            @error($procedureErrorPath . '.basedOnIdentifier')
                                <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </template>
                </div>

                {{-- Paper referral --}}
                <template x-if="modalProcedure.referralType === 'paper'" x-transition>
                    <div>
                        <div class="form-row-2">
                            <div class="form-group group">
                                <input
                                    x-model="modalProcedure.paperReferralRequisition"
                                    type="text"
                                    name="requisition"
                                    id="procedureRequisition"
                                    class="input peer"
                                    placeholder=" "
                                    autocomplete="off"
                                />
                                <label for="procedureRequisition" class="label">
                                    {{ __('medical-events.referral.number') }}
                                </label>

                                @error($procedureErrorPath . '.paperReferralRequisition')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="form-group group">
                                <input
                                    x-model="modalProcedure.paperReferralRequesterEmployeeName"
                                    type="text"
                                    name="requesterEmployeeName"
                                    id="procedureRequesterEmployeeName"
                                    class="input peer"
                                    placeholder=" "
                                    autocomplete="off"
                                />
                                <label for="procedureRequesterEmployeeName" class="label">
                                    {{ __('medical-events.referral.author') }}
                                </label>

                                @error($procedureErrorPath . '.paperReferralRequesterEmployeeName')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <input
                                    x-model="modalProcedure.paperReferralRequesterLegalEntityEdrpou"
                                    type="text"
                                    name="requesterLegalEntityEdrpou"
                                    id="procedureRequesterLegalEntityEdrpou"
                                    class="input peer"
                                    placeholder=" "
                                    autocomplete="off"
                                    maxlength="10"
                                    required
                                />
                                <label for="procedureRequesterLegalEntityEdrpou" class="label">
                                    {{ __('medical-events.referral.edrpou_of_the_issuing_institution') }}
                                </label>

                                @error($procedureErrorPath . '.paperReferralRequesterLegalEntityEdrpou')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="form-group group">
                                <input
                                    x-model="modalProcedure.paperReferralRequesterLegalEntityName"
                                    type="text"
                                    name="requesterLegalEntityName"
                                    id="procedureRequesterLegalEntityName"
                                    class="input peer"
                                    placeholder=" "
                                    autocomplete="off"
                                />
                                <label for="procedureRequesterLegalEntityName" class="label">
                                    {{ __('medical-events.referral.name_of_the_institution_that_issued_it') }}
                                </label>

                                @error($procedureErrorPath . '.paperReferralRequesterLegalEntityName')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="form-row-modal">
                            <div class="form-group group">
                                <div class="datepicker-wrapper">
                                    <input
                                        x-model="modalProcedure.paperReferralServiceRequestDate"
                                        type="text"
                                        name="serviceRequestDate"
                                        id="procedureServiceRequestDate"
                                        class="datepicker-input with-leading-icon input peer"
                                        placeholder=" "
                                        required
                                        autocomplete="off"
                                    />
                                    <label for="procedureServiceRequestDate" class="wrapped-label">
                                        {{ __('medical-events.referral.date') }}
                                    </label>

                                    @error($procedureErrorPath . '.paperReferralServiceRequestDate')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="form-group group">
                                <input
                                    x-model="modalProcedure.paperReferralNote"
                                    type="text"
                                    name="paperNote"
                                    id="paperNote"
                                    class="input peer"
                                    placeholder=" "
                                    autocomplete="off"
                                />
                                <label for="paperNote" class="label"> {{ __('medical-events.referral.notes') }} </label>

                                @error($procedureErrorPath . '.paperReferralNote')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        {{-- Category --}}
        <div class="form-row-2">
            <div class="form-group group">
                <label for="category" class="sr-only">{{ __('procedures.category') }}</label>
                <select
                    x-model="modalProcedure.categoryCode"
                    @unless ($isReadonly ?? false)
                        :disabled="modalProcedure.referralType === 'electronic' && modalProcedure.basedOnIdentifier"
                        :class="modalProcedure.referralType === 'electronic' && modalProcedure.basedOnIdentifier ? 'cursor-not-allowed pointer-events-none' : ''"
                        :style="modalProcedure.referralType === 'electronic' && modalProcedure.basedOnIdentifier ? 'color: rgb(107 114 128) !important; -webkit-text-fill-color: rgb(107 114 128) !important;' : ''"
                    @endunless
                    @change="
                        if (selectedServiceFromCatalog) {
                            selectedServiceFromCatalog = null;
                            modalProcedure.codeValue = '';
                        }
                    "
                    id="category"
                    class="input-select peer"
                    type="text"
                    required
                >
                    <option selected value="">
                        {{ __('forms.select') }} {{ mb_strtolower(__('procedures.category')) }} *
                    </option>
                    @foreach ($this->dictionaries['eHealth/procedure_categories'] as $key => $category)
                        <option value="{{ $key }}">{{ $category }}</option>
                    @endforeach
                </select>

                @error($procedureErrorPath . '.categoryCode')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group group">
                <label for="procedureStatus" class="sr-only">{{ __('forms.status.label') }}</label>
                <select
                    x-model="modalProcedure.status"
                    @change="
                        modalProcedure.status === 'completed'
                            ? setPerformedType(modalProcedure.performedType || 'period')
                            : setPerformedType('')
                    "
                    id="procedureStatus"
                    class="input-select peer"
                    required
                >
                    <option value="">{{ __('forms.select') }} {{ mb_strtolower(__('forms.status.label')) }} *</option>
                    <option value="completed">{{ __('procedures.status.completed') }}</option>

                    @if (in_array(($context ?? null), ['encounter', 'procedure'], true))
                        <option value="{{ ProcedureStatus::NOT_DONE->value }}">
                            {{ __('procedures.status.not_done') }}
                        </option>
                    @endif

                    @if (data_get($this->form, 'procedure.status') === ProcedureStatus::ENTERED_IN_ERROR->value)
                        <option value="{{ ProcedureStatus::ENTERED_IN_ERROR->value }}">
                            {{ __('procedures.status.entered_in_error') }}
                        </option>
                    @endif
                </select>

                @error($procedureErrorPath . '.status')
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
                            modelPath="modalProcedure.codeValue"
                            dictionaryName="custom/services"
                            id="serviceCode"
                            class="input peer"
                        />

                        <label for="serviceCode" class="label">
                            {{ __('forms.select') }} {{ mb_strtolower(__('procedures.code')) }} *
                        </label>
                    </div>
                </template>

                <template x-if="selectedServiceFromCatalog">
                    <div class="relative">
                        <input
                            type="text"
                            id="serviceCodeFromCatalog"
                            :value="`[${selectedServiceFromCatalog.code}] – ${selectedServiceFromCatalog.name}`"
                            readonly
                            :class="modalProcedure.referralType === 'electronic' && modalProcedure.basedOnIdentifier ? 'text-gray-400 cursor-not-allowed pointer-events-none dark:text-gray-500' : ''"
                            class="input"
                        >

                        <label for="serviceCodeFromCatalog" class="label">
                            {{ __('forms.select') }} {{ mb_strtolower(__('procedures.code')) }} *
                        </label>

                        <button
                            x-show="modalProcedure.referralType !== 'electronic' || !modalProcedure.basedOnIdentifier"
                            type="button"
                            @click="
                                modalProcedure.codeValue = '';
                                selectedServiceFromCatalog = null;
                            "
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                </template>

                @error($procedureErrorPath . '.codeValue')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>

            @unless ($isReadonly ?? false)
                <button
                    x-show="modalProcedure.referralType !== 'electronic' || !modalProcedure.basedOnIdentifier"
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

        {{-- Divisions --}}
        <div class="form-row-2">
            <div class="form-group group">
                <label for="procedureDivision" class="sr-only">{{ __('procedures.division') }}</label>
                <select
                    x-model="modalProcedure.divisionId"
                    @change="modalProcedure.usedReferences = []"
                    @if (count($divisions) === 1)
                        x-init="if (! modalProcedure.divisionId) modalProcedure.divisionId = '{{ $divisions[0]['uuid'] }}';"
                    @endif
                    id="procedureDivision"
                    class="input-select peer"
                >
                    <option selected value="">
                        {{ __('forms.select') }} {{ mb_strtolower(__('procedures.division')) }}
                    </option>
                    @foreach ($divisions as $key => $division)
                        <option value="{{ $division['uuid'] }}">{{ $division['name'] }}</option>
                    @endforeach
                </select>

                @error($procedureErrorPath . '.divisionId')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Outcome --}}
        <div class="form-row-modal">
            <div class="form-group group">
                <label for="outcome" class="sr-only">{{ __('procedures.outcome_result') }}</label>
                <select x-model="modalProcedure.outcomeCode" id="outcome" class="input-select peer" type="text">
                    <option selected value="">
                        {{ __('forms.select') }} {{ mb_strtolower(__('procedures.outcome_result')) }}
                    </option>
                    @foreach ($this->dictionaries['eHealth/procedure_outcomes'] as $key => $outcome)
                        <option value="{{ $key }}">{{ $outcome }}</option>
                    @endforeach
                </select>

                @error($procedureErrorPath . '.outcomeCode')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>
</fieldset>
