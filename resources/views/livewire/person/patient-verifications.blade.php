@use('App\Enums\Person\{Gender, VerificationStatus}')
@use('App\Livewire\Person\PatientVerifications')
@use('App\Models\DeclarationRequest')
@use('App\Models\MedicalEvents\Sql\{DiagnosticReport, Encounter, Episode, Procedure}')
@use('App\Models\Person\PersonRequest')
@use('App\Models\Preperson')

<div>
    <section>
        <x-header-navigation class="breadcrumb-form">
            <x-slot name="title">{{ __('patient-verifications.plural') }}</x-slot>
            <x-slot name="navigation">
                <div class="mb-8 block justify-end gap-4 sm:flex sm:items-center">
                    @can('create', Preperson::class)
                        <a
                            href="{{ route('persons.create', [legalEntity(), 'type' => 'preperson']) }}"
                            class="flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-800"
                        >
                            @icon('plus', 'w-4 h-4')
                            <span>{{ __('preperson.label_single') }}</span>
                        </a>
                    @endcan

                    @can('create', PersonRequest::class)
                        <a
                            href="{{ route('persons.create', [legalEntity()]) }}"
                            class="button-primary flex items-center gap-2"
                        >
                            @icon('plus', 'w-4 h-4')
                            <span>{{ __('patients.add_patient') }}</span>
                        </a>
                    @endcan
                </div>

                <div class="mb-8 flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <p>{{ __('patients.patient_search') }}</p>
                </div>

                <div class="form-row-4 mb-4">
                    <div class="form-group">
                        <select
                            wire:model="filterEmployeeId"
                            id="filterEmployeeId"
                            class="input-select peer"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee['uuid'] }}">
                                    {{ $employee['name'] }} — {{ $this->dictionaries['POSITION'][$employee['position']] ?? $employee['position'] }}
                                </option>
                            @endforeach
                        </select>
                        <label for="filterEmployeeId" class="label"> {{ __('forms.employee_id') }} </label>
                        @error('filterEmployeeId')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group">
                        <select
                            wire:model="filterVerificationStatus"
                            id="filterVerificationStatus"
                            class="input-select peer"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach ($this->dictionaries['PERSON_VERIFICATION_STATUSES'] as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label for="filterVerificationStatus" class="label">
                            {{ __('patients.verification_status') }}
                        </label>
                        @error('filterVerificationStatus')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group">
                        <select
                            wire:model="filterStatus"
                            id="filterStatus"
                            class="input-select peer"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach ($this->dictionaries['PERSON_STATUSES'] as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label for="filterStatus" class="label"> {{ __('forms.status.label') }} </label>
                        @error('filterStatus')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group">
                        <select
                            wire:model="filterDracsDeathStatus"
                            id="filterDracsDeathStatus"
                            class="input-select peer"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach ($this->dictionaries['PERSON_VERIFICATION_STATUSES'] as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label for="filterDracsDeathStatus" class="label">
                            {{ __('patient-verifications.dracs_death_status') }}
                        </label>
                        @error('filterDracsDeathStatus')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-6 mb-9 flex gap-2">
                    <button type="button" wire:click.prevent="search" class="button-primary flex items-center gap-2">
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                        {{ __('forms.reset_all_filters') }}
                    </button>
                </div>
            </x-slot>
        </x-header-navigation>

        <div class="mb-16 space-y-6 pl-3.5">
            @if ($isSearching)
                @forelse ($this->paginatedVerifications as $verification)
                @php($person = $this->personsByUuid->get($verification['personId']))
                <fieldset
                    wire:key="verification-{{ $verification['personId'] }}"
                    class="shift-content mt-6 mb-16 max-w-6xl rounded-lg border border-gray-200 p-4 shadow sm:p-8 sm:pb-10 dark:border-gray-700 dark:bg-gray-800"
                >
                    <legend class="legend flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center gap-2">
                            <span>{{ $person?->fullName ?: $verification['personId'] }}</span>
                            @if ($person?->primaryName?->language)
                                <span class="inline-flex items-center rounded border border-gray-300 bg-white px-2 py-0.5 text-xs font-normal text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                    {{ $this->dictionaries['LANGUAGE'][$person->primaryName->language] }}
                                </span>
                            @endif
                            <span class="{{ VerificationStatus::from($verification['verificationStatus'])->color() }} inline-flex items-center rounded px-2 py-0.5 text-xs font-normal">
                                {{ VerificationStatus::from($verification['verificationStatus'])->label() }}
                            </span>
                        </span>
                    </legend>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-4 pb-4">
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-500">
                            <span class="flex items-center gap-1.5 font-medium text-gray-700 dark:text-gray-300">
                                @icon('calendar-outline', 'w-5 h-5 text-gray-800 dark:text-white')
                                <span>{{ __('forms.birth_date_abbreviated') }} {{ $person?->birthDate ?: __('contracts.not_specified') }}</span>
                            </span>

                            @if ($person?->phones->isNotEmpty())
                                <span class="flex min-w-0 items-center gap-1.5 font-medium text-gray-700 dark:text-gray-300">
                                    @icon('tabler-phone', 'w-5 h-5 text-gray-800 dark:text-white')
                                    <a
                                        href="tel:{{ $person->phones->first()->number }}"
                                        class="truncate hover:underline"
                                    >{{ $person->phones->first()->number }}</a>
                                </span>
                            @endif

                            @if ($person?->gender)
                                <span class="flex items-center gap-1.5 font-medium text-gray-700 dark:text-gray-300">
                                    @icon('men', 'w-5 h-5 text-gray-800 dark:text-white')
                                    <span>{{ Gender::tryFrom($person->gender)?->label() }}</span>
                                </span>
                            @endif

                            <span class="flex items-center gap-1.5 font-medium text-gray-700 dark:text-gray-300">
                                @icon('file-lines', 'w-5 h-5 text-gray-800 dark:text-white')
                                <span>{{ __('patients.ehealth_id') }}: {{ $verification['personId'] }}</span>
                            </span>
                        </div>

                        @if ($person)
                            <div class="flex items-center space-x-6">
                                <a
                                    href="{{ route('persons.patient-data', [legalEntity(), 'person' => $person->id]) }}"
                                    class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-800"
                                >
                                    @icon('file-lines', 'w-4 h-4')
                                    <span>{{ __('patients.view_record') }}</span>
                                </a>

                                @can('create', Encounter::class)
                                    <a
                                        href="{{ route('encounter.create', [legalEntity(), 'person' => $person->id]) }}"
                                        class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-800"
                                    >
                                        @icon('plus', 'w-4 h-4 text-blue-600')
                                        <span>{{ __('patients.start_interacting') }}</span>
                                    </a>
                                @endcan
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 flow-root">
                        <div class="max-w-7xl">
                            <table class="table-input w-full table-auto">
                                <thead class="thead-input">
                                    <tr>
                                        @foreach (PatientVerifications::VERIFICATION_STREAMS as $languageKey)
                                            <th
                                                scope="col"
                                                class="th-input w-1/4 text-left text-xs font-bold tracking-wider text-gray-500 uppercase"
                                            >
                                                {{ mb_strtoupper(__('patient-verifications.sources.' . $languageKey)) }}
                                            </th>
                                        @endforeach
                                        <th
                                            scope="col"
                                            class="th-input w-16 text-center text-xs font-bold tracking-wider text-gray-500 uppercase"
                                        >
                                            {{ mb_strtoupper(__('forms.action')) }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        @foreach (PatientVerifications::VERIFICATION_STREAMS as $streamKey => $languageKey)
                                            <td class="td-input align-middle whitespace-nowrap">
                                                <span class="{{ VerificationStatus::from($verification['details'][$streamKey]['verificationStatus'])->color() }} rounded px-2 py-0.5 text-xs">
                                                    {{ VerificationStatus::from($verification['details'][$streamKey]['verificationStatus'])->label() }}
                                                </span>
                                            </td>
                                        @endforeach
                                        <td class="td-input text-center align-middle">
                                            @if ($person)
                                            <div
                                                class="relative inline-block"
                                                x-data="{ openInteractionDropdown: false }"
                                                @click.outside="openInteractionDropdown = false"
                                            >
                                                <button
                                                    @click="openInteractionDropdown = ! openInteractionDropdown"
                                                    class="inline-block cursor-pointer rounded-full p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-700"
                                                    title="{{ __('forms.action') }}"
                                                    type="button"
                                                >
                                                    @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                                </button>

                                                <div
                                                    x-show="openInteractionDropdown"
                                                    x-transition
                                                    x-cloak
                                                    class="absolute right-0 z-50 mt-2 w-64 rounded-lg border border-gray-200 bg-white py-1 text-left shadow-md dark:border-gray-600 dark:bg-gray-700"
                                                >
                                                    @can('create', DeclarationRequest::class)
                                                        <a
                                                            href="{{ route('declaration.create', [legalEntity(), 'person' => $person->id]) }}"
                                                            @click="openInteractionDropdown = false"
                                                            class="dropdown-button !flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('file-text', 'w-4 h-4 text-gray-400')
                                                            {{ __('patients.sign_declaration') }}
                                                        </a>
                                                    @endcan

                                                    @can('create', DiagnosticReport::class)
                                                        <a
                                                            href="{{ route('diagnostic-report.create', [legalEntity(), 'person' => $person->id]) }}"
                                                            @click="openInteractionDropdown = false"
                                                            class="dropdown-button !flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('activity', 'w-4 h-4 text-gray-400')
                                                            {{ __('diagnostic-reports.create') }}
                                                        </a>
                                                    @endcan

                                                    @can('create', Procedure::class)
                                                        <a
                                                            href="{{ route('procedure.create', [legalEntity(), 'person' => $person->id]) }}"
                                                            @click="openInteractionDropdown = false"
                                                            class="dropdown-button !flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('settings', 'w-4 h-4 text-gray-400')
                                                            {{ __('procedures.create') }}
                                                        </a>
                                                    @endcan

                                                    @can('create', Episode::class)
                                                        <a
                                                            href="{{ route('persons.episodes.create', [legalEntity(), 'person' => $person->id]) }}"
                                                            @click="openInteractionDropdown = false"
                                                            class="dropdown-button !flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('book', 'w-4 h-4 text-gray-400')
                                                            {{ __('episodes.create') }}
                                                        </a>
                                                    @endcan
                                                </div>
                                            </div>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </fieldset>
                @empty
                    <div class="shift-content mt-6 max-w-6xl">
                        <x-nothing-found />
                    </div>
                @endforelse

                <div class="shift-content mt-8 max-w-6xl">{{ $this->paginatedVerifications->links() }}</div>
            @else
                <div class="shift-content mt-6 max-w-6xl">
                    <x-nothing-found />
                </div>
            @endif
        </div>
    </section>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
