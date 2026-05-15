<fieldset class="fieldset" id="referral-section">
    <legend class="legend">
        {{ __('patients.referrals') }}
    </legend>

    <div x-data="{
             isReferralAvailable: false,
             referralType: $wire.entangle('form.encounter.referralType')
         }"
         x-init="
             isReferralAvailable = referralType !== '';
             $watch('isReferralAvailable', value => { if (!value) referralType = '' })
         "
    >
        <div class="mb-8">
            <div class="form-group group">
                <input x-model="isReferralAvailable"
                       type="checkbox"
                       name="isReferralAvailable"
                       id="isReferralAvailable"
                       class="default-checkbox mb-1"
                />
                <label class="default-p font-medium" for="isReferralAvailable">
                    {{ __('patients.referral_available') }}
                </label>
            </div>
        </div>

        <div x-show="isReferralAvailable" x-transition x-cloak>
            <div class="form-row-2 mb-10">
                <div class="form-group group">
                    <select x-model="referralType"
                            id="referralType"
                            class="input-select peer"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        <option value="electronic">{{ __('patients.electronic_referral') }}</option>
                        <option value="paper">{{ __('patients.paper_referral') }}</option>
                    </select>
                    <label for="referralType" class="label">
                        {{ __('patients.referral_type') }}
                    </label>
                </div>
            </div>

            <template x-if="referralType === 'electronic'">
                <div class="form-row-2">
                    <div class="form-group group">
                        <div class="relative">
                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none">
                                @icon('search', 'w-4 h-4 text-gray-400')
                            </div>
                            <input wire:model="form.encounter.referralNumber"
                                   type="text"
                                   name="requisitionNumber"
                                   id="requisitionNumber"
                                   class="input !pl-7 !pr-7 peer @error('form.encounter.referralNumber') input-error @enderror"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="requisitionNumber" class="label !left-7">
                                {{ __('patients.referral_number') }}
                            </label>
                            <div class="absolute inset-y-0 end-0 flex items-center">
                                <button type="button" @click="$wire.set('form.encounter.referralNumber', '')"
                                        class="text-gray-400 hover:text-gray-600"
                                >
                                    @icon('close', 'w-4 h-4')
                                </button>
                            </div>
                        </div>
                        @error('form.encounter.referralNumber')
                        <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </template>

            <template x-if="referralType === 'paper'">
                <div class="space-y-8">
                    <div class="form-row-2">
                        <div class="form-group group">
                            <div class="relative">
                                <input wire:model="form.encounter.paperReferral.requisition"
                                       type="text"
                                       id="paperReferralNumber"
                                       class="input !pr-7 peer @error('form.encounter.paperReferral.requisition') input-error @enderror"
                                       placeholder=" "
                                />
                                <label for="paperReferralNumber" class="label">
                                    {{ __('patients.referral_number') }}*
                                </label>
                                <div class="absolute inset-y-0 end-0 flex items-center">
                                    <button type="button"
                                            @click="$wire.set('form.encounter.paperReferral.requisition', '')"
                                            class="text-gray-400 hover:text-gray-600"
                                    >
                                        @icon('close', 'w-4 h-4')
                                    </button>
                                </div>
                            </div>
                            @error('form.encounter.paperReferral.requisition')
                            <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="form-group group">
                            <div class="relative">
                                <input wire:model="form.encounter.paperReferral.requesterEmployeeName"
                                       type="text"
                                       id="paperReferralAuthor"
                                       class="input !pr-7 peer @error('form.encounter.paperReferral.requesterEmployeeName') input-error @enderror"
                                       placeholder=" "
                                />
                                <label for="paperReferralAuthor" class="label">
                                    {{ __('patients.paper_referral_author') }}*
                                </label>
                                <div class="absolute inset-y-0 end-0 flex items-center">
                                    <button type="button"
                                            @click="$wire.set('form.encounter.paperReferral.requesterEmployeeName', '')"
                                            class="text-gray-400 hover:text-gray-600"
                                    >
                                        @icon('close', 'w-4 h-4')
                                    </button>
                                </div>
                            </div>
                            @error('form.encounter.paperReferral.requesterEmployeeName')
                            <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <div class="relative">
                                <input wire:model="form.encounter.paperReferral.requesterLegalEntityEdrpou"
                                       type="text"
                                       id="paperReferralEdrpou"
                                       class="input !pr-7 peer @error('form.encounter.paperReferral.requesterLegalEntityEdrpou') input-error @enderror"
                                       placeholder=" "
                                />
                                <label for="paperReferralEdrpou" class="label">
                                    {{ __('patients.paper_referral_edrpou_short') }}*
                                </label>
                                <div class="absolute inset-y-0 end-0 flex items-center">
                                    <button type="button"
                                            @click="$wire.set('form.encounter.paperReferral.requesterLegalEntityEdrpou', '')"
                                            class="text-gray-400 hover:text-gray-600"
                                    >
                                        @icon('close', 'w-4 h-4')
                                    </button>
                                </div>
                            </div>
                            @error('form.encounter.paperReferral.requesterLegalEntityEdrpou')
                            <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="form-group group">
                            <div class="relative">
                                <input wire:model="form.encounter.paperReferral.requesterLegalEntityName"
                                       type="text"
                                       id="paperReferralInstitutionName"
                                       class="input !pr-7 peer @error('form.encounter.paperReferral.requesterLegalEntityName') input-error @enderror"
                                       placeholder=" "
                                />
                                <label for="paperReferralInstitutionName" class="label">
                                    {{ __('patients.paper_referral_institution_short') }}
                                </label>
                                <div class="absolute inset-y-0 end-0 flex items-center">
                                    <button type="button"
                                            @click="$wire.set('form.encounter.paperReferral.requesterLegalEntityName', '')"
                                            class="text-gray-400 hover:text-gray-600"
                                    >
                                        @icon('close', 'w-4 h-4')
                                    </button>
                                </div>
                            </div>
                            @error('form.encounter.paperReferral.requesterLegalEntityName')
                            <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <div class="datepicker-wrapper">
                                <input wire:model="form.encounter.paperReferral.serviceRequestDate"
                                       type="text"
                                       datepicker-format="dd.mm.yyyy"
                                       id="paperReferralDate"
                                       class="datepicker-input with-leading-icon input peer @error('form.encounter.paperReferral.serviceRequestDate') input-error @enderror"
                                       placeholder=" "
                                       autocomplete="off"
                                />
                                <label for="paperReferralDate" class="wrapped-label">
                                    {{ __('patients.paper_referral_date') }}*
                                </label>
                            </div>
                            @error('form.encounter.paperReferral.serviceRequestDate')
                            <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="form-group group">
                            <div class="relative">
                                <input wire:model="form.encounter.paperReferral.note"
                                       type="text"
                                       id="paperReferralNotes"
                                       class="input !pr-7 peer @error('form.encounter.paperReferral.note') input-error @enderror"
                                       placeholder=" "
                                />
                                <label for="paperReferralNotes" class="label">
                                    {{ __('patients.paper_referral_notes') }}
                                </label>
                                <div class="absolute inset-y-0 end-0 flex items-center">
                                    <button type="button"
                                            @click="$wire.set('form.encounter.paperReferral.note', '')"
                                            class="text-gray-400 hover:text-gray-600"
                                    >
                                        @icon('close', 'w-4 h-4')
                                    </button>
                                </div>
                            </div>
                            @error('form.encounter.paperReferral.note')
                            <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</fieldset>
