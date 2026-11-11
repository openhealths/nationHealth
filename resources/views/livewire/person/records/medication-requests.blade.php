@use('App\Enums\Person\MedicationRequestStatus')
@use('App\Models\MedicalEvents\Sql\Encounter')

<x-layouts.patient
    :showLegacyMessages="false"
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />

    <x-slot name="headerActions">
        <div class="flex w-full flex-wrap items-center gap-2">
            @can('create', Encounter::class)
                <a
                    href="{{
                        $prepersonId
                        ? route('prepersons.encounters.create', [legalEntity(), 'preperson' => $prepersonId])
                        : route('persons.encounters.create', [legalEntity(), 'person' => $personId])
                    }}"
                    class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('encounters.new') }}
                </a>
            @endcan

            @if ($personId)
                <livewire:person.records.patient-approval
                    :person-id="$personId"
                    :key="'prescriptions-patient-approval-'.$personId"
                />
            @endif

            <button
                type="button"
                wire:click="synchronizeSignedPrescriptions"
                wire:loading.attr="disabled"
                wire:target="synchronizeSignedPrescriptions"
                class="button-sync flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon('refresh', 'w-4 h-4')
                <span>{{ __('medication-requests.synchronize_signed_details') }}</span>
            </button>
        </div>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full" x-data="{ showAdditionalParams: false }">
            <div class="summary-nav-row mb-6" role="group" aria-label="{{ __('patients.prescriptions') }}">
                <button
                    wire:click="switchTab('requests')"
                    type="button"
                    @class(['summary-tab cursor-pointer', 'summary-tab-active' => $activeTab === 'requests', 'summary-tab-inactive' => $activeTab !== 'requests'])
                    aria-pressed="{{ $activeTab === 'requests' ? 'true' : 'false' }}"
                >
                    {{ __('medication-requests.zaiavky_na_retsiepty') }} ({{ count($medicationRequests) }})
                </button>
                <button
                    wire:click="switchTab('prescriptions')"
                    type="button"
                    @class(['summary-tab cursor-pointer', 'summary-tab-active' => $activeTab === 'prescriptions', 'summary-tab-inactive' => $activeTab !== 'prescriptions'])
                    aria-pressed="{{ $activeTab === 'prescriptions' ? 'true' : 'false' }}"
                >
                    {{ __('medication-requests.retsiepty') }} ({{ count($prescriptions) }})
                </button>
                <div class="flex-1"></div>
            </div>

            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>
                    {{ $activeTab === 'requests' ? __('medication-requests.poshuk_zaiavok_na_retsepti') : __('medication-requests.poshuk_retseptiv') }}
                </p>
            </div>

            <form wire:submit="applyFilters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="searchRequestNumber"
                            wire:model="searchRequestNumber"
                            placeholder=" "
                            autocomplete="off"
                            class="input peer"
                        />
                        <label for="searchRequestNumber" class="label">{{ __('Номер рецепту / запиту') }}</label>
                    </div>
                    <div class="form-group group">
                        <select id="filterStatus" wire:model="filterStatus" class="input-select peer">
                            <option value="">{{ __('Будь-який статус') }}</option>
                            @foreach (MedicationRequestStatus::cases() as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>
                        <label for="filterStatus" class="label">{{ __('forms.status.label') }}</label>
                    </div>
                </div>

                <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="applyFilters"
                            class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                        >
                            @icon('search', 'w-4 h-4')
                            <span>{{ __('medication-requests.search_local') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="searchInEHealth"
                            wire:loading.attr="disabled"
                            wire:target="searchInEHealth, saveFromEHealth"
                            class="button-primary-outline flex items-center gap-2 px-5 py-2.5 text-sm"
                        >
                            @icon('search', 'w-4 h-4')
                            <span>{{ __('medication-requests.search_in_ehealth') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="resetFilters"
                            @click="
                                $el.form.querySelectorAll('[data-referral-date-range]').forEach((input) => {
                                    input._flatpickr?.clear(false);
                                    input.setCustomValidity('');
                                })
                            "
                            class="button-primary-outline-red px-5 py-2.5 text-sm"
                        >
                            {{ __('forms.reset_all_filters') }}
                        </button>
                        @if (!$isSearchMode)
                            <button
                                type="button"
                                class="button-minor flex items-center gap-2 px-5 py-2.5 text-sm"
                                @click="showAdditionalParams = ! showAdditionalParams"
                                :aria-expanded="showAdditionalParams"
                                aria-controls="prescriptionPeriodFilters"
                            >
                                @icon('adjustments', 'w-4 h-4')
                                <span>{{ __('forms.additional_search_parameters') }}</span>
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="resetSearch"
                                class="button-minor flex items-center gap-2 px-5 py-2.5 text-sm"
                            >
                                @icon('arrow-left', 'w-4 h-4')
                                <span>{{ __('Локальні дані') }}</span>
                            </button>
                        @endif
                    </div>
                </div>

                <div id="prescriptionPeriodFilters" x-show="showAdditionalParams" x-transition x-cloak>
                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Період лікування в картці пацієнта') }}
                    </p>
                    <div class="form-row-3 mb-9">
                        @foreach ([
                                ['id' => 'filterStartedAtRange', 'from' => 'filterStartedAtFrom', 'to' => 'filterStartedAtTo', 'label' => __('forms.filter_period_start_range')],
                                ['id' => 'filterEndedAtRange', 'from' => 'filterEndedAtFrom', 'to' => 'filterEndedAtTo', 'label' => __('forms.filter_period_end_range')],
                            ] as $dateRange)
                            <div class="form-group group">
                                <div
                                    class="datepicker-wrapper"
                                    x-data="{ from: $wire.entangle('{{ $dateRange['from'] }}'), to: $wire.entangle('{{ $dateRange['to'] }}') }"
                                    x-effect="
                                        if (! from && ! to) {
                                            $refs.input?._flatpickr?.clear(false);
                                            $refs.input?.setCustomValidity('');
                                        }
                                    "
                                >
                                    <input
                                        x-ref="input"
                                        id="{{ $dateRange['id'] }}"
                                        type="text"
                                        data-referral-date-range
                                        class="daterangepicker-uk with-leading-icon input peer w-full @error($dateRange['from']) input-error @enderror @error($dateRange['to']) input-error @enderror"
                                        placeholder=" "
                                        autocomplete="off"
                                        :value="[from, to]
                                            .filter(Boolean)
                                            .map((date) =>
                                                /^\d{4}-\d{2}-\d{2}$/.test(date)
                                                    ? date.split('-').reverse().join('.')
                                                    : date,
                                            )
                                            .join(' — ')"
                                        @date-range-change="
                                            const valid =
                                                ! $event.detail.from || /^\d{4}-\d{2}-\d{2}$/.test($event.detail.from);
                                            $refs.input.setCustomValidity(
                                                valid ? '' : 'Вкажіть коректну дату у форматі дд.мм.рррр.',
                                            );
                                            if (valid) {
                                                from = $event.detail.from;
                                                to = $event.detail.to;
                                            }
                                        "
                                    />
                                    <label
                                        for="{{ $dateRange['id'] }}"
                                        class="wrapped-label"
                                    >{{ $dateRange['label'] }}</label>
                                </div>
                                @error($dateRange['from'])
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                                @error($dateRange['to'])
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="form-row-3 my-6">
                    @foreach ($additionalFilters as $filter => $value)
                        <div class="form-group">
                            <label class="label">{{ __('medication-requests.filters.'.$filter) }}</label>
                            <input
                                type="{{ str_contains($filter, 'created_') || str_contains($filter, 'dispense_') ? 'date' : 'text' }}"
                                class="input w-full"
                                wire:model="additionalFilters.{{ $filter }}"
                            />
                            @error('additionalFilters.'.$filter)
                                <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </form>

            @if ($searchError)
                <div
                    class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300"
                    role="alert"
                >
                    {{ $searchError }}
                </div>
            @endif

            @if ($isSearchMode)
                <div class="mb-6 flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-700 dark:bg-blue-900/20 dark:text-blue-300">
                    @icon('info-circle', 'w-4 h-4 shrink-0')
                    <span>{{ __('Результати пошуку в ЕСОЗ — натисніть «Зберегти до картки», щоб зберегти запис локально') }}</span>
                </div>
            @else
                <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                    {{ $activeTab === 'requests' ? __('medication-requests.legacy_import_note') : __('Рецепти, збережені в картці пацієнта.') }}
                </p>
            @endif

            @php
                $rows = $isSearchMode ? $eHealthResults : ($activeTab === 'requests' ? $medicationRequests : $prescriptions);
            @endphp
            <div class="space-y-4">
                @forelse ($rows as $record)
                    @php
                        $recordUuid = $record['uuid'] ?? ($record['id'] ?? '');
                        $importId = $record['id'] ?? ($record['uuid'] ?? '');
                        $status = MedicationRequestStatus::resolve($record['status'] ?? '');
                    @endphp
                    <div
                        class="record-inner-card"
                        wire:key="medication-{{ $activeTab }}-{{ (int) $isSearchMode }}-{{ $recordUuid }}"
                    >
                        <div class="record-inner-header">
                            <div class="record-inner-column min-w-0 flex-1">
                                <div class="record-inner-label">{{ __('Номер') }}</div>
                                <div class="record-inner-value text-[16px] break-words">
                                    {{ $record['requestNumber'] ?? ($record['request_number'] ?? '—') }}
                                </div>
                                @if (!empty($record['categoryLabel']))
                                    <div class="record-inner-subvalue mt-1">{{ $record['categoryLabel'] }}</div>
                                @endif
                            </div>
                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    <span class="{{ $record['statusBadge'] ?? ($status?->color() ?? 'badge-dark') }}">
                                        {{ $record['statusLabel'] ?? ($status?->label() ?? ($record['status'] ?? '—')) }}
                                    </span>
                                </div>
                            </div>
                            <div class="record-inner-action-col">
                                <div
                                    class="relative"
                                    x-data="{ open: false }"
                                    x-id="['medication-actions']"
                                    @click.outside="open = false"
                                    @keydown.escape.prevent.stop="
                                        open = false;
                                        $refs.button.focus();
                                    "
                                >
                                    <button
                                        type="button"
                                        x-ref="button"
                                        @click="open = ! open"
                                        :aria-expanded="open"
                                        :aria-controls="$id('medication-actions')"
                                        aria-label="{{ __('forms.actions') }}"
                                        class="record-inner-action-btn cursor-pointer rounded-lg p-2 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                    >
                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                    </button>
                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-transition
                                        :id="$id('medication-actions')"
                                        class="dropdown-menu right-0 w-56"
                                    >
                                        <a
                                            class="button-minor"
                                            href="{{ route($activeTab === 'requests' ? 'persons.prescription-requests.view' : 'persons.medication-requests.view', [legalEntity(), 'person' => $personId, 'requestId' => $recordUuid]) }}"
                                        >{{ __('forms.details') }}</a>
                                        <button
                                            type="button"
                                            wire:click="toggleExpand('{{ $recordUuid }}')"
                                            @click="open = false"
                                            class="dropdown-item"
                                        >
                                            @icon('eye', 'w-4 h-4 shrink-0')
                                            {{ $expandedUuid === $recordUuid ? __('Приховати деталі') : __('forms.view_details') }}
                                        </button>
                                        @if ($isSearchMode && $importId !== '')
                                            <button
                                                type="button"
                                                wire:click="saveFromEHealth('{{ $importId }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="saveFromEHealth"
                                                @click="open = false"
                                                class="dropdown-item"
                                            >
                                                @icon('download', 'w-4 h-4 shrink-0')
                                                {{ __('Зберегти до картки') }}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="record-inner-body">
                            <div class="record-inner-grid-container min-w-0">
                                <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('Медикамент') }}</div>
                                        <div class="record-inner-value break-words">
                                            {{ $record['medicationName'] ?? (data_get($record, 'medication_info.medication_name') ?? (data_get($record, 'medication.name') ?? '—')) }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('Кількість') }}</div>
                                        <div class="record-inner-value">
                                            {{ $record['medicationQty'] ?? ($record['medication_qty'] ?? '—') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('Період') }}</div>
                                        <div class="record-inner-value">{{ $record['periodLabel'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('Програма') }}</div>
                                        <div class="record-inner-value break-words">
                                            {{ $record['programName'] ?? (data_get($record, 'medical_program.name') ?? '—') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('Підстава') }}</div>
                                        <div class="record-inner-value">
                                            @include('livewire.person.records.parts.medication-request-basis')
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="record-inner-id-col">
                                <div>
                                    <div class="record-inner-label">{{ __('Ідентифікатор') }}</div>
                                    <div class="record-inner-id-value">{{ $recordUuid ?: '—' }}</div>
                                </div>
                            </div>
                        </div>
                        @if ($expandedUuid === $recordUuid)
                            <div
                                class="border-t border-gray-200 p-4 dark:border-gray-700"
                                wire:key="prescription-details-{{ $recordUuid }}"
                            >
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <div class="record-inner-label">{{ __('Підстава') }}</div>
                                        <div class="record-inner-value">
                                            @include('livewire.person.records.parts.medication-request-basis')
                                        </div>
                                    </div>
                                    @if (is_string($record['note'] ?? null) && filled($record['note']))
                                        <div>
                                            <div class="record-inner-label">{{ __('Примітка') }}</div>
                                            <div class="record-inner-value break-words">{{ $record['note'] }}</div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <x-nothing-found
                        :title="$isSearchMode ? __('Нічого не знайдено за параметрами пошуку.') : ($activeTab === 'requests' ? __('Рецепт-запити не знайдено у локальній базі даних.') : __('Рецепти з ЕСОЗ ще не завантажені до картки пацієнта. Скористайтесь пошуком.'))"
                        :description="null"
                    />
                @endforelse
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
