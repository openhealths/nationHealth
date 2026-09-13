@use('App\Livewire\Composition\CompositionCreate', 'Wizard')

<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :title="__('patients.composition.create_newborn.title')"
>
    <div class="shift-content mt-6 pl-4">
        <div class="w-full max-w-screen-xl">
            <ol class="mb-8 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                @foreach ([
                                                                                                                    Wizard::STEP_ENCOUNTER => __('patients.composition.create_newborn.steps.encounter'),
                                                                                                                    Wizard::STEP_AUTH_METHOD => __('patients.composition.create_newborn.steps.auth_method'),
                                                                                                                    Wizard::STEP_DETAILS => __('patients.composition.create_newborn.steps.details'),
                                                                                                                    Wizard::STEP_AWAITING_JOB => __('patients.composition.create_newborn.steps.processing'),
                                                                                                                    Wizard::STEP_REVIEW => __('patients.composition.create_newborn.steps.review'),
                                                                                                                ] as $stepNumber => $label)
                    <li @class([
                                                                                                                            'flex items-center gap-2',
                                                                                                                            'font-semibold text-gray-900 dark:text-gray-100' => $step === $stepNumber,
                                                                                                                            'text-gray-400 dark:text-gray-500' => $step !== $stepNumber,
                                                                                                                        ])>
                        <span @class([
                                                                                                                                                        'flex h-6 w-6 items-center justify-center rounded-full text-xs',
                                                                                                                                                        'bg-primary-600 text-white' => $step >= $stepNumber,
                                                                                                                                                        'bg-gray-200 text-gray-600 dark:bg-gray-700' => $step < $stepNumber,
                                                                                                                                                    ])>
                            {{ $stepNumber }}
                        </span>
                        {{ $label }}
                    </li>
                @endforeach
            </ol>

            @if ($step === Wizard::STEP_ENCOUNTER)
                <div class="mb-4 font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('patients.composition.create_newborn.encounter_hint') }}
                </div>

                @if ($this->needsMother)
                    <div class="mb-6">
                        <p class="mb-3 text-sm text-gray-700 dark:text-gray-200">
                            {{ __('patients.composition.create_newborn.identify_mother') }}
                        </p>
                        <div class="form-group group mb-4 max-w-md">
                            <input
                                wire:model.live.debounce.400ms="counterpartQuery"
                                type="text"
                                id="counterpartQuery"
                                class="input peer w-full"
                                placeholder=" "
                            />
                            <label for="counterpartQuery" class="label">
                                {{ __('patients.composition.create_newborn.mother_search') }}
                            </label>
                        </div>
                        <div class="space-y-3">
                            @forelse ($this->counterpartMatches as $match)
                                <div class="record-inner-card" wire:key="mother-{{ $match->id }}">
                                    <div class="record-inner-header">
                                        <div class="record-inner-column flex-1">
                                            <div class="record-inner-label">
                                                {{ __('patients.composition.create_newborn.mother') }}
                                            </div>
                                            <div class="record-inner-value text-[15px] font-semibold">
                                                {{ $match->fullName }}
                                            </div>
                                        </div>
                                        <div class="record-inner-action-col">
                                            <button
                                                type="button"
                                                wire:click="selectMother({{ $match->id }})"
                                                class="button-primary px-5 py-2 text-sm"
                                            >
                                                {{ __('forms.select') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                @if ($counterpartQuery)
                                    <x-nothing-found :description="__('patients.composition.create_newborn.no_mother')" />
                                @endif
                            @endforelse
                        </div>
                    </div>
                @endif

                @if ($this->needsNewborn)
                    <div class="mb-6">
                        <p class="mb-3 text-sm text-gray-700 dark:text-gray-200">
                            {{ __('patients.composition.create_newborn.identify_newborn') }}
                        </p>
                        <div class="form-group group mb-4 max-w-md">
                            <input
                                wire:model.live.debounce.400ms="counterpartQuery"
                                type="text"
                                id="newbornQuery"
                                class="input peer w-full"
                                placeholder=" "
                            />
                            <label for="newbornQuery" class="label">
                                {{ __('patients.composition.create_newborn.newborn_search') }}
                            </label>
                        </div>
                        <div class="space-y-3">
                            @forelse ($this->counterpartMatches as $match)
                                <div class="record-inner-card" wire:key="newborn-{{ $match->id }}">
                                    <div class="record-inner-header">
                                        <div class="record-inner-column flex-1">
                                            <div class="record-inner-label">
                                                {{ __('patients.composition.create_newborn.newborn') }}
                                            </div>
                                            <div class="record-inner-value text-[15px] font-semibold">
                                                {{ $match->fullName }}
                                            </div>
                                        </div>
                                        <div class="record-inner-action-col">
                                            <button
                                                type="button"
                                                wire:click="selectNewborn({{ $match->id }})"
                                                class="button-primary px-5 py-2 text-sm"
                                            >
                                                {{ __('forms.select') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                @if ($counterpartQuery)
                                    <x-nothing-found :description="__('patients.composition.create_newborn.no_newborn')" />
                                @endif
                            @endforelse
                        </div>
                    </div>
                @endif

                @if (!$this->needsNewborn)
                    @if ($this->hasExistingActiveBirthConclusion)
                        <div class="status-alert-yellow mb-6">
                            <p class="text-sm font-medium">
                                {{ __('patients.composition.create_newborn.existing_warning') }}
                            </p>
                        </div>
                    @endif

                    <div class="space-y-3">
                        @forelse ($this->availableEncounters as $encounter)
                            <div
                                class="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-4 md:flex-row md:items-center dark:border-gray-700 dark:bg-gray-800"
                                wire:key="encounter-{{ $encounter['uuid'] }}"
                            >
                                <div class="grid min-w-0 flex-1 grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div>
                                        <div class="record-inner-label">
                                            {{ __('patients.composition.columns.date') }}
                                        </div>
                                        <div class="record-inner-value text-[15px] font-semibold">
                                            {{
                                                data_get($encounter, 'period.start')
                                                ? \Carbon\CarbonImmutable::parse(data_get($encounter, 'period.start'))->format(config('app.date_format'))
                                                : '-'
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">
                                            {{ __('patients.composition.create_newborn.encounter_class') }}
                                        </div>
                                        <div class="record-inner-value text-[15px] font-semibold">
                                            {{ $this->dictionaryLabel($encounter, 'class') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">
                                            {{ __('patients.composition.columns.encounter') }}
                                        </div>
                                        <div
                                            class="record-inner-value truncate font-mono text-xs font-normal text-gray-800 dark:text-gray-300"
                                            title="{{ $encounter['uuid'] }}"
                                        >
                                            {{ $encounter['uuid'] }}
                                        </div>
                                    </div>
                                </div>
                                <div class="shrink-0 md:pl-2">
                                    <button
                                        type="button"
                                        wire:click="selectEncounter('{{ $encounter['uuid'] }}')"
                                        class="button-primary w-full px-5 py-2 text-sm md:w-auto"
                                    >
                                        {{ __('forms.select') }}
                                    </button>
                                </div>
                            </div>
                        @empty
                            <x-nothing-found :description="__('patients.composition.create_newborn.no_encounters')" />
                        @endforelse
                    </div>
                @endif
            @endif

            @if ($step === Wizard::STEP_AUTH_METHOD)
                <div class="mb-4 font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('patients.composition.create_newborn.auth_method_hint') }}
                </div>

                <div class="space-y-3">
                    @foreach ($authMethods as $method)
                        <div
                            class="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-4 md:flex-row md:items-center dark:border-gray-700 dark:bg-gray-800"
                            wire:key="auth-{{ $method['uuid'] ?? $method['id'] }}"
                        >
                            <div class="grid flex-1 grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <div class="record-inner-label">
                                        {{ __('patients.composition.create_newborn.auth_method_type') }}
                                    </div>
                                    <div class="record-inner-value text-[15px] font-semibold">
                                        {{ __('patients.authentication_method.' . strtolower($method['type'])) }}
                                    </div>
                                </div>
                                <div>
                                    <div class="record-inner-label">
                                        {{ __('patients.composition.create_newborn.auth_method_alias') }}
                                    </div>
                                    <div class="record-inner-value text-[15px] font-semibold">
                                        {{ $method['alias'] ?? '-' }}
                                    </div>
                                </div>
                                <div>
                                    <div class="record-inner-label">
                                        {{ __('patients.composition.create_newborn.auth_method_phone') }}
                                    </div>
                                    <div class="record-inner-value text-[15px] font-semibold">
                                        {{
                                            $method['phone_number']
                                            ?? data_get($method, 'confidant_person.phones.0.number')
                                            ?? '-'
                                        }}
                                    </div>
                                </div>
                            </div>
                            <div class="shrink-0 md:pl-2">
                                <button
                                    type="button"
                                    wire:click="selectAuthMethod('{{ $method['uuid'] ?? $method['id'] }}')"
                                    class="button-primary w-full px-5 py-2 text-sm md:w-auto"
                                >
                                    {{ __('forms.select') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="status-alert-yellow mt-6 flex-col items-start">
                    <p class="text-sm font-medium">
                        {{ __('patients.composition.create_newborn.no_auth_method_warning') }}
                    </p>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="skipAuthMethod" class="button-minor px-5 py-2.5 text-sm">
                        {{ __('patients.composition.create_newborn.skip_auth_method') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('step', {{ Wizard::STEP_ENCOUNTER }})"
                        class="button-primary-outline px-5 py-2.5 text-sm"
                    >
                        {{ __('forms.back') }}
                    </button>
                </div>
            @endif

            @if ($step === Wizard::STEP_DETAILS)
                @if ($this->hasExistingActiveBirthConclusion)
                    <div class="status-alert-yellow mb-6">
                        <p class="text-sm font-medium">
                            {{ __('patients.composition.create_newborn.existing_warning') }}
                        </p>
                    </div>
                @endif

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="form.category"
                            class="input peer w-full"
                            value="{{ __('patients.composition.create_newborn.live_birth') }}"
                            disabled
                        />
                        <label for="form.category" class="label">
                            {{ __('patients.composition.columns.category') }} *
                        </label>
                    </div>

                    <div class="form-group group" wire:key="newborn-birth-date-{{ $step }}">
                        <div class="datepicker-wrapper">
                            <input
                                wire:model.lazy="form.newbornBirthDate"
                                type="text"
                                name="form.newbornBirthDate"
                                id="form.newbornBirthDate"
                                class="datepicker-input with-leading-icon input peer dark:text-white @error('form.newbornBirthDate') input-error @enderror"
                                placeholder=" "
                                autocomplete="off"
                                datepicker-autohide
                                datepicker-format="{{ frontendDateFormat() }}"
                                datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                            />
                            <label for="form.newbornBirthDate" class="wrapped-label">
                                {{ __('patients.composition.fields.newborn_birth_date') }} *
                            </label>
                        </div>
                        @error('form.newbornBirthDate')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group group">
                        <select
                            wire:model="form.newbornSex"
                            name="form.newbornSex"
                            id="form.newbornSex"
                            class="input-select peer w-full"
                        >
                            @foreach ($this->sexOptions as $code => $description)
                                <option value="{{ $code }}">{{ $description }}</option>
                            @endforeach
                        </select>
                        <label for="form.newbornSex" class="label">
                            {{ __('patients.composition.fields.newborn_sex') }} *
                        </label>
                        @error('form.newbornSex')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mb-6 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div class="min-w-0">
                        <div class="record-inner-label text-[10px] uppercase">
                            {{ __('patients.composition.create_newborn.mother') }}
                        </div>
                        <div class="record-inner-value text-[14px] font-semibold">
                            {{ $motherFullName ?: $form->personUuid }}
                        </div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label text-[10px] uppercase">
                            {{ __('patients.composition.create_newborn.newborn') }}
                        </div>
                        <div class="record-inner-value text-[14px] font-semibold">
                            {{ $newbornFullName ?: $form->prepersonUuid }}
                        </div>
                    </div>
                </div>

                @if (!$form->informWithUuid)
                    <div class="status-alert-yellow mb-6 flex-col items-start">
                        <p class="text-sm font-medium">
                            {{ __('patients.composition.create_newborn.no_auth_method_warning') }}
                        </p>
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="reviewDetails" class="button-primary px-5 py-2.5 text-sm">
                        {{ __('forms.sign_with_KEP') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('step', {{ Wizard::STEP_AUTH_METHOD }})"
                        class="button-primary-outline px-5 py-2.5 text-sm"
                    >
                        {{ __('forms.back') }}
                    </button>
                </div>
            @endif

            @if ($step === Wizard::STEP_AWAITING_JOB)
                <div @if (!$asyncJobErrors) wire:poll.3s="pollAsyncJob" @endif class="max-w-2xl">
                    @if ($asyncJobErrors)
                        <div class="status-alert-red mb-4 flex-col items-start">
                            <p class="mb-2 text-sm font-semibold">
                                {{ __('patients.composition.create_newborn.job_failed') }}
                            </p>
                            <ul class="list-inside list-disc text-sm">
                                @foreach ($asyncJobErrors as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <button type="button" wire:click="restart" class="button-primary px-5 py-2.5 text-sm">
                            {{ __('patients.composition.create_newborn.restart') }}
                        </button>
                    @else
                        <div class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                            @icon('refresh', 'w-5 h-5 animate-spin text-gray-500')
                            {{ __('patients.composition.create_newborn.processing', ['status' => $asyncJobStatus]) }}
                        </div>
                    @endif
                </div>
            @endif

            @if ($step === Wizard::STEP_REVIEW)
                @php
                    $reviewStatus = \App\Enums\Person\CompositionStatus::fromEHealth(data_get($compositionDetail, 'status'));
                @endphp

                <div class="status-alert-green mb-6">
                    <p class="text-sm font-medium">
                        {{
                            $reviewStatus?->isSignable()
                            ? __('patients.composition.create_newborn.created')
                            : __('patients.composition.create_newborn.signed')
                        }}
                    </p>
                </div>

                @include('livewire.composition.parts.details-summary', ['detail' => $compositionDetail])

                @if ($integrationData)
                    @include('livewire.composition.parts.integration-data', ['items' => $integrationData])
                @endif

                <div class="mt-6 flex flex-wrap gap-2">
                    <button type="button" wire:click="loadPrintForm" class="button-primary-outline px-5 py-2.5 text-sm">
                        {{ __('patients.composition.actions.print') }}
                    </button>
                    @if ($reviewStatus?->isSignable())
                        <button type="button" wire:click="openSigningModal" class="button-primary px-5 py-2.5 text-sm">
                            {{ __('forms.sign_with_KEP') }}
                        </button>
                    @endif
                    <button type="button" wire:click="restart" class="button-minor px-5 py-2.5 text-sm">
                        {{ __('patients.composition.create_newborn.restart') }}
                    </button>
                </div>
            @endif
        </div>
    </div>

    <x-signature-modal :method="$step === Wizard::STEP_REVIEW ? 'sign' : 'submitComposition'" />

    @include('livewire.composition.parts.print-modal', [
                                'modalId' => 'modal-nb-print',
                                'iframeId' => 'nb-print-iframe',
                            ])

    <x-forms.loading />
</x-layouts.patient>
