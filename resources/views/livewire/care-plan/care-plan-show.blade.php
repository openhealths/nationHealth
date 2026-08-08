@use('App\Livewire\CarePlan\CarePlanShow')
@use('App\Enums\CarePlanStatus')
@use('App\Enums\Status')

<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">План лікування №{{ $carePlan->requisition ?? $carePlan->id }}</x-slot>
        <x-slot name="actions">
            <button type="button" wire:click="sync" class="button-success flex items-center gap-2">
                @icon('refresh', 'w-4 h-4')
                <span>Синхронізувати дані з ЕСОЗ</span>
            </button>
        </x-slot>
    </x-header-navigation>

    <div
        x-data="{ 
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
        @close-drawers.window="
            showServiceDrawer = false;
            showServiceSearchDrawer = false;
            showMedicationDrawer = false;
            showMedicationSearchDrawer = false;
            showMedicationFormDrawer = false;
            showMedicalDeviceDrawer = false;
            showMedicalDeviceSearchDrawer = false;
            showMedicalDeviceFormDrawer = false;
        "
        class="form shift-content"
        wire:key="care-plan-show-container"
    >
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
        <div class="mb-8 flex items-center justify-between border-b border-gray-200 px-4 dark:border-gray-700">
            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                <button
                    @click="activeTab = 'info'"
                    :class="activeTab === 'info'
                        ? 'border-blue-500 text-blue-600 dark:text-blue-500 font-bold'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 font-medium'"
                    class="border-b-2 px-1 pb-4 text-sm whitespace-nowrap transition-all"
                >
                    Інформація про план
                </button>
                <button
                    @click="activeTab = 'activities'"
                    :class="activeTab === 'activities'
                        ? 'border-blue-500 text-blue-600 dark:text-blue-500 font-bold'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 font-medium'"
                    class="border-b-2 px-1 pb-4 text-sm whitespace-nowrap transition-all"
                >
                    Призначення
                </button>
            </nav>

            @if (in_array(strtolower($status), [CarePlanStatus::ACTIVE->value, CarePlanStatus::DRAFT->value, 'new', 'pending']))
                <div class="relative pr-2 pb-2">
                    <button
                        type="button"
                        @click="openDropdown = ! openDropdown"
                        @click.away="openDropdown = false"
                        class="button-primary flex items-center gap-2"
                    >
                        <span>+ {{ __('care-plan.new_prescription') }}</span>
                        @icon('chevron-down', 'w-4 h-4')
                    </button>

                    <div
                        x-show="openDropdown"
                        x-transition
                        style="display: none"
                        class="ring-opacity-5 absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black focus:outline-none dark:bg-gray-700"
                    >
                        <div class="py-1" role="none">
                            <button
                                type="button"
                                @click="
                                    openDropdown = false;
                                    showServiceDrawer = true;
                                "
                                wire:click="initActivityForm('service_request')"
                                class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                            >
                                {{ __('care-plan.service_prescription') }}
                            </button>
                            <button
                                type="button"
                                @click="
                                    openDropdown = false;
                                    showMedicationDrawer = true;
                                "
                                wire:click="initActivityForm('medication_request')"
                                class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                            >
                                {{ __('care-plan.medication_prescription') }}
                            </button>
                            <button
                                type="button"
                                @click="
                                    openDropdown = false;
                                    showMedicalDeviceDrawer = true;
                                "
                                wire:click="initActivityForm('device_request')"
                                class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                            >
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
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-6 flex items-center gap-2 text-lg font-bold text-gray-800 dark:text-gray-200">
                    @icon('doctor', 'w-5 h-5 text-blue-500')
                    {{ __('care-plan.doctors') }}
                </h3>
                <div class="grid grid-cols-1 gap-8 md:grid-cols-2">
                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">Автор</div>
                        <div class="font-medium text-gray-900 dark:text-white">
                            {{ $carePlan->author?->party?->full_name ?? '-' }}
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                            Керуючий лікар
                        </div>
                        <div class="font-medium text-gray-900 dark:text-white">
                            {{ $carePlan->author?->party?->full_name ?? '-' }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Patient Data --}}
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-6 flex items-center gap-2 text-lg font-bold text-gray-800 dark:text-gray-200">
                    @icon('patients', 'w-5 h-5 text-blue-500')
                    {{ __('care-plan.patient_data') }}
                </h3>
                <div class="grid grid-cols-1 gap-8 md:grid-cols-2">
                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">Пацієнт</div>
                        <div class="font-medium text-gray-900 dark:text-white">
                            {{ $carePlan->person?->full_name ?? '-' }}
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                            Медичний запис №
                        </div>
                        <div class="font-medium text-gray-900 dark:text-white">
                            {{ $carePlan->medical_number ?? '-' }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Care Plan Data --}}
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-6 flex items-center gap-2 text-lg font-bold text-gray-800 dark:text-gray-200">
                    @icon('contracts', 'w-5 h-5 text-blue-500')
                    {{ __('care-plan.care_plan_data') }}
                </h3>
                <div class="grid grid-cols-1 gap-x-8 gap-y-8 md:grid-cols-2">
                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">eHealth ID</div>
                        <div class="font-medium break-all text-gray-900 dark:text-white">
                            {{ $carePlan->uuid ?? '-' }}
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                            Статус в ЕСОЗ
                        </div>
                        <div class="mt-1">
                            <span class="badge {{ strtoupper($status) === 'ACTIVE' ? 'badge-green' : (strtoupper($status) === 'NEW' ? 'badge-yellow' : 'badge-dark') }}">
                                {{ $statusDisplay }}
                            </span>
                        </div>
                    </div>

                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">Категорія</div>
                        <div class="font-medium text-gray-900 dark:text-white">{{ $categoryLabel ?: '-' }}</div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                            Назва плану лікування
                        </div>
                        <div class="font-medium text-gray-900 dark:text-white">{{ $carePlan->title }}</div>
                    </div>

                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                            Намір (Intent)
                        </div>
                        <div class="font-medium text-gray-900 dark:text-white">{{ $intent ?: '-' }}</div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                            {{ __('forms.providing_condition') }}
                        </div>
                        <div class="font-medium text-gray-900 dark:text-white">{{ $tos ?: '-' }}</div>
                    </div>

                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                            Дата початку плану лікування
                        </div>
                        <div class="flex items-center gap-2 font-medium text-gray-900 dark:text-white">
                            @icon('calendar', 'w-4 h-4 text-blue-400')
                            {{ $carePlan->period_start?->format('d.m.Y') ?? '-' }}
                            <span class="ml-2 flex items-center gap-1 text-gray-400">
                                @icon('clock', 'w-4 h-4')
                                09:00 AM
                            </span>
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                            Дата завершення плану лікування
                        </div>
                        <div class="flex items-center gap-2 font-medium text-gray-900 dark:text-white">
                            @icon('calendar', 'w-4 h-4 text-blue-400')
                            {{ $carePlan->period_end ? $carePlan->period_end->format('d.m.Y') : 'Безтерміново' }}
                            @if ($carePlan->period_end)
                                <span class="ml-2 flex items-center gap-1 text-gray-400">
                                    @icon('clock', 'w-4 h-4')
                                    06:00 PM
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Condition/Diagnosis --}}
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-6 flex items-center gap-2 text-lg font-bold text-gray-800 dark:text-gray-200">
                    @icon('alert-circle', 'w-5 h-5 text-blue-500')
                    Стан/діагноз
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold tracking-wider text-gray-400 uppercase">
                                    Дата
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-bold tracking-wider text-gray-400 uppercase">
                                    Назва
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($carePlan->addresses ?? [] as $address)
                                @php
                                    $condId = is_array($address['reference'] ?? null) ? ($address['reference']['identifier']['value'] ?? null) : ($address['reference'] ?? null);
                                    if (str_contains($condId ?? '', '/')) {
                                        $condId = last(explode('/', $condId));
                                    }
                                    $condition = null;
                                    if ($condId) {
                                        $condition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $condId)->first();
                                    }
                                @endphp
                                <tr>
                                    <td class="px-4 py-4 text-gray-600 dark:text-gray-400">
                                        {{ $condition?->onset_date?->format('d.m.Y') ?? '-' }}
                                    </td>
                                    <td class="px-4 py-4 font-medium text-gray-900 dark:text-white">
                                        {{ $condition ? ($condition->typeConcept?->text ?? $condition->typeConcept?->coding->first()?->display ?? '-') : '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="px-4 py-8 text-center text-gray-400 italic">
                                        Немає пов'язаних станів
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Supporting Info --}}
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-6 flex items-center gap-2 text-lg font-bold text-gray-800 dark:text-gray-200">
                    @icon('file-text', 'w-5 h-5 text-blue-500')
                    Допоміжна інформація (епізоди, процедури чи діагностичні звіти)
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold tracking-wider text-gray-400 uppercase">
                                    Дата
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-bold tracking-wider text-gray-400 uppercase">
                                    Назва / ОПИС
                                </th>
                                <th class="w-20 px-4 py-3 text-right text-xs font-bold tracking-wider text-gray-400 uppercase">
                                    Дії
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @php
                                $episodes = $carePlan->supporting_info['episodes'] ?? [];
                                $medical_records = $carePlan->supporting_info['medical_records'] ?? [];
                                $allSupporting = array_merge($episodes, $medical_records);
                            @endphp
                            @forelse ($allSupporting as $item)
                                @php
                                    $ref = $item['reference'] ?? '';
                                    if (str_contains($ref, '/')) {
                                        $ref = last(explode('/', $ref));
                                    }
                                    $type = $item['type'] ?? '';
                                @endphp
                                <tr>
                                    <td class="px-4 py-4 text-gray-600 dark:text-gray-400">
                                        {{ \Carbon\CarbonImmutable::now()->format('d.m.Y') }}
                                    </td>
                                    <td class="px-4 py-4 font-medium text-gray-900 dark:text-white">
                                        {{ $ref }} ({{ $type }})
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <button
                                            type="button"
                                            class="text-gray-400 transition-colors hover:text-red-500"
                                        >
                                            @icon('trash', 'w-5 h-5')
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 py-8 text-center text-gray-400 italic">
                                        Немає допоміжної інформації
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Additional Info --}}
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-6 flex items-center gap-2 text-lg font-bold text-gray-800 dark:text-gray-200">
                    @icon('settings', 'w-5 h-5 text-blue-500')
                    Додаткова інформація
                </h3>

                <div class="space-y-6">
                    <div>
                        <div class="mb-2 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                            Розширений опис
                        </div>
                        <div class="min-h-[100px] rounded-lg border border-gray-100 bg-gray-50 p-4 text-sm whitespace-pre-line text-gray-700 dark:border-gray-600 dark:bg-gray-700/50 dark:text-gray-300">
                            {{ $carePlan->description ?: 'Опис відсутній' }}
                        </div>
                    </div>
                    <div>
                        <div class="mb-2 text-xs font-semibold tracking-wider text-gray-400 uppercase">Нотатки</div>
                        <div class="min-h-[100px] rounded-lg border border-gray-100 bg-gray-50 p-4 text-sm whitespace-pre-line text-gray-700 dark:border-gray-600 dark:bg-gray-700/50 dark:text-gray-300">
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
            <div class="mt-12 flex items-center justify-between border-t border-gray-100 pt-8 pb-12 dark:border-gray-700">
                <a
                    href="{{ route('persons.care-plans', [legalEntity(), $carePlan->person_id]) }}"
                    class="button-minor flex items-center gap-2"
                    wire:navigate
                >
                    @icon('arrow-left', 'w-4 h-4')
                    <span>{{ __('forms.back') }}</span>
                </a>

                <div class="flex items-center gap-4">
                    @if (!$carePlan->uuid && in_array(strtoupper($status), [Status::NEW->value, 'DRAFT', 'PENDING']))
                        <button
                            type="button"
                            class="button-primary-outline"
                            @click="$wire.openSignatureModal('sign_plan')"
                        >
                            Підписати та відправити План
                        </button>
                    @elseif ($carePlan->uuid && strtoupper($status) === 'NEW')
                        <button type="button" class="button-primary" wire:click="openMethodSelectionModal">
                            Активувати план (Дозвіл пацієнта)
                        </button>
                    @elseif ($carePlan->uuid && in_array(strtoupper($status), [Status::ACTIVE->value]))
                        <button type="button" class="button-danger-outline" @click="$wire.openSignatureModal('cancel')">
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
        <div x-show="activeTab === 'activities'" style="display: none" class="space-y-6 px-4">
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-6 flex items-center gap-2 text-lg font-bold text-gray-800 dark:text-gray-200">
                    @icon('list', 'w-5 h-5 text-blue-500')
                    {{ __('care-plan.activities') }}
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="w-[35%] px-4 py-3 text-left text-xs font-bold tracking-wider text-gray-400 uppercase">
                                    {{ __('care-plan.kind') }}
                                </th>
                                <th class="w-[15%] px-4 py-3 text-left text-xs font-bold tracking-wider text-gray-400 uppercase">
                                    {{ __('care-plan.quantity') }}
                                </th>
                                <th class="w-[20%] px-4 py-3 text-left text-xs font-bold tracking-wider text-gray-400 uppercase">
                                    {{ __('forms.start_date') }}
                                </th>
                                <th class="w-[15%] px-4 py-3 text-left text-xs font-bold tracking-wider text-gray-400 uppercase">
                                    {{ __('forms.status.label') }}
                                </th>
                                <th class="w-[15%] px-4 py-3 text-right text-xs font-bold tracking-wider text-gray-400 uppercase">
                                    Дії
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($carePlan->activities ?? [] as $activity)
                                <tr>
                                    <td class="px-4 py-4">
                                        @php
                                            $resolvedKind = $activity->resolvedKind();
                                            $kindTranslationKey = 'care-plan.activity_kind.' . $resolvedKind;
                                            $translatedKind = \Illuminate\Support\Facades\Lang::has($kindTranslationKey) ? __($kindTranslationKey) : $resolvedKind;
                                        @endphp
                                        <div class="font-medium text-gray-900 dark:text-white">
                                            {{ $translatedKind ?: '-' }}
                                        </div>
                                        <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                            @if ($activity->uuid)
                                                ID:
                                                <span class="font-mono">{{ $activity->uuid }}</span>
                                            @else
                                                ID:
                                                <span class="font-mono">{{ $activity->id }} (Чернетка)</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-gray-600 dark:text-gray-400">
                                        @if (is_array($activity->quantity))
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
                                            $statusVal = $activity->status instanceof \UnitEnum ? $activity->status->value : $activity->status;
                                            $activityStatus = is_array($statusVal) ? ($statusVal['coding'][0]['code'] ?? ($statusVal['text'] ?? '')) : $statusVal;
                                            $statusKey = 'care-plan.status.' . strtolower($activityStatus);
                                            $activityStatusDisplay = \Illuminate\Support\Facades\Lang::has($statusKey)
                                                ? __($statusKey)
                                                : (is_array($activity->status) ? ($activity->status['text'] ?? ($activity->status['coding'][0]['display'] ?? $activityStatus)) : $activityStatus);
                                        @endphp
                                        <span class="badge {{ in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']) ? 'badge-yellow' : 'badge-green' }}">
                                            {{ $activityStatusDisplay }}
                                        </span>
                                    </td>
                                    <td
                                        x-data="{ openDropdown: false }"
                                        class="relative overflow-visible px-4 py-4 text-right"
                                    >
                                        <div class="flex items-center justify-end gap-2">
                                            <a
                                                href="{{ route('care-plans.activities.show', [legalEntity(), $carePlan->id, $activity->id]) }}"
                                                class="text-sm whitespace-nowrap text-blue-600 hover:underline dark:text-blue-400"
                                                wire:navigate
                                            >
                                                Переглянути
                                            </a>
                                            <button
                                                @click.stop="openDropdown = ! openDropdown"
                                                type="button"
                                                class="cursor-pointer rounded p-1 transition-colors hover:bg-gray-100 dark:hover:bg-gray-700"
                                            >
                                                @icon('dots-vertical', 'w-5 h-5 text-gray-800 dark:text-white')
                                            </button>
                                        </div>

                                        <div
                                            x-show="openDropdown"
                                            @click.outside="openDropdown = false"
                                            x-transition
                                            class="absolute right-4 z-10 mt-1 w-52 divide-y divide-gray-100 rounded-md border border-gray-100 bg-white shadow-lg dark:divide-gray-600 dark:border-gray-600 dark:bg-gray-700"
                                            style="display: none"
                                        >
                                            @if (in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']))
                                                <div class="py-1">
                                                    <button
                                                        type="button"
                                                        @click="openDropdown = false"
                                                        wire:click="editActivity({{ $activity->id }})"
                                                        class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                                                    >
                                                        Редагувати
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="openDropdown = false"
                                                        wire:click="openSignatureModal('sign_activity', {{ $activity->id }})"
                                                        class="block w-full px-4 py-2 text-left text-sm text-green-600 hover:bg-gray-100 dark:text-green-400 dark:hover:bg-gray-600"
                                                    >
                                                        Підписати призначення
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="openDropdown = false"
                                                        wire:click="confirmDeleteActivity({{ $activity->id }})"
                                                        class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-gray-100 dark:text-red-400 dark:hover:bg-gray-600"
                                                    >
                                                        {{ __('forms.delete') }}
                                                    </button>
                                                </div>
                                            @elseif (in_array(strtoupper($activityStatus), ['ACTIVE', 'SCHEDULED', 'IN-PROGRESS', 'IN_PROGRESS', 'ON-HOLD', 'PROCESSED']))
                                                <div class="py-1">
                                                    <a
                                                        href="{{ route('care-plans.activities.show', [legalEntity(), $carePlan->id, $activity->id]) }}"
                                                        @click="openDropdown = false"
                                                        class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        wire:navigate
                                                    >
                                                        Деталі та виписки
                                                    </a>
                                                    <button
                                                        type="button"
                                                        @click="openDropdown = false"
                                                        wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})"
                                                        class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-gray-100 dark:text-red-400 dark:hover:bg-gray-600"
                                                    >
                                                        Скасувати призначення
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="openDropdown = false"
                                                        wire:click="openSignatureModal('complete_activity', {{ $activity->id }})"
                                                        class="block w-full px-4 py-2 text-left text-sm text-blue-600 hover:bg-gray-100 dark:text-blue-400 dark:hover:bg-gray-600"
                                                    >
                                                        Завершити призначення
                                                    </button>
                                                </div>
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

        @if ($actionType === 'cancel')
            @include('livewire.care-plan.parts.modals.cancel-care-plan-modal', ['method' => 'sign'])
        @elseif ($actionType === 'complete')
            @include('livewire.care-plan.parts.modals.complete-care-plan-modal', ['method' => 'sign'])
        @elseif ($actionType === 'cancel_activity')
            @include('livewire.care-plan.parts.modals.cancel-activity-modal', ['method' => 'sign'])
        @elseif ($actionType === 'complete_activity')
            @include('livewire.care-plan.parts.modals.complete-activity-modal', ['method' => 'sign'])
        @else
            @include('components.signature-modal', ['method' => 'sign'])
        @endif
        @if ($isPolling)
            <div wire:poll.3s.keep-alive="checkApprovalJobStatus" class="hidden"></div>
        @endif
        @if ($showAuthModal)
            @include('livewire.care-plan.modals.authentication')
        @endif
        @if ($showMethodSelectionModal)
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

        <x-confirmation-modal wire:model.live="confirmingActivityDeletion">
            <x-slot name="title">{{ __('care-plan.confirm_delete_activity_title') }}</x-slot>

            <x-slot name="content">{{ __('care-plan.confirm_delete_activity') }}</x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="cancelDeleteActivity" wire:loading.attr="disabled">
                    {{ __('forms.cancel') }}
                </x-secondary-button>

                @if ($activityToDelete)
                    <x-danger-button
                        class="ms-3"
                        wire:click="deleteActivity({{ $activityToDelete }})"
                        wire:loading.attr="disabled"
                    >
                        {{ __('forms.delete') }}
                    </x-danger-button>
                @endif
            </x-slot>
        </x-confirmation-modal>
    </div>

    <livewire:components.x-message :key="time()" />
</section>
