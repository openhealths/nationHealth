<fieldset class="fieldset">
    <legend class="legend">
        {{ __('forms.main_information') }}
    </legend>

    <div>
        {{-- Is referral available, show only in encounter. For single procedure referral is neccessary. --}}
        @if($context === 'encounter')
            <div class="form-row-2">
                <div class="form-group group">
                    <input x-model="modalProcedure.isReferralAvailable"
                           @click="modalProcedure.isReferralAvailable = !modalProcedure.isReferralAvailable"
                           type="checkbox"
                           name="isDiagnosticReferralAvailable"
                           id="isDiagnosticReferralAvailable"
                           class="default-checkbox mb-1"
                           tabindex="-1"
                    />
                    <label class="default-p" for="isDiagnosticReferralAvailable">
                        {{ __('patients.referral_available') }}
                    </label>
                </div>
            </div>
        @endif

        {{-- When referral available --}}
        <template x-if="modalProcedure.isReferralAvailable">
            <div class="form-group group">
                <div class="form-row-2" x-cloak>
                    <div>
                        <select x-model="modalProcedure.referralType"
                                id="referralType"
                                class="input-select peer"
                                type="text"
                                required
                        >
                            <option selected value="">
                                {{ __('forms.select') }} {{ mb_strtolower(__('patients.requisition_type')) }} *
                            </option>
                            <option value="electronic">{{ __('patients.electronic') }}</option>
                            <option value="paper">{{ __('patients.paper') }}</option>
                        </select>

                        @error('form.procedures.referralType')
                        <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Electronic referral --}}
                    <template x-if="modalProcedure.referralType === 'electronic'" x-transition>
                        <div class="form-group group">
                            <input wire:model="form.encounter.episode.identifier.value"
                                   type="text"
                                   name="eReferralNumber"
                                   id="eReferralNumber"
                                   class="input-select peer"
                                   placeholder=" "
                                   required
                                   autocomplete="off"
                            />
                            <label for="eReferralNumber" class="label">
                                {{ __('forms.number') }}
                            </label>
                        </div>
                    </template>
                </div>

                {{-- Paper referral --}}
                <template x-if="modalProcedure.referralType === 'paper'" x-transition>
                    <div>
                        <div class="form-row-2">
                            <div class="form-group group">
                                <input x-model="modalProcedure.paperReferral.requisition"
                                       type="text"
                                       name="requisition"
                                       id="requisition"
                                       class="input peer"
                                       placeholder=" "
                                       autocomplete="off"
                                >
                                <label for="requisition" class="label">
                                    {{ __('forms.number') }}
                                </label>

                                @error('form.procedures.paperReferral.requisition')
                                <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="form-group group">
                                <input x-model="modalProcedure.paperReferral.requesterEmployeeName"
                                       type="text"
                                       name="requesterEmployeeName"
                                       id="requesterEmployeeName"
                                       class="input peer"
                                       placeholder=" "
                                       autocomplete="off"
                                >
                                <label for="requesterEmployeeName" class="label">
                                    {{ __('patients.author') }}
                                </label>

                                @error('form.procedures.paperReferral.requesterEmployeeName')
                                <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <input x-model="modalProcedure.paperReferral.requesterLegalEntityEdrpou"
                                       type="text"
                                       name="requesterLegalEntityEdrpou"
                                       id="requesterLegalEntityEdrpou"
                                       class="input peer"
                                       placeholder=" "
                                       autocomplete="off"
                                       maxlength="10"
                                       required
                                >
                                <label for="requesterLegalEntityEdrpou" class="label">
                                    {{ __('patients.edrpou_of_the_issuing_institution') }}
                                </label>

                                @error('form.procedures.paperReferral.requesterLegalEntityEdrpou')
                                <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="form-group group">
                                <input x-model="modalProcedure.paperReferral.requesterLegalEntityName"
                                       type="text"
                                       name="requesterLegalEntityName"
                                       id="requesterLegalEntityName"
                                       class="input peer"
                                       placeholder=" "
                                       autocomplete="off"
                                       required
                                >
                                <label for="requesterLegalEntityName" class="label">
                                    {{ __('patients.name_of_the_institution_that_issued_it') }}
                                </label>

                                @error('form.procedures.paperReferral.requesterLegalEntityName')
                                <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="form-row-modal">
                            <div class="form-group group">
                                <div class="datepicker-wrapper">
                                    <input x-model="modalProcedure.paperReferral.serviceRequestDate"
                                           type="text"
                                           name="serviceRequestDate"
                                           id="serviceRequestDate"
                                           class="datepicker-input with-leading-icon input peer"
                                           placeholder=" "
                                           required
                                           autocomplete="off"
                                    >
                                    <label for="serviceRequestDate" class="wrapped-label">
                                        {{ __('forms.date') }}
                                    </label>

                                    @error('form.procedures.paperReferral.serviceRequestDate')
                                    <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="form-group group">
                                <input x-model="modalProcedure.paperReferral.note"
                                       type="text"
                                       name="paperNote"
                                       id="paperNote"
                                       class="input peer"
                                       placeholder=" "
                                       autocomplete="off"
                                >
                                <label for="paperNote" class="label">
                                    {{ __('patients.notes') }}
                                </label>

                                @error('form.procedures.paperReferral.note')
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
                <select x-model="modalProcedure.category.coding[0].code"
                        id="category"
                        class="input-select peer"
                        type="text"
                        required
                >
                    <option selected value="">
                        {{ __('forms.select') }} {{ mb_strtolower(__('forms.category')) }} *
                    </option>
                    @foreach($this->dictionaries['eHealth/procedure_categories'] as $key => $category)
                        <option value="{{ $key }}">{{ $category }}</option>
                    @endforeach
                </select>

                @error('form.procedures.category.coding.*.code')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Services --}}
        <div class="form-row-2 relative z-1">
            <div class="form-group group">
                <x-select2 modelPath="modalProcedure.code.identifier.value"
                           dictionaryName="custom/services"
                           id="serviceCode"
                           class="input peer"
                />
                <label for="serviceCode" class="label">
                    {{ __('forms.select')}} {{ mb_strtolower(__('forms.services')) }} *
                </label>

                @error('form.procedures.code.identifier.value')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Divisions --}}
        <div class="form-row-2">
            <div class="form-group group">
                <select x-model="modalProcedure.division.identifier.value"
                        @if(count($divisions) === 1)
                            {{-- Set division by default if only one exist --}}
                            x-init="modalProcedure.division.identifier.value = '{{ $divisions[0]['uuid'] }}';"
                        @endif
                        id="divisionNames"
                        class="input-select peer"
                >
                    <option selected value="">
                        {{ __('forms.select') }} {{ mb_strtolower(__('forms.division_name')) }}
                    </option>
                    @foreach($divisions as $key => $division)
                        <option value="{{ $division['uuid'] }}">{{ $division['name'] }}</option>
                    @endforeach
                </select>

                @error('form.procedures.division.identifier.value')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Outcome --}}
        <div class="form-row-modal">
            <div class="form-group group">
                <select x-model="modalProcedure.outcome.coding[0].code"
                        id="outcome"
                        class="input-select peer"
                        type="text"
                >
                    <option selected value="">
                        {{ __('forms.select') }} {{ mb_strtolower(__('patients.outcome_result')) }}
                    </option>
                    @foreach($this->dictionaries['eHealth/procedure_outcomes'] as $key => $outcome)
                        <option value="{{ $key }}">{{ $outcome }}</option>
                    @endforeach
                </select>

                @error('form.procedures.outcome.coding.*.code')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>
</fieldset>
