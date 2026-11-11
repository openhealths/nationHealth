@use('App\Enums\Person\DeviceRequestStatus')
<x-layouts.patient
    :personId="$personId"
    :patientFullName="$person->fullName"
    activeTab="device-requests"
    :showLegacyMessages="false"
>
    <x-slot name="description">{{ __('device-requests.patient.tab') }}</x-slot>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <div class="breadcrumb-form shift-content p-4" x-data="{ showFilters: false, selectedRecords: [] }">
        <div class="mt-6 mb-6 flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
            @icon('search-outline', 'w-5 h-5')
            {{ __('device-requests.patient.search') }}
        </div>
        <form wire:submit="search">
            <div class="form-row-3">
                <div class="form-group group">
                    <select id="deviceRequestStatus" wire:model="filters.parameters.status" class="input-select peer">
                        <option value="">{{ __('device-requests.labels.all') }}</option>
                        <option value="draft">{{ __('forms.draft') }}</option>
                        @foreach (['active', 'completed', 'revoked', 'entered-in-error'] as $status)
                            <option value="{{ $status }}">{{ DeviceRequestStatus::from($status)->label() }}</option>
                        @endforeach
                    </select>
                    <label for="deviceRequestStatus" class="label">{{ __('forms.status.label') }}</label>
                    @error('filters.parameters.status')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="form-group group">
                    <select id="deviceRequestCode" wire:model="filters.parameters.code" class="input-select peer">
                        <option value="">{{ __('device-requests.labels.all') }}</option>
                        @foreach ($deviceTypes as $code => $name)
                            <option value="{{ $code }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <label for="deviceRequestCode" class="label">{{ __('device-requests.labels.device_type') }}</label>
                    @error('filters.parameters.code')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="form-group group">
                    <select id="deviceRequestProgram" wire:model="filters.parameters.program" class="input-select peer">
                        <option value="">{{ __('device-requests.labels.all') }}</option>
                        @foreach ($programs as $program)
                            <option value="{{ $program['id'] }}">{{ $program['name'] }}</option>
                        @endforeach
                    </select>
                    <label
                        for="deviceRequestProgram"
                        class="label"
                    >{{ __('device-requests.labels.program_short') }}</label>
                    @error('filters.parameters.program')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        class="button-primary flex items-center gap-2"
                        @click="selectedRecords = []"
                        @disabled(!$canReadRequests)
                    >
                        @icon('search', 'w-4 h-4')
                        {{ __('forms.search') }}
                    </button>
                    <button
                        type="button"
                        class="button-primary-outline-red"
                        wire:click="resetFilters"
                        @click="selectedRecords = []"
                    >
                        {{ __('forms.reset_all_filters') }}
                    </button>
                    <button
                        type="button"
                        class="button-minor flex items-center gap-2"
                        @click="showFilters = ! showFilters"
                        :aria-expanded="showFilters"
                        aria-controls="deviceRequestExtraFilters"
                    >
                        @icon('adjustments', 'w-4 h-4')
                        {{ __('forms.additional_search_parameters') }}
                    </button>
                </div>
            </div>
            <div id="deviceRequestExtraFilters" class="form-row-3 mb-8" x-show="showFilters" x-cloak>
                @foreach (['context_episode_id' => 'episode', 'requester_legal_entity' => 'entity', 'encounter' => 'encounter'] as $filter => $label)
                    <div class="form-group group">
                        <input
                            id="deviceRequest-{{ $filter }}"
                            wire:model="filters.parameters.{{ $filter }}"
                            class="input peer pr-7"
                            type="text"
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label
                            for="deviceRequest-{{ $filter }}"
                            class="label"
                        >{{ __('device-requests.labels.'.$label) }}</label>
                        <button
                            type="button"
                            class="absolute top-2 right-0 text-gray-500"
                            wire:click="$set('filters.parameters.{{ $filter }}', '')"
                            aria-label="{{ __('forms.reset_all_filters') }}: {{ __('device-requests.labels.'.$label) }}"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>
                        @error('filters.parameters.'.$filter)
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>
        </form>
        <p class="mb-3 text-sm text-gray-500" x-show="selectedRecords.length" x-cloak>
            {{ __('device-requests.patient.selected') }}: <span x-text="selectedRecords.length"></span>
        </p>
        <div class="space-y-4" wire:key="device-request-results-{{ md5(json_encode($filters).$page) }}">
            @forelse ($records as $record)
                @php
                    $requestId = $record['uuid'] ?? $record['id'];
                    $deviceName = data_get($record, 'code_reference.display_value') ?? (data_get($record, 'code.text') ?? (data_get($record, 'code.coding.0.display') ?? ($deviceTypes[data_get($record, 'code.coding.0.code')] ?? data_get($record, 'code.coding.0.code', '-'))));
                    $status = DeviceRequestStatus::tryFrom($record['status']);
                    $programName = data_get($record, 'program.display_value') ?? (collect($programs)->firstWhere('id', data_get($record, 'program.identifier.value'))['name'] ?? '-');
                @endphp
                <article class="record-inner-card" wire:key="device-request-{{ $requestId }}">
                    <div class="record-inner-header">
                        <div class="record-inner-checkbox-col">
                            <input
                                type="checkbox"
                                class="default-checkbox"
                                value="{{ $requestId }}"
                                x-model="selectedRecords"
                                aria-label="{{ __('forms.select') }}: {{ $record['requisition'] ?? $deviceName }}"
                            />
                        </div>
                        <div class="record-inner-column min-w-0 flex-1">
                            <div class="record-inner-label">{{ __('device-requests.labels.device_type') }}</div>
                            <div class="record-inner-value text-base break-words">{{ $deviceName }}</div>
                        </div>
                        <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                            <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                            <div>
                                <span class="{{ $status?->color() ?? 'badge-dark' }}">{{ $status?->label() ?? $record['status'] }}</span>
                            </div>
                        </div>
                        <div
                            class="record-inner-action-col"
                            x-data="{ open: false }"
                            @click.outside="open = false"
                            @keydown.escape.prevent.stop="open = false"
                            x-id="['device-request-actions']"
                        >
                            <button
                                type="button"
                                class="record-inner-action-btn cursor-pointer rounded-lg p-2 hover:bg-gray-50 dark:hover:bg-gray-700"
                                @click="open = ! open"
                                :aria-expanded="open"
                                :aria-controls="$id('device-request-actions')"
                                aria-label="{{ __('forms.actions') }}"
                            >
                                @icon('edit-user-outline', 'w-6 h-6')
                            </button>
                            <div
                                x-show="open"
                                x-cloak
                                class="dropdown-menu right-0 w-56"
                                :id="$id('device-request-actions')"
                            >
                                @if ($record['isDraft'] ?? false)
                                    <a
                                        class="dropdown-item"
                                        href="{{ route('persons.device-requests.create', [legalEntity(), 'person' => $personId, 'draft' => $requestId]) }}"
                                    >
                                        @icon('edit', 'w-5 h-5 text-gray-500')
                                        {{ __('device-requests.labels.open_draft') }}
                                    </a>
                                @else
                                    <a
                                        class="dropdown-item"
                                        href="{{ route('persons.device-requests.view', [legalEntity(), 'person' => $personId, 'deviceRequest' => $requestId]) }}"
                                    >
                                        @icon('eye', 'w-5 h-5 text-gray-500')
                                        {{ __('forms.view_details') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="record-inner-body">
                        <div class="record-inner-grid-container">
                            <div class="grid grid-cols-2 gap-4 xl:grid-cols-3">
                                @foreach ([
                                    'number' => $record['requisition'] ?? '-', 'program_short' => $programName,
                                    'created_at' => filled($record['authored_on'] ?? null) ? convertToAppDateFormat($record['authored_on']) : '-',
                                    'entity' => data_get($record, 'requester_legal_entity.display_value', '-'),
                                    'quantity' => data_get($record, 'quantity.value', '-'),
                                    'dispense_until' => filled($record['dispense_valid_to'] ?? null) ? convertToAppDateFormat($record['dispense_valid_to']) : '-',
                                ] as $label => $value)
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('device-requests.labels.'.$label) }}</div>
                                        <div class="record-inner-value break-words">{{ $value }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="record-inner-id-col">
                            @foreach (['encounter' => 'encounter.identifier.value', 'episode' => 'context.identifier.value'] as $label => $path)
                                <div>
                                    <div class="record-inner-label">{{ __('device-requests.labels.'.$label) }}</div>
                                    <div class="record-inner-id-value">{{ data_get($record, $path, '-') }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </article>
            @empty
                @if ($canReadRequests)
                    <x-nothing-found :description="null" />
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('device-requests.patient.read_access_required') }}
                    </p>
                @endif
            @endforelse
        </div>
        @if ($totalPages > 1)
            <div class="mt-6 flex items-center justify-center gap-4">
                <button
                    type="button"
                    wire:click="goToPage({{ $page - 1 }})"
                    @disabled($page <= 1)
                    class="button-minor"
                    @click="selectedRecords = []"
                >
                    {{ __('device-requests.labels.previous') }}
                </button>
                <span class="text-sm text-gray-500">{{ $page }} / {{ $totalPages }}</span>
                <button
                    type="button"
                    wire:click="goToPage({{ $page + 1 }})"
                    @disabled($page >= $totalPages)
                    class="button-minor"
                    @click="selectedRecords = []"
                >
                    {{ __('device-requests.labels.next') }}
                </button>
            </div>
        @endif
    </div>
    <x-forms.loading />
</x-layouts.patient>
