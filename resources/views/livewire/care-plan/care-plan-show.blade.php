@use('App\Livewire\CarePlan\CarePlanShow')

<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            План лікування №{{ $carePlan->requisition ?? $carePlan->id }}
        </x-slot>
    </x-header-navigation>

    <div x-data="{ 
        activeTab: 'info',
        showSignatureModal: $wire.entangle('showSignatureModal').live,
        openDropdown: false,
        showServiceDrawer: false,
        showServiceSearchDrawer: false,
        showMedicationDrawer: false,
        showMedicationSearchDrawer: false,
        showMedicationFormDrawer: false,
        showMedicalDeviceDrawer: false,
        showMedicalDeviceSearchDrawer: false,
        showMedicalDeviceFormDrawer: false
    }" 
    @close-drawers.window="showServiceDrawer = false; showServiceSearchDrawer = false; showMedicationDrawer = false; showMedicationSearchDrawer = false; showMedicationFormDrawer = false; showMedicalDeviceDrawer = false; showMedicalDeviceSearchDrawer = false; showMedicalDeviceFormDrawer = false;"
    class="form shift-content" wire:key="{{ time() }}">

        {{-- Plan Header --}}
        @php
            $status = is_array($carePlan->status) ? ($carePlan->status['coding'][0]['code'] ?? ($carePlan->status['text'] ?? '')) : $carePlan->status;
            $statusDisplay = is_array($carePlan->status) ? ($carePlan->status['text'] ?? ($carePlan->status['coding'][0]['display'] ?? $status)) : $status;
            
            $categoryLabel = $carePlan->categoryConcept?->text ?? $carePlan->categoryConcept?->coding?->first()?->display;
            if (!$categoryLabel) {
                $categoryCode = is_array($carePlan->category) ? ($carePlan->category['coding'][0]['code'] ?? ($carePlan->category['text'] ?? '')) : $carePlan->category;
                $categoryLabel = $dictionaries['care_plan_categories'][$categoryCode] ?? $categoryCode;
            }
        @endphp

        <!-- Tabs Navigation -->
        <div class="border-b border-gray-200 dark:border-gray-700 mb-6 flex justify-between items-center px-4">
            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                <button @click="activeTab = 'info'"
                        :class="activeTab === 'info' ? 'border-blue-500 text-blue-600 dark:text-blue-500' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                    Інформація про план
                </button>
                <button @click="activeTab = 'activities'"
                        :class="activeTab === 'activities' ? 'border-blue-500 text-blue-600 dark:text-blue-500' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm">
                    Призначення
                </button>
            </nav>

            @if(in_array(strtoupper($status), ['ACTIVE', 'active']))
            <div class="relative pb-2 pr-2">
                <button type="button" 
                        @click="openDropdown = !openDropdown" 
                        @click.away="openDropdown = false"
                        class="button-primary flex items-center gap-2">
                    <span>+ {{ __('care-plan.new_prescription') }}</span>
                    <svg class="w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/>
                    </svg>
                </button>
                
                <div x-show="openDropdown" x-transition style="display: none;" class="absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none dark:bg-gray-700">
                    <div class="py-1" role="none">
                        <button type="button" @click="openDropdown = false; showServiceDrawer = true" wire:click="initActivityForm('service_request')" class="text-gray-700 block w-full px-4 py-2 text-left text-sm hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600">
                            {{ __('care-plan.service_prescription') }}
                        </button>
                        <button type="button" @click="openDropdown = false; showMedicationDrawer = true" wire:click="initActivityForm('medication_request')" class="text-gray-700 block w-full px-4 py-2 text-left text-sm hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600">
                            {{ __('care-plan.medication_prescription') }}
                        </button>
                        <button type="button" @click="openDropdown = false; showMedicalDeviceDrawer = true" wire:click="initActivityForm('device_request')" class="text-gray-700 block w-full px-4 py-2 text-left text-sm hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600">
                            {{ __('care-plan.medical_device_prescription') }}
                        </button>
                    </div>
                </div>
            </div>
            @endif
        </div>

        <!-- Info Tab Content -->
        <div x-show="activeTab === 'info'" class="space-y-6 px-4">
            
            {{-- Doctors --}}
            <fieldset class="fieldset">
                <legend class="legend">{{ __('care-plan.doctors') }}</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                    <div class="record-inner-column">
                        <div class="record-inner-label">{{ __('care-plan.author') }}</div>
                        <div class="record-inner-value">{{ $carePlan->author?->party?->full_name ?? '-' }}</div>
                    </div>
                </div>
            </fieldset>

            {{-- Patient Data --}}
            <fieldset class="fieldset">
                <legend class="legend">{{ __('care-plan.patient_data') }}</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                    <div class="record-inner-column">
                        <div class="record-inner-label">{{ __('care-plan.patient') }}</div>
                        <div class="record-inner-value">{{ $carePlan->person?->full_name ?? '-' }}</div>
                    </div>
                    <div class="record-inner-column border-l border-gray-100 dark:border-gray-700 pl-4">
                        <div class="record-inner-label">{{ __('care-plan.medical_number') }}</div>
                        <div class="record-inner-value">{{ $carePlan->encounter_id ?? '-' }}</div>
                    </div>
                </div>
            </fieldset>

            {{-- Care Plan Data --}}
            <fieldset class="fieldset">
                <legend class="legend">{{ __('care-plan.care_plan_data') }}</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6 mt-2">
                    <div class="record-inner-column">
                        <div class="record-inner-label">{{ __('care-plan.ehealth_id') }}</div>
                        <div class="record-inner-value">{{ $carePlan->uuid ?? '-' }}</div>
                    </div>
                    <div class="record-inner-column">
                        <div class="record-inner-label">{{ __('care-plan.ehealth_status') }}</div>
                        <div class="record-inner-value">
                            <span class="badge {{ in_array(strtoupper($status), ['ACTIVE', 'active']) ? 'badge-success' : 'badge-secondary' }}">
                                {{ $statusDisplay }}
                            </span>
                        </div>
                    </div>

                    <div class="record-inner-column border-t border-gray-100 dark:border-gray-700 pt-4">
                        <div class="record-inner-label">{{ __('care-plan.category') }}</div>
                        <div class="record-inner-value">{{ $categoryLabel ?: '-' }}</div>
                    </div>
                    <div class="record-inner-column border-t border-gray-100 dark:border-gray-700 pt-4">
                        <div class="record-inner-label">{{ __('care-plan.name_care_plan') }}</div>
                        <div class="record-inner-value">{{ $carePlan->title }}</div>
                    </div>

                    <div class="record-inner-column border-t border-gray-100 dark:border-gray-700 pt-4">
                        <div class="record-inner-label">{{ __('forms.start_date') }}</div>
                        <div class="record-inner-value flex items-center gap-2">
                            @icon('calendar', 'w-4 h-4 text-gray-400')
                            {{ $carePlan->period_start?->format('d.m.Y') ?? '-' }}
                        </div>
                    </div>
                    <div class="record-inner-column border-t border-gray-100 dark:border-gray-700 pt-4">
                        <div class="record-inner-label">{{ __('forms.end_date') }}</div>
                        <div class="record-inner-value flex items-center gap-2">
                            @icon('calendar', 'w-4 h-4 text-gray-400')
                            {{ $carePlan->period_end ? $carePlan->period_end->format('d.m.Y') : __('care-plan.no_end_date') }}
                        </div>
                    </div>
                </div>
            </fieldset>

            {{-- Condition/Diagnosis --}}
            <fieldset class="fieldset">
                <legend class="legend">{{ __('care-plan.condition_diagnosis') }}</legend>
                <div class="flow-root mt-2">
                    <table class="table-input w-full table-fixed text-sm border-0 shadow-none">
                        <thead class="thead-input bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th scope="col" class="th-input border-0 w-[30%]">Дата</th>
                                <th scope="col" class="th-input border-0 w-[70%]">Назва</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($carePlan->addresses ?? [] as $address)
                                @php
                                    $condId = is_array($address['reference'] ?? null) ? ($address['reference']['identifier']['value'] ?? null) : ($address['reference'] ?? null);
                                    if(str_contains($condId ?? '', '/')) {
                                        $condId = last(explode('/', $condId));
                                    }
                                    $condition = null;
                                    if ($condId) {
                                        $condition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $condId)->first();
                                    }
                                @endphp
                                <tr>
                                    <td class="td-input border-0">{{ $condition?->onset_date?->format('d.m.Y') ?? '-' }}</td>
                                    <td class="td-input border-0 text-gray-900 font-medium">{{ $condition ? ($condition->typeConcept?->text ?? $condition->typeConcept?->coding->first()?->display ?? '-') : '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="td-input py-4 text-center text-gray-400">Немає пов'язаних станів</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </fieldset>

            {{-- Supporting Info --}}
            <fieldset class="fieldset">
                <legend class="legend">{{ __('care-plan.supporting_information') }}</legend>
                <div class="flow-root mt-2">
                    <table class="table-input w-full table-fixed text-sm border-0 shadow-none">
                        <thead class="thead-input bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th scope="col" class="th-input border-0 w-[25%]">Дата</th>
                                <th scope="col" class="th-input border-0 w-[75%]">Назва / ОПИС</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                                $episodes = $carePlan->supporting_info['episodes'] ?? [];
                                $medical_records = $carePlan->supporting_info['medical_records'] ?? [];
                                $allSupporting = array_merge($episodes, $medical_records);
                            @endphp
                            @forelse($allSupporting as $item)
                                @php
                                    $ref = $item['reference'] ?? '';
                                    if(str_contains($ref, '/')) {
                                        $ref = last(explode('/', $ref));
                                    }
                                    $type = $item['type'] ?? '';
                                @endphp
                                <tr>
                                    <td class="td-input border-0">{{ \Carbon\CarbonImmutable::now()->format('d.m.Y') }}</td>
                                    <td class="td-input border-0 text-gray-900 font-medium">{{ $ref }} ({{ $type }})</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="td-input py-4 text-center text-gray-400">Немає допоміжної інформації</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </fieldset>

            {{-- Additional Info --}}
            <fieldset class="fieldset">
                <legend class="legend">{{ __('care-plan.additional_info') }}</legend>
                <div class="grid grid-cols-1 gap-6 mt-2">
                    <div class="record-inner-column">
                        <div class="record-inner-label text-sm text-gray-500 mb-2">Розширений опис</div>
                        <div class="record-inner-value border border-gray-200 dark:border-gray-700 rounded-md p-4 bg-gray-50 dark:bg-gray-800 min-h-[100px] whitespace-pre-line">{{ $carePlan->description ?: '-' }}</div>
                    </div>
                    <div class="record-inner-column">
                        <div class="record-inner-label text-sm text-gray-500 mb-2">Нотатки</div>
                        <div class="record-inner-value border border-gray-200 dark:border-gray-700 rounded-md p-4 bg-gray-50 dark:bg-gray-800 min-h-[100px] whitespace-pre-line">{{ $carePlan->note ?: '-' }}</div>
                    </div>
                </div>
            </fieldset>

            {{-- Approvals --}}
            @livewire('care-plan.care-plan-approvals', ['carePlan' => $carePlan])

            {{-- Action Buttons --}}
            <div class="mt-8 flex flex-row items-center gap-4 pt-6 pb-8 border-t border-gray-100 dark:border-gray-700">
                <a href="{{ route('persons.care-plans', [legalEntity(), $carePlan->person_id]) }}" class="button-minor" wire:navigate>
                    {{ __('forms.back') }}
                </a>

                <div class="flex-1"></div>

                @if(!$carePlan->uuid && in_array(strtoupper($status), ['NEW', 'DRAFT', 'PENDING']))
                @if($carePlan->status === 'new')
                    <a href="{{ route('care-plan.edit', [legalEntity(), $carePlan->id]) }}" class="button-secondary" wire:navigate>
                        {{ __('forms.edit') }}
                    </a>
                @endif
                    <button type="button" class="button-primary" @click="$wire.openSignatureModal('sign_plan')">
                        {{ __('care-plan.sign_and_send') }}
                    </button>
                @elseif($carePlan->uuid && in_array(strtoupper($status), ['ACTIVE', 'NEW', 'active', 'new']))
                    {{-- Status Reason (shown above the modal trigger) --}}
                    <div class="flex flex-col gap-4 max-w-sm mr-4">
                        <x-forms.textarea id="statusReason" name="statusReason" label="{{ __('care-plan.status_reason') }}" wire:model="statusReason" />
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="button" class="button-danger" @click="$wire.openSignatureModal('cancel')">
                            Відмінити
                        </button>
                        <button type="button" class="button-primary" @click="$wire.openSignatureModal('complete')">
                            Завершити
                        </button>
                    </div>
                @endif
            </div>
        </div>

        <!-- Activities Tab Content -->
        <div x-show="activeTab === 'activities'" style="display: none;" class="space-y-6 px-4">
            <fieldset class="fieldset">
                <legend class="legend">{{ __('care-plan.activities') }}</legend>
                <div class="flow-root mt-2">
                    <table class="table-input w-full table-fixed min-w-[800px] text-sm border-0 shadow-none">
                        <thead class="thead-input bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th scope="col" class="th-input border-0 w-[30%]">{{ __('care-plan.kind') }}</th>
                                <th scope="col" class="th-input border-0 w-[15%]">{{ __('care-plan.quantity') }}</th>
                                <th scope="col" class="th-input border-0 w-[20%]">{{ __('forms.start_date') }}</th>
                                <th scope="col" class="th-input border-0 w-[20%]">{{ __('forms.status.label') }}</th>
                                <th scope="col" class="th-input border-0 w-[15%]"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($carePlan->activities ?? [] as $activity)
                                <tr>
                                    <td class="td-input border-0 break-words whitespace-normal align-top text-gray-900 font-medium">
                                        @if($activity->kindConcept)
                                            {{ $activity->kindConcept->text ?? $activity->kindConcept->coding->first()?->display ?? $activity->kindConcept->coding->first()?->code ?? '-' }}
                                        @elseif(is_array($activity->kind))
                                            {{ $activity->kind['text'] ?? $activity->kind['coding'][0]['display'] ?? $activity->kind['coding'][0]['code'] ?? '-' }}
                                        @else
                                            {{ $activity->kind ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="td-input border-0 break-words whitespace-normal align-top">
                                        @if(is_array($activity->quantity))
                                            {{ $activity->quantity['value'] ?? '-' }} {{ $activity->quantity['unit'] ?? '' }}
                                        @else
                                            {{ $activity->quantity ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="td-input border-0 break-words whitespace-normal align-top">
                                        {{ $activity->scheduled_period_start?->format('d.m.Y') }}
                                    </td>
                                    <td class="td-input border-0 break-words whitespace-normal align-top">
                                        @php
                                            $activityStatus = is_array($activity->status) ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? '')) : $activity->status;
                                            $activityStatusDisplay = is_array($activity->status) ? ($activity->status['text'] ?? ($activity->status['coding'][0]['display'] ?? $activityStatus)) : $activityStatus;
                                        @endphp
                                        <span class="badge {{ in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']) ? 'badge-warning' : 'badge-success' }}">
                                            {{ $activityStatusDisplay }}
                                        </span>
                                    </td>
                                    <td class="td-input border-0 text-right align-middle">
                                        @if(in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']))
                                            <div class="flex flex-col space-y-2 lg:flex-row lg:space-y-0 lg:space-x-3 items-end lg:items-center justify-end">
                                                <button type="button" class="text-gray-600 hover:text-gray-800 text-sm font-medium" wire:click="editActivity({{ $activity->id }})">{{ __('forms.edit') }}</button>
                                                <button type="button" class="text-blue-600 hover:text-blue-800 text-sm font-medium" wire:click="openSignatureModal('sign_activity', {{ $activity->id }})">{{ __('forms.sign') }}</button>
                                            </div>
                                        @elseif(in_array(strtoupper($activityStatus), ['ACTIVE', 'SCHEDULED', 'IN-PROGRESS', 'IN_PROGRESS', 'ON-HOLD']))
                                            <div class="flex flex-col space-y-2 lg:flex-row lg:space-y-0 lg:space-x-3 items-end lg:items-center justify-end">
                                                <button type="button" class="text-red-500 hover:text-red-700 text-sm font-medium" wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})">Скасувати</button>
                                                <button type="button" class="text-green-600 hover:text-green-800 text-sm font-medium opacity-50" disabled wire:click="openSignatureModal('complete_activity', {{ $activity->id }})">Завершити</button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="td-input border-0 text-center py-8 text-gray-400">
                                        {{ __('care-plan.no_activities') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </fieldset>
            
            <div class="mt-8 flex flex-row items-center gap-4 pt-6 pb-8 border-t border-gray-100 dark:border-gray-700">
                {{-- Status Reason for Activities (shown above the modal trigger in a compact way) --}}
                @if(in_array($actionType, ['complete_activity', 'cancel_activity']))
                    <div class="flex flex-col gap-4 flex-1 max-w-lg">
                        @if($actionType === 'complete_activity')
                            <div class="grid grid-cols-1 gap-4">
                            <x-forms.select id="outcomeCode" name="outcomeCode" label="{{ __('care-plan.outcome_dictionary') }}" wire:model="outcomeCode" :options="$dictionaries['care_plan_activity_outcomes'] ?? []" />
                            @if(!empty($availableConditions))
                                <div class="mt-2">
                                    <label class="record-inner-label mb-2 block">{{ __('care-plan.referral_outcome') }}</label>
                                    <div class="grid grid-cols-1 gap-2 max-h-40 overflow-y-auto border border-gray-100 dark:border-gray-700 rounded p-2">
                                        @foreach($availableConditions as $cond)
                                            <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 p-1 rounded">
                                                <input type="checkbox" wire:model="outcomeReferences" value="{{ $cond['uuid'] }}" class="checkbox">
                                                <div class="flex flex-col">
                                                    <span class="text-sm">{{ $cond['name'] }}</span>
                                                    <span class="text-xs text-gray-500">{{ $cond['date'] }}</span>
                                                </div>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            </div>
                        @endif
                        <x-forms.textarea id="activity_status_reason" name="statusReason" label="{{ __('care-plan.status_reason') }}" wire:model="statusReason" />
                    </div>
                @endif
            </div>
        </div>

        @include('components.signature-modal', ['method' => 'sign'])

        {{-- Drawers --}}
        @include('livewire.care-plan.parts.modals.services-drawer')
        @include('livewire.care-plan.parts.modals.service-search-drawer')
        @include('livewire.care-plan.parts.modals.medications-drawer')
        @include('livewire.care-plan.parts.modals.medication-search-drawer')
        @include('livewire.care-plan.parts.modals.medication-form-drawer')
        @include('livewire.care-plan.parts.modals.medical-devices-drawer')
        @include('livewire.care-plan.parts.modals.medical-device-search-drawer')
        @include('livewire.care-plan.parts.modals.medical-device-form-drawer')
    </div>
</section>
