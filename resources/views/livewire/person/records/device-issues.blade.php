<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :activeTab="'device-issues'"
>
    <x-slot name="headerActions">
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                <span>{{ __('patients.starts_interacting') }}</span>
            </button>
            <button
                type="button"
                class="button-primary-outline px-4 py-2 text-sm shadow-sm"
                style="margin: 0 !important"
            >
                {{ __('patients.data_access') }}
            </button>
            <button
                type="button"
                class="button-sync flex items-center gap-2 px-4 py-2 text-sm shadow-sm"
                style="margin: 0 !important"
            >
                @icon('refresh', 'w-4 h-4')
                <span>{{ __('forms.synchronise_with_eHealth') }}</span>
            </button>
        </div>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full" x-data="{ showAdditionalParams: false }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.search_detected_medical_device_problems') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <input type="text" class="input peer" wire:model.defer="filterIssueId" placeholder=" " />
                    <label class="label">{{ __('patients.medical_device_id') }}</label>
                </div>
                <div class="form-group group">
                    <input type="text" class="input peer" wire:model.defer="filterEncounterId" placeholder=" " />
                    <label class="label">{{ __('patients.encounter_id') }}</label>
                </div>
                <div class="form-group group">
                    <select class="input-select peer w-full" wire:model.defer="filterStatus">
                        <option value="">{{ __('forms.status.active') }}</option>
                        <option value="inactive">{{ __('forms.status.non_active') }}</option>
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
                        Скинути фільтри
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
                        Групові дії
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
                        <label class="label">{{ __('devices.sgusoz') }}</label>
                    </div>
                    <div class="form-group group">
                        <select class="input-select peer w-full" wire:model.defer="filterPractitioner">
                            <option value="">Сидоренко І.В.</option>
                            <option value="1">Сидоренко І.В.</option>
                        </select>
                        <label class="label">{{ __('forms.employee') }}</label>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <div
                            class="datepicker-wrapper"
                            x-data="{
                                from: $wire.entangle('filterDetectedAtFrom'),
                                to: $wire.entangle('filterDetectedAtTo'),
                                rangeText: '',
                            }"
                            x-init="
                                if (from && to) rangeText = from + ' — ' + to;
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
                                    const parts = $event.target.value.split(' — ');
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
                            <label class="wrapped-label">{{ __('patients.date_and_time_of_problem_detection') }}</label>
                        </div>
                    </div>
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input
                                wire:model.defer="filterCreatedAt"
                                type="text"
                                class="datepicker-input with-leading-icon input peer"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label class="wrapped-label">{{ __('patients.record_creation_date') }}</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($issues as $issue)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox h-5 w-5" />
                            </div>

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('patients.medical_device_name') }}</div>
                                <div class="record-inner-value text-[16px] font-bold text-gray-900 dark:text-gray-100">
                                    {{ $issue['name'] }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    <span class="badge-green"> {{ $issue['status'] }} </span>
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
                                        class="record-inner-action-btn cursor-pointer"
                                    >
                                        @icon('edit-user-outline', 'w-5 h-5')
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
                                        <button
                                            type="button"
                                            @click="close($refs.button)"
                                            class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') }}
                                        </button>

                                        <button
                                            type="button"
                                            @click="close($refs.button)"
                                            class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
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
                                                {{ __('patients.medical_device_id') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $issue['device_id'] }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('devices.sgusoz') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $issue['organization'] }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.date_and_time_of_problem_detection') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $issue['detected_at'] }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                Працівник
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $issue['practitioner'] }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0"></div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.record_creation_date') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ $issue['created_at'] }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="min-w-0 space-y-2.5">
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.issue_id') }}
                                    </div>
                                    <div class="record-inner-id-value">{{ $issue['issue_id'] }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.encounter_id') }}
                                    </div>
                                    <div class="record-inner-id-value">{{ $issue['encounter_id'] }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">{{ __('episodes.id') }}</div>
                                    <div class="record-inner-id-value">{{ $issue['episode_id'] }}</div>
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
