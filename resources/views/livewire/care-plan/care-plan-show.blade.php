@use('App\Livewire\CarePlan\CarePlanShow')
@use('App\Enums\CarePlanStatus')
@use('App\Enums\Status')

<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            План лікування №{{ $carePlan->requisition ?? $carePlan->id }}
        </x-slot>
        <x-slot name="actions">
            <button type="button" wire:click="sync" class="button-success flex items-center gap-2">
                @icon('refresh', 'w-4 h-4')
                <span>Синхронізувати дані з ЕСОЗ</span>
            </button>
        </x-slot>
    </x-header-navigation>

    <div x-data="{ 
        activeTab: 'info',
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
    class="form shift-content" wire:key="care-plan-show-container">

        {{-- Plan Header --}}
        @php
            $status = is_array($carePlan->status) ? ($carePlan->status['coding'][0]['code'] ?? ($carePlan->status['text'] ?? '')) : $carePlan->status;
            $statusDisplay = is_array($carePlan->status) ? ($carePlan->status['text'] ?? ($carePlan->status['coding'][0]['display'] ?? $status)) : $status;
            
            $categoryLabel = $carePlan->categoryConcept?->text ?? $carePlan->categoryConcept?->coding?->first()?->display;
            if (!$categoryLabel) {
                $categoryCode = is_array($carePlan->category) ? ($carePlan->category['coding'][0]['code'] ?? ($carePlan->category['text'] ?? '')) : $carePlan->category;
                $categoryLabel = $dictionaries['care_plan_categories'][$categoryCode] ?? $categoryCode;
            }

            $intent = 'order'; // In eHealth plans always have intent 'order'
            $tos = is_array($carePlan->terms_of_service) ? ($carePlan->terms_of_service['coding'][0]['code'] ?? ($carePlan->terms_of_service['text'] ?? '')) : $carePlan->terms_of_service;
        @endphp

        <!-- Tabs Navigation -->
        <div class="border-b border-gray-200 dark:border-gray-700 mb-8 flex justify-between items-center px-4">
            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                <button @click="activeTab = 'info'"
                        :class="activeTab === 'info' ? 'border-blue-500 text-blue-600 dark:text-blue-500 font-bold' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 font-medium'"
                        class="whitespace-nowrap pb-4 px-1 border-b-2 text-sm transition-all">
                    Інформація про план
                </button>
                <button @click="activeTab = 'activities'"
                        :class="activeTab === 'activities' ? 'border-blue-500 text-blue-600 dark:text-blue-500 font-bold' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 font-medium'"
                        class="whitespace-nowrap pb-4 px-1 border-b-2 text-sm transition-all">
                    Призначення
                </button>
            </nav>

            @if(in_array(strtolower($status), [CarePlanStatus::ACTIVE->value, CarePlanStatus::DRAFT->value, 'new', 'pending']))
            <div class="relative pb-2 pr-2">
                <button type="button" 
                        @click="openDropdown = !openDropdown" 
                        @click.away="openDropdown = false"
                        class="button-primary flex items-center gap-2">
                    <span>+ {{ __('care-plan.new_prescription') }}</span>
                    @icon('chevron-down', 'w-4 h-4')
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
        <div x-show="activeTab === 'info'" class="space-y-8 px-4">
            
            {{-- Doctors --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-6 flex items-center gap-2">
                    @icon('doctor', 'w-5 h-5 text-blue-500')
                    {{ __('care-plan.doctors') }}
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Автор</div>
                        <div class="text-gray-900 dark:text-white font-medium">{{ $carePlan->author?->party?->full_name ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Керуючий лікар</div>
                        <div class="text-gray-900 dark:text-white font-medium">{{ $carePlan->author?->party?->full_name ?? '-' }}</div>
                    </div>
                </div>
            </div>

            {{-- Patient Data --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-6 flex items-center gap-2">
                    @icon('patients', 'w-5 h-5 text-blue-500')
                    {{ __('care-plan.patient_data') }}
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Пацієнт</div>
                        <div class="text-gray-900 dark:text-white font-medium">{{ $carePlan->person?->full_name ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Медичний запис №</div>
                        <div class="text-gray-900 dark:text-white font-medium">{{ $carePlan->medical_number ?? '-' }}</div>
                    </div>
                </div>
            </div>

            {{-- Care Plan Data --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-6 flex items-center gap-2">
                    @icon('contracts', 'w-5 h-5 text-blue-500')
                    {{ __('care-plan.care_plan_data') }}
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-8">
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">eHealth ID</div>
                        <div class="text-gray-900 dark:text-white font-medium break-all">{{ $carePlan->uuid ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Статус в ЕСОЗ</div>
                        <div class="mt-1">
                            <span class="badge {{ strtoupper($status) === 'ACTIVE' ? 'badge-green' : (strtoupper($status) === 'NEW' ? 'badge-yellow' : 'badge-dark') }}">
                                {{ $statusDisplay }}
                            </span>
                        </div>
                    </div>

                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Категорія</div>
                        <div class="text-gray-900 dark:text-white font-medium">{{ $categoryLabel ?: '-' }}</div>
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
                        <button type="button" class="button-danger-outline" @click="$wire.openSignatureModal('complete')">
                            Відмінити план лікування
                        </button>
                        <button type="button" class="button-primary" @click="$wire.openSignatureModal('complete')">
                            Завершити план лікування
                        </button>
                    @endif
                </div>
            </div>
        </div>

        <!-- Activities Tab Content -->
        <div x-show="activeTab === 'activities'" style="display: none;" class="space-y-6 px-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-6 flex items-center gap-2">
                    @icon('list', 'w-5 h-5 text-blue-500')
                    {{ __('care-plan.activities') }}
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wider w-[35%]">{{ __('care-plan.kind') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wider w-[15%]">{{ __('care-plan.quantity') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wider w-[20%]">{{ __('forms.start_date') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase tracking-wider w-[15%]">{{ __('forms.status.label') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase tracking-wider w-[15%]">Дії</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($carePlan->activities ?? [] as $activity)
                                <tr>
                                    <td class="px-4 py-4 text-gray-900 dark:text-white font-medium">
                                        @if($activity->kindConcept)
                                            {{ $activity->kindConcept->text ?? $activity->kindConcept->coding->first()?->display ?? $activity->kindConcept->coding->first()?->code ?? '-' }}
                                        @elseif(is_array($activity->kind))
                                            {{ $activity->kind['text'] ?? $activity->kind['coding'][0]['display'] ?? $activity->kind['coding'][0]['code'] ?? '-' }}
                                        @else
                                            {{ $activity->kind ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-gray-600 dark:text-gray-400">
                                        @if(is_array($activity->quantity))
                                            {{ $activity->quantity['value'] ?? '-' }} {{ $activity->quantity['unit'] ?? '' }}
                                        @else
                                            {{ $activity->quantity ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-gray-600 dark:text-gray-400">
                                        {{ $activity->scheduled_period_start?->format('d.m.Y') }}
                                    </td>
                                    <td class="px-4 py-4">
                                        @php
                                            $activityStatus = is_array($activity->status) ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? '')) : $activity->status;
                                            $activityStatusDisplay = is_array($activity->status) ? ($activity->status['text'] ?? ($activity->status['coding'][0]['display'] ?? $activityStatus)) : $activityStatus;
                                        @endphp
                                        <span class="badge {{ in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']) ? 'badge-yellow' : 'badge-green' }}">
                                            {{ $activityStatusDisplay }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            @if(in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']))
                                                <button type="button" class="text-blue-500 hover:text-blue-700 transition-colors" wire:click="editActivity({{ $activity->id }})">
                                                    @icon('edit', 'w-5 h-5')
                                                </button>
                                                <button type="button" class="text-green-500 hover:text-green-700 transition-colors" wire:click="openSignatureModal('sign_activity', {{ $activity->id }})">
                                                    @icon('user-check', 'w-5 h-5')
                                                </button>
                                            @elseif(in_array(strtoupper($activityStatus), ['ACTIVE', 'SCHEDULED', 'IN-PROGRESS', 'IN_PROGRESS', 'ON-HOLD']))
                                                <button type="button" class="text-red-500 hover:text-red-700 transition-colors" wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})">
                                                    @icon('x-circle', 'w-5 h-5')
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-12 text-center text-gray-400 italic">
                                        {{ __('care-plan.no_activities') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @include('components.signature-modal', ['method' => 'sign'])
        @if($showAuthModal)
            @include('livewire.care-plan.modals.authentication')
        @endif
        @if($showMethodSelectionModal)
            @include('livewire.care-plan.modals.method-selection')
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
