<div>
@php
    $patientName = $patientFullName ?? 'Пацієнт';
    $title = __('patients.encounter') . ' - ' . $patientName;

    $breadcrumbs = [
        ['label' => __('Головна'), 'url' => route('dashboard', [legalEntity()])],
        ['label' => __('Пацієнти'), 'url' => route('persons.index', [legalEntity()])],
        ['label' => $patientName, 'url' => route('persons.summary', [legalEntity(), 'id' => $patientId])],
        ['label' => __('patients.encounter')]
    ];

    $mainGroups = [
        ['id' => 'main-data', 'label' => __('patients.main_data'), 'icon' => 'pie-chart', 'view' => 'livewire.encounter.parts.main-data'],
        ['id' => 'conditions', 'label' => __('patients.diagnoses'), 'icon' => 'file', 'view' => 'livewire.encounter.parts.conditions'],
        ['id' => 'reasons', 'label' => __('patients.reasons_for_visit'), 'icon' => 'person', 'view' => 'livewire.encounter.parts.reasons'],
        ['id' => 'actions', 'label' => __('forms.actions'), 'icon' => 'check-box', 'view' => 'livewire.encounter.parts.actions'],
        ['id' => 'observations', 'label' => __('patients.observation'), 'icon' => 'heart', 'view' => 'livewire.encounter.parts.observations'],
        ['id' => 'immunizations', 'label' => __('patients.immunizations'), 'icon' => 'shield', 'view' => 'livewire.encounter.parts.immunizations'],
        ['id' => 'procedures', 'label' => __('patients.procedures'), 'icon' => 'settings', 'view' => 'livewire.encounter.parts.procedures'],
        ['id' => 'diagnostic-reports', 'label' => __('patients.diagnostic_reports'), 'icon' => 'activity', 'view' => 'livewire.encounter.parts.diagnostic-reports'],
        ['id' => 'clinical-impressions', 'label' => __('patients.clinical_impressions'), 'icon' => 'check', 'view' => 'livewire.encounter.parts.clinical-impressions'],
    ];

    $additionalItems = [
        ['id' => 'clinical-impressions-2', 'label' => __('patients.clinical_impressions'), 'icon' => 'book-open'],
        ['id' => 'referrals', 'label' => __('patients.referrals'), 'icon' => 'right-arrow'],
        ['id' => 'medical-reports', 'label' => __('patients.medical_reports'), 'icon' => 'Edit3'],
        ['id' => 'care-plans', 'label' => __('patients.care_plans'), 'icon' => 'file-text', 'view' => 'livewire.encounter.parts.care-plan'],
    ];

    // Additional data section usually at the bottom
    $footerItems = [
        ['id' => 'additional-data', 'label' => __('patients.additional_data'), 'icon' => 'details', 'view' => 'livewire.encounter.parts.additional-data'],
    ];
@endphp

<x-layouts.patient :id="$patientId" :patientFullName="$patientFullName">
    <x-slot name="title">{{ $title }}</x-slot>
    <x-slot name="breadcrumbs" :breadcrumbs="$breadcrumbs"></x-slot>

    <div class="breadcrumb-form p-4 shift-content">
        <div x-data="{ activeSection: '' }" class="flex flex-col lg:flex-row gap-8 lg:gap-12">

            <!-- Main Content -->
            <div class="flex-1 space-y-4">
                @foreach(array_merge($mainGroups, $footerItems) as $item)
                    @if(isset($item['view']))
                        <div id="block-{{ $item['id'] }}"
                             class="bg-white dark:bg-gray-800 rounded-xl scroll-mt-8"
                             :class="activeSection === '{{ $item['id'] }}' ? 'summary-section-active' : 'summary-section-inactive'"
                        >
                            <button @click="activeSection = activeSection === '{{ $item['id'] }}' ? '' : '{{ $item['id'] }}'"
                                    type="button"
                                    class="w-full flex items-center justify-between p-5 focus:outline-none"
                            >
                                <div class="flex items-center gap-4 text-gray-900 dark:text-gray-100 font-medium text-[15px]">
                                    <span class="w-6 h-6 flex items-center justify-center shrink-0 text-gray-900 dark:text-gray-100">
                                        @icon($item['icon'], 'w-6 h-6')
                                    </span>
                                    <span class="truncate">{{ $item['label'] }}</span>
                                </div>

                                <div class="shrink-0 text-gray-400 dark:text-gray-500 transition-transform duration-300"
                                     :class="activeSection === '{{ $item['id'] }}' ? '' : '-rotate-90'"
                                >
                                    @icon('chevron-down', 'w-5 h-5')
                                </div>
                            </button>

                            <div x-show="activeSection === '{{ $item['id'] }}'"
                                 x-transition
                                 x-cloak
                                 class="pb-5"
                            >
                                @include($item['view'])
                            </div>
                        </div>
                    @endif
                @endforeach

                <!-- Additional static blocks from mockup -->
                <div class="pt-4">
                    <!-- eHealth Status -->
                    <fieldset class="fieldset !mb-0 !shadow-none !p-5 !pb-6">
                        <legend class="legend !text-[15px]">
                            Статус ЕСОЗ
                        </legend>
                        <div class="flex items-center gap-4 mt-2">
                            <div class="form-group group w-64 mb-0">
                                <input type="text" id="esoz_status" class="input peer !bg-transparent !border-t-0 !border-x-0 !rounded-none !border-b !border-gray-200 dark:!border-gray-600 !px-0 focus:ring-0" value="Підписано" readonly placeholder=" " />
                                <label for="esoz_status" class="wrapped-label !left-0">Статус підписання</label>
                            </div>
                            <button type="button" class="button-primary whitespace-nowrap">Оновити</button>
                        </div>
                    </fieldset>

                    <div class="border-t border-gray-200 dark:border-gray-700 my-8"></div>

                    <h3 class="text-gray-900 dark:text-white font-bold text-lg mb-4">Додаткові дії</h3>

                    <div class="space-y-4">
                        <!-- Recipes -->
                        <fieldset class="fieldset !mb-4 !shadow-none !p-5">
                            <legend class="legend">Рецепти</legend>
                            <button type="button" class="item-add">
                                Додати рецепт
                            </button>
                        </fieldset>

                        <!-- Referrals -->
                        <fieldset class="fieldset !mb-4 !shadow-none !p-5">
                            <legend class="legend">Направлення</legend>
                            <button type="button" class="item-add">
                                Додати направлення
                            </button>
                        </fieldset>

                        <!-- Medical Reports -->
                        <fieldset class="fieldset !mb-4 !shadow-none !p-5">
                            <legend class="legend">Медичні висновки</legend>
                            <button type="button" class="item-add">
                                Додати медичний висновок
                            </button>
                        </fieldset>

                        <!-- Care Plans -->
                        <fieldset class="fieldset !mb-4 !shadow-none !p-5">
                            <legend class="legend">Плани лікування</legend>
                            <button type="button" @click="window.location.href = '{{ route('care-plan.create', [legalEntity(), 'patientId' => $patientId]) }}'" class="item-add">
                                Додати план лікування
                            </button>
                        </fieldset>
                    </div>

                    <div class="pt-6">
                        <button type="button" class="button-primary-outline-red !w-full sm:!w-auto">
                            Взаємодія внесена помилково
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sidebar Navigation (Right) -->
            <div class="w-full lg:w-[300px] flex-shrink-0 space-y-6 mt-4 lg:mt-0 sticky top-6 self-start">
                <!-- Group 1 -->
                <div class="space-y-1">
                    @foreach($mainGroups as $item)
                        <button @click="
                                    activeSection = '{{ $item['id'] }}';
                                    setTimeout(() => { document.getElementById('block-{{ $item['id'] }}').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
                                "
                                type="button"
                                :class="activeSection === '{{ $item['id'] }}' ? 'summary-sidebar-btn-active' : 'summary-sidebar-btn-inactive'"
                                class="summary-sidebar-btn"
                        >
                            <span class="w-5 h-5 flex items-center justify-center shrink-0">
                                @icon($item['icon'], 'w-5 h-5')
                            </span>
                            <span class="truncate">{{ $item['label'] }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="border-t border-gray-100 dark:border-gray-700 my-4"></div>

                <!-- Group 2 -->
                <div class="space-y-1">
                    @foreach($additionalItems as $item)
                        <button @if(isset($item['view']))
                                    @click="
                                        activeSection = '{{ $item['id'] }}';
                                        setTimeout(() => { document.getElementById('block-{{ $item['id'] }}').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
                                    "
                                @endif
                                type="button"
                                :class="activeSection === '{{ $item['id'] }}' ? 'summary-sidebar-btn-active' : 'summary-sidebar-btn-inactive'"
                                class="summary-sidebar-btn"
                        >
                            <span class="w-5 h-5 flex items-center justify-center shrink-0">
                                @icon($item['icon'], 'w-5 h-5')
                            </span>
                            <span class="truncate">{{ $item['label'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <x-signature-modal method="sign" />
</x-layouts.patient>
</div>
