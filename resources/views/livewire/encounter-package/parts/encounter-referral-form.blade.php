@use('App\Enums\JobStatus')
@use('Illuminate\Support\Facades\Lang')
@use('App\Services\MedicalEvents\InformWith')
<div
    x-data="{ openServiceCatalog: false }"
    @encounter-referral-service-catalog-close.window="openServiceCatalog = false"
>
    @php
        $submissionPending = ($encounterReferralSubmission['status'] ?? null) === JobStatus::PENDING->value;
        $preparingSignature = $prepareReferralForSigning ?? false;
    @endphp
    @if ($submissionPending)
        <div class="mb-6 rounded-lg border border-gray-200 p-4 dark:border-gray-600" role="status">
            <p>{{ __('referrals.submission_pending') }}</p>
            <button
                type="button"
                class="button-minor mt-3"
                wire:click="checkEncounterReferralSubmission(true)"
                wire:loading.attr="disabled"
            >
                {{ __('referrals.submission_check') }}
            </button>
        </div>
    @endif
    <form wire:submit.prevent="validateEncounterReferral" class="space-y-6">
        @if (($personId ?? null) !== null && !($referralReadonly ?? false))
            <fieldset @disabled($submissionPending || $preparingSignature) class="fieldset-card p-6 sm:p-8">
                <legend class="legend">
                    {{ __('referrals.plan_link.title') }}
                </legend>
                <div class="form-row-2 mt-6">
                    <div class="form-group group">
                        <label for="referralPlanSearch" class="label">
                            {{ __('forms.search') }}
                        </label>
                        <input
                            id="referralPlanSearch"
                            type="search"
                            class="input peer w-full"
                            wire:model.live.debounce.300ms="encounterReferralPlanSearch"
                        />
                    </div>
                    <div class="form-group group">
                        <label for="referralPlan" class="label">
                            {{ __('referrals.plan_link.plan') }}
                        </label>
                        <select
                            id="referralPlan"
                            class="input-select peer w-full"
                            wire:model.live="encounterReferralForm.care_plan_id"
                        >
                            <option value="0">{{ __('referrals.plan_link.without_plan') }}</option>
                            @foreach ($this->encounterReferralPlanChoices as $plan)
                                <option value="{{ $plan['id'] }}">{{ $plan['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @php
                    $selectedPlan = collect($this->encounterReferralPlanChoices)->firstWhere('id', (int) ($encounterReferralForm['care_plan_id'] ?? 0));
                    $selectedActivity = collect($this->encounterReferralActivityChoices)->firstWhere('id', (int) ($encounterReferralForm['care_plan_activity_id'] ?? 0));
                @endphp
                @if ($selectedPlan !== null && $selectedPlan['approvalRequired'])
                    <div class="mt-4">
                        <p class="mb-4">
                            {{ __('referrals.plan_link.obtain_access') }}
                        </p>
                        @livewire('care-plan.care-plan-approvals', $this->encounterReferralApprovalContext, key('encounter-referral-plan-approval-'.$selectedPlan['id'].'-'.$this->encounterReferralApprovalContext['actingEmployeeUuid']))
                    </div>
                @elseif ($selectedPlan !== null)
                    <div class="form-group group mt-6">
                        <label for="referralPlanActivity" class="label required">
                            {{ __('referrals.plan_link.activity') }}
                        </label>
                        <select
                            id="referralPlanActivity"
                            class="input-select peer w-full"
                            wire:model.live="encounterReferralForm.care_plan_activity_id"
                        >
                            <option value="0">{{ __('forms.select') }}</option>
                            @foreach ($this->encounterReferralActivityChoices as $activity)
                                <option value="{{ $activity['id'] }}">{{ $activity['description'] ?: $activity['uuid'] }}</option>
                            @endforeach
                        </select>
                        @error('encounterReferralForm.care_plan_activity_id')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                        @if ($selectedActivity !== null)
                            <p class="mt-2 text-sm" role="status">
                                {{ __('care-plan.remaining_quantity') }}:
                                {{ data_get($selectedActivity, 'remainingQuantity.value') ?? __('referrals.plan_link.unknown_remaining') }}
                                {{ data_get($selectedActivity, 'remainingQuantity.unit') ?? data_get($selectedActivity, 'remainingQuantity.code') }}
                            </p>
                            <p class="mt-2 text-sm" role="status">
                                {{ __('referrals.plan_link.activity_capacity') }}:
                                {{ data_get($selectedActivity, 'serviceCreationCapacity.remaining') }}
                            </p>
                        @endif
                    </div>
                @endif
            </fieldset>
        @endif
        <fieldset
            @disabled($submissionPending || $preparingSignature || ($referralReadonly ?? false))
            class="fieldset-card p-6 sm:p-8"
        >
            <legend class="legend">{{ __('encounters.service') }}</legend>
            <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">
                <div class="form-group group md:col-span-2">
                    <label for="encounterReferralServiceSearch" class="label required">
                        {{ __('encounters.service') }}
                    </label>
                    <div class="flex items-center gap-4">
                        <input
                            type="text"
                            id="encounterReferralServiceSearch"
                            class="input peer w-full"
                            placeholder="{{ __('encounters.referral_drawer.service_search_placeholder') }}"
                            wire:model="encounterReferralServiceSearch"
                            wire:keydown.enter.prevent="searchEncounterReferralServices"
                        />

                        <button
                            type="button"
                            class="button-primary shrink-0"
                            wire:click="searchEncounterReferralServices"
                        >
                            {{ __('forms.search') }}
                        </button>

                        <button
                            type="button"
                            @click.prevent="openServiceCatalog = true"
                            class="inline-flex shrink-0 cursor-pointer items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
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
                    </div>
                    @error('encounterReferralForm.service_id')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                @if ($encounterReferralServiceResults !== [])
                    <div class="max-h-64 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3 md:col-span-2 dark:border-gray-600 dark:bg-gray-900/40">
                        @foreach ($encounterReferralServiceResults as $service)
                            <button
                                type="button"
                                wire:click="selectEncounterReferralService('{{ $service['id'] }}')"
                                class="w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-left text-sm transition-colors hover:border-blue-300 hover:bg-blue-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:border-blue-400 dark:hover:bg-gray-700"
                            >
                                <div class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ ($service['code'] ?? '') }} — {{ $service['name'] ?? __('encounters.service') }}
                                </div>
                                @php
                                    $serviceCategoryKey = 'encounters.referral_category.'.strtolower((string) ($service['category'] ?? ''));
                                @endphp

                                @if (Lang::has($serviceCategoryKey))
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ __($serviceCategoryKey) }}
                                    </div>

                                @endif
                            </button>

                        @endforeach
                    </div>

                @elseif ($encounterReferralHasSearched)
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center text-sm text-gray-500 md:col-span-2 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                        {{ __('encounters.referral_drawer.services_not_found') }}
                    </div>

                @endif

                <div class="form-group group md:col-span-2">
                    <label
                        for="encounterReferralSelectedService"
                        class="label"
                    >{{ __('encounters.referral_drawer.selected_service') }}</label>
                    <input
                        type="text"
                        id="encounterReferralSelectedService"
                        class="input peer w-full"
                        value="{{ !empty($encounterReferralSelectedService) ? (($encounterReferralSelectedService['code'] ?? '') . ' — ' . ($encounterReferralSelectedService['name'] ?? '')) : '' }}"
                        placeholder="{{ __('encounters.select_service') }}"
                        readonly
                    />
                </div>
                <div class="form-group group">
                    <label for="encounterReferralCategory" class="label required">{{ __('forms.category') }}</label>
                    <select
                        id="encounterReferralCategory"
                        class="input-select peer w-full"
                        wire:model.live="encounterReferralForm.category"
                        @disabled($encounterReferralIsTransfer)
                    >
                        @if ($encounterReferralServiceType === 'service_group')
                            <option value="">{{ __('forms.select') }}</option>
                        @endif
                        @foreach (__('encounters.referral_category') as $code => $label)
                            <option value="{{ match ($code) { 'transfer' => 'transfer_of_care', 'counseling' => 'counselling', default => $code } }}">
                                {{ $label }}
                            </option>

                        @endforeach
                    </select>
                </div>

                @if (!$encounterReferralIsTransfer)
                    <div class="form-group group md:col-span-2">
                        <label
                            for="referralDestinationUuid"
                            class="label"
                        >{{ __('encounters.referral_destination') }}</label>
                        <input
                            id="referralDestinationUuid"
                            type="text"
                            wire:model="encounterReferralDestinationUuid"
                            class="input w-full"
                            placeholder="{{ __('referrals.destination_identifier') }}"
                        />
                        @error('encounterReferralDestinationUuid')
                            <p class="text-error text-xs">{{ $message }}</p>
                        @enderror
                        <button type="button" class="button-minor mt-2" wire:click="selectEncounterReferralDestination">
                            {{ __('forms.select') }}
                        </button>
                        @if ($encounterReferralPerformerName !== '')
                            <p class="mt-2 text-sm">{{ $encounterReferralPerformerName }}</p>
                        @endif
                    </div>
                @endif
                @if ($encounterReferralIsTransfer)
                    <div class="form-group group md:col-span-2">
                        <label
                            for="encounterReferralPerformer"
                            class="label required"
                        >{{ __('encounters.referral_destination') }}</label>
                        <input
                            type="text"
                            id="encounterReferralPerformer"
                            class="input peer w-full"
                            value="{{ $encounterReferralPerformerName }}"
                            readonly
                        />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('encounters.referral_destination_from_discharge') }}
                        </p>
                    </div>
                    <div class="form-group group">
                        <label
                            for="encounterReferralLocation"
                            class="label required"
                        >{{ __('encounters.referral_performer_division') }}</label>
                        <select
                            id="encounterReferralLocation"
                            class="input-select peer w-full"
                            wire:model="encounterReferralForm.location_reference"
                        >
                            <option value="">{{ __('encounters.referral_select_division') }}</option>

                            @foreach ($encounterReferralDivisions as $division)
                                <option value="{{ $division['id'] }}">{{ $division['name'] }}</option>

                            @endforeach
                        </select>
                    </div>
                @endif
                @if (!$encounterReferralIsTransfer && $encounterReferralDivisions !== [])
                    <div class="form-group group">
                        <label
                            for="referralDestinationDivision"
                            class="label"
                        >{{ __('encounters.referral_performer_division') }}</label>
                        <select
                            id="referralDestinationDivision"
                            wire:model="encounterReferralForm.location_reference"
                            class="input-select w-full"
                        >
                            <option value="">{{ __('encounters.referral_select_division') }}</option>
                            @foreach ($encounterReferralDivisions as $division)
                                <option value="{{ $division['id'] }}">{{ $division['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($encounterReferralIsTransfer || ($encounterReferralForm['category'] ?? '') === 'hospitalization')
                    <div class="form-group group">
                        <label
                            for="encounterReferralPerformerType"
                            class="label required"
                        >{{ __('encounters.referral_performer_speciality') }}</label>
                        <select
                            id="encounterReferralPerformerType"
                            class="input-select peer w-full"
                            wire:model="encounterReferralForm.performer_type"
                        >
                            <option value="">{{ __('encounters.referral_not_selected') }}</option>

                            @foreach ($encounterReferralSpecialities as $code => $label)
                                <option value="{{ match ($code) { 'transfer' => 'transfer_of_care', 'counseling' => 'counselling', default => $code } }}">
                                    {{ $label }}
                                </option>

                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('encounters.referral_recipient_speciality_help') }}
                        </p>
                    </div>

                @endif

                <div class="form-group group">
                    <label for="encounterReferralProgram" class="label">{{ __('care-plan.program') }}</label>
                    <select
                        id="encounterReferralProgram"
                        class="input-select peer w-full"
                        wire:model="encounterReferralForm.program_id"
                    >
                        <option value="">{{ __('encounters.referral_drawer.not_selected') }}</option>

                        @foreach ($encounterReferralPrograms as $program)
                            <option value="{{ $program['id'] }}">{{ $program['name'] }}</option>

                        @endforeach
                    </select>
                </div>
            </div>
        </fieldset>

        <fieldset
            @disabled($submissionPending || $preparingSignature || ($referralReadonly ?? false))
            class="fieldset-card p-6 sm:p-8"
        >
            <legend class="legend">{{ __('encounters.referral_drawer.validity_and_quantity') }}</legend>
            <div class="mb-4 grid grid-cols-1 gap-6 md:grid-cols-3">
                @foreach (['started_at' => 'start', 'ended_at' => 'end'] as $field => $period)
                    <div class="grid grid-cols-1 gap-6">
                        <div class="form-group">
                            <div class="datepicker-wrapper" wire:key="referral-date-{{ $field }}">
                                <input
                                    type="text"
                                    id="encounterReferral-{{ $field }}"
                                    wire:model="encounterReferralForm.{{ $field }}"
                                    class="datepicker-input with-leading-icon input peer @error('encounterReferralForm.'.$field) input-error @enderror"
                                    placeholder="{{ __('referrals.period_fields.date_placeholder') }}"
                                    autocomplete="off"
                                    datepicker-autohide
                                    datepicker-format="{{ frontendDateFormat() }}"
                                    datepicker-min-date="{{ today()->format(config('app.date_format')) }}"
                                />
                                <label for="encounterReferral-{{ $field }}" class="wrapped-label required">
                                    {{ $period === 'start' ? __('forms.start_date') : __('forms.end_date') }}
                                </label>
                            </div>
                            <x-input-error for="encounterReferralForm.{{ $field }}" />
                        </div>
                        <div class="form-group">
                            <input
                                id="encounterReferral-{{ $period }}Time"
                                type="text"
                                wire:model="encounterReferralForm.{{ $period }}_time"
                                class="timepicker-uk input peer"
                                autocomplete="off"
                                placeholder="{{ __('referrals.period_fields.time_placeholder') }}"
                            />
                            <label for="encounterReferral-{{ $period }}Time" class="label required">
                                {{ __('referrals.period_fields.'.$period.'_time') }}
                            </label>
                            <x-input-error for="encounterReferralForm.{{ $period }}_time" />
                        </div>
                    </div>

                @endforeach

                <div class="form-group group">
                    <label for="encounterReferralQuantity" class="label required">{{ __('care-plan.quantity') }}</label>
                    <input
                        type="number"
                        id="encounterReferralQuantity"
                        min="0.01"
                        step="any"
                        class="input peer"
                        wire:model="encounterReferralForm.quantity"
                    />
                </div>
            </div>
            <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">
                <div class="form-group group">
                    <label
                        for="encounterReferralPriority"
                        class="label required"
                    >{{ __('encounters.priority') }}</label>
                    <select
                        id="encounterReferralPriority"
                        class="input-select peer w-full"
                        wire:model="encounterReferralForm.priority"
                    >
                        @foreach ($this->encounterReferralPriorityOptions as $code => $description)
                            <option value="{{ $code }}">{{ $description }}</option>
                        @endforeach
                    </select>
                    <x-input-error for="encounterReferralForm.priority" />
                </div>
            </div>
        </fieldset>

        <fieldset
            @disabled($submissionPending || $preparingSignature || ($referralReadonly ?? false))
            class="fieldset-card p-6 sm:p-8"
        >
            <legend class="legend">{{ __('encounters.referral_drawer.additional') }}</legend>
            <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">
                <div class="form-group group">
                    <label for="encounterReferralInformWith" class="label">{{ __('forms.auth_method') }}</label>
                    <select
                        id="encounterReferralInformWith"
                        class="input-select peer w-full"
                        wire:model="encounterReferralForm.inform_with"
                    >
                        <option value="">{{ __('encounters.referral_drawer.not_selected') }}</option>

                        @foreach ($encounterReferralAuthMethods as $method)
                            <option value="{{ InformWith::formValue($method) }}">{{ $method['label'] ?? '' }}</option>

                        @endforeach
                    </select>
                </div>
                <div class="form-group group">
                    <label
                        for="encounterReferralPatientInstruction"
                        class="label"
                    >{{ __('referrals.registry.patient_instruction') }}</label>
                    <input
                        type="text"
                        id="encounterReferralPatientInstruction"
                        class="input peer"
                        wire:model="encounterReferralForm.patient_instruction"
                    />
                </div>
            </div>
            <div class="form-group group">
                <label for="encounterReferralNote" class="label">{{ __('encounters.referral_drawer.notes') }}</label>
                <textarea
                    id="encounterReferralNote"
                    class="input peer min-h-20"
                    wire:model="encounterReferralForm.note"
                ></textarea>
            </div>
        </fieldset>

        @if ($encounterReferralWarningMessage !== '')
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                {{ $encounterReferralWarningMessage }}
            </div>

        @endif
        <div class="flex justify-start gap-3">
            <a href="{{ $encounterReturnUrl }}" class="button-minor">{{ __('forms.cancel') }}</a>
            @if (!$submissionPending && !$preparingSignature && !($referralReadonly ?? false))
                <button
                    type="button"
                    class="button-primary"
                    wire:click="saveEncounterReferralDraft"
                    wire:loading.attr="disabled"
                    wire:target="saveEncounterReferralDraft,validateEncounterReferral,signEncounterReferral,sign"
                >
                    {{ __('forms.save') }}
                </button>
                <button
                    type="submit"
                    class="button-primary"
                    wire:loading.attr="disabled"
                    wire:target="saveEncounterReferralDraft,validateEncounterReferral,signEncounterReferral,sign"
                >
                    {{ __('forms.save_and_sign') }}
                </button>
            @endif
        </div>
    </form>
    <x-dialog-drawer
        x-model="openServiceCatalog"
        x-bind:aria-hidden="! openServiceCatalog"
        onCloseClick="openServiceCatalog = false"
        maxWidth="4/5"
        overlayWidth="100%"
        :stopClickPropagation="true"
        zIndex="50"
    >
        <livewire:dictionary.service-catalog
            lazy
            :legal-entity="legalEntity()"
            :selection-mode="true"
            :request-allowed-only="true"
            :allow-group-selection="true"
            selection-event="encounter-referral-service-selected"
            :key="'encounter-referral-service-catalog'"
        />

        <div class="mt-8">
            <button type="button" @click="openServiceCatalog = false" class="button-minor">
                {{ __('forms.cancel') }}
            </button>
        </div>
    </x-dialog-drawer>
</div>
