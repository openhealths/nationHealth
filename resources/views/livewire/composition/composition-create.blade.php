@use('App\Livewire\Composition\CompositionCreate', 'Wizard')

<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :title="__('compositions.create_newborn.title')"
>
    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full">
            <ol class="mb-8 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                @foreach ([
                                                                    Wizard::STEP_ENCOUNTER => __('compositions.create_newborn.steps.encounter'),
                                                                    Wizard::STEP_AUTH_METHOD => __('compositions.create_newborn.steps.auth_method'),
                                                                    Wizard::STEP_DETAILS => __('compositions.create_newborn.steps.details'),
                                                                    Wizard::STEP_AWAITING_JOB => __('compositions.create_newborn.steps.processing'),
                                                                    Wizard::STEP_REVIEW => __('compositions.create_newborn.steps.review'),
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
                    {{ __('compositions.create_newborn.encounter_hint') }}
                </div>

                @if ($this->needsMother)
                    <div class="mb-6">
                        <p class="mb-3 text-sm text-gray-700 dark:text-gray-200">
                            {{ __('compositions.create_newborn.identify_mother') }}
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
                                {{ __('compositions.create_newborn.mother_search') }}
                            </label>
                        </div>
                        <div class="space-y-3">
                            @forelse ($this->counterpartMatches as $match)
                                <div class="record-inner-card" wire:key="mother-{{ $match->id }}">
                                    <div class="record-inner-header">
                                        <div class="record-inner-column flex-1">
                                            <div class="record-inner-label">
                                                {{ __('compositions.create_newborn.mother') }}
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
                                    <x-nothing-found :description="__('compositions.create_newborn.no_mother')" />
                                @endif
                            @endforelse
                        </div>
                    </div>
                @endif

                @if ($this->needsNewborn)
                    <div class="mb-6">
                        <p class="mb-3 text-sm text-gray-700 dark:text-gray-200">
                            {{ __('compositions.create_newborn.identify_newborn') }}
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
                                {{ __('compositions.create_newborn.newborn_search') }}
                            </label>
                        </div>
                        <div class="space-y-3">
                            @forelse ($this->counterpartMatches as $match)
                                <div class="record-inner-card" wire:key="newborn-{{ $match->id }}">
                                    <div class="record-inner-header">
                                        <div class="record-inner-column flex-1">
                                            <div class="record-inner-label">
                                                {{ __('compositions.create_newborn.newborn') }}
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
                                    <x-nothing-found :description="__('compositions.create_newborn.no_newborn')" />
                                @endif
                            @endforelse
                        </div>
                    </div>
                @endif

                @if (!$this->needsNewborn)
                    @if ($this->hasExistingActiveBirthConclusion)
                        <div class="status-alert-yellow mb-6">
                            <p class="text-sm font-medium">{{ __('compositions.create_newborn.existing_warning') }}</p>
                        </div>
                    @endif

                    <div class="space-y-4">
                        @forelse ($this->availableEncounters as $encounter)
                            <div class="record-inner-card" wire:key="encounter-{{ $encounter['uuid'] }}">
                                <div class="record-inner-header">
                                    <div class="record-inner-column flex-1">
                                        <div class="record-inner-label">{{ __('compositions.columns.date') }}</div>
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
                                            {{ __('compositions.create_newborn.encounter_class') }}
                                        </div>
                                        <div class="record-inner-value text-[15px] font-semibold">
                                            {{ $this->dictionaryLabel($encounter, 'class') }}
                                        </div>
                                    </div>
                                    <div class="record-inner-action-col">
                                        <button
                                            type="button"
                                            wire:click="selectEncounter('{{ $encounter['uuid'] }}')"
                                            class="button-primary px-5 py-2 text-sm"
                                        >
                                            {{ __('forms.select') }}
                                        </button>
                                    </div>
                                </div>
                                <div class="record-inner-body">
                                    <div class="record-inner-id-col">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">ID</div>
                                            <div class="record-inner-id-value">{{ $encounter['uuid'] }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <x-nothing-found :description="__('compositions.create_newborn.no_encounters')" />
                        @endforelse
                    </div>
                @endif
            @endif

            @if ($step === Wizard::STEP_AUTH_METHOD)
                <div class="mb-4 font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('compositions.create_newborn.auth_method_hint') }}
                </div>

                <div class="space-y-3">
                    @foreach ($authMethods as $method)
                        <div class="record-inner-card" wire:key="auth-{{ $method['uuid'] ?? $method['id'] }}">
                            <div class="record-inner-header">
                                <div class="record-inner-column flex-1">
                                    <div class="record-inner-label">
                                        {{ __('compositions.create_newborn.auth_method_type') }}
                                    </div>
                                    <div class="record-inner-value text-[15px] font-semibold">
                                        {{ __('patients.authentication_method.' . strtolower($method['type'])) }}
                                    </div>
                                </div>
                                <div class="record-inner-column flex-1">
                                    <div class="record-inner-label">
                                        {{ __('compositions.create_newborn.auth_method_alias') }}
                                    </div>
                                    <div class="record-inner-value text-[15px] font-semibold">
                                        {{ $method['alias'] ?? '-' }}
                                    </div>
                                </div>
                                <div class="record-inner-column flex-1">
                                    <div class="record-inner-label">
                                        {{ __('compositions.create_newborn.auth_method_phone') }}
                                    </div>
                                    <div class="record-inner-value text-[15px] font-semibold">
                                        {{
                                            $method['phone_number']
                                            ?? data_get($method, 'confidant_person.phones.0.number')
                                            ?? '-'
                                        }}
                                    </div>
                                </div>
                                <div class="record-inner-action-col">
                                    <button
                                        type="button"
                                        wire:click="selectAuthMethod('{{ $method['uuid'] ?? $method['id'] }}')"
                                        class="button-primary px-5 py-2 text-sm"
                                    >
                                        {{ __('forms.select') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="status-alert-yellow mt-6 flex-col items-start">
                    <p class="text-sm font-medium">{{ __('compositions.create_newborn.no_auth_method_warning') }}</p>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="skipAuthMethod" class="button-minor px-5 py-2.5 text-sm">
                        {{ __('compositions.create_newborn.skip_auth_method') }}
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
                        <p class="text-sm font-medium">{{ __('compositions.create_newborn.existing_warning') }}</p>
                    </div>
                @endif

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="form.category"
                            class="input peer w-full"
                            value="{{ __('compositions.create_newborn.live_birth') }}"
                            disabled
                        />
                        <label for="form.category" class="label"> {{ __('compositions.columns.category') }} * </label>
                    </div>

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input
                                wire:model="form.newbornBirthDate"
                                type="date"
                                name="form.newbornBirthDate"
                                id="form.newbornBirthDate"
                                class="input peer w-full"
                            />
                            <label for="form.newbornBirthDate" class="label">
                                {{ __('compositions.fields.newborn_birth_date') }} *
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
                            {{ __('compositions.fields.newborn_sex') }} *
                        </label>
                        @error('form.newbornSex')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mb-6 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div class="min-w-0">
                        <div class="record-inner-label text-[10px] uppercase">
                            {{ __('compositions.create_newborn.mother') }}
                        </div>
                        <div class="record-inner-value text-[14px] font-semibold">
                            {{ $motherFullName ?: $form->personUuid }}
                        </div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label text-[10px] uppercase">
                            {{ __('compositions.create_newborn.newborn') }}
                        </div>
                        <div class="record-inner-value text-[14px] font-semibold">
                            {{ $newbornFullName ?: $form->prepersonUuid }}
                        </div>
                    </div>
                </div>

                @if (!$form->informWithUuid)
                    <div class="status-alert-yellow mb-6 flex-col items-start">
                        <p class="text-sm font-medium">
                            {{ __('compositions.create_newborn.no_auth_method_warning') }}
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
                            <p class="mb-2 text-sm font-semibold">{{ __('compositions.create_newborn.job_failed') }}</p>
                            <ul class="list-inside list-disc text-sm">
                                @foreach ($asyncJobErrors as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <button type="button" wire:click="restart" class="button-primary px-5 py-2.5 text-sm">
                            {{ __('compositions.create_newborn.restart') }}
                        </button>
                    @else
                        <div class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                            @icon('refresh', 'w-5 h-5 animate-spin text-gray-500')
                            {{ __('compositions.create_newborn.processing', ['status' => $asyncJobStatus]) }}
                        </div>
                    @endif
                </div>
            @endif

            @if ($step === Wizard::STEP_REVIEW)
                <div class="status-alert-green mb-6">
                    <p class="text-sm font-medium">{{ __('compositions.create_newborn.created') }}</p>
                </div>

                @include('livewire.composition.parts.details-summary', ['detail' => $compositionDetail])

                @if ($integrationData)
                    @include('livewire.composition.parts.integration-data', ['items' => $integrationData])
                @endif

                <div class="mt-6 flex flex-wrap gap-2">
                    <button type="button" wire:click="loadPrintForm" class="button-primary-outline px-5 py-2.5 text-sm">
                        {{ __('compositions.actions.print') }}
                    </button>
                    <button type="button" wire:click="openSigningModal" class="button-primary px-5 py-2.5 text-sm">
                        {{ __('forms.sign_with_KEP') }}
                    </button>
                    <button type="button" wire:click="restart" class="button-minor px-5 py-2.5 text-sm">
                        {{ __('compositions.create_newborn.restart') }}
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
