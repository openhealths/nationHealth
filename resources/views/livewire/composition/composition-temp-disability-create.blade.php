@use('App\Livewire\Composition\CompositionTempDisabilityCreate', 'Wizard')
@use('App\Enums\Composition\CompositionCategory')

<x-layouts.patient
    :showLegacyMessages="false"
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :title="__('compositions.create_temp_disability.title')"
>
    <x-slot name="headerActions"></x-slot>

    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full max-w-screen-xl space-y-8">
            @php
                $encounterOptions = $step >= Wizard::STEP_AWAITING_JOB || ($selectedEncounter !== null && !$showEncounterPicker)
                    ? collect(array_filter([$selectedEncounter]))
                    : $this->availableEncounters
                        ->sortByDesc(fn (array $encounter): bool => $encounter['uuid'] === $form->encounterUuid)
                        ->values();
            @endphp
            <fieldset
                class="fieldset-card p-6 sm:p-8"
                x-data="{ visibleEncounterCount: 3 }"
                wire:key="composition-encounter-options-{{ $showEncounterPicker ? 'picker' : ($form->encounterUuid ?: 'editing') }}"
                @disabled($step >= Wizard::STEP_AWAITING_JOB)
            >
                <legend class="legend">
                    {{ __('compositions.create_temp_disability.steps.encounter') }}
                </legend>
                <div class="mt-6">
                    <div class="mb-4 font-semibold text-gray-900 dark:text-gray-100">
                        {{ __($selectedEncounter !== null && !$showEncounterPicker ? 'compositions.wizard.encounter_selected_hint' : 'compositions.create_temp_disability.encounter_hint') }}
                    </div>

                    <div class="space-y-4">
                        @forelse($encounterOptions as $encounter)
                            <div
                                @class(['record-inner-card', 'ring-2 ring-blue-500' => $form->encounterUuid === $encounter['uuid']])
                                wire:key="encounter-{{ $encounter['uuid'] }}"
                                x-show="{{ $loop->index }} < visibleEncounterCount"
                                x-cloak
                            >
                                <div class="record-inner-header">
                                    <div class="record-inner-column flex-1">
                                        <div class="record-inner-label">
                                            {{ __('compositions.columns.date') }}
                                        </div>
                                        <div class="record-inner-value text-[15px] font-semibold">
                                            {{
                                                data_get($encounter, 'period.start')
                                                ? \Carbon\CarbonImmutable::parse(data_get($encounter, 'period.start'))->format(config('app.date_format'))
                                                : '-'
                                            }}
                                        </div>
                                    </div>

                                    <div class="record-inner-column flex-1">
                                        <div class="record-inner-label">
                                            {{ __('compositions.create_temp_disability.encounter_class') }}
                                        </div>
                                        <div class="record-inner-value text-[15px] font-semibold">
                                            {{ $this->dictionaryLabel($encounter, 'class') }}
                                        </div>
                                    </div>

                                    <div
                                        class="flex shrink-0 items-center justify-end border-t border-gray-200 p-4 md:border-t-0 md:border-l dark:border-gray-700"
                                    >
                                        <button
                                            type="button"
                                            wire:click="selectEncounter('{{ $encounter['uuid'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="selectEncounter"
                                            @disabled($form->encounterUuid === $encounter['uuid'])
                                            class="button-primary px-4 py-2 text-sm whitespace-nowrap"
                                        >
                                            {{ __($form->encounterUuid === $encounter['uuid'] ? 'compositions.wizard.selected' : 'forms.select') }}
                                        </button>
                                    </div>
                                </div>

                                <div class="record-inner-body">
                                    <div class="record-inner-id-col">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                ID
                                            </div>
                                            <div class="record-inner-id-value">
                                                {{ $encounter['uuid'] }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <x-nothing-found :description="__('compositions.create_temp_disability.no_encounters')" />
                        @endforelse
                    </div>
                    @if($encounterOptions->count() > 3)
                        <button
                            type="button"
                            @click.prevent="visibleEncounterCount += 3"
                            x-show="visibleEncounterCount < {{ $encounterOptions->count() }}"
                            x-cloak
                            class="button-outline-primary mt-4"
                        >
                            {{ __('general.show_more') }}
                        </button>
                    @endif
                    @if($selectedEncounter !== null && $step < Wizard::STEP_AWAITING_JOB)
                        <button type="button" wire:click="$toggle('showEncounterPicker')" class="button-minor mt-4 px-4 py-2 text-sm">
                            {{ __($showEncounterPicker ? 'compositions.wizard.keep_encounter' : 'compositions.wizard.change_encounter') }}
                        </button>
                    @endif
                    @error('form.encounterUuid')
                        <p class="text-error">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </fieldset>
            <fieldset id="composition-auth-method" class="fieldset-card p-6 sm:p-8" @disabled($step < Wizard::STEP_AUTH_METHOD || $step >= Wizard::STEP_AWAITING_JOB)>
                <legend class="legend">
                    {{ __('compositions.create_temp_disability.steps.auth_method') }}
                </legend>
                <div class="mt-6">
                    <div class="mb-4 font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('compositions.create_temp_disability.auth_method_hint') }}
                    </div>

                    <div class="space-y-3">
                        @foreach($authMethods as $method)
                            <div
                                @class(['record-inner-card', 'ring-2 ring-blue-500' => $form->informWithUuid === ($method['uuid'] ?? $method['id'])])
                                wire:key="auth-{{ $method['uuid'] ?? $method['id'] }}"
                            >
                                <div class="record-inner-header">
                                    <div class="record-inner-column flex-1">
                                        <div class="record-inner-label">
                                            {{ __('compositions.create_temp_disability.auth_method_type') }}
                                        </div>
                                        <div class="record-inner-value text-[15px] font-semibold">
                                            {{ __('patients.authentication_method.' . strtolower($method['type'])) }}
                                        </div>
                                    </div>

                                    <div class="record-inner-column flex-1">
                                        <div class="record-inner-label">
                                            {{ __('compositions.create_temp_disability.auth_method_alias') }}
                                        </div>
                                        <div class="record-inner-value text-[15px] font-semibold">
                                            {{ $method['alias'] ?? '-' }}
                                        </div>
                                    </div>

                                    <div class="record-inner-column flex-1">
                                        <div class="record-inner-label">
                                            {{ __('compositions.create_temp_disability.auth_method_phone') }}
                                        </div>
                                        <div class="record-inner-value text-[15px] font-semibold">
                                            {{
                                                $method['phone_number']
                                                ?? data_get($method, 'confidant_person.phones.0.number')
                                                ?? '-'
                                            }}
                                        </div>
                                    </div>

                                    <div
                                        class="flex shrink-0 items-center justify-end border-t border-gray-200 p-3 md:border-t-0 md:border-l dark:border-gray-700"
                                    >
                                        <button
                                            type="button"
                                            wire:click="selectAuthMethod('{{ $method['uuid'] ?? $method['id'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="selectAuthMethod"
                                            @disabled($form->informWithUuid === ($method['uuid'] ?? $method['id']))
                                            class="button-primary px-3 py-1.5 text-sm whitespace-nowrap"
                                        >
                                            {{ __($form->informWithUuid === ($method['uuid'] ?? $method['id']) ? 'compositions.wizard.selected' : 'forms.select') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Warn only when eHealth returned no usable method (TV 3.8.2.4.4). --}}
                    @if($authMethods === [])
                        <div class="status-alert-yellow mt-6">
                            <p class="text-sm font-medium">
                                {{ __('compositions.create_temp_disability.no_auth_method_warning') }}
                            </p>
                        </div>
                    @endif

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="skipAuthMethod"
                            @class(['button-minor px-5 py-2.5 text-sm', 'ring-2 ring-blue-500' => $acknowledgedMissingAuthMethod])
                        >
                            {{ __('compositions.create_temp_disability.skip_auth_method') }}
                        </button>
                    </div>
                    @error('form.informWithUuid')
                        <p class="text-error">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </fieldset>
            <fieldset id="composition-details" class="fieldset-card p-6 sm:p-8" @disabled($step < Wizard::STEP_DETAILS || $step >= Wizard::STEP_AWAITING_JOB)>
                <legend class="legend">
                    {{ __('compositions.create_temp_disability.steps.details') }}
                </legend>
                <div class="mt-6">
                    @error('form.guard')
                        <p class="text-error mb-6">
                            {{ $message }}
                        </p>
                    @enderror
                    <div class="form-row-3 mb-6">
                        <div class="form-group group">
                            <select
                                wire:model.live="form.category"
                                name="form.category"
                                id="form.category"
                                class="input-select peer w-full"
                            >
                                @foreach($this->categoryOptions() as $code => $description)
                                    <option value="{{ $code }}">
                                        {{ $description }}
                                    </option>
                                @endforeach
                            </select>
                            <label for="form.category" class="label">
                                {{ __('compositions.columns.category') }} *
                            </label>
                            @error('form.category')
                                <p class="text-error">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div class="form-group group">
                            <div class="datepicker-wrapper">
                                <input
                                    wire:model.live="form.eventPeriodStart"
                                    type="text"
                                    name="form.eventPeriodStart"
                                    id="form.eventPeriodStart"
                                    class="datepicker-input with-leading-icon input peer w-full @error('form.eventPeriodStart') input-error @enderror"
                                    placeholder=" "
                                    autocomplete="off"
                                    datepicker-format="{{ frontendDateFormat() }}"
                                />
                                <label for="form.eventPeriodStart" class="wrapped-label">
                                    {{ __('compositions.columns.period_start') }} *
                                </label>
                            </div>
                            @error('form.eventPeriodStart')
                                <p class="text-error">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div class="form-group group">
                            @if($this->pregnancyPeriodOptions)
                                {{-- TV 3.8.2.5.4 — a pregnancy conclusion may only use the configured durations. --}}
                                <select
                                    wire:model="form.eventPeriodEnd"
                                    name="form.eventPeriodEnd"
                                    id="form.eventPeriodEnd"
                                    class="input-select peer w-full"
                                >
                                    <option value="">
                                        {{ __('forms.select') }} ...
                                    </option>
                                    @foreach($this->pregnancyPeriodOptions as $days => $endDate)
                                        <option value="{{ $endDate }}">
                                            {{ $endDate }} ({{ $days }} {{ __('compositions.create_temp_disability.days') }})
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <div class="datepicker-wrapper">
                                    <input
                                        wire:model="form.eventPeriodEnd"
                                        type="text"
                                        name="form.eventPeriodEnd"
                                        id="form.eventPeriodEnd"
                                        class="datepicker-input with-leading-icon input peer w-full @error('form.eventPeriodEnd') input-error @enderror"
                                        placeholder=" "
                                        autocomplete="off"
                                        datepicker-format="{{ frontendDateFormat() }}"
                                    />
                                </div>
                            @endif
                            <label for="form.eventPeriodEnd" class="label">
                                {{ __('compositions.columns.period_end') }} *
                            </label>
                            @error('form.eventPeriodEnd')
                                <p class="text-error">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-6 grid grid-cols-1 gap-3 md:grid-cols-2">
                        @foreach([
                                                                                                                                                                        'form.isAccident' => __('compositions.detail.flags.is_accident'),
                                                                                                                                                                        'form.isIntoxicated' => __('compositions.detail.flags.is_intoxicated'),
                                                                                                                                                                        'form.isForeignTreatment' => __('compositions.detail.flags.is_foreign_treatment'),
                                                                                                                                                                        'form.isForceRenew' => __('compositions.detail.flags.is_force_renew'),
                                                                                                                                                                    ] as $model => $label)
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                <input type="checkbox" wire:model="{{ $model }}" class="default-checkbox h-5 w-5" />
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>

                    <div class="form-row-3 mb-6">
                        <div class="form-group group">
                            <select
                                wire:model.live="form.treatmentViolation"
                                name="form.treatmentViolation"
                                id="form.treatmentViolation"
                                class="input-select peer w-full"
                            >
                                <option value="">
                                    {{ __('forms.select') }} ...
                                </option>
                                @foreach($this->dictionaries[\App\Enums\Composition\TreatmentViolation::DICTIONARY] ?? [] as $code => $description)
                                    <option value="{{ $code }}">
                                        {{ $description }}
                                    </option>
                                @endforeach
                            </select>
                            <label for="form.treatmentViolation" class="label">
                                {{ __('compositions.detail.treatment_violation') }}
                            </label>
                            @error('form.treatmentViolation')
                                <p class="text-error">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        @if($form->treatmentViolation)
                            <div class="form-group group">
                                <div class="datepicker-wrapper">
                                    <input
                                        wire:model="form.treatmentViolationDate"
                                        type="text"
                                        name="form.treatmentViolationDate"
                                        id="form.treatmentViolationDate"
                                        class="datepicker-input with-leading-icon input peer w-full @error('form.treatmentViolationDate') input-error @enderror"
                                        placeholder=" "
                                        autocomplete="off"
                                        datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                        datepicker-format="{{ frontendDateFormat() }}"
                                    />
                                    <label for="form.treatmentViolationDate" class="wrapped-label">
                                        {{ __('compositions.create_temp_disability.violation_date') }} *
                                    </label>
                                </div>
                                @error('form.treatmentViolationDate')
                                    <p class="text-error">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>
                        @endif
                    </div>

                    @if(!$form->informWithUuid)
                        <div class="status-alert-yellow mb-6">
                            <p class="text-sm font-medium">
                                {{ __('compositions.create_temp_disability.no_auth_method_warning') }}
                            </p>
                        </div>
                    @endif

                    @if($this->requiresUnidentifiedErlnWarning)
                        {{-- TV 3.8.2.6.1 — an unidentified patient gets no ERLN record at all. --}}
                        <div class="status-alert-yellow mb-6 flex-col items-start">
                            <p class="text-sm font-medium">
                                {{ __('compositions.create_temp_disability.unidentified_erln_warning') }}
                            </p>
                            <button
                                type="button"
                                wire:click="acknowledgeUnidentifiedErln"
                                class="button-minor mt-3 px-5 py-2 text-sm"
                            >
                                {{ __('compositions.create_temp_disability.acknowledge') }}
                            </button>
                        </div>
                    @endif
                </div>
            </fieldset>
            @if($step === Wizard::STEP_DETAILS)
                <div class="flex flex-wrap gap-3">
                    <button
                        type="button"
                        wire:click="submitComposition"
                        wire:loading.attr="disabled"
                        @disabled($this->requiresUnidentifiedErlnWarning)
                        class="button-primary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('forms.create') }}
                    </button>
                </div>
            @endif
            @if($step === Wizard::STEP_AWAITING_JOB)
                <fieldset class="fieldset-card p-6 sm:p-8">
                    <legend class="legend">
                        {{ __('compositions.create_temp_disability.steps.processing') }}
                    </legend>
                    <div class="mt-6">
                        <div
                            @if(!$asyncJobErrors && $asyncJobStatus !== \App\Enums\Composition\CompositionJobStatus::FAILED->value)
                                wire:poll.3s="pollAsyncJob"
                            @endif
                            class="max-w-2xl"
                        >
                            @if($asyncJobErrors)
                                {{-- TV 3.8.2.7.1 — creation cannot continue, so only the errors are shown. --}}
                                <div class="status-alert-red mb-4 flex-col items-start">
                                    <p class="mb-2 text-sm font-semibold">
                                        {{ $asyncJobStatus === \App\Enums\Composition\CompositionJobStatus::FAILED->value ? __('compositions.create_temp_disability.job_failed') : __('compositions.async.result_unavailable') }}
                                    </p>
                                    <ul class="list-inside list-disc text-sm">
                                        @foreach($asyncJobErrors as $error)
                                            <li>
                                                {{ $error }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>

                                @if($asyncJobStatus === \App\Enums\Composition\CompositionJobStatus::FAILED->value)
                                    <button
                                        type="button"
                                        wire:click="restart"
                                        class="button-primary px-5 py-2.5 text-sm"
                                    >
                                        {{ __('compositions.create_temp_disability.restart') }}
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="pollAsyncJob"
                                        wire:loading.attr="disabled"
                                        class="button-primary px-5 py-2.5 text-sm"
                                    >
                                        {{ __('compositions.async.retry_read') }}
                                    </button>
                                @endif
                            @else
                                <div class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                                    @icon('refresh', 'w-5 h-5 animate-spin text-gray-500')
                                    {{ $asyncJobStatus === \App\Enums\Composition\CompositionJobStatus::DONE->value ? __('compositions.async.loading_result') : __('compositions.create_temp_disability.processing', ['status' => \App\Enums\Composition\CompositionJobStatus::tryFrom($asyncJobStatus)?->label() ?? $asyncJobStatus]) }}
                                </div>
                            @endif
                        </div>
                    </div>
                </fieldset>
            @endif
            @if($step === Wizard::STEP_REVIEW)
                <fieldset class="fieldset-card p-6 sm:p-8">
                    <legend class="legend">
                        {{ __('compositions.create_temp_disability.steps.review') }}
                    </legend>
                    <div class="mt-6">
                        @php
                        $reviewStatus = \App\Enums\Composition\CompositionStatus::fromEHealth(data_get($compositionDetail, 'status'));
                    @endphp

                        @include('livewire.composition.parts.details-summary', ['detail' => $compositionDetail])

                        <div class="mt-6 flex flex-wrap gap-2">
                            <button
                                type="button"
                                wire:click="loadPrintForm"
                                class="button-primary-outline px-5 py-2.5 text-sm"
                            >
                                {{ __('compositions.actions.print') }}
                            </button>
                            @if($reviewStatus?->isSignable())
                                <button
                                    type="button"
                                    wire:click="openSigningModal"
                                    class="button-primary px-5 py-2.5 text-sm"
                                >
                                    {{ __('forms.sign_with_KEP') }}
                                </button>
                            @endif
                            <button type="button" wire:click="restart" class="button-minor px-5 py-2.5 text-sm">
                                {{ __('compositions.create_temp_disability.restart') }}
                            </button>
                        </div>
                    </div>
                </fieldset>
            @endif
        </div>
    </div>

    {{-- Signing serves both submitting the conclusion and signing it afterwards. --}}
    <x-signature-modal method="sign" />

    @include('livewire.composition.parts.print-modal', [
        'modalId' => 'modal-td-print',
        'iframeId' => 'td-print-iframe',
    ])

    <x-forms.loading />
</x-layouts.patient>
