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
        <div class="flex items-center gap-3 mb-4">
            <h2 class="title">{{ $carePlan->title }}</h2>
            <span class="badge {{ in_array($carePlan->status, ['ACTIVE', 'active']) ? 'badge-success' : 'badge-secondary' }}">
                {{ $carePlan->status }}
            </span>
        </div>

        {{-- Core Details --}}
        <fieldset class="fieldset">
            <legend class="legend">{{ __('care-plan.care_plan_data') }}</legend>

            <div class="form-row-2">
                <div>
                    <p class="label">{{ __('care-plan.category') }}</p>
                    <p class="value">{{ $carePlan->category ?? '-' }}</p>
                </div>
                <div>
                    <p class="label">{{ __('care-plan.name_care_plan') }}</p>
                    <p class="value">{{ $carePlan->title }}</p>
                </div>
            </div>

            <div class="form-row-2 mt-3">
                <div>
                    <p class="label">{{ __('forms.start_date') }}</p>
                    <p class="value">{{ $carePlan->period_start?->format('d.m.Y') ?? '-' }}</p>
                </div>
                <div>
                    <p class="label">{{ __('forms.end_date') }}</p>
                    <p class="value">{{ $carePlan->period_end ? $carePlan->period_end->format('d.m.Y') : __('care-plan.no_end_date') }}</p>
                </div>
            </div>

            <div class="form-row-2 mt-3">
                <div>
                    <p class="label">{{ __('care-plan.patient') }}</p>
                    <p class="value">{{ $carePlan->person?->last_name }} {{ $carePlan->person?->first_name }}</p>
                </div>
                <div>
                    <p class="label">{{ __('care-plan.author') }}</p>
                    <p class="value">{{ $carePlan->author?->party?->last_name }} {{ $carePlan->author?->party?->first_name }}</p>
                </div>
            </div>

            @if($carePlan->description)
            <div class="mt-3">
                <p class="label">{{ __('care-plan.description') }}</p>
                <p class="value">{{ $carePlan->description }}</p>
            </div>
            @endif
        </fieldset>

        {{-- Activities Table --}}
        <fieldset class="fieldset mt-6">
            <div class="flex items-center justify-between mb-4">
                <legend class="legend mb-0">{{ __('care-plan.activities') }}</legend>
                
                @if(in_array($carePlan->status, ['ACTIVE', 'active']))
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

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('care-plan.kind') }}</th>
                            <th>{{ __('care-plan.quantity') }}</th>
                            <th>{{ __('forms.start_date') }}</th>
                            <th>{{ __('forms.status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($carePlan->activities ?? [] as $activity)
                            <tr>
                                <td>{{ $activity->kind }}</td>
                                <td>{{ $activity->quantity ?? '-' }}</td>
                                <td>{{ $activity->scheduled_period_start?->format('d.m.Y') }}</td>
                                <td>
                                    <span class="badge {{ $activity->status === 'NEW' ? 'badge-warning' : 'badge-success' }}">
                                        {{ $activity->status }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    @if($activity->status === 'NEW')
                                        <button type="button"
                                                class="text-blue-600 hover:text-blue-800 text-sm font-medium"
                                                wire:click="openSignatureModal('sign_activity', {{ $activity->id }})">
                                            {{ __('forms.sign') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-4 text-gray-400">
                                    {{ __('care-plan.no_activities') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </fieldset>

        {{-- Action Buttons for ACTIVE/NEW plans synced with eHealth --}}
        @if(in_array($carePlan->status, ['ACTIVE', 'NEW', 'active', 'new']) && $carePlan->uuid)
            <div class="mt-6 flex flex-row items-center gap-4 pt-6">

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
            </div>

            @include('components.signature-modal', ['method' => 'sign'])
        @endif

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
