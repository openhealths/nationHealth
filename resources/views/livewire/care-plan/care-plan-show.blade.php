@use('App\Livewire\CarePlan\CarePlanShow')

<x-layouts.patient
    :personId="$carePlan->person_id"
    :patientFullName="$carePlan->person->fullName"
    :hideNavigation="true"
    :title="__('care-plan.care_plan_no') . ($carePlan->requisition ?? $carePlan->id)"
    :breadcrumbs="[
        ['label' => __('care-plan.home'), 'url' => route('dashboard', [legalEntity()])],
        ['label' => __('care-plan.patients'), 'url' => route('persons.index', [legalEntity()])],
        ['label' => $carePlan->person->fullName ?? __('care-plan.patient'), 'url' => route('persons.patient-data', [legalEntity(), $carePlan->person_id])],
        ['label' => __('care-plan.care_plan_no') . ($carePlan->requisition ?? $carePlan->id)]
    ]"
>
    <x-slot name="headerActions">
        <button wire:click="sync" class="button-success flex items-center gap-2">
            @icon('sync', 'w-4 h-4')
            <span>{{ __('care-plan.sync_data_with_ehealth') }}</span>
        </button>
    </x-slot>

    <div x-data="{
        showSignatureModal: $wire.entangle('showSignatureModal').live,
        activeTab: 'info',
        showServiceDrawer: $wire.entangle('showServiceDrawer').live,
        showServiceSearchDrawer: $wire.entangle('showServiceSearchDrawer').live,
        showMedicationDrawer: $wire.entangle('showMedicationDrawer').live,
        showMedicationSearchDrawer: $wire.entangle('showMedicationSearchDrawer').live,
        showMedicationFormDrawer: $wire.entangle('showMedicationFormDrawer').live,
        showMedicalDeviceDrawer: $wire.entangle('showMedicalDeviceDrawer').live,
        showMedicalDeviceSearchDrawer: $wire.entangle('showMedicalDeviceSearchDrawer').live,
        showMedicalDeviceFormDrawer: $wire.entangle('showMedicalDeviceFormDrawer').live,
        showMedicalRecordsSearchDrawer: false
    }"
    @close-drawers.window="showServiceDrawer = false; showServiceSearchDrawer = false; showMedicationDrawer = false; showMedicationSearchDrawer = false; showMedicationFormDrawer = false; showMedicalDeviceDrawer = false; showMedicalDeviceSearchDrawer = false; showMedicalDeviceFormDrawer = false; showMedicalRecordsSearchDrawer = false;"
    class="form shift-content pl-3.5" wire:key="care-plan-show-{{ $carePlan->id }}">

        @php
            $status = is_array($carePlan->status) ? ($carePlan->status['coding'][0]['code'] ?? ($carePlan->status['text'] ?? '')) : $carePlan->status;
            $statusDisplay = is_array($carePlan->status) ? ($carePlan->status['text'] ?? ($carePlan->status['coding'][0]['display'] ?? $status)) : $status;

            $categoryLabel = $carePlan->categoryConcept?->text ?? $carePlan->categoryConcept?->coding?->first()?->display;
            if (!$categoryLabel) {
                $categoryCode = is_array($carePlan->category) ? ($carePlan->category['coding'][0]['code'] ?? ($carePlan->category['text'] ?? '')) : $carePlan->category;
                $categoryLabel = $dictionaries['care_plan_categories'][$categoryCode] ?? $categoryCode;
            }

            $intent = 'order';
            $tos = $carePlan->careProvisionConditions ?? $carePlan->terms_of_service;

            $getActivityName = function($activity) {
                if ($activity->productConcept) {
                    return $activity->productConcept->text ?? $activity->productConcept->coding->first()?->display ?? $activity->productConcept->coding->first()?->code;
                }
                if (!empty($activity->product_codeable_concept)) {
                    return $activity->product_codeable_concept;
                }
                if (!empty($activity->product_reference)) {
                    return $activity->product_reference;
                }
                if ($activity->kindConcept) {
                    return $activity->kindConcept->text ?? $activity->kindConcept->coding->first()?->display ?? $activity->kindConcept->coding->first()?->code;
                }
                if (is_array($activity->kind)) {
                    return $activity->kind['text'] ?? $activity->kind['coding'][0]['display'] ?? $activity->kind['coding'][0]['code'] ?? '-';
                }
                return $activity->kind ?? '-';
            };

            $services = [];
            $medications = [];
            $devices = [];

            foreach ($carePlan->activities ?? [] as $activity) {
                $getKindStr = function($act) {
                    if ($act->kindConcept) {
                        return $act->kindConcept->coding->first()?->code ?? $act->kindConcept->text ?? '';
                    }
                    if (is_array($act->kind)) {
                        return $act->kind['coding'][0]['code'] ?? $act->kind['text'] ?? '';
                    }
                    return $act->kind ?? '';
                };

                $kindStr = strtolower($getKindStr($activity));
                if (str_contains($kindStr, 'service')) {
                    $services[] = $activity;
                } elseif (str_contains($kindStr, 'medication')) {
                    $medications[] = $activity;
                } elseif (str_contains($kindStr, 'device')) {
                    $devices[] = $activity;
                } else {
                    $services[] = $activity;
                }
            }
        @endphp

        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-6 border-b border-gray-200 dark:border-gray-700">
                <button type="button"
                        @click="activeTab = 'info'"
                        :class="activeTab === 'info' ? 'text-blue-600 border-b-2 border-blue-600 font-semibold' : 'text-gray-500 hover:text-gray-700 font-medium border-b-2 border-transparent'"
                        class="pb-3 text-sm transition-colors">
                    {{ __('care-plan.plan_info') }}
                </button>
                <button type="button"
                        @click="activeTab = 'prescriptions'"
                        :class="activeTab === 'prescriptions' ? 'text-blue-600 border-b-2 border-blue-600 font-semibold' : 'text-gray-500 hover:text-gray-700 font-medium border-b-2 border-transparent'"
                        class="pb-3 text-sm transition-colors">
                    {{ __('care-plan.prescriptions') }}
                </button>
            </div>

            <div class="relative pb-3" x-data="{ openDropdown: false }">
                <button type="button"
                        @click="openDropdown = !openDropdown"
                        @click.away="openDropdown = false"
                        class="text-blue-600 hover:text-blue-800 font-medium text-sm flex items-center gap-1 transition-colors">
                    <span>+ {{ __('care-plan.new_prescription') }}</span>
                </button>

                <div x-show="openDropdown"
                     x-transition
                     style="display: none;"
                     class="absolute right-0 z-10 mt-2 w-48 origin-top-right rounded-lg bg-white p-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none dark:bg-gray-700 border border-gray-100 dark:border-gray-600">
                    <div class="py-1" role="none">
                        <button type="button" @click="openDropdown = false; showServiceDrawer = true" wire:click="initActivityForm('service_request')" class="text-gray-700 block w-full px-4 py-2 text-left text-sm hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600 rounded-md">
                            {{ __('care-plan.services') }}
                        </button>
                        <button type="button" @click="openDropdown = false; showMedicationDrawer = true" wire:click="initActivityForm('medication_request')" class="text-gray-700 block w-full px-4 py-2 text-left text-sm hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600 rounded-md">
                            {{ __('care-plan.medications') }}
                        </button>
                        <button type="button" @click="openDropdown = false; showMedicalDeviceDrawer = true" wire:click="initActivityForm('device_request')" class="text-gray-700 block w-full px-4 py-2 text-left text-sm hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600 rounded-md">
                            {{ __('care-plan.medical_devices') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="activeTab === 'info'">
            @include('livewire.care-plan.parts.doctors', ['isReadOnly' => true])
            @include('livewire.care-plan.parts.patient_data', ['isReadOnly' => true])
            @include('livewire.care-plan.parts.care_plan_data', ['isReadOnly' => true])
            @include('livewire.care-plan.parts.condition_diagnosis', ['isReadOnly' => true])
            @include('livewire.care-plan.parts.supporting_information', ['isReadOnly' => true])
            @include('livewire.care-plan.parts.additional_info', ['isReadOnly' => true])

            <div class="mt-8 flex items-center gap-4 pt-4">
                <a href="{{ route('persons.care-plans', [legalEntity(), $carePlan->person_id]) }}" class="button-minor px-6 py-2.5" wire:navigate>
                    {{ __('care-plan.cancel') }}
                </a>

                @if(!$carePlan->uuid && in_array(strtoupper($status), ['NEW', 'DRAFT', 'PENDING']))
                    <button type="button"
                            class="button-primary px-8 py-2.5"
                            @click="$wire.openSignatureModal('sign_plan')">
                        {{ __('care-plan.sign_and_send') }}
                    </button>
                @elseif($carePlan->uuid && in_array(strtoupper($status), ['ACTIVE', 'NEW', 'active', 'new']))
                    <button type="button"
                            class="button-primary-outline-red px-6 py-2.5"
                            @click="$wire.openSignatureModal('cancel')">
                        {{ __('care-plan.cancel_care_plan_btn') }}
                    </button>

                    <button type="button"
                            class="button-primary px-8 py-2.5"
                            @click="$wire.openSignatureModal('complete')">
                        {{ __('care-plan.complete_care_plan') }}
                    </button>
                @endif
            </div>
        </div>

        <div x-show="activeTab === 'prescriptions'" style="display: none;">
            <fieldset class="fieldset mt-6">
                <legend class="legend">{{ __('care-plan.services') }}</legend>
                @if(empty($services))
                    <div class="bg-blue-50 dark:bg-blue-950/20 rounded-[8px] p-4 flex items-center gap-3 mb-4">
                        <div class="flex items-center justify-center w-5 h-5 rounded-full bg-[#2563eb] text-white shrink-0">
                            <svg class="w-3 h-3 stroke-[3px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <p class="text-sm font-semibold text-[#0052cc] dark:text-blue-400">{{ __('care-plan.no_prescriptions_yet') }}</p>
                    </div>
                @else
                    <div class="flow-root mt-4 mb-4 overflow-x-auto">
                        <table class="table-input w-full table-fixed min-w-[700px] text-sm">
                            <thead class="thead-input">
                                <tr>
                                    <th scope="col" class="th-input w-[40%]">{{ __('care-plan.prescriptions') }}</th>
                                    <th scope="col" class="th-input w-[15%]">{{ __('care-plan.quantity') }}</th>
                                    <th scope="col" class="th-input w-[20%]">{{ __('care-plan.start_date') }}</th>
                                    <th scope="col" class="th-input w-[15%]">{{ __('care-plan.status_label') }}</th>
                                    <th scope="col" class="th-input w-[10%]"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($services as $activity)
                                    <tr>
                                        <td class="td-input break-words whitespace-normal align-top">
                                            {{ $getActivityName($activity) }}
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
                                                        {{ __('care-plan.complete') }}
                                                    </button>
                                                    <button type="button"
                                                            class="text-red-500 hover:text-red-700 text-sm font-medium"
                                                            wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})">
                                                        {{ __('care-plan.cancel') }}
                                                    </button>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <button type="button"
                        @click="showServiceDrawer = true"
                        wire:click="initActivityForm('service_request')"
                        class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium text-sm flex items-center gap-1 transition-colors cursor-pointer mt-4"
                >
                    + {{ __('care-plan.add_services') }}
                </button>
            </fieldset>

            <fieldset class="fieldset mt-6">
                <legend class="legend">{{ __('care-plan.medications') }}</legend>
                @if(empty($medications))
                    <div class="bg-blue-50 dark:bg-blue-950/20 rounded-[8px] p-4 flex items-center gap-3 mb-4">
                        <div class="flex items-center justify-center w-5 h-5 rounded-full bg-[#2563eb] text-white shrink-0">
                            <svg class="w-3 h-3 stroke-[3px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <p class="text-sm font-semibold text-[#0052cc] dark:text-blue-400">{{ __('care-plan.no_prescriptions_yet') }}</p>
                    </div>
                @else
                    <div class="flow-root mt-4 mb-4 overflow-x-auto">
                        <table class="table-input w-full table-fixed min-w-[700px] text-sm">
                            <thead class="thead-input">
                                <tr>
                                    <th scope="col" class="th-input w-[40%]">{{ __('care-plan.prescriptions') }}</th>
                                    <th scope="col" class="th-input w-[15%]">{{ __('care-plan.quantity') }}</th>
                                    <th scope="col" class="th-input w-[20%]">{{ __('care-plan.start_date') }}</th>
                                    <th scope="col" class="th-input w-[15%]">{{ __('care-plan.status_label') }}</th>
                                    <th scope="col" class="th-input w-[10%]"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($medications as $activity)
                                    <tr>
                                        <td class="td-input break-words whitespace-normal align-top">
                                            {{ $getActivityName($activity) }}
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
                                                        {{ __('care-plan.complete') }}
                                                    </button>
                                                    <button type="button"
                                                            class="text-red-500 hover:text-red-700 text-sm font-medium"
                                                            wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})">
                                                        {{ __('care-plan.cancel') }}
                                                    </button>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <button type="button"
                        @click="showMedicationDrawer = true"
                        wire:click="initActivityForm('medication_request')"
                        class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium text-sm flex items-center gap-1 transition-colors cursor-pointer mt-4"
                >
                    + {{ __('care-plan.add_medications') }}
                </button>
            </fieldset>

            <fieldset class="fieldset mt-6">
                <legend class="legend">{{ __('care-plan.medical_devices') }}</legend>
                @if(empty($devices))
                    <div class="bg-blue-50 dark:bg-blue-950/20 rounded-[8px] p-4 flex items-center gap-3 mb-4">
                        <div class="flex items-center justify-center w-5 h-5 rounded-full bg-[#2563eb] text-white shrink-0">
                            <svg class="w-3 h-3 stroke-[3px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <p class="text-sm font-semibold text-[#0052cc] dark:text-blue-400">{{ __('care-plan.no_prescriptions_yet') }}</p>
                    </div>
                @else
                    <div class="flow-root mt-4 mb-4 overflow-x-auto">
                        <table class="table-input w-full table-fixed min-w-[700px] text-sm">
                            <thead class="thead-input">
                                <tr>
                                    <th scope="col" class="th-input w-[40%]">{{ __('care-plan.prescriptions') }}</th>
                                    <th scope="col" class="th-input w-[15%]">{{ __('care-plan.quantity') }}</th>
                                    <th scope="col" class="th-input w-[20%]">{{ __('care-plan.start_date') }}</th>
                                    <th scope="col" class="th-input w-[15%]">{{ __('care-plan.status_label') }}</th>
                                    <th scope="col" class="th-input w-[10%]"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($devices as $activity)
                                    <tr>
                                        <td class="td-input break-words whitespace-normal align-top">
                                            {{ $getActivityName($activity) }}
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
                                                        {{ __('care-plan.complete') }}
                                                    </button>
                                                    <button type="button"
                                                            class="text-red-500 hover:text-red-700 text-sm font-medium"
                                                            wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})">
                                                        {{ __('care-plan.cancel') }}
                                                    </button>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <button type="button"
                        @click="showMedicalDeviceDrawer = true"
                        wire:click="initActivityForm('device_request')"
                        class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium text-sm flex items-center gap-1 transition-colors cursor-pointer mt-4"
                >
                    + {{ __('care-plan.add_medical_devices') }}
                </button>
            </fieldset>
        </div>

        <div class="mt-8 flex items-center gap-4 pt-4">
            <a href="{{ route('persons.care-plans', [legalEntity(), $carePlan->person_id]) }}" class="button-minor px-6 py-2.5" wire:navigate>
                {{ __('care-plan.cancel') }}
            </a>

            @if(!$carePlan->uuid && in_array(strtoupper($status), ['NEW', 'DRAFT', 'PENDING']))
                <button type="button"
                        class="button-primary px-8 py-2.5"
                        @click="$wire.openSignatureModal('sign_plan')">
                    {{ __('care-plan.sign_and_send') }}
                </button>
            @elseif($carePlan->uuid && in_array(strtoupper($status), ['ACTIVE', 'NEW', 'active', 'new']))
                <button type="button"
                        class="button-primary-outline-red px-6 py-2.5"
                        @click="$wire.openSignatureModal('cancel')">
                    {{ __('care-plan.cancel_care_plan_btn') }}
                </button>

                <button type="button"
                        class="button-primary px-8 py-2.5"
                        @click="$wire.openSignatureModal('complete')">
                    {{ __('care-plan.complete_care_plan') }}
                </button>
            @endif
        </div>

    @include('livewire.care-plan.parts.modals.services-drawer')
    @include('livewire.care-plan.parts.modals.service-search-drawer')
    @include('livewire.care-plan.parts.modals.medications-drawer')
    @include('livewire.care-plan.parts.modals.medication-search-drawer')
    @include('livewire.care-plan.parts.modals.medication-form-drawer')
    @include('livewire.care-plan.parts.modals.medical-devices-drawer')
    @include('livewire.care-plan.parts.modals.medical-device-search-drawer')
    @include('livewire.care-plan.parts.modals.medical-device-form-drawer')
    @include('livewire.care-plan.parts.modals.medical-records-search-drawer')

    </div>

    @include('components.signature-modal', ['method' => 'sign'])
    <x-forms.loading />
</x-layouts.patient>
