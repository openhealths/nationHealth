<x-layouts.patient :personId="$personId ?? null" :prepersonId="$prepersonId ?? null" :patientFullName="$patientFullName ?? ''" :activeTab="'device-dispenses'">
    <div class="breadcrumb-form shift-content px-4 pt-4 pb-10" x-data="{ showAdditionalParams: false }">
        <h2 class="mb-6 flex items-center gap-2 text-xl font-bold text-gray-900 dark:text-white">
            @icon('search', 'w-5 h-5')
            <span>{{ __('patients.search_device_dispenses') }}</span>
        </h2>

        <div class="mt-6 w-full">
            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <input type="text" class="input peer" wire:model.defer="filterDeviceId" placeholder=" " />
                    <label class="label">{{ __('patients.device_id') }}</label>
                </div>
                <div class="form-group group">
                    <input type="text" class="input peer" wire:model.defer="filterEncounterId" placeholder=" " />
                    <label class="label">{{ __('patients.encounter_id') }}</label>
                </div>
                <div class="form-group group">
                    <select class="input-select peer w-full" wire:model.defer="filterStatus">
                        <option value="">{{ __('forms.status.label') }}</option>
                        <option value="active">{{ __('forms.status.active') }}</option>
                    </select>
                    <label class="label">{{ __('forms.status.label') }}</label>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="search"
                        class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="button-primary-outline-red px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.reset_filters') }}
                    </button>
                    <button
                        type="button"
                        class="button-minor flex items-center gap-2 px-5 py-2.5 text-sm whitespace-nowrap"
                        @click.prevent="showAdditionalParams = ! showAdditionalParams"
                    >
                        @icon('adjustments', 'w-4 h-4 text-gray-500')
                        <span>{{ __('forms.additional_search_parameters') }}</span>
                    </button>
                </div>

                <div class="relative" x-data="{ openGroupActions: false }" @click.outside="openGroupActions = false">
                    <button
                        type="button"
                        @click="openGroupActions = ! openGroupActions"
                        class="button-primary-outline px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.group_actions') }}
                    </button>

                    <div
                        x-show="openGroupActions"
                        x-transition
                        x-cloak
                        class="absolute top-full right-0 z-10 mt-2 w-60 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-700"
                    >
                        <div class="py-1">
                            <button
                                type="button"
                                @click="openGroupActions = false"
                                class="dropdown-button !flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                            >
                                <span class="text-gray-500">
                                    @icon('close', 'w-4 h-4')
                                </span>
                                {{ __('patients.revoke_access') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="showAdditionalParams" x-transition x-cloak>
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <input type="text" class="input peer" wire:model.defer="filterEpisodeId" placeholder=" " />
                        <label class="label">{{ __('episodes.id') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" wire:model.defer="filterOrganization" placeholder=" " />
                        <label class="label">{{ __('patients.sgusoz') }}</label>
                    </div>
                    <div class="form-group group">
                        <select class="input-select peer w-full" wire:model.defer="filterPractitioner">
                            <option value="">{{ __('forms.select') }}</option>
                            <option value="1">Сидоренко І.В.</option>
                        </select>
                        <label class="label">{{ __('forms.employee') }}</label>
                    </div>
                </div>

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <input type="text" class="input peer" wire:model.defer="filterProcedureId" placeholder=" " />
                        <label class="label">{{ __('patients.procedure_id') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" wire:model.defer="filterCarePlanId" placeholder=" " />
                        <label class="label">{{ __('patients.care_plan_id') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" wire:model.defer="filterRelatedEpisodeId" placeholder=" " />
                        <label class="label">{{ __('patients.related_prescription_episode_id') }}</label>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <div
                            class="datepicker-wrapper"
                            x-data="{
                                from: $wire.entangle('filterDispenseDateFrom'),
                                to: $wire.entangle('filterDispenseDateTo'),
                                rangeText: '',
                            }"
                            x-init="
                                if (from && to) rangeText = from + ' - ' + to;
                                $watch('from', (val) => {
                                    if (! val) {
                                        rangeText = '';
                                        const fp = $el.querySelector('input')._flatpickr;
                                        if (fp) fp.clear();
                                    }
                                });
                                $watch('to', (val) => {
                                    if (! val) {
                                        rangeText = '';
                                        const fp = $el.querySelector('input')._flatpickr;
                                        if (fp) fp.clear();
                                    }
                                });
                            "
                        >
                            <input
                                x-model="rangeText"
                                @change="
                                    const parts = $event.target.value.split(' - ');
                                    if (parts.length === 2) {
                                        from = parts[0];
                                        to = parts[1];
                                    } else if (! $event.target.value) {
                                        from = '';
                                        to = '';
                                    }
                                "
                                type="text"
                                class="daterangepicker-uk with-leading-icon input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label class="wrapped-label">{{ __('patients.filter_dispense_date_range') }}</label>
                        </div>
                    </div>
                    <div class="form-group group">
                        <div
                            class="datepicker-wrapper"
                            x-data="{
                                from: $wire.entangle('filterCreatedAtFrom'),
                                to: $wire.entangle('filterCreatedAtTo'),
                                rangeText: '',
                            }"
                            x-init="
                                if (from && to) rangeText = from + ' - ' + to;
                                $watch('from', (val) => {
                                    if (! val) {
                                        rangeText = '';
                                        const fp = $el.querySelector('input')._flatpickr;
                                        if (fp) fp.clear();
                                    }
                                });
                                $watch('to', (val) => {
                                    if (! val) {
                                        rangeText = '';
                                        const fp = $el.querySelector('input')._flatpickr;
                                        if (fp) fp.clear();
                                    }
                                });
                            "
                        >
                            <input
                                x-model="rangeText"
                                @change="
                                    const parts = $event.target.value.split(' - ');
                                    if (parts.length === 2) {
                                        from = parts[0];
                                        to = parts[1];
                                    } else if (! $event.target.value) {
                                        from = '';
                                        to = '';
                                    }
                                "
                                type="text"
                                class="daterangepicker-uk with-leading-icon input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label class="wrapped-label">{{ __('patients.filter_created_at_range') }}</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($dispenses as $dispense)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox h-5 w-5" />
                            </div>

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('patients.medical_device') }}</div>
                                <div class="record-inner-value text-[16px] font-bold text-gray-900 dark:text-gray-100">
                                    {{ $dispense['name'] }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    <span class="badge-green"> {{ $dispense['status'] }} </span>
                                </div>
                            </div>

                            <div class="record-inner-action-col">
                                <div
                                    x-data="{
                                        open: false,
                                        toggle() {
                                            if (this.open) {
                                                return this.close();
                                            }
                                            this.$refs.button.focus();
                                            this.open = true;
                                        },
                                        close(focusAfter) {
                                            if (! this.open) return;
                                            this.open = false;
                                            focusAfter && focusAfter.focus();
                                        },
                                    }"
                                    @keydown.escape.prevent.stop="close($refs.button)"
                                    @focusin.window="! $refs.panel.contains($event.target) && close()"
                                    x-id="['dropdown-button']"
                                    class="relative"
                                >
                                    <button
                                        @click="toggle()"
                                        x-ref="button"
                                        :aria-expanded="open"
                                        :aria-controls="$id('dropdown-button')"
                                        type="button"
                                        class="record-inner-action-btn cursor-pointer rounded-lg p-2 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                    >
                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                    </button>

                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-ref="panel"
                                        x-transition.origin.top.right
                                        @click.outside="close($refs.button)"
                                        :id="$id('dropdown-button')"
                                        class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-600 dark:bg-gray-700"
                                    >
                                        <a
                                            href="javascript:void(0)"
                                            class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') }}
                                        </a>

                                        <button
                                            type="button"
                                            @click="close($refs.button)"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.status.entered_in_error') }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-4">
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.date_and_time_of_dispense') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $dispense['dispense_date'] }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.sgusoz') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $dispense['organization'] }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.procedure_id') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $dispense['procedure_id'] }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('forms.employee') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $dispense['practitioner'] }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.care_plan_id') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $dispense['care_plan_id'] }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.record_creation_date') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $dispense['created_at'] }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.related_prescription_episode') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $dispense['related_episode_id'] }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.dispense_id') }}
                                    </div>
                                    <div class="record-inner-id-value">{{ $dispense['dispense_id'] }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.encounter_id') }}
                                    </div>
                                    <div class="record-inner-id-value">{{ $dispense['encounter_id'] }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">{{ __('episodes.id') }}</div>
                                    <div class="record-inner-id-value">{{ $dispense['episode_id'] }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.patient>
