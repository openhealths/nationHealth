@use('App\Enums\JobStatus')

@php
    $referralPrototypeEnabled = $referralPrototypeEnabled ?? false;
    $hasReferenceSearch = method_exists($this, 'searchReferralReferenceOptions');
    $hasQuantityUnits = method_exists($this, 'encounterReferralQuantityUnits');
    $quantityUnits = $hasQuantityUnits ? $this->encounterReferralQuantityUnits : ['PIECE' => __('referrals.pcs')];
    if ($referralPrototypeEnabled && !$hasQuantityUnits) {
        try {
            $quantityUnits = dictionary()->basics()->byName('SERVICE_UNIT')->asCodeDescription()->all() + $quantityUnits;
        } catch (\Throwable) {
            // The local preview remains usable while dictionary data is unavailable.
        }
    }
    $referralPrograms = $encounterReferralPrograms;
    if ($referralPrototypeEnabled && !in_array('preview-service-program', array_column($referralPrograms, 'id'), true)) {
        $referralPrograms[] = ['id' => 'preview-service-program', 'name' => __('encounters.referral_layout.test_program'),
            'is_demo' => true, 'medical_program_settings' => ['care_plan_required' => true]];
    }
@endphp
<div
    x-data="{
        prototypeEnabled: @js($referralPrototypeEnabled),
        openServiceCatalog: false,
        openServicePicker: false,
        previewServiceQuery: '',
        previewServices: @js(array_column($prototypeContext['demoReferrals'] ?? [], 'service')),
        previewPrograms: @js($referralPrototypeEnabled ? $referralPrograms : []),
        get programs() { return this.prototypeEnabled ? this.previewPrograms : $wire.encounterReferralPrograms; },
        get previewServiceResults() {
            const terms = this.previewServiceQuery.trim().toLocaleLowerCase('uk').split(/\s+/).filter(Boolean);
            return this.previewServices.filter(service => terms.every(term => [service.code, service.name].join(' ').toLocaleLowerCase('uk').includes(term)));
        },
        selectPreviewService(service) {
            $wire.set('encounterReferralSelectedService', service, false);
            $wire.set('encounterReferralForm.service_id', service.id, false);
            $wire.set('encounterReferralForm.category', service.category, false);
            this.openServicePicker = false;
            this.previewServiceQuery = '';
        },
        showProgramInfo: false,
        programInfoId: '',
        referencePickerOpen: false,
        referenceField: 'reason_reference',
        referencePresetType: '',
        init() {
            if (this.prototypeEnabled) {
                const form = $wire.encounterReferralForm;
                if (!('supporting_info' in form)) $wire.set('encounterReferralForm.supporting_info', [], false);
                if (!('quantity_code' in form)) $wire.set('encounterReferralForm.quantity_code', 'PIECE', false);
                this.$dispatch('referral-prototype-form-ready', { form: JSON.parse(JSON.stringify($wire.encounterReferralForm)) });
            }
        },
        prototypePayload() {
            const form = JSON.parse(JSON.stringify($wire.encounterReferralForm));
            delete form.inform_with;
            const unit = this.$el.querySelector('#encounterReferralQuantityUnit');
            const quantityUnitLabel = unit?.selectedOptions[0]?.textContent.trim() || form.quantity_unit;
            return {
                form,
                quantityUnitLabel,
                service: JSON.parse(JSON.stringify($wire.encounterReferralSelectedService || {})),
                categoryLabel: this.$el.querySelector('#encounterReferralCategory')?.selectedOptions[0]?.textContent.trim() || '',
            };
        },
        restorePrototype(item) {
            if (!this.prototypeEnabled) return;
            for (const [key, value] of Object.entries(item.form || {})) {
                if (key in $wire.encounterReferralForm) $wire.set('encounterReferralForm.' + key, value, false);
            }
            $wire.set('encounterReferralSelectedService', item.service?.id ? item.service : null, false);
        },
        previewReferenceKey(record) {
            return (record.type || '') + ':' + (record.uuid || JSON.stringify(record));
        },
        addPreviewReference(field, record) {
            const references = [...($wire.encounterReferralForm[field] || [])];
            const key = this.previewReferenceKey(record);
            if (!references.some(reference => this.previewReferenceKey(reference) === key)) references.push(record);
            $wire.set('encounterReferralForm.' + field, references, false);
        },
        removePreviewReference(field, record) {
            const key = this.previewReferenceKey(record);
            $wire.set('encounterReferralForm.' + field, ($wire.encounterReferralForm[field] || []).filter(reference => this.previewReferenceKey(reference) !== key), false);
        },
        referenceName(reference, field) {
            const option = $wire.encounterReferralReferenceOptions?.[(reference.type || '') + ':' + (reference.uuid || '')] || {};
            const name = reference.label || option.label || reference.displayValue || reference.display_value || reference.text || reference.uuid || '—';
            const labels = @js(['episode' => __('episodes.label'), 'condition' => __('conditions.condition_or_diagnosis'), 'observation' => __('observations.label'), 'diagnostic_report' => __('diagnostic-reports.label')]);
            return [field === 'supporting_info' ? labels[reference.type] : '', name].filter(Boolean).join(' ');
        },
        get selectedProgram() {
            return (
                this.programs.find(
                    (program) =>
                        program.id === $wire.encounterReferralForm.program_id,
                ) ?? null
            );
        },
        get infoProgram() {
            return (
                this.programs.find(
                    (program) => program.id === this.programInfoId,
                ) ?? null
            );
        },
        selectProgram(id) {
            $wire.set('encounterReferralForm.program_id', id, !this.prototypeEnabled);
            this.showProgramInfo = false;
            this.programInfoId = '';
        },
        toggleProgramInfo(id) {
            this.showProgramInfo =
                !this.showProgramInfo || this.programInfoId !== id;
            this.programInfoId = id;
        },
        isReasonReferenceAdded(uuid) {
            return ($wire.encounterReferralForm.reason_reference ?? []).some(
                (record) => record.uuid === uuid,
            );
        },
        isSupportingReferenceAdded(uuid) {
            return ($wire.encounterReferralForm.supporting_info ?? []).some(
                (record) => record.uuid === uuid,
            );
        },
        selectReferenceField(field, presetType = '') {
            this.referenceField = field;
            this.referencePresetType = presetType;
            this.openServiceCatalog = false;
            this.openServicePicker = false;
            this.referencePickerOpen = true;
        },
        setPeriod(from, to) {
            const displayDate = (value) =>
                /^\d{4}-\d{2}-\d{2}$/.test(value)
                    ? value.split('-').reverse().join('.')
                    : value;
            $wire.set(
                'encounterReferralForm.started_at',
                displayDate(from),
                false,
            );
            $wire.set('encounterReferralForm.ended_at', displayDate(to), false);
        },
    }"
    @referral-prototype-restore.window="restorePrototype($event.detail.item)"
    @encounter-referral-reason-record-selected.window="
        prototypeEnabled
            ? addPreviewReference('reason_reference', $event.detail.record)
            : $wire.addReferralReference(
                  'reason_reference',
                  $event.detail.record.type,
                  $event.detail.record.uuid,
              )
    "
    @encounter-referral-supporting-record-selected.window="
        prototypeEnabled
            ? addPreviewReference('supporting_info', $event.detail.record)
            : $wire.addReferralReference(
                  'supporting_info',
                  $event.detail.record.type,
                  $event.detail.record.uuid,
              )
    "
    @encounter-referral-service-catalog-close.window="
        openServiceCatalog = false;
        openServicePicker = false;
    "
>
    @php
        $submissionPending = ($encounterReferralSubmission['status'] ?? null) === JobStatus::PENDING->value;
        $preparingSignature = $prepareReferralForSigning ?? false;
        $formDisabled = $submissionPending || $preparingSignature || ($referralReadonly ?? false);
        $referenceLabels = ['condition' => __('encounters.referral_layout.conditions_and_diagnoses'), 'observation' => __('observations.label'), 'diagnostic_report' => __('diagnostic-reports.plural'), 'episode' => __('episodes.plural')];
    @endphp
    @if ($submissionPending)
        <div
            class="mb-6 rounded-lg border border-gray-200 p-4 dark:border-gray-600"
            role="status"
        >
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
    <form
        @if ($referralPrototypeEnabled)
            novalidate
            @submit.prevent="
                $dispatch('referral-prototype-submit', prototypePayload())
            "
        @else
            wire:submit.prevent="validateEncounterReferral"
        @endif
        class="space-y-6"
    >
        <fieldset @disabled ($formDisabled) class="fieldset-card p-4 sm:p-6">
            <legend class="legend">
                {{ __('encounters.referral_layout.main_data') }}
            </legend>
            <div class="form-row-2 mt-4 mb-0! [&>.form-group]:mb-0!">
                <div
                    class="form-group group relative"
                    data-referral-service-field
                    :style="{ zIndex: openServicePicker ? 30 : 0 }"
                    @keydown.escape.prevent.stop="openServicePicker = false"
                >
                    <button
                        type="button"
                        id="encounterReferralSelectedService"
                        class="input-select peer relative w-full text-left"
                        @click="
                            openServicePicker = !openServicePicker;
                            if (openServicePicker)
                                $nextTick(() => $refs.serviceSearch.focus());
                        "
                        :aria-expanded="openServicePicker"
                        aria-controls="encounterReferralServiceOptions"
                    >
                        <span
                            x-text="$wire.encounterReferralSelectedService?.name ? [$wire.encounterReferralSelectedService.code, $wire.encounterReferralSelectedService.name].filter(Boolean).join(' — ') : @js(__('encounters.select_service'))"
                        ></span>
                        <span
                            class="absolute right-2 top-3 h-4 w-4 pointer-events-none"
                            aria-hidden="true"
                        >
                            @icon ('chevron-down', 'h-4 w-4 text-gray-500')
                        </span>
                    </button>
                    <label
                        for="encounterReferralSelectedService"
                        class="label border-0! required"
                        >{{ __('encounters.service') }}</label
                    >
                    <x-input-error for="encounterReferralForm.service_id" />
                    <div
                        id="encounterReferralServiceOptions"
                        x-show="openServicePicker"
                        x-cloak
                        @click.outside="openServicePicker = false"
                        class="absolute top-full left-0 z-40 mt-2 w-full rounded-lg border border-gray-200 bg-white p-3 shadow-lg dark:border-gray-600 dark:bg-gray-800"
                    >
                        <div class="flex items-center gap-2">
                            <input
                                x-ref="serviceSearch"
                                type="search"
                                name="referral_service_query"
                                autocomplete="off"
                                id="encounterReferralServiceSearch"
                                class="input peer w-full"
                                placeholder="{{ __('encounters.referral_drawer.service_search_placeholder') }}"
                                @if ($referralPrototypeEnabled)
                                    x-model="previewServiceQuery"
                                    @keydown.enter.prevent
                                @else
                                    wire:model="encounterReferralServiceSearch"
                                    wire:keydown.enter.prevent="searchEncounterReferralServices"
                                @endif
                                aria-label="{{ __('encounters.referral_drawer.service_search_placeholder') }}"
                            />
                            <button
                                type="button"
                                class="button-primary shrink-0"
                                @if ($referralPrototypeEnabled)
                                    @click="$refs.serviceSearch.focus()"
                                @else
                                    wire:click="searchEncounterReferralServices"
                                @endif
                                aria-label="{{ __('forms.search') }}"
                            >
                                @icon ('search-outline', 'h-4 w-4')
                            </button>
                        </div>
                        <div class="mt-3">
                            @if ($referralPrototypeEnabled)
                                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ __('encounters.referral_layout.preview_services') }}</p>
                                <div class="max-h-64 space-y-2 overflow-y-auto">
                                    <template
                                        x-for="service in previewServiceResults"
                                        :key="service.id"
                                    >
                                        <button
                                            type="button"
                                            @click="
                                                selectPreviewService(service)
                                            "
                                            class="w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-left text-sm transition-colors hover:border-blue-300 hover:bg-blue-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:border-blue-400 dark:hover:bg-gray-700"
                                        >
                                            <span
                                                x-text="
                                                    service.code +
                                                    ' — ' +
                                                    service.name
                                                "
                                            ></span>
                                        </button>
                                    </template>
                                    <p
                                        x-show="!previewServiceResults.length"
                                        class="p-3 text-sm text-gray-500 dark:text-gray-400"
                                    >{{ __('encounters.referral_drawer.services_not_found') }}</p>
                                </div>
                            @else
                                @if ($encounterReferralServiceResults !== [])
                                    <div
                                        class="max-h-64 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3 md:col-span-2 dark:border-gray-600 dark:bg-gray-900/40"
                                    >
                                        @foreach ($encounterReferralServiceResults as $service)
                                            <button
                                                type="button"
                                                @click="
                                                    openServicePicker = false
                                                "
                                                wire:click="selectEncounterReferralService('{{ $service['id'] }}')"
                                                class="w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-left text-sm transition-colors hover:border-blue-300 hover:bg-blue-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:border-blue-400 dark:hover:bg-gray-700"
                                            >
                                                <div
                                                    class="font-medium text-gray-900 dark:text-gray-100"
                                                >
                                                    {{ ($service['code'] ?? '') }} — {{ $service['name'] ?? __('encounters.service') }}
                                                </div>
                                                @php
                                    $serviceCategoryKey = 'encounters.referral_category.'.strtolower((string) ($service['category'] ?? ''));
                                @endphp

                                                @if (\Illuminate\Support\Facades\Lang::has($serviceCategoryKey))
                                                    <div
                                                        class="text-xs text-gray-500 dark:text-gray-400"
                                                    >
                                                        {{ __($serviceCategoryKey) }}
                                                    </div>

                                                @endif
                                            </button>

                                        @endforeach
                                    </div>

                                @elseif ($encounterReferralHasSearched)
                                    <div
                                        class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center text-sm text-gray-500 md:col-span-2 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                                    >
                                        {{ __('encounters.referral_drawer.services_not_found') }}
                                    </div>

                                @endif
                            @endif
                        </div>
                    </div>
                </div>
                <div class="flex items-center">
                    <button
                        type="button"
                        @click="
                            openServiceCatalog = true;
                            openServicePicker = false;
                        "
                        class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400"
                    >
                        <span
                            class="flex h-5 w-5 shrink-0 items-center justify-center [&>svg]:h-full [&>svg]:w-full"
                        >
                            @icon ('book-open')
                        </span>
                        {{ __('dictionaries.service_catalog.choose_from_catalog') }}
                    </button>
                </div>
                <div class="form-group group">
                    <div class="flex flex-nowrap! items-end gap-4 max-w-64">
                        <div class="relative min-w-0 flex-1">
                            <input
                                type="number"
                                id="encounterReferralQuantity"
                                min="0.01"
                                step="any"
                                class="input peer w-full"
                                wire:model="encounterReferralForm.quantity"
                            />
                            <label
                                for="encounterReferralQuantity"
                                class="label border-0! required"
                                >{{ __('care-plan.quantity') }}</label
                            >
                        </div>
                        <select
                            id="encounterReferralQuantityUnit"
                            @if ($referralPrototypeEnabled)
                                wire:model="encounterReferralForm.quantity_code"
                            @elseif ($hasQuantityUnits)
                                wire:model.live="encounterReferralForm.quantity_code"
                            @else
                                disabled
                            @endif
                            class="input-select w-28! shrink-0"
                            aria-label="{{ __('encounters.referral_layout.quantity_unit') }}"
                        >
                            @foreach ($quantityUnits as $code => $label)
                                <option value="{{ $code }}">
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <x-input-error
                        for="encounterReferralForm.quantity"
                    /><x-input-error
                        for="encounterReferralForm.quantity_code"
                    />
                </div>
                <div class="hidden md:block" aria-hidden="true"></div>
                <div class="form-group group">
                    <select
                        id="encounterReferralCategory"
                        class="input-select peer w-full"
                        wire:model="encounterReferralForm.category"
                        @disabled ($encounterReferralIsTransfer)
                    >
                        @if ($encounterReferralServiceType === 'service_group')
                            <option value="">{{ __('forms.select') }}</option>
                        @endif
                        @foreach (__('encounters.referral_category') as $code => $label)
                            <option
                                value="{{ match ($code) { 'transfer' => 'transfer_of_care', 'counseling' => 'counselling', default => $code } }}"
                            >
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    <label
                        for="encounterReferralCategory"
                        class="label border-0! required"
                        >{{ __('forms.category') }}</label
                    >
                </div>
                <div class="form-group group">
                    <select
                        id="encounterReferralPriority"
                        class="input-select peer w-full"
                        wire:model="encounterReferralForm.priority"
                    >
                        @foreach ($this->encounterReferralPriorityOptions as $code => $description)
                            <option value="{{ $code }}">
                                {{ $description }}
                            </option>
                        @endforeach
                    </select>
                    <label
                        for="encounterReferralPriority"
                        class="label border-0! required"
                        >{{ __('encounters.priority') }}</label
                    >
                    <x-input-error for="encounterReferralForm.priority" />
                </div>
            </div>
        </fieldset>

        <fieldset @disabled ($formDisabled) class="fieldset-card p-4 sm:p-6">
            <legend class="legend">
                {{ __('encounters.referral_layout.additional_information') }}
            </legend>
            <div class="form-row-2 mt-4 mb-0! [&>.form-group]:mb-0!">
                <div class="form-group group">
                    <select
                        id="encounterReferralPeriodMode"
                        class="input-select peer w-full"
                    >
                        <option>
                            {{ __('encounters.referral_layout.period') }}
                        </option>
                    </select>
                    <label
                        for="encounterReferralPeriodMode"
                        class="label border-0! whitespace-nowrap"
                        >{{ __('encounters.referral_layout.occurrence') }}</label
                    >
                </div>
                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input
                            type="text"
                            id="encounterReferralPeriod"
                            data-referral-date-range
                            class="daterangepicker-uk with-leading-icon input peer w-full"
                            :value="[
                                $wire.encounterReferralForm.started_at,
                                $wire.encounterReferralForm.ended_at,
                            ]
                                .filter(Boolean)
                                .join(' — ')"
                            @date-range-change="
                                setPeriod($event.detail.from, $event.detail.to)
                            "
                            autocomplete="off"
                            placeholder=" "
                        />
                        <label
                            for="encounterReferralPeriod"
                            class="wrapped-label border-0! required"
                            >{{ __('referrals.period') }}</label
                        >
                    </div>
                    <x-input-error
                        for="encounterReferralForm.started_at"
                    /><x-input-error for="encounterReferralForm.ended_at" />
                </div>
                <div class="form-group group">
                    <x-forms.time
                        id="encounterReferralStartTime"
                        wire:model="encounterReferralForm.start_time"
                        placeholder=" "
                        :label="__('referrals.period_fields.start_time')"
                        :error="'encounterReferralForm.start_time'"
                    />
                </div>
                <div class="form-group group">
                    <x-forms.time
                        id="encounterReferralEndTime"
                        wire:model="encounterReferralForm.end_time"
                        placeholder=" "
                        :label="__('referrals.period_fields.end_time')"
                        :error="'encounterReferralForm.end_time'"
                    />
                </div>
                <div class="form-group group">
                    <x-dropdown
                        align="left"
                        width="w-full"
                        contentClasses="py-1 bg-white dark:bg-gray-800 ring-0!"
                        dropdownClasses="border border-gray-200 dark:border-gray-600 shadow-none!"
                    >
                        <x-slot name="trigger">
                            <button
                                type="button"
                                id="encounterReferralProgram"
                                class="input-select peer relative w-full text-left"
                                aria-haspopup="menu"
                                :aria-expanded="open"
                                aria-controls="encounterReferralProgramOptions"
                                @keydown.escape.prevent.stop="open = false"
                            >
                                <span
                                    class="block truncate"
                                    x-text="selectedProgram?.name ?? @js(__('encounters.referral_drawer.not_selected'))"
                                ></span>
                                <span
                                    class="pointer-events-none absolute right-2 top-3 h-4 w-4"
                                    aria-hidden="true"
                                >
                                    @icon ('chevron-down', 'h-4 w-4 text-gray-500')
                                </span>
                            </button>
                            <label
                                for="encounterReferralProgram"
                                class="label border-0!"
                                >{{ __('care-plan.program') }}</label
                            >
                        </x-slot>
                        <x-slot name="content">
                            <ul
                                id="encounterReferralProgramOptions"
                                x-effect="
                                    $el.closest('.form-group').style.zIndex =
                                        open ? '30' : ''
                                "
                                role="menu"
                                aria-label="{{ __('care-plan.program') }}"
                                @keydown.escape.prevent.stop="open = false"
                            >
                                <li role="none">
                                    <button
                                        type="button"
                                        role="menuitemradio"
                                        data-program-option=""
                                        :aria-checked="!$wire
                                            .encounterReferralForm.program_id"
                                        @click="selectProgram('')"
                                        class="w-full cursor-pointer px-2 py-1 text-left text-sm text-gray-500 dark:text-gray-300"
                                    >
                                        {{ __('encounters.referral_drawer.not_selected') }}
                                    </button>
                                </li>
                                @foreach ($referralPrograms as $program)
                                    <li
                                        role="none"
                                        class="flex items-start gap-1 px-1 hover:bg-gray-50 dark:hover:bg-gray-700"
                                    >
                                        <button
                                            type="button"
                                            role="menuitem"
                                            data-program-help="{{ $program['id'] }}"
                                            @click="toggleProgramInfo(@js($program['id']))"
                                            :aria-expanded="showProgramInfo && programInfoId === @js($program['id'])"
                                            aria-controls="encounterReferralProgramInfo"
                                            aria-label="{{ __('encounters.referral_layout.program_help') }}: {{ $program['name'] }}"
                                            class="mt-0.5 shrink-0 cursor-pointer rounded p-1 text-gray-500 hover:text-blue-600 focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-300 dark:hover:text-blue-400"
                                        >
                                            @icon ('question-mark-circle', 'h-4 w-4')
                                        </button>
                                        <button
                                            type="button"
                                            role="menuitemradio"
                                            data-program-option="{{ $program['id'] }}"
                                            :aria-checked="$wire.encounterReferralForm.program_id === @js($program['id'])"
                                            @click="selectProgram(@js($program['id']))"
                                            class="min-w-0 flex-1 cursor-pointer py-1 pr-2 text-left text-sm text-gray-500 dark:text-gray-300"
                                        >
                                            {{ $program['name'] }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </x-slot>
                    </x-dropdown>
                    <x-input-error for="encounterReferralForm.program_id" />
                </div>
                <div class="hidden md:block" aria-hidden="true"></div>
                <fieldset
                    id="encounterReferralProgramInfo"
                    x-show="showProgramInfo && infoProgram"
                    x-cloak
                    class="fieldset-card p-4 md:col-span-2"
                >
                    <legend class="legend" x-text="infoProgram?.name"></legend>
                    <p class="text-sm text-gray-500 dark:text-gray-300">
                        {{ __('dictionaries.service_programs.care_plan_required') }}:
                        <span
                            x-text="infoProgram?.medical_program_settings?.care_plan_required === true ? @js(__('forms.yes')) : infoProgram?.medical_program_settings?.care_plan_required === false ? @js(__('forms.no')) : @js(__('encounters.referral_layout.not_specified'))"
                        ></span>
                    </p>
                    <p
                        x-show="infoProgram?.is_demo"
                        class="mt-2 text-sm text-gray-500 dark:text-gray-300"
                    >
                        {{ __('encounters.referral_layout.test_program_hint') }}
                    </p>
                </fieldset>
                <div class="form-group group">
                    <input
                        id="encounterReferralRequester"
                        type="text"
                        class="input peer w-full"
                        value="{{ auth()->user()?->activeDoctorEmployee()?->fullName ?: auth()->user()?->party?->fullName ?: '—' }}"
                        readonly
                    />
                    <label
                        for="encounterReferralRequester"
                        class="label border-0!"
                        >{{ __('encounters.referral_layout.doctor') }}</label
                    >
                </div>
                <div class="form-group group">
                    <input
                        id="encounterReferralRequesterOrganization"
                        type="text"
                        class="input peer w-full"
                        value="{{ legalEntity()->name }}"
                        readonly
                    />
                    <label
                        for="encounterReferralRequesterOrganization"
                        class="label border-0!"
                        >{{ __('encounters.referral_layout.provider') }}</label
                    >
                </div>
                @if ($encounterReferralIsTransfer)
                    <div class="form-group group md:col-span-2">
                        <input
                            type="text"
                            id="encounterReferralPerformer"
                            class="input peer w-full"
                            value="{{ $encounterReferralPerformerName }}"
                            readonly
                        />
                        <label
                            for="encounterReferralPerformer"
                            class="label border-0! required"
                            >{{ __('encounters.referral_destination') }}</label
                        >
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('encounters.referral_destination_from_discharge') }}
                        </p>
                    </div>
                    <div class="form-group group">
                        <select
                            id="encounterReferralLocation"
                            class="input-select peer w-full"
                            wire:model="encounterReferralForm.location_reference"
                        >
                            <option value="">
                                {{ __('encounters.referral_select_division') }}
                            </option>

                            @foreach ($encounterReferralDivisions as $division)
                                <option value="{{ $division['id'] }}">
                                    {{ $division['name'] }}
                                </option>

                            @endforeach
                        </select>
                        <label
                            for="encounterReferralLocation"
                            class="label border-0! required"
                            >{{ __('encounters.referral_performer_division') }}</label
                        >
                    </div>
                    <div class="form-group group">
                        <select
                            id="encounterReferralPerformerType"
                            class="input-select peer w-full"
                            wire:model="encounterReferralForm.performer_type"
                        >
                            <option value="">
                                {{ __('encounters.referral_not_selected') }}
                            </option>

                            @foreach ($encounterReferralSpecialities as $code => $label)
                                <option
                                    value="{{ match ($code) { 'transfer' => 'transfer_of_care', 'counseling' => 'counselling', default => $code } }}"
                                >
                                    {{ $label }}
                                </option>

                            @endforeach
                        </select>
                        <label
                            for="encounterReferralPerformerType"
                            class="label border-0!"
                            >{{ __('encounters.referral_performer_speciality') }}</label
                        >
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('encounters.referral_recipient_speciality_help') }}
                        </p>
                    </div>

                @endif

                @unless ($referralPrototypeEnabled)
                    <div class="form-group group md:col-span-2">
                        <select
                            id="encounterReferralInformWith"
                            class="input-select peer w-full"
                            wire:model="encounterReferralForm.inform_with"
                        >
                            <option value="">
                                {{ __('encounters.referral_layout.without_notification') }}
                            </option>
                            @foreach ($encounterReferralAuthMethods as $method)
                                <option
                                    value="{{ \App\Services\MedicalEvents\InformWith::formValue($method) }}"
                                >
                                    {{ $method['label'] ?? $method['type'] ?? $method['uuid'] ?? '' }}
                                </option>
                            @endforeach
                        </select>
                        <label
                            for="encounterReferralInformWith"
                            class="label border-0!"
                        >
                            {{ __('referrals.inform_patient') }}
                        </label>
                        <x-input-error
                            for="encounterReferralForm.inform_with"
                        />
                    </div>
                @endunless

                <div class="form-group group md:col-span-2">
                    <label
                        for="encounterReferralNote"
                        class="label-secondary"
                        >{{ __('encounters.referral_layout.doctor_notes') }}</label
                    >
                    <textarea
                        id="encounterReferralNote"
                        class="textarea min-h-32 bg-gray-50! dark:bg-gray-900/40!"
                        wire:model="encounterReferralForm.note"
                        placeholder="{{ __('encounters.referral_layout.comment_placeholder') }}"
                    ></textarea>
                </div>
                <div class="form-group group md:col-span-2">
                    <label
                        for="encounterReferralPatientInstruction"
                        class="label-secondary"
                        >{{ __('encounters.referral_layout.patient_notes') }}</label
                    >
                    <textarea
                        id="encounterReferralPatientInstruction"
                        class="textarea min-h-32 bg-gray-50! dark:bg-gray-900/40!"
                        wire:model="encounterReferralForm.patient_instruction"
                        placeholder="{{ __('encounters.referral_layout.comment_placeholder') }}"
                    ></textarea>
                </div>
            </div>
        </fieldset>

        @foreach (['reason_reference' => 'reason_records', 'supporting_info' => 'supporting_records'] as $field => $title)
            @php
                $selectedReferences = collect($encounterReferralForm[$field] ?? []);
                if (!$referralPrototypeEnabled && !$hasReferenceSearch && $selectedReferences->isEmpty()) continue;
                $referenceGroups = [
                    ($field === 'reason_reference' ? 'reason' : 'supporting') => [
                        'records' => $selectedReferences,
                        'label' => $field === 'reason_reference' ? 'add_reason_records' : 'add_supporting_records',
                        'preset' => '',
                    ],
                ];
            @endphp
            <fieldset
                @disabled ($formDisabled)
                class="fieldset-card p-4 sm:p-6"
                data-reference-field="{{ $field }}"
            >
                <legend class="legend">
                    {{ __('encounters.referral_layout.'.$title) }}
                </legend>
                <div class="mt-2 space-y-6">
                    @foreach ($referenceGroups as $referenceGroup => $group)
                        <div>
                            @include ('livewire.encounter-package.parts.referral-reference-table', ['references' => $group['records']])
                            @if ($referralPrototypeEnabled || $hasReferenceSearch)
                            <button
                                type="button"
                                class="item-add flex! w-fit text-left"
                                data-reference-add="{{ $referenceGroup }}"
                                @click="selectReferenceField(@js($field), @js($group['preset']))"
                                @unless ($referralPrototypeEnabled) wire:click="loadReferralReferenceOptions('{{ $field }}')" @endunless
                            >
                                {{ __('encounters.referral_layout.'.$group['label']) }}
                            </button>
                            @endif
                        </div>
                    @endforeach
                    <x-input-error for="encounterReferralForm.{{ $field }}" />
                </div>
            </fieldset>
        @endforeach
        @if ($encounterReferralWarningMessage !== '')
            <div
                class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"
                role="alert"
            >
                {{ $encounterReferralWarningMessage }}
            </div>
        @endif
        <div class="flex flex-wrap items-center justify-start gap-3">
            @if ($referralPrototypeEnabled)
                <button type="button" class="button-primary-outline-red px-4 py-2 text-sm"
                    @click="if (discardFormDraft()) window.location.assign(@js($encounterReturnUrl))">
                    {{ __('forms.delete') }}
                </button>
            @else
                <a href="{{ $encounterReturnUrl }}" class="button-primary-outline-red px-4 py-2 text-sm">
                    {{ __('forms.cancel') }}
                </a>
            @endif
            @if (!$formDisabled)
                <button
                    type="button"
                    class="button-primary-outline inline-flex items-center gap-2 px-4 py-2 text-sm"
                    @if ($referralPrototypeEnabled)
                        @click="
                            $dispatch(
                                'referral-prototype-save',
                                prototypePayload(),
                            )
                        "
                    @else
                        wire:click="saveEncounterReferralDraft"
                    @endif
                    wire:loading.attr="disabled"
                    @unless ($referralPrototypeEnabled)
                        :disabled="selectedProgram?.is_demo === true"
                    @endunless
                    wire:target="saveEncounterReferralDraft,validateEncounterReferral,signEncounterReferral,sign"
                >
                    <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12l4 4v12a2 2 0 0 1-2 2Z" />
                        <path d="M17 21v-8H7v8M7 3v5h8" />
                    </svg>
                    {{ __('forms.save') }}
                </button>
                <button
                    type="submit"
                    class="button-primary px-4 py-2 text-sm"
                    wire:loading.attr="disabled"
                    @unless ($referralPrototypeEnabled)
                        :disabled="selectedProgram?.is_demo === true"
                    @endunless
                    wire:target="saveEncounterReferralDraft,validateEncounterReferral,signEncounterReferral,sign"
                >
                    {{ __('encounters.referral_layout.create') }}
                </button>
            @endif
            @if ($referralPrototypeEnabled)
                <span
                    x-show="prototypeSaved"
                    x-cloak
                    role="status"
                    class="text-sm text-gray-500 dark:text-gray-300"
                    >{{ __('encounters.referral_layout.preview_saved') }}</span
                >
            @endif
        </div>
    </form>
    @if ($referralPrototypeEnabled || $hasReferenceSearch)
    <x-dialog-drawer
        x-model="referencePickerOpen"
        maxWidth="4/5"
        customWidth="w-full lg:w-[calc(100%-19.5rem)]"
        overlayWidth="100%"
        onCloseClick="referencePickerOpen = false"
        :stopClickPropagation="true"
        zIndex="50"
    >
        <x-slot name="header">
            <livewire:components.header
                :key="'encounter-referral-records-header'"
            />
        </x-slot>
        <x-slot name="title">
            {{ __('encounters.search_medical_records') }}
        </x-slot>
        <div
            wire:loading.class="opacity-50"
            wire:target="loadReferralReferenceOptions,searchReferralReferenceOptions,loadMoreReferralReferenceOptions,loadMoreReferralEpisodeOptions,addReferralReference"
        >
            <div x-show="referenceField === 'reason_reference'">
                <x-encounter.medical-record-picker
                    :records="array_filter($encounterReferralReferenceOptions ?? [], static fn (array $record): bool => in_array($record['type'], ['condition', 'observation'], true))"
                    :recordTypes="array_intersect_key($referenceLabels, array_flip(['condition', 'observation']))"
                    :episodes="$encounterReferralEpisodes ?? []"
                    :loadMoreEpisodesAction="$referralPrototypeEnabled ? '' : 'loadMoreReferralEpisodeOptions'"
                    :episodesHasMore="!$referralPrototypeEnabled && ($encounterReferralEpisodesHasMore ?? false)"
                    :withEpisodeFilter="true"
                    :showTypeColumn="true"
                    :showQuery="true"
                    :searchAction="$referralPrototypeEnabled ? '' : 'searchReferralReferenceOptions'"
                    :recordsModel="$referralPrototypeEnabled ? '' : 'encounterReferralReferenceOptions'"
                    :loadMoreAction="$referralPrototypeEnabled ? '' : 'loadMoreReferralReferenceOptions'"
                    :hasMore="!$referralPrototypeEnabled && ($encounterReferralReferenceHasMore ?? false)"
                    selectionIcon="plus-circle"
                    selection-event="encounter-referral-reason-record-selected"
                    is-added-check="isReasonReferenceAdded"
                    data-selection-scope="reason_reference"
                    x-effect="
                        if (referencePickerOpen && prototypeEnabled) {
                            recordType = referencePresetType;
                            episodeId = '';
                        }
                    "
                />
            </div>
            <div x-show="referenceField === 'supporting_info'">
                <x-encounter.medical-record-picker
                    :records="array_values($encounterReferralReferenceOptions ?? [])"
                    :recordTypes="['condition' => $referenceLabels['condition'], 'episode' => $referenceLabels['episode'], 'observation' => $referenceLabels['observation'], 'diagnostic_report' => $referenceLabels['diagnostic_report']]"
                    :episodes="$encounterReferralEpisodes ?? []"
                    :loadMoreEpisodesAction="$referralPrototypeEnabled ? '' : 'loadMoreReferralEpisodeOptions'"
                    :episodesHasMore="!$referralPrototypeEnabled && ($encounterReferralEpisodesHasMore ?? false)"
                    :withEpisodeFilter="true"
                    :showTypeColumn="true"
                    :showQuery="true"
                    :searchAction="$referralPrototypeEnabled ? '' : 'searchReferralReferenceOptions'"
                    :recordsModel="$referralPrototypeEnabled ? '' : 'encounterReferralReferenceOptions'"
                    :loadMoreAction="$referralPrototypeEnabled ? '' : 'loadMoreReferralReferenceOptions'"
                    :hasMore="!$referralPrototypeEnabled && ($encounterReferralReferenceHasMore ?? false)"
                    selectionIcon="plus-circle"
                    selection-event="encounter-referral-supporting-record-selected"
                    is-added-check="isSupportingReferenceAdded"
                    data-selection-scope="supporting_info"
                    x-effect="
                        if (referencePickerOpen && prototypeEnabled) {
                            recordType = referencePresetType;
                            episodeId = '';
                        }
                    "
                />
            </div>
        </div>
        <button
            type="button"
            @click="referencePickerOpen = false"
            class="button-minor mt-6"
        >
            {{ __('forms.cancel') }}
        </button>
    </x-dialog-drawer>
    @endif
    <x-dialog-drawer
        x-model="openServiceCatalog"
        x-bind:aria-hidden="!openServiceCatalog"
        onCloseClick="openServiceCatalog = false"
        maxWidth="4/5"
        customWidth="w-full lg:w-[calc(100%-19.5rem)]"
        overlayWidth="100%"
        :stopClickPropagation="true"
        zIndex="50"
    >
        <x-slot name="header">
            <livewire:components.header
                :key="'encounter-referral-catalog-header'"
            />
        </x-slot>
        <div data-referral-service-catalog>
            <livewire:dictionary.service-catalog
                lazy
                :legal-entity="legalEntity()"
                :selection-mode="true"
                :request-allowed-only="true"
                :allow-group-selection="true"
                :show-all-search-filters="true"
                selection-event="encounter-referral-service-selected"
                :key="'encounter-referral-service-catalog'"
            />
        </div>

        <div class="mt-8">
            <button
                type="button"
                @click="openServiceCatalog = false"
                class="button-minor"
            >
                {{ __('forms.cancel') }}
            </button>
        </div>
    </x-dialog-drawer>
</div>
