@use('App\Models\MedicalEvents\Sql\{DiagnosticReport, Encounter, Episode, Procedure}')
@use('App\Models\DeclarationRequest')
@use('App\Models\Person\{Person, PersonRequest}')
@use('App\Enums\Person\{Status, Gender}')

<div>
    <section>
        <x-header-navigation x-data="{ showFilter: true }" class="breadcrumb-form">
            <x-slot name="title">{{ __('patients.patients') }}</x-slot>
            <x-slot name="navigation">
                <div class="mb-8 block justify-end sm:flex md:divide-x md:divide-gray-100 dark:divide-gray-700">
                    @can('create', PersonRequest::class)
                        <a
                            href="{{ route('persons.create', [legalEntity()]) }}"
                            class="button-primary flex items-center gap-2"
                        >
                            @icon('plus', 'w-4 h-4')
                            {{ __('patients.add_patient') }}
                        </a>
                    @endcan
                </div>

                <div class="mb-8 flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <p>{{ __('patients.patient_search') }}</p>
                </div>

                @include('livewire.person.parts.search-filter', ['context' => 'index'])

                <div class="mt-6 mb-9 flex gap-2">
                    @can('viewAny', Person::class)
                        <button wire:click.prevent="searchForPerson" class="button-primary flex items-center gap-2">
                            @icon('search', 'w-4 h-4')
                            <span>{{ __('forms.search') }}</span>
                        </button>
                    @endcan
                    <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                        {{ __('forms.reset_all_filters') }}
                    </button>
                </div>
            </x-slot>
        </x-header-navigation>

        <div class="space-y-6 pl-3.5" wire:key="patients-{{ $paginatedPatients->total() }}">
            @forelse ($paginatedPatients->items() as $patient)
                <fieldset
                    wire:key="patient-{{ $patient['source'] }}-{{ $patient['id'] }}"
                    class="shift-content mt-6 mb-16 max-w-6xl rounded-lg border border-gray-200 p-4 shadow sm:p-8 sm:pb-10 dark:border-gray-700 dark:bg-gray-800"
                >
                    <legend class="legend flex flex-wrap items-center gap-3">
                        @foreach ($patient['names'] as $name)
                            <span
                                wire:key="patient-{{ $patient['source'] }}-{{ $patient['id'] }}-name-{{ $loop->index }}"
                                class="inline-flex items-center gap-2"
                            >
                                <span>{{ ( ($name['lastName'] ?? '') . ' ' . $name['firstName'] . ' ' . ($name['secondName'] ?? '')) }}</span>
                                <span class="inline-flex items-center rounded border border-gray-300 bg-white px-2 py-0.5 text-xs font-normal text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                    {{ $this->dictionaries['LANGUAGE'][$name['language']] }}
                                </span>
                            </span>
                        @endforeach
                    </legend>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-4 pb-4">
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-500">
                            @if ($patient['birthDate'])
                                <span class="flex items-center gap-1.5 font-medium text-gray-700 dark:text-gray-300">
                                    @icon('calendar-outline', 'w-5 h-5 text-gray-800 dark:text-white')
                                    <span>{{ __('forms.birth_date_abbreviated') }} {{ $patient['birthDate'] }}</span>
                                </span>
                            @endif

                            @if (isset($patient['phones'][0]['number']))
                                <span class="flex min-w-0 items-center gap-1.5 font-medium text-gray-700 dark:text-gray-300">
                                    @icon('tabler-phone', 'w-5 h-5 text-gray-800 dark:text-white')
                                    <a
                                        href="tel:{{ $patient['phones'][0]['number'] }}"
                                        class="truncate hover:underline"
                                        title="{{ $patient['phones'][0]['number'] }}"
                                    >
                                        {{ $patient['phones'][0]['number'] }}
                                    </a>
                                </span>
                            @endif

                            @if (isset($patient['gender']))
                                <span class="flex items-center gap-1.5 font-medium text-gray-700 dark:text-gray-300">
                                    @if ($patient['gender'] === Gender::MALE->value)
                                        @icon('men', 'w-5 h-5 text-gray-800 dark:text-white')
                                        <span>{{ __('patients.male') }}</span>
                                    @elseif ($patient['gender'] === Gender::FEMALE->value)
                                        @icon('women', 'w-5 h-5 text-gray-800 dark:text-white')
                                        <span>{{ __('patients.female') }}</span>
                                    @endif
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center space-x-6">
                            @if ($patient['source'] === 'request')
                                <a
                                    href="{{ route('persons.edit', [legalEntity(), $patient['id']]) }}"
                                    class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-800"
                                >
                                    @icon('file-lines', 'w-4 h-4')
                                    <span>{{ __('patients.continue_registration') }}</span>
                                </a>
                            @else
                                @can('view', Person::class)
                                    <button
                                        wire:click="redirectTo('{{ $patient['id'] }}', 'persons.patient-data')"
                                        class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-800"
                                    >
                                        @icon('file-lines', 'w-4 h-4 text-blue-600')
                                        <span>{{ __('patients.view_record') }}</span>
                                    </button>
                                @endcan

                                @can('create', Encounter::class)
                                    <button
                                        wire:click="redirectTo('{{ $patient['id'] }}', 'encounter.create')"
                                        class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-800"
                                        type="button"
                                    >
                                        @icon('plus', 'w-4 h-4 text-blue-600')
                                        <span>{{ __('patients.start_interacting') }}</span>
                                    </button>
                                @endcan
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 flow-root">
                        <div class="max-w-7xl">
                            <table class="table-input w-full table-auto">
                                <thead class="thead-input">
                                    <tr>
                                        <th
                                            scope="col"
                                            class="th-input text-left text-xs font-bold tracking-wider text-gray-500 uppercase"
                                        >
                                            {{ __('patients.birth_country_and_settlement') }}
                                        </th>
                                        <th
                                            scope="col"
                                            class="th-input text-left text-xs font-bold tracking-wider text-gray-500 uppercase"
                                        >
                                            {{ __('forms.rnokpp') }}(ІПН)
                                        </th>
                                        <th
                                            scope="col"
                                            class="th-input text-left text-xs font-bold tracking-wider text-gray-500 uppercase"
                                        >
                                            {{ __('forms.document_type') }}
                                        </th>
                                        <th
                                            scope="col"
                                            class="th-input text-left text-xs font-bold tracking-wider text-gray-500 uppercase"
                                        >
                                            {{ __('forms.document_number') }}
                                        </th>
                                        <th
                                            scope="col"
                                            class="th-input text-left text-xs font-bold tracking-wider text-gray-500 uppercase"
                                        >
                                            {{ __('forms.status.label') }}
                                        </th>
                                        <th
                                            scope="col"
                                            class="th-input text-center text-xs font-bold tracking-wider text-gray-500 uppercase"
                                        >
                                            {{ __('forms.action') }}
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <tr>
                                        <td class="td-input overflow-hidden align-middle font-normal text-ellipsis whitespace-nowrap text-gray-900 dark:text-white">
                                            @php
                                                $birthPlace = '';
                                                if (!empty($patient['birthCountry']) && !empty($patient['birthSettlement'])) {
                                                    if (in_array(mb_strtolower($patient['birthCountry']), ['ua', 'україна', 'ukraine'])) {
                                                        $birthPlace = $patient['birthCountry'] . ', ' . $patient['birthSettlement'];
                                                    } else {
                                                        $birthPlace = $patient['birthSettlement'] . ', ' . $patient['birthCountry'];
                                                    }
                                                } else {
                                                    $birthPlace = $patient['birthSettlement'] ?? $patient['birthCountry'] ?? '-';
                                                }
                                            @endphp
                                            {{ $birthPlace }}
                                        </td>
                                        <td class="td-input overflow-hidden align-middle font-normal text-ellipsis whitespace-nowrap text-gray-900 dark:text-white">
                                            {{ $patient['taxId'] ?? '-' }}
                                        </td>
                                        <td class="td-input overflow-hidden align-middle font-normal text-ellipsis whitespace-nowrap text-gray-900 dark:text-white">
                                            @forelse ($patient['documents'] ?? [] as $document)
                                                <span class="block">
                                                    {{ $this->dictionaries['DOCUMENT_TYPE'][$document['type']] ?? $document['type'] }}
                                                </span>
                                            @empty
                                                -
                                            @endforelse
                                        </td>
                                        <td class="td-input overflow-hidden align-middle font-normal text-ellipsis whitespace-nowrap text-gray-900 dark:text-white">
                                            @forelse ($patient['documents'] ?? [] as $document)
                                                <span class="block">{{ $document['number'] }}</span>
                                            @empty
                                                -
                                            @endforelse
                                        </td>
                                        <td class="td-input align-middle whitespace-nowrap">
                                            @php
                                                if ($patient['source'] === 'request') {
                                                    $color = Status::from($patient['status'])->color();
                                                    $label = Status::from($patient['status'])->label();
                                                } elseif ($patient['source'] === 'local') {
                                                    $color = 'badge-green';
                                                    $label = __('patients.source.local');
                                                } elseif ($patient['source'] === 'ehealth') {
                                                    $color = 'badge-green';
                                                    $label = __('patients.source.ehealth');
                                                }
                                            @endphp

                                            <span class="{{ $color }} px-2 py-0.5 rounded text-xs">{{ $label }}</span>
                                        </td>
                                        <td class="td-input text-center align-middle">
                                            @if ($patient['source'] === 'request')
                                                <div class="inline-flex items-center gap-1">
                                                    <a
                                                        href="{{ route('persons.edit', [legalEntity(), $patient['id']]) }}"
                                                        class="inline-block cursor-pointer rounded-full p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-700"
                                                        title="{{ __('patients.continue_registration') }}"
                                                    >
                                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                                    </a>
                                                    @if ($patient['status'] === Status::DRAFT->value)
                                                        @can('create', PersonRequest::class)
                                                            <button
                                                                wire:click="deleteDraft({{ $patient['id'] }})"
                                                                class="inline-block cursor-pointer rounded-full p-1.5 text-red-600 transition-colors hover:bg-gray-100 dark:hover:bg-gray-700"
                                                                title="{{ __('forms.delete') }}"
                                                                type="button"
                                                            >
                                                                @icon('delete', 'w-5 h-5')
                                                            </button>
                                                        @endcan
                                                    @endif
                                                </div>
                                            @else
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
                                                        @can('create', PersonRequest::class)
                                                            <a
                                                                wire:click="redirectTo('{{ $patient['id'] }}', 'persons.update')"
                                                                class="dropdown-button !flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                                @click="openInteractionDropdown = false"
                                                            >
                                                                @icon('edit-user-outline', 'w-4 h-4 text-gray-400')
                                                                {{ __('forms.edit') }}
                                                            </a>
                                                        @endcan

                                                        @can('create', DeclarationRequest::class)
                                                            <a
                                                                wire:click="redirectTo('{{ $patient['id'] }}', 'declaration.create')"
                                                                class="dropdown-button !flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                                @click="openInteractionDropdown = false"
                                                            >
                                                                @icon('file-text', 'w-4 h-4 text-gray-400')
                                                                {{ __('patients.sign_declaration') }}
                                                            </a>
                                                        @endcan

                                                        @can('create', DiagnosticReport::class)
                                                            <a
                                                                wire:click="redirectTo('{{ $patient['id'] }}', 'diagnostic-report.create')"
                                                                class="dropdown-button !flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                                @click="openInteractionDropdown = false"
                                                            >
                                                                @icon('activity', 'w-4 h-4 text-gray-400')
                                                                {{ __('diagnostic-reports.create') }}
                                                            </a>
                                                        @endcan

                                                        @can('create', Procedure::class)
                                                            <a
                                                                wire:click="redirectTo('{{ $patient['id'] }}', 'procedure.create')"
                                                                class="dropdown-button !flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                                @click="openInteractionDropdown = false"
                                                            >
                                                                @icon('settings', 'w-4 h-4 text-gray-400')
                                                                {{ __('procedures.create') }}
                                                            </a>
                                                        @endcan

                                                        @can('create', Episode::class)
                                                            <a
                                                                wire:click="redirectTo('{{ $patient['id'] }}', 'persons.episodes.create')"
                                                                class="dropdown-button !flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                                @click="openInteractionDropdown = false"
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
                <div class="shift-content max-w-6xl">
                    <x-nothing-found />
                </div>
            @endforelse
        </div>

        <div class="mt-8">{{ $paginatedPatients->links() }}</div>
    </section>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
