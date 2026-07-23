<div class="breadcrumb-form p-4 shift-content space-y-6" x-data="{ showCertificate: false }">
    @use('App\Enums\Preperson\Status')
    @use('App\Models\MergeRequest')

    @php
        $emergencyContact = (array) $preperson->emergencyContact;
    @endphp
    <div
        x-data="{ open: true }"
        class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm"
    >
        <h2>
            <button
                type="button"
                class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                @click="open = !open"
                :aria-expanded="open"
            >
                <span class="text-base font-semibold text-gray-900 dark:text-white">
                    {{ __('preperson.ehealth_info') }}
                </span>
                @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
            </button>
        </h2>

        <div x-show="open" wire:ignore.self>
            <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-4 space-y-6">
                <div class="form-row-2">
                    <div class="flex items-center gap-2 mt-4">
                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                            {{ __('preperson.ehealth_status') }}:
                        </span>
                        <span class="px-2 py-0.5 rounded text-xs {{ $preperson->status->color() }}">
                            {{ $preperson->status->label() }}
                        </span>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonEhealthId"
                            class="input peer"
                            placeholder=" "
                            value="{{ $preperson->uuid }}"
                            autocomplete="new-password"
                            readonly
                        />
                        <label for="prepersonEhealthId" class="label">{{ __('preperson.ehealth_id') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="form-group relative w-full">
                                @icon('calendar-week', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                                <input
                                    type="text"
                                    id="prepersonCreatedAt"
                                    class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                                    placeholder=" "
                                    value="{{ formatDisplayDate($preperson->ehealthInsertedAt) }}"
                                    autocomplete="off"
                                    readonly
                                />
                                <label for="prepersonCreatedAt" class="wrapped-label">
                                    {{ __('forms.created_at') }}
                                </label>
                            </div>

                            <div class="form-group relative w-full">
                                @icon('clock', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                                <input
                                    type="text"
                                    id="prepersonCreatedTime"
                                    class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                                    placeholder=" "
                                    value="{{ formatDisplayDate($preperson->ehealthInsertedAt, 'H:i') }}"
                                    autocomplete="off"
                                    readonly
                                />
                                <label for="prepersonCreatedTime" class="wrapped-label">
                                    {{ __('forms.created_time') }}
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonCreatedBy"
                            class="input peer"
                            placeholder=" "
                            value="{{ $preperson->insertedByUser?->party?->fullName ?: $preperson->ehealthInsertedBy }}"
                            autocomplete="off"
                            readonly
                        />
                        <label for="prepersonCreatedBy" class="label">{{ __('forms.created_by') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="form-group relative w-full">
                                @icon('calendar-week', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                                <input
                                    type="text"
                                    id="prepersonUpdatedAt"
                                    class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                                    placeholder=" "
                                    value="{{ formatDisplayDate($preperson->ehealthUpdatedAt) }}"
                                    autocomplete="off"
                                    readonly
                                />
                                <label for="prepersonUpdatedAt" class="wrapped-label">
                                    {{ __('forms.updated_at') }}
                                </label>
                            </div>

                            <div class="form-group relative w-full">
                                @icon('clock', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                                <input
                                    type="text"
                                    id="prepersonUpdatedTime"
                                    class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                                    placeholder=" "
                                    value="{{ formatDisplayDate($preperson->ehealthUpdatedAt, 'H:i') }}"
                                    autocomplete="off"
                                    readonly
                                />
                                <label for="prepersonUpdatedTime" class="wrapped-label">
                                    {{ __('forms.updated_time') }}
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonUpdatedBy"
                            class="input peer"
                            placeholder=" "
                            value="{{ $preperson->updatedByUser?->party?->fullName ?: $preperson->ehealthUpdatedBy }}"
                            autocomplete="off"
                            readonly
                        />
                        <label for="prepersonUpdatedBy" class="label">{{ __('forms.updated_by') }}</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @can('create', MergeRequest::class)
        @php
            $isInactive = $preperson->status !== Status::ACTIVE;
            $mergeRequestsList = method_exists($preperson, 'mergeRequests') ? $preperson->mergeRequests : collect();

            $confirmedMergeRequest = $mergeRequestsList->first(function ($item) {
                $code = is_object($item) && isset($item->status_code) ? $item->status_code : (isset($item->status) ? $item->status->value : '');
                return in_array($code, ['APPROVED', 'SIGNED'], true);
            });

            $hasConfirmedMerge = $confirmedMergeRequest !== null;
            $masterPersonId = $confirmedMergeRequest ? (is_object($confirmedMergeRequest) ? ($confirmedMergeRequest->master_person_id ?? null) : ($confirmedMergeRequest->master_person_id ?? null)) : null;
        @endphp

        <div
            x-data="{ open: true }"
            class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm"
        >
            <h2>
                <button
                    type="button"
                    class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                    @click="open = !open"
                    :aria-expanded="open"
                >
                    <span class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ __('preperson.identification') }}
                    </span>
                    @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                </button>
            </h2>

            <div x-show="open">
                <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-4">
                    @if($mergeRequestsList->isNotEmpty())
                        <div class="overflow-x-auto relative mb-4">
                            <table class="table-input w-full">
                                <thead class="thead-input">
                                    <tr>
                                        <th scope="col" class="td-input">
                                            {{ __('preperson.merge_request_table.number') }}
                                        </th>
                                        <th scope="col" class="td-input">
                                            {{ __('preperson.merge_request_table.patient_name') }}
                                        </th>
                                        <th scope="col" class="td-input">
                                            {{ __('preperson.merge_request_table.birth_date') }}
                                        </th>
                                        <th scope="col" class="td-input">
                                            {{ __('preperson.merge_request_table.status') }}
                                        </th>
                                        <th scope="col" class="td-input">
                                            {{ __('preperson.merge_request_table.action') }}
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach($mergeRequestsList as $index => $item)
                                        @php
                                            $id = is_object($item) && isset($item->id) ? $item->id : 1;
                                            $number = is_object($item) && isset($item->number) ? $item->number : ($index + 1);
                                            $patientName = is_object($item) && isset($item->patient_name) ? $item->patient_name : (is_object($item) && isset($item->masterPerson) ? $item->masterPerson?->fullName : '-');
                                            $birthDate = is_object($item) && isset($item->birth_date) ? $item->birth_date : (is_object($item) && isset($item->masterPerson) ? formatDisplayDate($item->masterPerson?->birthDate) : '-');
                                            $statusCode = is_object($item) && isset($item->status_code) ? $item->status_code : (isset($item->status) ? $item->status->value : '');
                                            $statusLabel = is_object($item) && isset($item->status_label) ? $item->status_label : __('preperson.statuses.' . $statusCode);
                                            $canConfirm = is_object($item) && isset($item->can_confirm) ? $item->can_confirm : in_array($statusCode, ['NEW', 'APPROVED'], true);
                                        @endphp
                                        <tr>
                                            <td class="td-input">
                                                {{ $number }}
                                            </td>
                                            <td class="td-input">
                                                {{ $patientName }}
                                            </td>
                                            <td class="td-input">
                                                {{ $birthDate }}
                                            </td>
                                            <td class="td-input">
                                                @if($statusCode === 'CANCELLED' || $statusCode === 'REJECTED')
                                                    <span class="badge-red">
                                                        {{ $statusLabel }}
                                                    </span>
                                                @elseif($statusCode === 'NEW')
                                                    <span class="badge-yellow">
                                                        {{ $statusLabel }}
                                                    </span>
                                                @elseif($statusCode === 'APPROVED' || $statusCode === 'SIGNED')
                                                    <span class="badge-green">
                                                        {{ $statusLabel }}
                                                    </span>
                                                @else
                                                    <span class="badge-dark">
                                                        {{ $statusLabel }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="td-input">
                                                @if($canConfirm)
                                                    <div
                                                        x-data="{
                                                            openDropdown: false,
                                                            toggle() {
                                                                if (this.openDropdown) {
                                                                    return this.close();
                                                                }
                                                                this.$refs.button.focus();
                                                                this.openDropdown = true;
                                                            },
                                                            close(focusAfter) {
                                                                if (!this.openDropdown) return;
                                                                this.openDropdown = false;
                                                                focusAfter && focusAfter.focus();
                                                            }
                                                        }"
                                                        @keydown.escape.prevent.stop="close($refs.button)"
                                                        @focusin.window="!$refs.panel.contains($event.target) && close()"
                                                        x-id="['dropdown-button']"
                                                        class="relative"
                                                    >
                                                        <button
                                                            x-ref="button"
                                                            @click="toggle()"
                                                            :aria-expanded="openDropdown"
                                                            :aria-controls="$id('dropdown-button')"
                                                            type="button"
                                                            class="cursor-pointer"
                                                        >
                                                            @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200 svg-hover-action')
                                                        </button>

                                                        <div class="absolute" style="left: -120%">
                                                            <div
                                                                x-ref="panel"
                                                                x-show="openDropdown"
                                                                x-transition.origin.top.left
                                                                @click.outside="close($refs.button)"
                                                                :id="$id('dropdown-button')"
                                                                class="dropdown-panel relative"
                                                                style="left: -50%; display: none;"
                                                            >
                                                                <button
                                                                    type="button"
                                                                    class="dropdown-button"
                                                                    @click.prevent="
                                                                        openDropdown = false;
                                                                        if (typeof $wire.resumeMergeRequest === 'function') {
                                                                            $wire.resumeMergeRequest({{ $id }}).then(() => {
                                                                                showMergeAuthDrawer = true;
                                                                            });
                                                                        } else {
                                                                            showMergeAuthDrawer = true;
                                                                        }
                                                                    "
                                                                >
                                                                    {{ __('forms.confirm') }}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                        <div>
                            @if($isInactive || $hasConfirmedMerge)
                                <a
                                    href="{{ route('persons.patient-data', [legalEntity(), $masterPersonId]) }}"
                                    class="cursor-pointer text-[#2f54eb] hover:text-blue-700 dark:text-blue-400 font-medium text-sm transition-colors inline-block mt-4"
                                >
                                    {{ __('preperson.go_to_merged_patient') }}
                                </a>
                            @else
                                <button
                                    type="button"
                                    class="cursor-pointer text-[#2f54eb] hover:text-blue-700 dark:text-blue-400 font-medium text-sm transition-colors flex items-center gap-1 mt-4"
                                    @click="showMergePatientDrawer = true"
                                >
                                    {{ __('preperson.create_merge_request') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
    @endcan

    <div x-data="{ open: true }"
         class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
        <h2>
            <button
                type="button"
                class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                @click="open = !open"
                :aria-expanded="open"
            >
                <span class="text-base font-semibold text-gray-900 dark:text-white">
                    {{ __('patients.main_info') }}
                </span>
                @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
            </button>
        </h2>
        <div x-show="open" wire:ignore.self>
            <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-4 space-y-6">
                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonExternalId"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->externalId }}"
                        />
                        <label for="prepersonExternalId" class="label">{{ __('preperson.external_id') }}</label>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonFirstName"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->firstName ?: '-' }}"
                        />
                        <label for="prepersonFirstName" class="label">{{ __('forms.first_name') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonLastName"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->lastName ?: '-' }}"
                        />
                        <label for="prepersonLastName" class="label">{{ __('forms.last_name') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonSecondName"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->secondName ?: '-' }}"
                        />
                        <label for="prepersonSecondName" class="label">{{ __('forms.second_name') }}</label>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonGender"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->gender->label() }}"
                        />
                        <label for="prepersonGender" class="label">{{ __('forms.gender') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonBirthDate"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->birthDate ?: '-' }}"
                        />
                        <label for="prepersonBirthDate" class="label">{{ __('forms.birth_date') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonDeathDate"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ formatDisplayDate($preperson->deathDate) ?: '-' }}"
                        />
                        <label for="prepersonDeathDate" class="label">{{ __('preperson.death_date') }}</label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group group">
                        <label for="prepersonNote" class="label-secondary">{{ __('preperson.note') }}</label>
                        <textarea
                            id="prepersonNote"
                            class="textarea w-full"
                            rows="3"
                            readonly
                            placeholder="{{ __('preperson.note') }}"
                        >{{ $preperson->note }}</textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div
        x-data="{ open: true }"
        class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm"
    >
        <h2>
            <button
                type="button"
                class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                @click="open = !open"
                :aria-expanded="open"
            >
                <span class="text-base font-semibold text-gray-900 dark:text-white">
                    {{ __('preperson.contact_person') }}
                </span>
                @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
            </button>
        </h2>

        <div x-show="open">
            <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-4 space-y-6">
                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonContactFirstName"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->emergencyContact['first_name'] ?? '-' }}"
                        />
                        <label for="prepersonContactFirstName" class="label">{{ __('forms.first_name') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonContactLastName"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->emergencyContact['last_name'] ?? '-' }}"
                        />
                        <label for="prepersonContactLastName" class="label">{{ __('forms.last_name') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonContactSecondName"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->emergencyContact['second_name'] ?? '-' }}"
                        />
                        <label for="prepersonContactSecondName" class="label">{{ __('forms.second_name') }}</label>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonContactPhoneType"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ dictionary()->basics()->byName('PHONE_TYPE')->asCodeDescription()->toArray()[$preperson->emergencyContact['phones'][0]['type'] ?? ''] ?? '-' }}"
                        />
                        <label for="prepersonContactPhoneType" class="label">{{ __('forms.phone_type') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            id="prepersonContactPhone"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->emergencyContact['phones'][0]['number'] ?? '-' }}"
                        />
                        <label for="prepersonContactPhone" class="label">{{ __('forms.phone') }}</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap gap-4 pt-4 border-t border-gray-100 dark:border-gray-700 justify-start items-center">
        <a href="{{ route('prepersons.index', [legalEntity()]) }}" class="button-minor" style="margin: 0 !important;">
            {{ __('forms.close') }}
        </a>

        @if($preperson->status === Status::DRAFT)
            @can('edit', $preperson)
                <a
                    href="{{ route('prepersons.edit', [legalEntity(), $preperson]) }}"
                    class="button-primary"
                    style="margin: 0 !important;"
                >
                    {{ __('patients.edit_data') }}
                </a>
            @endcan
        @else
            @can('update', $preperson)
                <button
                    type="button"
                    class="button-primary"
                    style="margin: 0 !important;"
                    @click="openEdit()"
                >
                    {{ __('patients.edit_data') }}
                </button>
            @endcan

            <button
                type="button"
                class="button-primary-outline flex items-center gap-2 !me-0"
                style="margin: 0 !important;"
                @click="showCertificate = true"
            >
                @icon('printer', 'w-4 h-4')
                <span>{{ __('preperson.info_certificate') }}</span>
            </button>

            @can('update', $preperson)
                <button
                    type="button"
                    class="button-primary-outline-red !me-0"
                    style="margin: 0 !important;"
                    @click="showRegisterDeathModal = true"
                >
                    {{ __('patients.register_death') }}
                </button>
            @endcan
        @endif
    </div>

    @include('livewire.person.records.partials.information-certificate')
</div>
