<div id="referral-section">
    <div
        x-data="{
            isReferralAvailable: false,
            referralType: $wire.entangle('form.encounter.referralType'),
        }"
        x-init="
            isReferralAvailable = referralType !== '';
            $watch('isReferralAvailable', (value) => {
                if (! value) referralType = '';
            });
        "
    >
        <div class="mt-2 mb-8">
            <div class="form-group group">
                <input
                    x-model="isReferralAvailable"
                    type="checkbox"
                    name="isReferralAvailable"
                    id="isReferralAvailable"
                    class="default-checkbox mb-1"
                />
                <label class="default-p font-medium" for="isReferralAvailable">
                    {{ __('encounters.referral_available') }}
                </label>
            </div>
        </div>

        <div x-show="isReferralAvailable" x-transition x-cloak>
            <div class="form-row-2 mb-10">
                <div class="form-group group">
                    <select x-model="referralType" id="referralType" class="input-select peer">
                        <option value="" selected>{{ __('forms.select') }}</option>
                        <option value="electronic">{{ __('encounters.electronic_referral') }}</option>
                        <option value="paper">{{ __('encounters.paper_referral') }}</option>
                    </select>
                    <label for="referralType" class="label"> {{ __('encounters.referral_type') }} </label>
                </div>
            </div>

            <template x-if="referralType === 'electronic'">
                <div class="form-row-2">
                    <div
                        class="form-group group"
                        x-data="{
                            showReferrals: false,
                            referralNumber: @js($form->encounter['referralNumber'] ?? ''),
                            referrals: @js($availableReferrals),
                            get filteredReferrals() {
                                const value = this.referralNumber.replaceAll('-', '').toUpperCase();

                                if (! value) {
                                    return this.referrals;
                                }

                                return this.referrals.filter(referral =>
                                    referral.requisition.replaceAll('-', '').toUpperCase().includes(value)
                                );
                            },
                            selectReferral(referral) {
                                this.referralNumber = referral.requisition;
                                this.showReferrals = false;

                                // takeIntoWork / qualify run here — not during KEP signing
                                $wire.selectElectronicReferral(referral.id);
                            }
                        }"
                        @click.outside="showReferrals = false"
                    >
                        <div class="relative">
                            <input
                                x-model="referralNumber"
                                type="text"
                                id="requisitionNumber"
                                class="input !pr-7 peer @error('form.encounter.referralNumber') input-error @enderror uppercase"
                                placeholder=" "
                                autocomplete="off"
                                x-mask="****-****-****-****"
                                x-on:focus="showReferrals = true"
                                x-on:input="
                                    referralNumber = $el.value.toUpperCase();
                                    $wire.$set('form.encounter.referralNumber', referralNumber, false);
                                    $wire.$set('selectedReferralUuid', null, false);
                                    $wire.$set('preparedElectronicReferralUuid', null, false);
                                    showReferrals = true;
                                "
                            />

                            <label for="requisitionNumber" class="label">
                                {{ __('encounters.referral_number') }}
                            </label>

                            <div class="absolute inset-y-0 end-0 flex items-center">
                                <button
                                    type="button"
                                    @click="
                                        referralNumber = '';
                                        $wire.$set('form.encounter.referralNumber', '', false);
                                        $wire.$set('selectedReferralUuid', null, false);
                                        $wire.$set('preparedElectronicReferralUuid', null, false);
                                        showReferrals = true;
                                    "
                                    class="text-gray-400 hover:text-gray-600"
                                >
                                    @icon('close', 'w-4 h-4')
                                </button>
                            </div>

                            @if ($referralsLoaded)
                                <div
                                    x-show="showReferrals"
                                    x-cloak
                                    class="absolute top-full left-0 z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
                                >
                                    <template x-for="referral in filteredReferrals" :key="referral.id">
                                        <button
                                            type="button"
                                            @click="selectReferral(referral)"
                                            class="w-full rounded-md px-3 py-2 text-left hover:bg-gray-100 dark:hover:bg-gray-700"
                                        >
                                            <div
                                                class="font-medium text-gray-900 dark:text-white"
                                                x-text="referral.requisition"
                                            ></div>

                                            <div
                                                class="text-sm text-gray-500 dark:text-gray-400"
                                                x-text="referral.category"
                                            ></div>
                                        </button>
                                    </template>

                                    <div
                                        x-show="filteredReferrals.length === 0"
                                        class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400"
                                    >
                                        {{ __('encounters.messages.referral_not_found') }}
                                    </div>
                                </div>
                            @endif
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
                                <input
                                    wire:model="form.encounter.paperReferral.requisition"
                                    type="text"
                                    id="paperReferralNumber"
                                    class="input !pr-7 peer @error('form.encounter.paperReferral.requisition') input-error @enderror"
                                    placeholder=" "
                                />
                                <label for="paperReferralNumber" class="label">
                                    {{ __('encounters.referral_number') }}
                                </label>
                                <div class="absolute inset-y-0 end-0 flex items-center">
                                    <button
                                        type="button"
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
                                <input
                                    wire:model="form.encounter.paperReferral.requesterEmployeeName"
                                    type="text"
                                    id="paperReferralAuthor"
                                    class="input !pr-7 peer @error('form.encounter.paperReferral.requesterEmployeeName') input-error @enderror"
                                    placeholder=" "
                                    required
                                />
                                <label for="paperReferralAuthor" class="label required">
                                    {{ __('encounters.paper_referral_author') }}
                                </label>
                                <div class="absolute inset-y-0 end-0 flex items-center">
                                    <button
                                        type="button"
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
                                <input
                                    wire:model="form.encounter.paperReferral.requesterLegalEntityEdrpou"
                                    type="text"
                                    id="paperReferralEdrpou"
                                    class="input !pr-7 peer @error('form.encounter.paperReferral.requesterLegalEntityEdrpou') input-error @enderror"
                                    placeholder=" "
                                    required
                                />
                                <label for="paperReferralEdrpou" class="label required">
                                    {{ __('encounters.paper_referral_edrpou_short') }}
                                </label>
                                <div class="absolute inset-y-0 end-0 flex items-center">
                                    <button
                                        type="button"
                                        @click="
                                            $wire.set('form.encounter.paperReferral.requesterLegalEntityEdrpou', '')
                                        "
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
                                <input
                                    wire:model="form.encounter.paperReferral.requesterLegalEntityName"
                                    type="text"
                                    id="paperReferralInstitutionName"
                                    class="input !pr-7 peer @error('form.encounter.paperReferral.requesterLegalEntityName') input-error @enderror"
                                    placeholder=" "
                                />
                                <label for="paperReferralInstitutionName" class="label">
                                    {{ __('encounters.paper_referral_institution_short') }}
                                </label>
                                <div class="absolute inset-y-0 end-0 flex items-center">
                                    <button
                                        type="button"
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
                                <input
                                    wire:model="form.encounter.paperReferral.serviceRequestDate"
                                    type="text"
                                    id="paperReferralDate"
                                    class="datepicker-input with-leading-icon input peer @error('form.encounter.paperReferral.serviceRequestDate') input-error @enderror"
                                    placeholder=" "
                                    autocomplete="off"
                                    required
                                />
                                <label for="paperReferralDate" class="wrapped-label required">
                                    {{ __('encounters.paper_referral_date') }}
                                </label>
                            </div>
                            @error('form.encounter.paperReferral.serviceRequestDate')
                                <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="form-group group">
                            <div class="relative">
                                <input
                                    wire:model="form.encounter.paperReferral.note"
                                    type="text"
                                    id="paperReferralNotes"
                                    class="input !pr-7 peer @error('form.encounter.paperReferral.note') input-error @enderror"
                                    placeholder=" "
                                />
                                <label for="paperReferralNotes" class="label">
                                    {{ __('encounters.paper_referral_notes') }}
                                </label>
                                <div class="absolute inset-y-0 end-0 flex items-center">
                                    <button
                                        type="button"
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

        <div class="mt-8">
            <h3 class="mb-4 text-lg font-bold text-gray-900 dark:text-gray-100">{{ __('encounters.incoming_referral') }} (Fake Data)</h3>
            <div class="overflow-x-auto">
                <table class="table-input w-inherit">
                    <thead class="thead-input">
                        <tr>
                            <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                            <th scope="col" class="th-input">{{ __('encounters.referral_number') }}</th>
                            <th scope="col" class="th-input">{{ __('encounters.service') }}</th>
                            <th scope="col" class="th-input text-center">{{ __('forms.status.label') }}</th>
                            <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-gray-200 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/40">
                            <td class="td-input text-[14px] text-gray-900 dark:text-gray-300">10.10.2026</td>
                            <td class="td-input text-[14px] text-gray-900 dark:text-gray-300">1234-5678-9012-3456</td>
                            <td class="td-input text-[14px] text-gray-900 dark:text-white">Cardiologist Consultation</td>
                            <td class="td-input text-center"><span class="inline-flex items-center text-sm font-medium text-green-600 dark:text-green-400">Active</span></td>
                            <td class="td-input text-center">
                                <button type="button" class="inline-flex cursor-pointer items-center justify-center text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">View</button>
                            </td>
                        </tr>
                        <tr class="border-b border-gray-200 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/40">
                            <td class="td-input text-[14px] text-gray-900 dark:text-gray-300">12.10.2026</td>
                            <td class="td-input text-[14px] text-gray-900 dark:text-gray-300">9876-5432-1098-7654</td>
                            <td class="td-input text-[14px] text-gray-900 dark:text-white">Ultrasound</td>
                            <td class="td-input text-center"><span class="inline-flex items-center text-sm font-medium text-gray-500">Redeemed</span></td>
                            <td class="td-input text-center">
                                <button type="button" class="inline-flex cursor-pointer items-center justify-center text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">View</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
