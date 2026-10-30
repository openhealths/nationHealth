@use(App\Models\MedicalEvents\Sql\DeviceRequestRequest)
@use(App\Enums\Person\DeviceRequestStatus)

<x-layouts.patient
    :personId="$patientContext ? $person?->id : null"
    :patientFullName="$person?->fullName ?? __('device-requests.labels.registry')"
    :title="__('device-requests.labels.registry') . ($patientContext && $person ? ' - ' . $person->fullName : '')"
    :hideNavigation="!$patientContext"
    :showLegacyMessages="false"
    activeTab="device-requests"
>
    <x-slot name="headerActions">
        @if ($person)
            @can('create', DeviceRequestRequest::class)
                <a
                    class="button-primary flex items-center gap-2"
                    href="{{ route('persons.device-requests.create', ['legalEntity' => $legalEntity, 'person' => $person]) }}"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('device-requests.labels.create') }}
                </a>
            @endcan
        @endif
    </x-slot>

    <div
        class="section-form shift-content max-w-screen-xl space-y-6 px-3.5 pt-4 pb-12"
        x-data
        @print-device-request.window="window.printSandboxedHtml($event.detail.html)"
    >
        @assets
            <script src="{{ asset('js/print-sandboxed.js') }}"></script>
        @endassets
        <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
        <x-forms.loading />

        @if ($errors->any())
            <div role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-gray-800 dark:text-red-400">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if (!$patientContext)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('device-requests.labels.patient_required_hint') }}
            </p>
            @if ($person)
                <div class="fieldset-card flex flex-wrap items-center gap-3 p-4">
                    <span class="font-medium text-gray-900 dark:text-white">
                        {{ __('device-requests.labels.patient') }}: {{ $person->fullName }}
                    </span>
                    <button type="button" wire:click="clearPatient" class="button-minor">
                        {{ __('device-requests.labels.change_patient') }}
                    </button>
                    <a
                        class="button-primary-outline"
                        href="{{ route('persons.device-requests', ['legalEntity' => $legalEntity, 'person' => $person]) }}"
                    >
                        {{ __('device-requests.labels.patient_card') }}
                    </a>
                </div>
            @else
                <form wire:submit="searchPatients" autocomplete="off" class="space-y-6">
                    <div class="flex items-center gap-2 font-semibold text-gray-900 dark:text-white">
                        @icon('search-outline', 'w-4.5 h-4.5')
                        <span>{{ __('device-requests.labels.find_patient') }}</span>
                    </div>
                    <div class="form-row-3">
                        <div class="form-group group">
                            <input
                                id="device-request-patient-search"
                                type="text"
                                wire:model="patientSearch"
                                class="input peer @error('patientSearch') input-error @enderror"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label
                                for="device-request-patient-search"
                                class="label"
                            >{{ __('device-requests.labels.patient_surname') }}</label>
                            <x-input-error for="patientSearch" />
                        </div>
                    </div>
                    <button
                        type="submit"
                        class="button-primary gap-2"
                        wire:loading.attr="disabled"
                        wire:target="searchPatients"
                    >
                        @icon('search', 'w-4 h-4')
                        {{ __('device-requests.labels.find_patient') }}
                    </button>
                </form>
                @if ($patients)
                    <div class="flex flex-wrap gap-2">
                        @foreach ($patients as $patient)
                            <button
                                type="button"
                                wire:key="patient-{{ $patient['id'] }}"
                                wire:click="openPatient({{ $patient['id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="openPatient"
                                class="button-minor text-left"
                            >
                                {{ $patient['name'] }}
                            </button>
                        @endforeach
                    </div>
                @else
                    <x-nothing-found />
                @endif
            @endif
        @endif

        @if ($person)
            @if ($drafts)
                <section class="fieldset-card space-y-3 p-4">
                    <h2 class="font-semibold text-gray-900 dark:text-white">
                        {{ __('device-requests.labels.drafts') }}
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($drafts as $draft)
                            <a
                                class="button-minor gap-2"
                                href="{{ route('persons.device-requests.create', ['legalEntity' => $legalEntity, 'person' => $person, 'draft' => $draft['uuid']]) }}"
                            >
                                @icon('edit', 'w-4 h-4')
                                {{ $draft['device'] }} · {{ $draft['updatedAt'] }} - {{ __('device-requests.labels.open_draft') }}
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('device-requests.labels.patient_results_hint') }}
            </p>
            <form wire:submit="search" autocomplete="off" class="space-y-6">
                <div class="flex items-center gap-2 font-semibold text-gray-900 dark:text-white">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <span>{{ __('device-requests.labels.search') }}</span>
                </div>
                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            id="device-request-entity"
                            class="input peer @error('requesterEntitySearch') input-error @enderror"
                            wire:model="requesterEntitySearch"
                            list="device-request-entities"
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label
                            for="device-request-entity"
                            class="label"
                        >{{ __('device-requests.labels.requester_entity') }}</label>
                        <datalist id="device-request-entities">
                            @foreach ($legalEntityOptions as $entity)
                                <option value="{{ $entity['name'] }}" label="{{ $entity['uuid'] }}"></option>
                            @endforeach
                        </datalist>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('device-requests.labels.entity_search_hint') }}
                        </p>
                        <x-input-error for="requesterEntitySearch" />
                    </div>
                    @foreach (['requester' => __('device-requests.labels.doctor'), 'code' => __('device-requests.labels.device_code')] as $key => $label)
                        <div class="form-group group">
                            <input
                                id="device-request-filter-{{ $key }}"
                                class="input peer"
                                wire:model="filters.parameters.{{ $key }}"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label for="device-request-filter-{{ $key }}" class="label">{{ $label }}</label>
                            <x-input-error for="filters.parameters.{{ $key }}" />
                        </div>
                    @endforeach
                    <div class="form-group group">
                        <select
                            id="device-request-status"
                            wire:model="filters.parameters.status"
                            class="input-select peer"
                        >
                            <option value="">{{ __('device-requests.labels.all') }}</option>
                            @foreach (['active' => __('device-requests.labels.active'), 'completed' => __('device-requests.labels.completed'), 'revoked' => __('device-requests.labels.revoked'), 'entered-in-error' => __('device-requests.labels.entered_in_error')] as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label
                            for="device-request-status"
                            class="label"
                        >{{ __('device-requests.labels.status') }}</label>
                    </div>
                </div>
                <details>
                    <summary class="button-minor [&::-webkit-details-marker]:hidden inline-flex w-fit cursor-pointer list-none items-center gap-2">
                        @icon('adjustments', 'w-4 h-4')
                        {{ __('device-requests.labels.additional_filters') }}
                    </summary>
                    <div class="form-row-3 mt-6">
                        @foreach (['code_reference' => 'device_model', 'context_episode_id' => 'episode', 'encounter' => 'encounter', 'program' => 'program', 'based_on' => 'based_on', 'priority' => 'priority', 'performer' => 'performer'] as $key => $label)
                            <div class="form-group group">
                                <input
                                    id="device-request-filter-{{ $key }}"
                                    class="input peer"
                                    wire:model="filters.parameters.{{ $key }}"
                                    placeholder=" "
                                    autocomplete="off"
                                />
                                <label
                                    for="device-request-filter-{{ $key }}"
                                    class="label"
                                >{{ __('device-requests.labels.'.$label) }}</label>
                                <x-input-error for="filters.parameters.{{ $key }}" />
                            </div>
                        @endforeach
                        @foreach (['authored_on' => 'created_at', 'occurrence_period_start' => 'period_start', 'occurrence_period_end' => 'period_end'] as $key => $label)
                            @foreach (['from', 'to'] as $bound)
                                <div class="form-group group">
                                    <input
                                        id="device-request-filter-{{ $key }}-{{ $bound }}"
                                        type="datetime-local"
                                        class="input peer"
                                        wire:model="filters.parameters.{{ $key }}_{{ $bound }}"
                                    />
                                    <label for="device-request-filter-{{ $key }}-{{ $bound }}" class="label">
                                        {{ __('device-requests.labels.'.$label) }} - {{ __('device-requests.labels.'.$bound) }}
                                    </label>
                                    <x-input-error for="filters.parameters.{{ $key }}_{{ $bound }}" />
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                </details>
                <div class="mb-9 flex flex-col gap-2 sm:flex-row">
                    <button
                        type="submit"
                        class="button-primary gap-2"
                        wire:loading.attr="disabled"
                        wire:target="search,resetFilters"
                    >
                        @icon('search', 'w-4 h-4')
                        {{ __('device-requests.labels.search') }}
                    </button>
                    <button
                        type="button"
                        wire:click="resetFilters"
                        wire:loading.attr="disabled"
                        wire:target="search,resetFilters"
                        class="button-primary-outline-red"
                    >
                        {{ __('forms.reset_all_filters') }}
                    </button>
                </div>
            </form>

            @if ($requests)
                <div class="index-table-wrapper overflow-x-auto">
                    <table class="index-table">
                        <thead class="index-table-thead">
                            <tr>
                                <th scope="col" class="index-table-th w-[15%]">
                                    {{ __('device-requests.labels.number') }}
                                </th>
                                <th scope="col" class="index-table-th w-[24%]">
                                    {{ __('device-requests.labels.device') }}
                                </th>
                                <th scope="col" class="index-table-th w-[12%]">
                                    {{ __('device-requests.labels.status') }}
                                </th>
                                <th scope="col" class="index-table-th w-[8%]">
                                    {{ __('device-requests.labels.quantity') }}
                                </th>
                                <th scope="col" class="index-table-th w-[10%]">
                                    {{ __('device-requests.labels.program_short') }}
                                </th>
                                <th scope="col" class="index-table-th w-[11%]">
                                    {{ __('device-requests.labels.doctor') }}
                                </th>
                                <th scope="col" class="index-table-th w-[12%]">
                                    {{ __('device-requests.labels.requester_entity') }}
                                </th>
                                <th scope="col" class="index-table-th w-[8%] text-center">{{ __('forms.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $record)
                                <tr wire:key="request-{{ $record['id'] }}" class="index-table-tr">
                                    <td class="index-table-td">{{ $record['requisition'] ?? '-' }}</td>
                                    <td class="index-table-td-primary">
                                        {{ data_get($record, 'code_reference.display_value', data_get($record, 'code.coding.0.display', data_get($record, 'code.coding.0.code', '-'))) }}
                                    </td>
                                    <td class="index-table-td">
                                        <span class="{{ DeviceRequestStatus::colorFor($record['status']) }} inline-flex items-center justify-center text-center whitespace-normal">
                                            {{ DeviceRequestStatus::labelFor($record['status']) }}
                                        </span>
                                    </td>
                                    <td class="index-table-td">
                                        {{ data_get($record, 'quantity.value', '-') }} {{ data_get($record, 'quantity.unit', data_get($record, 'quantity.code')) }}
                                    </td>
                                    <td class="index-table-td">
                                        {{ data_get($record, 'program.display_value', '-') }}
                                    </td>
                                    <td class="index-table-td">
                                        {{ data_get($record, 'requester.display_value', '-') }}
                                    </td>
                                    <td class="index-table-td">
                                        {{ data_get($record, 'requester_legal_entity.display_value', '-') }}
                                    </td>
                                    <td class="index-table-td-actions">
                                        <button
                                            type="button"
                                            wire:click="showDetails('{{ $record['id'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="showDetails"
                                            class="hover:text-primary cursor-pointer"
                                            aria-label="{{ __('device-requests.labels.details') }}"
                                            title="{{ __('device-requests.labels.details') }}"
                                        >
                                            @icon('eye', 'svg-hover-action w-6 h-6 text-gray-800 dark:text-gray-300')
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-nothing-found />
            @endif

            <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-gray-400">
                <button
                    type="button"
                    wire:click="goToPage({{ $page - 1 }})"
                    class="button-minor gap-2"
                    @disabled($page <= 1)
                >
                    @icon('arrow-left', 'w-4 h-4')
                    {{ __('device-requests.labels.previous') }}
                </button>
                <span>{{ $page }} / {{ $totalPages }}</span>
                <button
                    type="button"
                    wire:click="goToPage({{ $page + 1 }})"
                    class="button-minor gap-2"
                    @disabled($page >= $totalPages)
                >
                    {{ __('device-requests.labels.next') }}
                    @icon('arrow-right', 'w-4 h-4')
                </button>
            </div>
            @if ($selected)
                <section class="fieldset-card space-y-6 p-4 md:p-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        {{ __('device-requests.labels.request') }} {{ $selected['requisition'] ?? '' }}
                    </h2>
                    @include('livewire.device-request.record-details', ['record' => $selected])
                    @foreach ($catalog as $device)
                        <details class="rounded border p-3">
                            <summary>
                                {{ __('device-requests.labels.catalog_info') }}: {{ data_get($device, 'device_names.0.name', $device['model_number'] ?? '') }}
                            </summary>
                            @include('livewire.device-request.record-value', ['value' => $device])
                        </details>
                    @endforeach
                    <div class="flex flex-wrap gap-3">
                        <button
                            wire:click="printout"
                            wire:loading.attr="disabled"
                            wire:target="printout"
                            class="button-minor"
                        >
                            {{ __('device-requests.labels.print') }}
                        </button>
                        @if ($selected['status'] === 'active')
                            @can('device_request:revoke')
                                <button wire:click="startAction('revoke')" class="button-minor">
                                    {{ __('device-requests.labels.revoke') }}
                                </button>
                            @endcan
                            @if (!isset($selected['quantity']))
                                @can('device_request:complete')
                                    <button
                                        wire:click="complete"
                                        wire:confirm="{{ __('device-requests.labels.complete_confirm') }}"
                                        class="button-minor"
                                    >
                                        {{ __('device-requests.labels.complete') }}
                                    </button>
                                @endcan
                            @endif
                        @endif
                        @if (!in_array($selected['status'], ['entered-in-error', 'entered_in_error']))
                            @can('device_request:mark_in_error')
                                <button wire:click="startAction('mark_in_error')" class="button-minor">
                                    {{ __('device-requests.labels.mark_in_error') }}
                                </button>
                            @endcan
                        @endif
                        @can('device_dispense:read')
                            <button
                                wire:click="loadDispenses"
                                wire:loading.attr="disabled"
                                wire:target="loadDispenses,showDetails"
                                class="button-minor"
                            >
                                {{ __('device-requests.labels.dispenses') }}
                            </button>
                        @endcan
                    </div>
                    @if ($phone && $selected['status'] === 'active')
                        @can('device_request:resend')
                            <div>
                                <p>{{ __('device-requests.labels.sms_hint') }}: {{ $phone }}</p>
                                <label
                                    ><input type="checkbox" wire:model="phoneConfirmed" />
                                    {{ __('device-requests.labels.phone_confirmed') }}</label
                                ><button
                                    wire:click="resendSms"
                                    wire:confirm="{{ __('device-requests.labels.sms_confirm') }}"
                                    class="button-minor"
                                >
                                    {{ __('device-requests.labels.resend') }}
                                </button>
                            </div>
                        @endcan
                    @endif
                    @if ($action)
                        <div class="space-y-3">
                            <label
                                >{{ __('device-requests.labels.reason') }}<select
                                    wire:model="reason"
                                    class="input-select w-full"
                                >
                                    <option value="">{{ __('device-requests.labels.choose_reason') }}</option>
                                    @foreach ($reasons as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach</select></label
                            ><label
                                >{{ __('device-requests.labels.explanation') }}<textarea
                                    wire:model="reasonText"
                                    class="input w-full"
                                ></textarea></label
                            ><button wire:click="openSignatureModal" class="button-primary">
                                {{ __('device-requests.labels.sign_change') }}
                            </button>
                        </div>
                    @endif
                    <p wire:loading wire:target="loadDispenses" role="status">
                        {{ __('device-requests.labels.dispenses_loading') }}
                    </p>
                    <div wire:loading.remove wire:target="loadDispenses,showDetails">
                        @if ($dispensesLoaded)
                            <section class="space-y-3" aria-label="{{ __('device-requests.labels.dispenses') }}">
                                <h3 class="font-semibold">{{ __('device-requests.labels.dispenses') }}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ __('device-requests.labels.dispenses_hint') }}
                                </p>
                                @forelse ($dispenses as $dispense)
                                    <button
                                        wire:key="dispense-{{ $dispense['id'] }}"
                                        wire:click="showDispense('{{ $dispense['id'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="showDispense"
                                        class="button-minor"
                                    >
                                        {{ __('device-requests.labels.dispense') }} {{ $dispense['id'] }} · {{ $dispense['status'] ?? '' }}
                                    </button>
                                @empty
                                    <p role="status">{{ __('device-requests.labels.dispenses_empty') }}</p>
                                @endforelse
                                @if ($dispenseDetails)
                                    <h3 class="font-semibold">{{ __('device-requests.labels.dispense_details') }}</h3>
                                    @include('livewire.device-request.record-details', ['record' => $dispenseDetails])
                                @endif
                            </section>
                        @endif
                    </div>
                </section>
            @endif
        @endif
        <x-signature-modal method="sign" />
    </div>
</x-layouts.patient>
