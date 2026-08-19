@use('App\Livewire\Composition\CompositionTempDisabilityCreate', 'Wizard')
@use('App\Enums\Person\CompositionCategory')

<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :title="__('patients.composition.create_temp_disability.title')"
>
    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full">
            {{-- Progress --}}
            <ol class="mb-8 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                @foreach ([
                                                                    Wizard::STEP_ENCOUNTER => __('patients.composition.create_temp_disability.steps.encounter'),
                                                                    Wizard::STEP_AUTH_METHOD => __('patients.composition.create_temp_disability.steps.auth_method'),
                                                                    Wizard::STEP_DETAILS => __('patients.composition.create_temp_disability.steps.details'),
                                                                    Wizard::STEP_AWAITING_JOB => __('patients.composition.create_temp_disability.steps.processing'),
                                                                    Wizard::STEP_REVIEW => __('patients.composition.create_temp_disability.steps.review'),
                                                                ] as $stepNumber => $label)
                    <li @class([
                                                                        'flex items-center gap-2',
                                                                        'font-semibold text-gray-900 dark:text-gray-100' => $step === $stepNumber,
                                                                        'text-gray-400 dark:text-gray-500' => $step !== $stepNumber,
                                                                    ])>
                        <span
                            @class([
                                                                                                                'flex h-6 w-6 items-center justify-center rounded-full text-xs',
                                                                                                                'bg-primary-600 text-white' => $step >= $stepNumber,
                                                                                                                'bg-gray-200 text-gray-600 dark:bg-gray-700' => $step < $stepNumber,
                                                                                                            ])
                        >{{ $stepNumber }}</span>
                        {{ $label }}
                    </li>
                @endforeach
            </ol>

            {{-- Step 1: encounter --}}
            @if ($step === Wizard::STEP_ENCOUNTER)
                <div class="mb-4 font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('patients.composition.create_temp_disability.encounter_hint') }}
                </div>

                <div class="space-y-4">
                    @forelse ($this->availableEncounters as $encounter)
                        <div class="record-inner-card" wire:key="encounter-{{ $encounter['uuid'] }}">
                            <div class="record-inner-header">
                                <div class="record-inner-column flex-1">
                                    <div class="record-inner-label">{{ __('patients.composition.columns.date') }}</div>
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
                                        {{ __('patients.composition.create_temp_disability.encounter_class') }}
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
                        <x-nothing-found :description="__('patients.composition.create_temp_disability.no_encounters')" />
                    @endforelse
                </div>
            @endif

            {{-- Step 2: authentication method --}}
            @if ($step === Wizard::STEP_AUTH_METHOD)
                <div class="mb-4 font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('patients.composition.create_temp_disability.auth_method_hint') }}
                </div>

                <div class="space-y-3">
                    @foreach ($authMethods as $method)
                        <div class="record-inner-card" wire:key="auth-{{ $method['uuid'] ?? $method['id'] }}">
                            <div class="record-inner-header">
                                <div class="record-inner-column flex-1">
                                    <div class="record-inner-label">
                                        {{ __('patients.composition.create_temp_disability.auth_method_type') }}
                                    </div>
                                    <div class="record-inner-value text-[15px] font-semibold">
                                        {{ __('patients.authentication_method.' . strtolower($method['type'])) }}
                                    </div>
                                </div>

                                <div class="record-inner-column flex-1">
                                    <div class="record-inner-label">
                                        {{ __('patients.composition.create_temp_disability.auth_method_alias') }}
                                    </div>
                                    <div class="record-inner-value text-[15px] font-semibold">
                                        {{ $method['alias'] ?? '-' }}
                                    </div>
                                </div>

                                <div class="record-inner-column flex-1">
                                    <div class="record-inner-label">
                                        {{ __('patients.composition.create_temp_disability.auth_method_phone') }}
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

                {{-- TV 3.8.2.4.4 — proceeding without a method must be a deliberate choice. --}}
                <div class="status-alert-yellow mt-6">
                    <p class="text-sm font-medium">
                        {{ __('patients.composition.create_temp_disability.no_auth_method_warning') }}
                    </p>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="skipAuthMethod" class="button-minor px-5 py-2.5 text-sm">
                        {{ __('patients.composition.create_temp_disability.skip_auth_method') }}
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

            {{-- Step 3: details --}}
            @if ($step === Wizard::STEP_DETAILS)
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <select
                            wire:model.live="form.category"
                            name="form.category"
                            id="form.category"
                            class="input-select peer w-full"
                        >
                            @foreach ($this->categoryOptions as $code => $description)
                                <option value="{{ $code }}">{{ $description }}</option>
                            @endforeach
                        </select>
                        <label for="form.category" class="label">
                            {{ __('patients.composition.columns.category') }} *
                        </label>
                        @error('form.category')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input
                                wire:model.live="form.eventPeriodStart"
                                type="date"
                                name="form.eventPeriodStart"
                                id="form.eventPeriodStart"
                                class="input peer w-full"
                            />
                            <label for="form.eventPeriodStart" class="label">
                                {{ __('patients.composition.columns.period_start') }} *
                            </label>
                        </div>
                        @error('form.eventPeriodStart')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group group">
                        @if ($this->pregnancyPeriodOptions)
                            {{-- TV 3.8.2.5.4 — a pregnancy conclusion may only use the configured durations. --}}
                            <select
                                wire:model="form.eventPeriodEnd"
                                name="form.eventPeriodEnd"
                                id="form.eventPeriodEnd"
                                class="input-select peer w-full"
                            >
                                <option value="">{{ __('forms.select') }} ...</option>
                                @foreach ($this->pregnancyPeriodOptions as $days => $endDate)
                                    <option value="{{ $endDate }}">
                                        {{ $endDate }} ({{ $days }} {{ __('patients.composition.create_temp_disability.days') }})
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <div class="datepicker-wrapper">
                                <input
                                    wire:model="form.eventPeriodEnd"
                                    type="date"
                                    name="form.eventPeriodEnd"
                                    id="form.eventPeriodEnd"
                                    class="input peer w-full"
                                />
                            </div>
                        @endif
                        <label for="form.eventPeriodEnd" class="label">
                            {{ __('patients.composition.columns.period_end') }} *
                        </label>
                        @error('form.eventPeriodEnd')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mb-6 grid grid-cols-1 gap-3 md:grid-cols-2">
                    @foreach ([
                                                                                    'form.isAccident' => __('patients.composition.detail.flags.is_accident'),
                                                                                    'form.isIntoxicated' => __('patients.composition.detail.flags.is_intoxicated'),
                                                                                    'form.isForeignTreatment' => __('patients.composition.detail.flags.is_foreign_treatment'),
                                                                                    'form.isForceRenew' => __('patients.composition.detail.flags.is_force_renew'),
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
                            <option value="">{{ __('forms.select') }} ...</option>
                            @foreach ($this->treatmentViolationOptions as $code => $description)
                                <option value="{{ $code }}">{{ $description }}</option>
                            @endforeach
                        </select>
                        <label for="form.treatmentViolation" class="label">
                            {{ __('patients.composition.detail.treatment_violation') }}
                        </label>
                        @error('form.treatmentViolation')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($form->treatmentViolation)
                        <div class="form-group group">
                            <div class="datepicker-wrapper">
                                <input
                                    wire:model="form.treatmentViolationDate"
                                    type="date"
                                    name="form.treatmentViolationDate"
                                    id="form.treatmentViolationDate"
                                    class="input peer w-full"
                                />
                                <label for="form.treatmentViolationDate" class="label">
                                    {{ __('patients.composition.create_temp_disability.violation_date') }} *
                                </label>
                            </div>
                            @error('form.treatmentViolationDate')
                                <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                </div>

                @if (!$form->informWithUuid)
                    <div class="status-alert-yellow mb-6">
                        <p class="text-sm font-medium">
                            {{ __('patients.composition.create_temp_disability.no_auth_method_warning') }}
                        </p>
                    </div>
                @endif

                @if ($this->requiresUnidentifiedErlnWarning)
                    {{-- TV 3.8.2.6.1 — an unidentified patient gets no ERLN record at all. --}}
                    <div class="status-alert-yellow mb-6 flex-col items-start">
                        <p class="text-sm font-medium">
                            {{ __('patients.composition.create_temp_disability.unidentified_erln_warning') }}
                        </p>
                        <button
                            type="button"
                            wire:click="acknowledgeUnidentifiedErln"
                            class="button-minor mt-3 px-5 py-2 text-sm"
                        >
                            {{ __('patients.composition.create_temp_disability.acknowledge') }}
                        </button>
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="reviewDetails"
                        @disabled($this->requiresUnidentifiedErlnWarning)
                        class="button-primary px-5 py-2.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                    >
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

            {{-- Step 4: waiting for eHealth --}}
            @if ($step === Wizard::STEP_AWAITING_JOB)
                <div @if (!$asyncJobErrors) wire:poll.3s="pollAsyncJob" @endif class="max-w-2xl">
                    @if ($asyncJobErrors)
                        {{-- TV 3.8.2.7.1 — creation cannot continue, so only the errors are shown. --}}
                        <div class="status-alert-red mb-4 flex-col items-start">
                            <p class="mb-2 text-sm font-semibold">
                                {{ __('patients.composition.create_temp_disability.job_failed') }}
                            </p>
                            <ul class="list-inside list-disc text-sm">
                                @foreach ($asyncJobErrors as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>

                        <button type="button" wire:click="restart" class="button-primary px-5 py-2.5 text-sm">
                            {{ __('patients.composition.create_temp_disability.restart') }}
                        </button>
                    @else
                        <div class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                            @icon('refresh', 'w-5 h-5 animate-spin text-gray-500')
                            {{ __('patients.composition.create_temp_disability.processing', ['status' => $asyncJobStatus]) }}
                        </div>
                    @endif
                </div>
            @endif

            {{-- Step 5: review and sign --}}
            @if ($step === Wizard::STEP_REVIEW)
                <div class="status-alert-green mb-6">
                    <p class="text-sm font-medium">{{ __('patients.composition.create_temp_disability.created') }}</p>
                </div>

                @include('livewire.composition.parts.details-summary', ['detail' => $compositionDetail])

                <div class="mt-6 flex flex-wrap gap-2">
                    <button type="button" wire:click="loadPrintForm" class="button-primary-outline px-5 py-2.5 text-sm">
                        {{ __('patients.composition.actions.print') }}
                    </button>
                    <button type="button" wire:click="openSigningModal" class="button-primary px-5 py-2.5 text-sm">
                        {{ __('forms.sign_with_KEP') }}
                    </button>
                    <button type="button" wire:click="restart" class="button-minor px-5 py-2.5 text-sm">
                        {{ __('patients.composition.create_temp_disability.restart') }}
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- Signing serves both submitting the conclusion and signing it afterwards. --}}
    <x-signature-modal :method="$step === Wizard::STEP_REVIEW ? 'sign' : 'submitComposition'" />

    <x-dialog-modal maxWidth="3xl" id="modal-td-print" wire:model.live="showPrintModal">
        <x-slot name="title">{{ __('patients.composition.print.title') }}</x-slot>

        <x-slot name="content">
            {{-- TV 3.8.2.8.3.1 forbids adding anything to what eHealth returns. --}}
            <iframe
                id="td-print-iframe"
                srcdoc="{{ $printFormHtml }}"
                class="w-full border-0"
                style="min-height: 600px"
                sandbox="allow-same-origin"
            ></iframe>
        </x-slot>

        <x-slot name="footer">
            <button
                type="button"
                onclick="document.getElementById('td-print-iframe').contentWindow.print()"
                class="button-primary px-5 py-2 text-sm"
            >
                {{ __('patients.composition.actions.print') }}
            </button>
            <button type="button" wire:click="closePrintModal" class="button-primary-outline ml-2 px-5 py-2 text-sm">
                {{ __('forms.close') }}
            </button>
        </x-slot>
    </x-dialog-modal>

    <x-forms.loading />
</x-layouts.patient>
