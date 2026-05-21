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
        showServiceDrawer: @entangle('showServiceDrawer'),
        showServiceSearchDrawer: @entangle('showServiceSearchDrawer'),
        showMedicationDrawer: @entangle('showMedicationDrawer'),
        showMedicationSearchDrawer: @entangle('showMedicationSearchDrawer'),
        showMedicationFormDrawer: @entangle('showMedicationFormDrawer'),
        showMedicalDeviceDrawer: @entangle('showMedicalDeviceDrawer'),
        showMedicalDeviceSearchDrawer: @entangle('showMedicalDeviceSearchDrawer'),
        showMedicalDeviceFormDrawer: @entangle('showMedicalDeviceFormDrawer')
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
                            <div class="record-inner-value">{{ $carePlan->period_start?->format(config('app.date_format')) ?? '-' }}</div>
                        </div>
                        <div class="record-inner-column border-t border-gray-100 dark:border-gray-700 pt-3">
                            <div class="record-inner-label">{{ __('forms.end_date') }}</div>
                            <div class="record-inner-value">{{ $carePlan->period_end ? $carePlan->period_end->format(config('app.date_format')) : __('care-plan.no_end_date') }}</div>
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

                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Назва плану лікування</div>
                        <div class="text-gray-900 dark:text-white font-medium">{{ $carePlan->title }}</div>
                    </div>

                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Намір (Intent)</div>
                        <div class="text-gray-900 dark:text-white font-medium">{{ $intent ?: '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Умови надання послуг</div>
                        <div class="text-gray-900 dark:text-white font-medium">{{ $tos ?: '-' }}</div>
                    </div>

                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Дата початку плану лікування</div>
                        <div class="text-gray-900 dark:text-white font-medium flex items-center gap-2">
                            @icon('calendar', 'w-4 h-4 text-blue-400')
                            {{ $carePlan->period_start?->format('d.m.Y') ?? '-' }}
                            <span class="text-gray-400 ml-2 flex items-center gap-1">
                                @icon('clock', 'w-4 h-4')
                                09:00 AM
                            </span>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Дата завершення плану лікування</div>
                        <div class="text-gray-900 dark:text-white font-medium flex items-center gap-2">
                            @icon('calendar', 'w-4 h-4 text-blue-400')
                            {{ $carePlan->period_end ? $carePlan->period_end->format('d.m.Y') : 'Безтерміново' }}
                            @if($carePlan->period_end)
                                <span class="text-gray-400 ml-2 flex items-center gap-1">
                                    @icon('clock', 'w-4 h-4')
                                    06:00 PM
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Condition/Diagnosis --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-6 flex items-center gap-2">
                    @icon('alert-circle', 'w-5 h-5 text-blue-500')
                    Стан/діагноз
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">Дата</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">Назва</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
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
                                    <td class="px-4 py-4 text-gray-600 dark:text-gray-400">{{ $condition?->onset_date?->format('d.m.Y') ?? '-' }}</td>
                                    <td class="px-4 py-4 text-gray-900 dark:text-white font-medium">{{ $condition ? ($condition->typeConcept?->text ?? $condition->typeConcept?->coding->first()?->display ?? '-') : '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="px-4 py-8 text-center text-gray-400 italic">Немає пов'язаних станів</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Supporting Info --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-6 flex items-center gap-2">
                    @icon('file-text', 'w-5 h-5 text-blue-500')
                    Допоміжна інформація (епізоди, процедури чи діагностичні звіти)
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">Дата</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">Назва / ОПИС</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase tracking-wider w-20">Дії</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
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
                                    <td class="px-4 py-4 text-gray-600 dark:text-gray-400">{{ \Carbon\CarbonImmutable::now()->format('d.m.Y') }}</td>
                                    <td class="px-4 py-4 text-gray-900 dark:text-white font-medium">{{ $ref }} ({{ $type }})</td>
                                    <td class="px-4 py-4 text-right">
                                        <button type="button" class="text-gray-400 hover:text-red-500 transition-colors">
                                            @icon('trash', 'w-5 h-5')
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400 italic">Немає допоміжної інформації</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Additional Info --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-6 flex items-center gap-2">
                    @icon('settings', 'w-5 h-5 text-blue-500')
                    Додаткова інформація
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Клінічний протокол</div>
                        <div class="text-gray-900 dark:text-white font-medium">{{ $carePlan->clinical_protocol ?: '-' }}</div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Розширений опис</div>
                        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 text-gray-700 dark:text-gray-300 text-sm border border-gray-100 dark:border-gray-600 min-h-[100px] whitespace-pre-line">
                            {{ $carePlan->description ?: 'Опис відсутній' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Нотатки</div>
                        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 text-gray-700 dark:text-gray-300 text-sm border border-gray-100 dark:border-gray-600 min-h-[100px] whitespace-pre-line">
                            {{ $carePlan->note ?: 'Нотатки відсутні' }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Approvals --}}
            <div class="mt-4">
                @livewire('care-plan.care-plan-approvals', ['carePlan' => $carePlan])
            </div>

            {{-- Bottom Actions --}}
            <div class="mt-12 flex items-center justify-between pt-8 pb-12 border-t border-gray-100 dark:border-gray-700">
                <a href="{{ route('persons.care-plans', [legalEntity(), $carePlan->person_id]) }}" class="button-minor flex items-center gap-2" wire:navigate>
                    @icon('arrow-left', 'w-4 h-4')
                    <span>{{ __('forms.back') }}</span>
                </a>

                <div class="flex items-center gap-4">
                    @if(!$carePlan->uuid && in_array(strtoupper($status), [Status::NEW->value, 'DRAFT', 'PENDING']))
                        <button type="button" class="button-primary-outline" @click="$wire.openSignatureModal('sign_plan')">
                            Підписати та відправити План
                        </button>
                    @elseif($carePlan->uuid && strtoupper($status) === 'NEW')
                        <button type="button" class="button-primary" wire:click="openMethodSelectionModal">
                            Активувати план (Дозвіл пацієнта)
                        </button>
                    @elseif($carePlan->uuid && in_array(strtoupper($status), [Status::ACTIVE->value]))
                        <button type="button" class="button-minor text-red-500 border-red-200 hover:bg-red-50" @click="$wire.openSignatureModal('cancel')">
                            Скасувати
                        </button>
                        <button type="button" class="button-danger-outline" @click="$wire.openSignatureModal('revoke')">
                            Відмінити план лікування
                        </button>
                        <button type="button" class="button-primary" @click="$wire.openSignatureModal('complete')">
                            Завершити план лікування
                        </button>

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
                                    @if($activity->kindConcept)
                                        {{ $activity->kindConcept->text ?? $activity->kindConcept->coding->first()?->display ?? $activity->kindConcept->coding->first()?->code ?? '-' }}
                                    @elseif(is_array($activity->kind))
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
                                    {{ $activity->scheduled_period_start?->format(config('app.date_format')) }}
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
                <div class="flex flex-col gap-4 flex-1">
                    @if(in_array($actionType, ['complete_activity', 'cancel_activity']))
                        @if($actionType === 'complete_activity')
                            <x-forms.select
                                id="outcomeCode"
                                name="outcomeCode"
                                label="{{ __('care-plan.outcome_dictionary') }}"
                                wire:model="outcomeCode"
                                :options="$dictionaries['care_plan_activity_outcomes'] ?? []"
                            />

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
                        @endif

                        <x-forms.textarea
                            id="activity_status_reason"
                            name="statusReason"
                            label="{{ __('care-plan.status_reason') }}"
                            wire:model="statusReason"
                        />
                    @else
                        <x-forms.textarea
                            id="statusReason"
                            name="statusReason"
                            label="{{ __('care-plan.status_reason') }}"
                            wire:model="statusReason"
                        />
                    @endif
                </div>

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

        @livewire('care-plan.care-plan-approvals', ['carePlan' => $carePlan])

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
