<div id="referral-section">
    @if ($incomingReferral !== null)
        @include('livewire.encounter-package.parts.incoming-referral-card')
    @else
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
                        wire:key="encounter-electronic-referral-selector"
                        x-data="{
                            showReferrals: false,
                            referralNumber: $wire.entangle('form.encounter.referralNumber'),
                            referrals: $wire.entangle('availableReferrals'),
                            preparing: false,
                            pending: false,
                            timer: null,
                            selectedNumber: '',

                            get filteredReferrals() {
                                const value = (this.referralNumber || '').replace(/[^A-Z0-9]/gi, '').toUpperCase();

                                return this.referrals.filter(referral =>
                                    !value || referral.requisition.replace(/[^A-Z0-9]/gi, '').toUpperCase().includes(value)
                                );
                            },

                            updateNumber(value, pasted = false) {
                                const clean = value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                                this.referralNumber = clean.match(/.{1,4}/g)?.join('-') || '';
                                this.selectedNumber = '';
                                $wire.$set('selectedReferralUuid', null, false);
                                $wire.$set('preparedElectronicReferralUuid', null, false);
                                this.showReferrals = true;

                                if (pasted || clean.length === 16) {
                                    return this.resolveNumber();
                                }
                            },

                            async resolveNumber() {
                                if (this.preparing || this.pending || !this.referralNumber || this.selectedNumber === this.referralNumber) return;
                                this.preparing = true;

                                try {
                                    this.applySelectionResult(await $wire.selectElectronicReferralByNumber(this.referralNumber));
                                } finally {
                                    this.preparing = false;
                                }
                            },

                            async selectReferral(referral) {
                                if (this.preparing || this.pending) return;
                                this.referralNumber = referral.requisition;
                                this.preparing = true;

                                try {
                                    this.applySelectionResult(await $wire.selectElectronicReferral(referral.id));
                                } finally {
                                    this.preparing = false;
                                }
                            },

                            applySelectionResult(result) {
                                this.pending = result === 'pending';
                                this.selectedNumber = result === true ? this.referralNumber : '';
                                this.showReferrals = result === false;

                                if (this.pending) {
                                    this.timer = setTimeout(() => this.checkPreparation(), 3000);
                                }
                            },

                            async checkPreparation() {
                                try {
                                    this.applySelectionResult(await $wire.checkElectronicReferralPreparation());
                                } catch (error) {
                                    this.applySelectionResult(false);
                                }
                            },

                            destroy() {
                                clearTimeout(this.timer);
                            }
                        }"
                        x-init="$wire.loadAvailableReferrals()"
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
                                x-on:focus="showReferrals = true"
                                x-bind:disabled="preparing || pending"
                                x-on:input="updateNumber($el.value)"
                                x-on:paste.prevent="updateNumber($event.clipboardData.getData('text'), true)"
                                x-on:keydown.enter.prevent="resolveNumber()"
                                x-on:keydown.escape="showReferrals = false"
                            />

                            <label for="requisitionNumber" class="label">
                                {{ __('encounters.referral_number') }}
                            </label>

                            <div class="absolute inset-y-0 end-0 flex items-center">
                                <button
                                    type="button"
                                    @click="updateNumber('')"
                                    x-bind:disabled="preparing || pending"
                                    class="text-gray-400 hover:text-gray-600"
                                >
                                    @icon('close', 'w-4 h-4')
                                </button>
                            </div>

                            <div
                                x-show="showReferrals && !preparing && !pending"
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
                                            x-text="[referral.serviceName, referral.category, referral.priority, referral.status].filter(Boolean).join(' · ')"
                                        ></div>

                                        <div class="text-sm text-gray-500 dark:text-gray-400" x-text="[referral.programName, referral.period].filter(Boolean).join(' · ')"></div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400" x-text="referral.identity || ''"></div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400" x-text="'UUID: ' + referral.id"></div>
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

                        <button type="button" class="button-primary mt-2" @click="resolveNumber()" x-bind:disabled="preparing || pending" x-show="!selectedNumber" x-cloak>
                            {{ __('forms.search') }}
                        </button>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-show="preparing || pending" x-cloak>
                            {{ __('encounters.referral_loading') }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-show="selectedNumber" x-cloak>
                            {{ __('encounters.referral_selected') }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-show="!selectedNumber && !preparing && !pending" x-cloak>
                            {{ __('encounters.referral_number_hint') }}
                        </p>

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
    </div>
    @endif
</div>
