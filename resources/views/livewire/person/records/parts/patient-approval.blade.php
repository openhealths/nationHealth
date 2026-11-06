@use(App\Models\MedicalEvents\Sql\Approval)

<div
    x-data="{
        visible: $wire.entangle('open'),
        groupSearch: '',
        groupExpanded: false
    }"
>
    @can('create', Approval::class)
        <button
            type="button"
            wire:click="openDrawer"
            class="button-primary-outline px-4 py-2 text-sm shadow-sm"
        >
            {{ __('patients.data_access') }}
        </button>
    @endcan

    <div
        x-show="visible"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        @click="$wire.closeDrawer()"
        class="fixed inset-0 bg-gray-900/50"
        style="z-index: {{ $step === 'form' ? 45 : 50 }};"
    ></div>

    <div
        x-show="visible"
        x-transition:enter="transition ease-out duration-300 transform"
        x-transition:enter-start="{{ $step === 'form' ? 'translate-x-full' : 'opacity-0' }}"
        x-transition:enter-end="{{ $step === 'form' ? 'translate-x-0' : 'opacity-100' }}"
        x-transition:leave="transition ease-in duration-200 transform"
        x-transition:leave-start="{{ $step === 'form' ? 'translate-x-0' : 'opacity-100' }}"
        x-transition:leave-end="{{ $step === 'form' ? 'translate-x-full' : 'opacity-0' }}"
        x-cloak
        class="{{ $step === 'form'
            ? 'fixed top-0 right-0 h-screen w-4/5 overflow-y-auto bg-white p-4 pt-20 shadow-2xl dark:bg-gray-800'
            : 'fixed inset-0 flex items-center justify-center p-4'
        }}"
        style="z-index: {{ $step === 'form' ? 46 : 51 }};"
        role="dialog"
        aria-modal="true"
        @keydown.escape.prevent.stop="$wire.closeDrawer()"
        @click.self="if ($wire.step !== 'form') $wire.closeDrawer()"
    >
        <div
            class="{{ $step === 'form'
                ? 'w-full'
                : ($step === 'code' ? 'modal-content mx-auto w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-xl sm:p-8 dark:bg-gray-800' : 'modal-content mx-auto w-full max-w-4xl max-h-[90vh] overflow-y-auto')
            }}"
            @click.stop
            x-trap.noscroll.inert="visible && $wire.step !== 'form'"
        >
                    @if($step !== 'code')
                        <div class="{{ $step === 'form' ? '' : 'mb-8 flex items-center justify-between' }}">
                            <h2 class="{{ $step === 'form' ? 'modal-header' : 'legend !mb-0' }}">
                                @if($step === 'form')
                                    {{ __('patients.get_data_access') }}
                                @elseif($step === 'methods')
                                    {{ __('patients.authentication_methods') }}
                                @elseif($step === 'processing')
                                    {{ __('approval.create.request_title') }}
                                @elseif($step === 'verifying')
                                    {{ __('approval.create.verification_processing_title') }}
                                @else
                                    {{ __('approval.create.confirmation_title') }}
                                @endif
                            </h2>
                        </div>
                    @endif

                    @error('approvalCreation')
                        <p class="text-error mt-3 mb-4">
                            {{ $message }}
                        </p>
                    @enderror

                    @if($step !== 'form')
                        @error('resourceIds')
                            <p class="text-error mt-3 mb-4">
                                {{ $message }}
                            </p>
                        @enderror
                    @endif
                    
                    @if($step === 'form')
                        <div class="mt-8 max-w-md space-y-6">
                            <div class="form-group group max-w-xl">
                                <select
                                    wire:model.live="mode"
                                    @change="groupSearch = ''; groupExpanded = false"
                                    class="input-select peer w-full"
                                >
                                    <option value="" disabled>
                                        {{ __('forms.select') }}
                                    </option>

                                    <option value="patient">
                                        {{ __('approval.create.mode_patient') }}
                                    </option>
                                    <option value="person">
                                        {{ __('approval.create.mode_person') }}
                                    </option>
                                    <option value="resource">
                                        {{ __('approval.create.mode_resource') }}
                                    </option>
                                    <option value="child">
                                        {{ __('approval.create.mode_child') }}
                                    </option>
                                    <option value="service_request">
                                        {{ __('approval.create.service_request') }}
                                    </option>
                                    <option value="service_group">
                                        {{ __('approval.resource_types.service_group') }}
                                    </option>
                                    <option value="diagnoses_group">
                                        {{ __('approval.resource_types.diagnoses_group') }}
                                    </option>
                                    <option value="forbidden_group">
                                        {{ __('approval.create.mode_forbidden_group') }}
                                    </option>
                                    <option value="resource_types">
                                        {{ __('approval.create.mode_resource_types') }}
                                    </option>
                                </select>

                                <label class="label">{{ __('approval.data_type') }}</label>

                                @error('mode')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror

                            </div>

                            @if(in_array($mode, ['resource', 'child', 'service_request'], true))
                                @if($mode === 'resource')
                                    <div class="form-group group max-w-xl">
                                        <select
                                            wire:model.live="resourceType"
                                            class="input-select peer w-full"
                                        >
                                            <option value="episode_of_care">
                                                {{ __('approval.create.episode_of_care') }}
                                            </option>
                                            <option value="diagnostic_report">
                                                {{ __('approval.create.diagnostic_report') }}
                                            </option>
                                            <option value="encounter">
                                                {{ __('approval.create.encounter') }}
                                            </option>
                                            <option value="procedure">
                                                {{ __('approval.create.procedure') }}
                                            </option>
                                            <option value="care_plan">
                                                {{ __('approval.create.care_plan') }}
                                            </option>
                                            <option value="specimen">
                                                {{ __('approval.create.specimen') }}
                                            </option>
                                        </select>

                                        <label class="label">
                                            {{ __('approval.create.record_type') }}
                                        </label>
                                    </div>
                                @elseif($mode === 'child')
                                    <div class="form-group group max-w-xl">
                                        <select
                                            wire:model.live="childType"
                                            class="input-select peer w-full"
                                        >
                                            <option value="encounter">
                                                {{ __('approval.create.encounter') }}
                                            </option>
                                            <option value="diagnostic_report">
                                                {{ __('approval.create.diagnostic_report') }}
                                            </option>
                                            <option value="procedure">
                                                {{ __('approval.create.procedure') }}
                                            </option>
                                            <option value="condition">
                                                {{ __('approval.create.condition') }}
                                            </option>
                                            <option value="observation">
                                                {{ __('approval.create.observation') }}
                                            </option>
                                            <option value="activity">
                                                {{ __('approval.create.activity') }}
                                            </option>
                                            <option value="clinical_impression">
                                                {{ __('approval.create.clinical_impression') }}
                                            </option>
                                            <option value="immunization">
                                                {{ __('approval.create.immunization') }}
                                            </option>
                                            <option value="device">
                                                {{ __('approval.create.device') }}
                                            </option>
                                        </select>

                                        <label class="label">
                                            {{ __('approval.create.child_record') }}
                                        </label>
                                    </div>
                                @endif

                                @if($mode === 'service_request')
                                    <div
                                        x-data="{ referralExpanded: false }"
                                        @click.outside="if (!$wire.previewSection) referralExpanded = false"
                                        @keydown.escape.stop="referralExpanded = false"
                                        class="max-w-xl space-y-4"
                                    >
                                        <div class="flex items-end gap-4">
                                            <div class="min-w-0 flex-1">
                                                <label class="mb-2 block text-sm text-gray-700 dark:text-gray-300">
                                                    {{ __('approval.create.referral_number') }}
                                                </label>

                                                <input
                                                    type="search"
                                                    wire:model="search"
                                                    @input="referralExpanded = false"
                                                    autocomplete="off"
                                                    class="input w-full"
                                                >
                                            </div>

                                            <button
                                                type="button"
                                                wire:click="$refresh"
                                                @click="referralExpanded = true"
                                                class="button-primary"
                                            >
                                                {{ __('approval.create.referral_search') }}
                                            </button>
                                        </div>

                                        <div
                                            x-show="referralExpanded"
                                            x-cloak
                                            class="space-y-3"
                                        >
                                            @forelse($this->availableRecords as $record)
                                                <div
                                                    wire:key="approval-referral-{{ $record['uuid'] }}"
                                                    class="rounded-xl border border-gray-200 bg-white p-4 shadow-md dark:border-gray-600 dark:bg-gray-800"
                                                >
                                                    <button
                                                        type="button"
                                                        wire:click="selectRecord('{{ $record['uuid'] }}')"
                                                        @click="referralExpanded = false"
                                                        @disabled(!$record['selectable'])
                                                        @class([
                                                            'w-full space-y-1 text-left text-sm',
                                                            'cursor-pointer hover:text-blue-600 dark:hover:text-blue-400' => $record['selectable'],
                                                            'cursor-not-allowed opacity-50' => !$record['selectable'],
                                                        ])
                                                    >
                                                        <p class="font-semibold text-base">
                                                            {{ $record['name'] }}
                                                        </p>

                                                        @if($record['date'] !== '')
                                                            <p>{{ $record['date'] }}</p>
                                                        @endif

                                                        <p>
                                                            {{ __('approval.create.referral_status') }}:
                                                            {{ __('care-plan.referral_status.'.$record['status']) }}
                                                        </p>

                                                        @if($record['processing_status'])
                                                            <p>
                                                                {{ __('approval.create.referral_processing_status') }}:
                                                                {{ __('care-plan.referral_status.'.$record['processing_status']) }}
                                                            </p>
                                                        @endif

                                                        @if($record['category'])
                                                            <p>
                                                                {{ __('approval.create.referral_category') }}:
                                                                {{ __('care-plan.referral_category.'.$record['category']) }}
                                                            </p>
                                                        @endif

                                                        @if($record['service_name'])
                                                            <p>
                                                                {{ __('approval.create.referral_service') }}:
                                                                {{ $record['service_code'] }} — {{ $record['service_name'] }}
                                                            </p>
                                                        @endif
                                                    </button>

                                                    <button
                                                        type="button"
                                                        wire:click="showReferralDetails('{{ $record['uuid'] }}')"
                                                        @click.stop="referralExpanded = true"
                                                        class="mt-2 text-left text-sm underline hover:text-blue-600 dark:hover:text-blue-400"
                                                    >
                                                        {{ __('approval.create.referral_requested_records') }}
                                                    </button>

                                                    @if(!$record['selectable'])
                                                        <p class="mt-2 text-xs text-red-600 dark:text-red-400">
                                                            {{ $record['availability_known'] ? __('approval.create.referral_no_resources') : __('approval.create.referral_check_failed') }}
                                                        </p>
                                                    @endif
                                                </div>
                                            @empty
                                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                                    {{ __('approval.create.no_records') }}
                                                </p>
                                            @endforelse
                                        </div>
                                    </div>
                                @else
                                    <div
                                        wire:key="approval-record-picker-{{ $mode }}-{{ $mode === 'child' ? $childType : $resourceType }}"
                                        x-data="{ recordExpanded: false }"
                                        @click.outside="recordExpanded = false"
                                        @keydown.escape.stop="recordExpanded = false"
                                        class="relative max-w-xl"
                                    >
                                        <label class="mb-2 block text-sm text-gray-500 dark:text-gray-400">
                                            {{ __('approval.create.select_record') }}
                                        </label>

                                        <div class="relative">
                                            <input
                                                x-ref="recordInput"
                                                type="search"
                                                wire:model.live.debounce.1000ms="search"
                                                @focus="recordExpanded = true"
                                                @input="recordExpanded = true"
                                                autocomplete="off"
                                                placeholder="{{ __('approval.create.search_record') }}"
                                                class="input w-full pr-9"
                                            >

                                            <button
                                                type="button"
                                                class="absolute inset-y-0 right-0 px-3"
                                                @click="recordExpanded = !recordExpanded"
                                            >
                                                <span class="text-gray-500">⌄</span>
                                            </button>
                                        </div>

                                        <div
                                            x-show="recordExpanded"
                                            x-cloak
                                            class="absolute left-0 right-0 z-50 mt-2 max-h-80 space-y-2 overflow-y-auto rounded-xl border border-gray-200 bg-white p-3 shadow-xl dark:border-gray-600 dark:bg-gray-800"
                                        >
                                            @forelse($this->availableRecords as $record)
                                                @php
                                                    $selected = $mode === 'child'
                                                        ? $childUuid === $record['uuid']
                                                        : in_array($record['uuid'], $resourceIds, true);
                                                @endphp

                                                <button
                                                    type="button"
                                                    wire:key="approval-record-{{ $record['uuid'] }}"
                                                    wire:click="selectRecord('{{ $record['uuid'] }}')"
                                                    @click="recordExpanded = {{ $mode === 'resource' ? 'true' : 'false' }}"
                                                    @disabled(!$record['selectable'])
                                                    @class([
                                                        'flex w-full items-center gap-3 rounded-lg p-2 text-left',
                                                        'bg-blue-500/10' => $selected,
                                                        'hover:bg-gray-100 dark:hover:bg-gray-700' => !$selected,
                                                        'cursor-not-allowed opacity-50' => !$record['selectable'],
                                                    ])
                                                >
                                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded border border-blue-500">
                                                        {{ $selected ? '✓' : '' }}
                                                    </span>

                                                    <span class="min-w-0 space-y-1">
                                                        <span class="block text-sm">
                                                            {{ $record['name'] }}
                                                        </span>

                                                        @if($record['date'] !== '')
                                                            <span class="block text-xs text-gray-500">
                                                                {{ $record['date'] }}
                                                            </span>
                                                        @endif

                                                        <span class="block break-all text-xs text-gray-500">
                                                            ID {{ $record['uuid'] }}
                                                        </span>
                                                    </span>
                                                </button>

                                                @if($mode === 'child' && !$record['selectable'])
                                                    <p class="px-2 text-sm text-red-600 dark:text-red-400">
                                                        {{ $record['unavailable_reason'] }}
                                                    </p>
                                                @endif
                                            @empty
                                                <p class="text-sm text-gray-500">
                                                    {{ __('approval.create.no_records') }}
                                                </p>
                                            @endforelse
                                        </div>
                                    </div>
                                @endif

                                @if($this->selectedRecords !== [])
                                    <div class="max-w-xl space-y-2">
                                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                            {{ __('approval.create.selected_records') }}
                                        </p>

                                        @foreach($this->selectedRecords as $record)
                                            <div
                                                wire:key="approval-selected-record-{{ $record['uuid'] }}"
                                                class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-600"
                                            >
                                                <span class="min-w-0 space-y-1">
                                                    <span class="block text-sm text-gray-900 dark:text-gray-100">
                                                        {{ $record['name'] }}
                                                    </span>

                                                    @if($record['date'] !== '')
                                                        <span class="block text-sm text-gray-600 dark:text-gray-300">
                                                            {{ $record['date'] }}
                                                        </span>
                                                    @endif

                                                    <span class="block break-all text-xs text-gray-500 dark:text-gray-400">
                                                        ID {{ $record['uuid'] }}
                                                    </span>
                                                </span>

                                                <button
                                                    type="button"
                                                    wire:click="selectRecord('{{ $record['uuid'] }}')"
                                                    class="shrink-0 text-gray-500 hover:text-red-600 dark:text-gray-400"
                                                    aria-label="{{ __('approval.create.remove_selection') }}"
                                                >
                                                    &times;
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @error('resourceIds')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror

                                @error('childUuid')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror

                            @endif
                                                     
                            @if(in_array($mode, ['service_group', 'diagnoses_group', 'forbidden_group'], true))
                                <div
                                    class="relative max-w-xl"
                                    @click.outside="groupExpanded = false"
                                    @keydown.escape.stop="groupExpanded = false"
                                >
                                    <label class="mb-2 block text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('approval.create.catalog_group') }}
                                    </label>

                                    <div class="relative">
                                        <input
                                            x-ref="groupInput"
                                            type="search"
                                            x-model="groupSearch"
                                            @focus="groupExpanded = true"
                                            @input="
                                                groupExpanded = true;

                                                if ($wire.groupId) {
                                                    $wire.set('groupId', '');
                                                }
                                            "
                                            placeholder="{{ __('approval.create.search_group') }}"
                                            autocomplete="off"
                                            role="combobox"
                                            aria-autocomplete="list"
                                            aria-controls="approval-group-options"
                                            :aria-expanded="groupExpanded.toString()"
                                            class="input w-full pr-10 placeholder-gray-500 dark:placeholder-gray-400"
                                        >

                                        <button
                                            type="button"
                                            tabindex="-1"
                                            class="absolute inset-y-0 right-0 flex items-center justify-center px-3 text-gray-500 dark:text-gray-400"
                                            @click="
                                                groupExpanded = !groupExpanded;

                                                if (groupExpanded) {
                                                    $nextTick(() => $refs.groupInput.focus());
                                                }
                                            "
                                            aria-label="{{ __('forms.select') }}"
                                        >
                                            <svg
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                aria-hidden="true"
                                            >
                                                <path d="m6 9 6 6 6-6" />
                                            </svg>
                                        </button>
                                    </div>

                                    <div
                                        id="approval-group-options"
                                        x-show="groupExpanded"
                                        x-cloak
                                        class="absolute left-0 right-0 z-50 mt-3 rounded-xl border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-600 dark:bg-gray-800"
                                    >
                                        <div
                                            x-ref="groupOptions"
                                            class="max-h-80 space-y-6 overflow-y-auto"
                                        >
                                            @forelse($this->selectableGroups as $group)
                                                <div
                                                    wire:key="approval-group-option-{{ $mode }}-{{ $group['id'] }}"
                                                    data-search="{{ trim(($group['code'] ?? '').' '.$group['name']) }}"
                                                    x-show="$el.dataset.search.toLocaleLowerCase().includes(groupSearch.trim().toLocaleLowerCase())"
                                                    class="space-y-1"
                                                >
                                                    <button
                                                        type="button"
                                                        data-label="{{ trim(($group['code'] ?? '').' '.$group['name']) }}"
                                                        wire:click="$set('groupId', '{{ $group['id'] }}')"
                                                        @click="
                                                            groupSearch = $el.dataset.label;
                                                            groupExpanded = false;
                                                        "
                                                        class="block w-full text-left text-base font-normal text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400"
                                                    >
                                                        @if(!empty($group['code']))
                                                            <span class="mr-1">
                                                                {{ $group['code'] }}
                                                            </span>
                                                        @endif

                                                        {{ $group['name'] }}
                                                    </button>

                                                    <div class="flex flex-col items-start gap-1">
                                                        @if(in_array($mode, ['diagnoses_group', 'forbidden_group'], true))
                                                            <button
                                                                type="button"
                                                                wire:click="showGroupDetails('codes', '{{ $group['id'] }}')"
                                                                @click.stop="groupExpanded = true"
                                                                wire:loading.attr="disabled"
                                                                wire:target="showGroupDetails"
                                                                class="text-left text-sm text-gray-900 underline hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400"
                                                            >
                                                                {{ __('patients.list_of_diagnosis_codes') }}
                                                            </button>
                                                        @endif

                                                        @if(in_array($mode, ['forbidden_group', 'service_group'], true))
                                                            <button
                                                                type="button"
                                                                wire:click="showGroupDetails('services', '{{ $group['id'] }}')"
                                                                @click.stop="groupExpanded = true"
                                                                wire:loading.attr="disabled"
                                                                wire:target="showGroupDetails"
                                                                class="text-left text-sm text-gray-900 underline hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400"
                                                            >
                                                                {{ __('patients.list_of_services') }}
                                                            </button>
                                                        @endif
                                                    </div>
                                                </div>
                                            @empty
                                                <p class="p-2 text-sm text-gray-500 dark:text-gray-400">
                                                    {{ __('approval.create.no_groups') }}
                                                </p>
                                            @endforelse
                                        </div>

                                        @if($this->selectableGroups !== [])
                                            <p
                                                x-show="!Array.from($refs.groupOptions.querySelectorAll('[data-search]')).some(item => item.dataset.search.toLocaleLowerCase().includes(groupSearch.trim().toLocaleLowerCase()))"
                                                x-cloak
                                                class="p-2 text-sm text-gray-500 dark:text-gray-400"
                                            >
                                                {{ __('approval.create.no_search_matches') }}
                                            </p>
                                        @endif
                                    </div>

                                    @error('groupId')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif
                     
                            @if($mode === 'resource_types')
                                <p class="text-sm text-gray-700 dark:text-gray-300">
                                    {{ __('approval.create.device_types_notice') }}
                                </p>

                                <label
                                    wire:key="approval-detected-issue-checkbox"
                                    class="flex cursor-pointer items-center gap-3 text-left text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        wire:model="includeDetectedIssue"
                                        class="default-checkbox h-5 w-5 shrink-0 cursor-pointer"
                                    >

                                    <span>{{ __('approval.create.include_detected_issue') }}</span>
                                </label>
                            @endif

                            @if(
                                $mode === 'resource'
                                && in_array($resourceType, ['diagnostic_report', 'care_plan'], true)
                            )
                                <div class="form-group group max-w-xl">
                                    <select
                                        wire:model="accessLevel"
                                        class="input-select peer w-full"
                                    >
                                        <option value="read">{{ __('approval.create.read') }}</option>
                                        <option value="write">{{ __('approval.create.write') }}</option>
                                    </select>

                                    <label class="label">
                                        {{ __('approval.access_level') }}
                                    </label>

                                    @error('accessLevel')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            @else
                                <div class="space-y-2">
                                    <p class="text-sm text-gray-600 dark:text-gray-300">
                                        {{ __('approval.access_level') }}:
                                        <strong>
                                            {{
                                                $mode === 'resource'
                                                && in_array($resourceType, ['encounter', 'procedure', 'specimen'], true) ? __('approval.create.write') : __('approval.create.read')
                                            }}
                                        </strong>
                                    </p>

                                    @if($mode === 'resource' && in_array($resourceType, ['encounter', 'procedure'], true))
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('approval.create.write_only_resource_note') }}
                                        </p>
                                    @endif

                                    @error('accessLevel')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif

                            @if($mode === 'service_request')
                                <div class="space-y-2">
                                    <label
                                        wire:key="approval-legal-entity-checkbox"
                                        class="flex cursor-pointer items-center gap-3 text-left text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            wire:model="grantToLegalEntity"
                                            class="default-checkbox h-5 w-5 shrink-0 cursor-pointer"
                                        >

                                        <span>{{ __('approval.create.grant_to_legal_entity') }}</span>
                                    </label>

                                    @error('grantToLegalEntity')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif

                            <div class="flex gap-3">
                                <button
                                    type="button"
                                    class="button-minor"
                                    wire:click="closeDrawer"
                                >
                                    {{ __('forms.cancel') }}
                                </button>

                                <button
                                    type="button"
                                    class="button-primary"
                                    wire:click="chooseAuthentication"
                                    wire:loading.attr="disabled"
                                    wire:target="chooseAuthentication"
                                >
                                    {{ __('patients.get_access') }}
                                </button>
                            </div>
                        </div>

                    @elseif($step === 'methods')
                        <div class="space-y-4">
                            @forelse($authMethods as $method)
                                <div
                                    class="rounded-2xl border border-gray-300 p-5 dark:border-gray-600"
                                    wire:key="auth-{{ $method['uuid'] }}"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-4">
                                        <div class="space-y-2">
                                            <h3 class="font-semibold text-lg">
                                                @if($method['type'] === 'OTP')
                                                    {{ __('patients.authentication_SMS') }}
                                                @elseif($method['type'] === 'OFFLINE')
                                                    {{ __('patients.authentication_documents') }}
                                                @else
                                                    {{ __('patients.authentication_third_person') }}
                                                @endif
                                            </h3>

                                            <p class="text-sm font-medium text-gray-600 dark:text-gray-300">
                                                {{ $method['alias'] ?? __('forms.auth_method') }}
                                            </p>

                                            @if($method['type'] === 'OTP')
                                                <p class="text-sm">
                                                    {{ __('approval.create.phone_number') }}:
                                                    {{ $method['phoneNumber'] ?? '—' }}
                                                </p>
                                            @endif
                                        </div>

                                        <div class="flex items-center gap-4">
                                            <button
                                                type="button"
                                                class="text-blue-600 text-sm hover:underline"
                                                @click="visible = false; $wire.$parent.openAuthMethodModal()"
                                            >
                                                {{ __('patients.change') }}
                                            </button>

                                            <button
                                                type="button"
                                                class="button-primary"
                                                wire:click="$set('selectedAuthUuid', '{{ $method['uuid'] }}')"
                                            >
                                                {{ $selectedAuthUuid === $method['uuid'] ? __('approval.create.selected') : __('forms.select') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p class="text-error">
                                    {{ __('approval.create.no_active_auth_methods') }}
                                </p>
                            @endforelse

                            <button
                                type="button"
                                class="text-blue-600 hover:underline text-sm"
                                @click="visible = false; $wire.$parent.openAuthMethodModal()"
                            >
                                {{ __('approval.create.manage_auth_methods') }}
                            </button>

                            @if((collect($authMethods)->firstWhere('uuid', $selectedAuthUuid)['type'] ?? null) === 'OFFLINE')
                                @include('livewire.person.records.parts.approval-offline-leaflet')
                            @endif

                            @error('selectedAuthUuid')
                                <p class="text-error">{{ $message }}</p>
                            @enderror

                            <div class="mt-8 flex gap-3">
                                <button
                                    type="button"
                                    class="button-minor"
                                    wire:click="$set('step', 'form')"
                                >
                                    {{ __('forms.back') }}
                                </button>

                                <button
                                    type="button"
                                    class="button-primary"
                                    wire:click="create"
                                    wire:loading.attr="disabled"
                                    wire:target="create"
                                    @disabled($selectedAuthUuid === '')
                                >
                                    {{ __('approval.create.submit_request') }}
                                </button>
                            </div>
                        </div>

                    @elseif($step === 'processing')
                        <div
                            wire:poll.2s="poll"
                            class="py-12 text-center text-gray-600 dark:text-gray-300"
                        >
                            {{ __('approval.create.processing_request') }}
                        </div>

                    @elseif($step === 'verifying')
                        <div
                            wire:poll.2s="poll"
                            class="py-12 text-center text-gray-600 dark:text-gray-300"
                        >
                            {{ __('approval.create.processing_verification') }}
                        </div>

                    @elseif($step === 'confirming')
                        <div class="space-y-4">
                            <p class="text-sm">
                                {{ __('approval.create.request_created') }}
                            </p>

                            @if($selectedAuthType === 'OFFLINE')
                                @include('livewire.person.records.parts.approval-offline-leaflet')
                            @endif

                            <button
                                type="button"
                                class="button-primary"
                                wire:click="continueAfterCreation"
                                wire:loading.attr="disabled"
                                wire:target="continueAfterCreation"
                            >
                                {{ $selectedAuthType === 'OFFLINE' ? __('approval.create.verify_offline') : __('approval.create.to_sms') }}
                            </button>
                        </div>

                    @elseif($step === 'code')
                        @include('livewire.person.records.parts.approval-sms-modal')
                    @endif
        </div>
    </div>

    @if($step === 'form' && $previewSection !== '')
        <div
            wire:key="approval-preview-backdrop"
            class="fixed inset-0 bg-gray-900/30"
            style="z-index: 47;"
            wire:click="closePreview"
            @click.stop="groupExpanded = true"
        ></div>

        <div
            wire:key="approval-preview-drawer"
            class="fixed top-0 right-0 h-screen overflow-y-auto bg-white p-4 pt-20 shadow-2xl dark:bg-gray-800"
            style="width: 74%; z-index: 48;"
            role="dialog"
            aria-modal="true"
            aria-label="{{ $previewTitle }}"
            tabindex="-1"
            @keydown.escape.prevent.stop="$wire.closePreview()"
        >
            <h2 class="modal-header">
                {{ $previewTitle }}
            </h2>

            <div class="mt-8 max-w-4xl space-y-6">
                @if($previewSection === 'codes' && $mode === 'forbidden_group')
                    <div class="flex flex-wrap items-center gap-4 text-sm">
                        <span class="font-semibold">
                            {{ __('patients.coding') }}
                        </span>

                        @foreach([
                            'all' => __('approval.create.all_coding_systems'),
                            'eHealth/ICPC2/condition_codes' => 'ICPC',
                            'eHealth/ICD10_AM/condition_codes' => __('medical-events.icd_10'),
                        ] as $system => $label)
                            <label
                                @class([
                                    'flex cursor-pointer items-center gap-2',
                                    'font-medium text-blue-600 dark:text-blue-400' => $previewCodingSystem === $system,
                                ])
                            >
                                <input
                                    type="radio"
                                    name="approval-preview-coding"
                                    wire:model.live="previewCodingSystem"
                                    value="{{ $system }}"
                                    class="default-radio h-4 w-4 cursor-pointer"
                                    @checked($previewCodingSystem === $system)
                                >

                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif

                <div class="space-y-2 text-sm text-gray-900 dark:text-gray-100">
                    @if($previewSection === 'referral')
                        <h3 class="text-lg font-semibold">
                            {{ __('approval.create.referral_requested_records') }}
                        </h3>

                        <ul class="list-disc space-y-6 pl-6">
                            @forelse($previewRows as $row)
                                <li wire:key="referral-resource-{{ $loop->index }}">
                                    <p class="font-semibold">
                                        {{ __('approval.resource_types.'.$row['code']) }}
                                    </p>

                                    <p class="mt-1 break-all">
                                        ID {{ $row['name'] }}
                                    </p>
                                </li>
                            @empty
                                <li>{{ __('approval.create.referral_no_resources') }}</li>
                            @endforelse
                        </ul>
                    @else
                        @forelse($previewRows as $row)
                            @if(
                                $previewCodingSystem === 'all'
                                || ($row['system'] ?? null) === $previewCodingSystem
                            )
                                <p wire:key="preview-row-{{ $loop->index }}">
                                    <span class="font-semibold">
                                        {{ $row['code'] }}
                                    </span>

                                    @if($row['name'] !== '')
                                        — {{ $row['name'] }}
                                    @endif
                                </p>
                            @endif
                        @empty
                            <p>
                                {{ __('approval.create.no_group_details') }}
                            </p>
                        @endforelse
                    @endif
                </div>

                <button
                    type="button"
                    class="button-minor"
                    wire:click="closePreview"
                    @click.stop="groupExpanded = true"
                >
                    {{ __('forms.back') }}
                </button>
            </div>
        </div>
    @endif
</div>
