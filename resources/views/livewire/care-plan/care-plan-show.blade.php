@use('App\Livewire\CarePlan\CarePlanShow')

<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            {{ __('care-plan.care_plan_details') }} #{{ $carePlan->requisition ?? $carePlan->id }}
        </x-slot>
    </x-header-navigation>

    <div x-data="{ 
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
            
            $categoryCode = is_array($carePlan->category) ? ($carePlan->category['coding'][0]['code'] ?? ($carePlan->category['text'] ?? '')) : $carePlan->category;
            $categoryLabel = $dictionaries['care_plan_categories'][$categoryCode] ?? $categoryCode;
        @endphp
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <h2 class="title">{{ $carePlan->title }}</h2>
                <span class="badge {{ in_array(strtoupper($status), ['ACTIVE', 'active']) ? 'badge-success' : 'badge-secondary' }}">
                    {{ $statusDisplay }}
                </span>
            </div>
            
            @if(!$carePlan->uuid && in_array(strtoupper($status), ['NEW', 'DRAFT', 'PENDING']))
                <a href="{{ route('care-plan.edit', [legalEntity(), $carePlan->id]) }}" 
                   class="button-secondary flex items-center gap-2"
                   wire:navigate>
                    @icon('edit-user-outline', 'w-4 h-4')
                    <span>{{ __('forms.edit') }}</span>
                </a>
            @endif
        </div>

        {{-- Core Details Card --}}
        <div class="record-inner-card">
            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
                        <div class="record-inner-column">
                            <div class="record-inner-label">{{ __('care-plan.category') }}</div>
                            <div class="record-inner-value">{{ $categoryLabel ?: '-' }}</div>
                        </div>
                        <div class="record-inner-column">
                            <div class="record-inner-label">{{ __('care-plan.name_care_plan') }}</div>
                            <div class="record-inner-value">{{ $carePlan->title }}</div>
                        </div>

                        <div class="record-inner-column border-t border-gray-100 dark:border-gray-700 pt-3">
                            <div class="record-inner-label">{{ __('forms.start_date') }}</div>
                            <div class="record-inner-value">{{ $carePlan->period_start?->format('d.m.Y') ?? '-' }}</div>
                        </div>
                        <div class="record-inner-column border-t border-gray-100 dark:border-gray-700 pt-3">
                            <div class="record-inner-label">{{ __('forms.end_date') }}</div>
                            <div class="record-inner-value">{{ $carePlan->period_end ? $carePlan->period_end->format('d.m.Y') : __('care-plan.no_end_date') }}</div>
                        </div>

                        <div class="record-inner-column border-t border-gray-100 dark:border-gray-700 pt-3">
                            <div class="record-inner-label">{{ __('care-plan.patient') }}</div>
                            <div class="record-inner-value text-blue-600">{{ $carePlan->person?->full_name ?? '-' }}</div>
                        </div>
                        <div class="record-inner-column border-t border-gray-100 dark:border-gray-700 pt-3">
                            <div class="record-inner-label">{{ __('care-plan.author') }}</div>
                            <div class="record-inner-value">{{ $carePlan->author?->party?->full_name ?? '-' }}</div>
                        </div>
                    </div>

                    @if($carePlan->description)
                    <div class="record-inner-column border-t border-gray-100 dark:border-gray-700 pt-3 mt-2">
                        <div class="record-inner-label">{{ __('care-plan.extended_description') }}</div>
                        <div class="record-inner-value whitespace-pre-line">{{ $carePlan->description }}</div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Activities Table --}}
        <fieldset class="fieldset mt-6">
            <div class="flex items-center justify-between mb-4">
                <legend class="legend mb-0">{{ __('care-plan.activities') }}</legend>
                
                @if(in_array(strtoupper($status), ['ACTIVE', 'active']))
                    {{-- Dropdown for New Prescription --}}
                    <div class="relative">
                        <button type="button" 
                                @click="openDropdown = !openDropdown" 
                                @click.away="openDropdown = false"
                                class="button-primary flex items-center gap-2">
                            <span>+ {{ __('care-plan.new_prescription') }}</span>
                            <svg class="w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/>
                            </svg>
                        </button>
                        
                        <div x-show="openDropdown" 
                             x-transition
                             style="display: none;"
                             class="absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none dark:bg-gray-700">
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

            <div class="flow-root mt-4">
                <table class="table-input w-full table-fixed min-w-[800px] text-sm">
                    <thead class="thead-input">
                        <tr>
                            <th scope="col" class="th-input w-[30%]">{{ __('care-plan.kind') }}</th>
                            <th scope="col" class="th-input w-[15%]">{{ __('care-plan.quantity') }}</th>
                            <th scope="col" class="th-input w-[20%]">{{ __('forms.start_date') }}</th>
                            <th scope="col" class="th-input w-[20%]">{{ __('forms.status.label') }}</th>
                            <th scope="col" class="th-input w-[15%]"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($carePlan->activities ?? [] as $activity)
                            <tr>
                                <td class="td-input break-words whitespace-normal align-top">
                                    @if(is_array($activity->kind))
                                        {{ $activity->kind['text'] ?? $activity->kind['coding'][0]['display'] ?? $activity->kind['coding'][0]['code'] ?? '-' }}
                                    @else
                                        {{ $activity->kind ?? '-' }}
                                    @endif
                                </td>
                                <td class="td-input break-words whitespace-normal align-top">
                                    @if(is_array($activity->quantity))
                                        {{ $activity->quantity['value'] ?? '-' }} {{ $activity->quantity['unit'] ?? '' }}
                                    @else
                                        {{ $activity->quantity ?? '-' }}
                                    @endif
                                </td>
                                <td class="td-input break-words whitespace-normal align-top">
                                    {{ $activity->scheduled_period_start?->format('d.m.Y') }}
                                </td>
                                <td class="td-input break-words whitespace-normal align-top">
                                    @php
                                        $activityStatus = is_array($activity->status) ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? '')) : $activity->status;
                                        $activityStatusDisplay = is_array($activity->status) ? ($activity->status['text'] ?? ($activity->status['coding'][0]['display'] ?? $activityStatus)) : $activityStatus;
                                    @endphp
                                    <span class="badge {{ in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']) ? 'badge-warning' : 'badge-success' }}">
                                        {{ $activityStatusDisplay }}
                                    </span>
                                </td>
                                <td class="td-input text-right align-middle">
                                    @if(in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']))
                                        <button type="button"
                                                class="text-blue-600 hover:text-blue-800 text-sm font-medium"
                                                wire:click="openSignatureModal('sign_activity', {{ $activity->id }})">
                                            {{ __('forms.sign') }}
                                        </button>
                                    @elseif(in_array(strtoupper($activityStatus), ['ACTIVE', 'SCHEDULED', 'IN-PROGRESS', 'IN_PROGRESS', 'ON-HOLD']))
                                        <div class="flex flex-col space-y-2 lg:flex-row lg:space-y-0 lg:space-x-3 items-end lg:items-center">
                                            <button type="button"
                                                    class="text-green-600 hover:text-green-800 text-sm font-medium"
                                                    wire:click="openSignatureModal('complete_activity', {{ $activity->id }})">
                                                Завершити
                                            </button>
                                            <button type="button"
                                                    class="text-red-500 hover:text-red-700 text-sm font-medium"
                                                    wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})">
                                                Скасувати
                                            </button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="td-input text-center py-8 text-gray-400">
                                    {{ __('care-plan.no_activities') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </fieldset>

        {{-- Action Buttons --}}
        <div class="mt-6 flex flex-row items-center gap-4 pt-6">
            @if(!$carePlan->uuid && in_array(strtoupper($status), ['NEW', 'DRAFT', 'PENDING']))
                {{-- Sign button for drafts --}}
                <div class="flex items-center gap-3">
                    <button type="button"
                            class="button-primary"
                            @click="$wire.openSignatureModal('sign_plan')">
                        {{ __('care-plan.sign_and_send') }}
                    </button>
                </div>
            @elseif($carePlan->uuid && in_array(strtoupper($status), ['ACTIVE', 'NEW', 'active', 'new']))
                {{-- Status Reason (shown above the modal trigger) --}}
                <x-forms.textarea
                    id="statusReason"
                    name="statusReason"
                    label="{{ __('care-plan.status_reason') }}"
                    wire:model="statusReason"
                    class="flex-1"
                />

                <div class="flex items-center gap-3">
                    <button type="button"
                            class="button-success"
                            @click="$wire.openSignatureModal('complete')">
                        {{ __('care-plan.complete_care_plan') }}
                    </button>

                    <button type="button"
                            class="button-danger"
                            @click="$wire.openSignatureModal('cancel')">
                        {{ __('care-plan.cancel_care_plan') }}
                    </button>
                </div>
            @endif
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
