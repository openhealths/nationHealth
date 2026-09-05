@use('App\Enums\Preperson\Status')
@use('App\Models\MedicalEvents\Sql\Episode')
@use('App\Models\Preperson')
@use('Illuminate\Support\Carbon')

<div
    x-data="{
        showCertificate: false,
        isEditModalOpen: false,
        showRegisterDeathModal: false,
        showRegisterDeathDateModal: false,
        deathPrepersonId: null,
        editSnapshot: null,
        openEdit(id) {
            this.$wire.startEdit(id).then(() => {
                this.editSnapshot = JSON.parse(JSON.stringify(this.$wire.get('form.person')));
                this.isEditModalOpen = true;
            });
        },
        cancelEdit() {
            this.$wire.set('form.person', this.editSnapshot, false);
            this.isEditModalOpen = false;
        },
        confirmEdit() {
            this.$wire.saveEdit(this.$wire.get('editingId')).then(() => {
                this.editSnapshot = JSON.parse(JSON.stringify(this.$wire.get('form.person')));
                this.isEditModalOpen = false;
            });
        },
    }"
>
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">{{ __('preperson.label') }}</x-slot>
        <x-slot name="navigation">
            <div class="mb-8 flex items-center justify-end gap-4">
                @can('create', Preperson::class)
                    <a
                        href="{{ route('persons.create', [legalEntity(), 'type' => 'preperson']) }}"
                        class="button-primary flex items-center gap-2"
                    >
                        @icon('plus', 'w-4 h-4')
                        {{ __('preperson.label_single') }}
                    </a>
                @endcan
            </div>

            <div class="mb-8 flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.patient_search') }}</p>
            </div>

            <div class="form-row-3">
                <div class="form-group group">
                    <input
                        wire:model="searchId"
                        type="text"
                        name="searchId"
                        id="searchId"
                        class="input peer"
                        placeholder=" "
                        autocomplete="off"
                    />
                    <label for="searchId" class="label">ID</label>
                </div>

                <div class="form-group group">
                    <input
                        wire:model="searchName"
                        type="text"
                        name="searchName"
                        id="searchName"
                        class="input peer"
                        placeholder=" "
                        autocomplete="off"
                    />
                    <label for="searchName" class="label">{{ __('patients.patient_full_name') }}</label>
                </div>

                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input
                            wire:model="searchBirthDate"
                            datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                            type="text"
                            name="searchBirthDate"
                            id="searchBirthDate"
                            class="datepicker-input with-leading-icon input peer"
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label for="searchBirthDate" class="wrapped-label"> {{ __('forms.birth_date') }} </label>
                    </div>
                </div>
            </div>

            <div class="mt-6 mb-9 flex gap-2">
                <button wire:click.prevent="search" class="button-primary flex items-center gap-2">
                    @icon('search', 'w-4 h-4')
                    <span>{{ __('forms.search') }}</span>
                </button>
                <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                    {{ __('forms.reset_all_filters') }}
                </button>
            </div>
        </x-slot>
    </x-header-navigation>

    <!-- Search Results List -->
    <div class="mt-12 space-y-6 pl-3.5">
        @forelse ($prepersons as $preperson)
            <fieldset
                wire:key="preperson-{{ $preperson->id }}"
                class="shift-content mt-6 mb-16 max-w-6xl rounded-lg border border-gray-200 p-4 shadow sm:p-8 sm:pb-10 dark:border-gray-700 dark:bg-gray-800"
            >
                <legend class="legend">ID {{ $preperson->externalId }}</legend>

                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-4 dark:border-gray-700">
                    <div class="mt-2 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-500">
                        @if ($preperson->birthDate)
                            <span class="flex items-center gap-1.5">
                                @icon('calendar-outline', 'w-6 h-6 text-gray-800 dark:text-white')
                                <span>{{ __('forms.birth_date_abbreviated') }} {{ Carbon::parse($preperson->birthDate)->format(config('app.date_format')) }}</span>
                            </span>
                        @endif

                        @php($phone = data_get($preperson->emergencyContact, 'phones.0.number'))
                        @if ($phone)
                            <span class="flex min-w-0 items-center gap-1.5">
                                @icon('tabler-phone', 'w-6 h-6 text-gray-800 dark:text-white')
                                <a
                                    href="tel:{{ $phone }}"
                                    class="truncate text-base font-medium text-gray-900 hover:underline dark:text-gray-200"
                                    title="{{ $phone }}"
                                >
                                    {{ $phone }}</a>
                            </span>
                        @endif

                        <span class="flex items-center gap-1.5">
                            @icon($preperson->gender->icon(), 'w-6 h-6 text-gray-800 dark:text-white')
                            <span>{{ $preperson->gender->label() }}</span>
                        </span>
                    </div>

                    <div class="flex items-center space-x-6">
                        @if ($preperson->status === Status::DRAFT)
                            <a
                                href="{{ route('prepersons.edit', [legalEntity(), $preperson]) }}"
                                class="flex cursor-pointer items-center gap-1.5 font-medium text-blue-600 hover:text-blue-800"
                            >
                                @icon('file-lines', 'w-4 h-4')
                                <span class="text-sm">{{ __('patients.continue_registration') }}</span>
                            </a>
                        @else
                            <a
                                href="{{ route('prepersons.patient-data', [legalEntity(), $preperson->id]) }}"
                                class="flex cursor-pointer items-center gap-1.5 font-medium text-blue-600 hover:text-blue-800"
                            >
                                @icon('file-lines', 'w-4 h-4')
                                <span class="text-sm">{{ __('patients.view_record') }}</span>
                            </a>
                            <a
                                href="{{ route('prepersons.encounter.create', [legalEntity(), $preperson->id]) }}"
                                class="flex cursor-pointer items-center gap-1.5 font-medium text-blue-600 hover:text-blue-800"
                            >
                                @icon('plus', 'w-4 h-4')
                                <span class="text-sm">{{ __('patients.start_interacting') }}</span>
                            </a>
                            @can('create', Episode::class)
                                <a
                                    href="{{ route('prepersons.episodes.create', [legalEntity(), $preperson->id]) }}"
                                    class="flex cursor-pointer items-center gap-1.5 font-medium text-blue-600 hover:text-blue-800"
                                >
                                    @icon('book', 'w-4 h-4')
                                    <span class="text-sm">{{ __('episodes.create') }}</span>
                                </a>
                            @endcan
                        @endif
                    </div>
                </div>

                <div class="mt-4 flow-root">
                    <div class="max-w-6xl">
                        <table class="table-input w-full table-auto">
                            <thead class="thead-input">
                                <tr>
                                    <th scope="col" class="th-input">{{ __('patients.patient_full_name') }}</th>
                                    <th scope="col" class="th-input">{{ __('preperson.note') }}</th>
                                    <th scope="col" class="th-input">{{ __('forms.status.label') }}</th>
                                    <th scope="col" class="th-input text-center">{{ __('forms.actions') }}</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr>
                                    <td class="td-input overflow-hidden align-top font-bold text-ellipsis whitespace-nowrap text-gray-900 dark:text-white">
                                        {{ $preperson->fullName ?: '-' }}
                                    </td>
                                    <td class="td-input align-top text-gray-700 dark:text-gray-300">
                                        {{ $preperson->note ?: '-' }}
                                    </td>
                                    <td class="td-input align-top whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded text-xs {{ $preperson->status?->color() }}">
                                            {{ $preperson->status?->label() ?? '-' }}
                                        </span>
                                    </td>
                                    <td class="td-input text-center">
                                        <div
                                            class="relative"
                                            x-data="{ openDropdown: false }"
                                            @click.outside="openDropdown = false"
                                        >
                                            <button
                                                @click="openDropdown = ! openDropdown"
                                                type="button"
                                                class="cursor-pointer rounded-full p-1 transition-colors hover:bg-gray-100 dark:hover:bg-gray-700"
                                            >
                                                @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                            </button>

                                            <div
                                                x-show="openDropdown"
                                                x-transition
                                                x-cloak
                                                class="absolute right-0 z-10 w-56 rounded border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-700"
                                            >
                                                <div class="py-1">
                                                    @if ($preperson->status !== Status::DRAFT)
                                                        <button
                                                            @click="openDropdown = false; $wire.selectCertificate({{ $preperson->id }}).then(() => showCertificate = true)"
                                                            class="dropdown-button !flex w-full cursor-pointer items-center gap-2 border-b border-gray-100 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-600"
                                                            type="button"
                                                        >
                                                            @icon('file-text', 'w-4 h-4')
                                                            {{ __('preperson.get_certificate') }}
                                                        </button>

                                                        @can('update', $preperson)
                                                            <button
                                                                @click="openDropdown = false; openEdit({{ $preperson->id }})"
                                                                class="dropdown-button !flex w-full cursor-pointer items-center gap-2 border-b border-gray-100 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-600"
                                                                type="button"
                                                            >
                                                                @icon('file-text', 'w-4 h-4')
                                                                {{ __('patients.edit_data') }}
                                                            </button>
                                                        @endcan

                                                        @can('update', $preperson)
                                                            <button
                                                                @click="openDropdown = false; deathPrepersonId = {{ $preperson->id }}; showRegisterDeathModal = true"
                                                                class="dropdown-button !flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20"
                                                                type="button"
                                                            >
                                                                @icon('trash', 'w-4 h-4')
                                                                {{ __('patients.register_death') }}
                                                            </button>
                                                        @endcan
                                                    @endif

                                                    @can('delete', $preperson)
                                                        <button
                                                            type="button"
                                                            wire:click="deleteDraft({{ $preperson->id }})"
                                                            class="dropdown-button !flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20"
                                                        >
                                                            @icon('trash', 'w-4 h-4')
                                                            {{ __('preperson.delete_draft') }}
                                                        </button>
                                                    @endcan
                                                </div>
                                            </div>
                                        </div>
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

        @if ($prepersons->hasPages())
            <div class="pagination">{{ $prepersons->links() }}</div>
        @endif
    </div>

    @if ($this->certificatePreperson)
        @include('livewire.person.records.partials.information-certificate', [
                    'preperson' => $this->certificatePreperson,
                    'emergencyContact' => (array) $this->certificatePreperson->emergencyContact
                ])
    @endif

    @if ($editingId)
        @include('livewire.preperson.modals.edit-preperson')
    @endif

    @include('livewire.preperson.modals.register-death')
    @include('livewire.preperson.modals.register-death-date')

    <livewire:components.x-message :key="now()->timestamp" />
    <x-forms.loading />
</div>
