@php
    $patientName = $patientFullName ?? __('forms.patient');
    $title = __('patients.encounter') . ' - ' . $patientName;

    $mainGroups = [
        ['id' => 'referral', 'label' => __('patients.referrals'), 'icon' => 'arrow-right', 'view' => 'livewire.encounter.parts.referral'],
        ['id' => 'main-data', 'label' => __('patients.main_data'), 'icon' => 'pie-chart', 'view' => 'livewire.encounter.parts.main-data'],
        ['id' => 'conditions', 'label' => __('patients.diagnoses'), 'icon' => 'file', 'view' => 'livewire.encounter.parts.conditions'],
        ['id' => 'reasons', 'label' => __('patients.reasons_for_visit'), 'icon' => 'person', 'view' => 'livewire.encounter.parts.reasons'],
        ['id' => 'actions', 'label' => __('forms.actions'), 'icon' => 'check-box', 'view' => 'livewire.encounter.parts.actions'],
        ['id' => 'observations', 'label' => __('patients.observation'), 'icon' => 'heart', 'view' => 'livewire.encounter.parts.observations'],
        ['id' => 'immunizations', 'label' => __('patients.immunizations'), 'icon' => 'shield', 'view' => 'livewire.encounter.parts.immunizations'],
        ['id' => 'procedures', 'label' => __('patients.procedures'), 'icon' => 'settings', 'view' => 'livewire.encounter.parts.procedures'],
        ['id' => 'diagnostic-reports', 'label' => __('patients.diagnostic_reports'), 'icon' => 'activity', 'view' => 'livewire.encounter.parts.diagnostic-reports'],
        ['id' => 'clinical-impressions', 'label' => __('patients.clinical_impressions'), 'icon' => 'check', 'view' => 'livewire.encounter.parts.clinical-impressions'],
        ['id' => 'additional-data', 'label' => __('patients.additional_data'), 'icon' => 'Edit3', 'view' => 'livewire.encounter.parts.additional-data'],
    ];

    $footerItems = [];
@endphp

<x-layouts.patient :personId="$personId"
                   :patientFullName="$patientFullName"
                   :hideNavigation="true"
                   :title="$title"
                   :breadcrumbs="[
                       ['label' => __('general.home'), 'url' => route('dashboard', [legalEntity()])],
                       ['label' => $patientName]
                   ]"
>
    <x-slot name="headerActions"></x-slot>

    <div class="breadcrumb-form p-4 shift-content">
        @php
            $allBlockIds = array_column(array_merge($mainGroups, $footerItems), 'id');
            $initialActiveSections = isset($encounterId) ? '[]' : json_encode($allBlockIds);
        @endphp
        <div x-data='{
                activeSections: {!! $initialActiveSections !!},
                toggle(id) {
                    if (this.activeSections.includes(id)) {
                        this.activeSections = this.activeSections.filter(i => i !== id);
                    } else {
                        this.activeSections.push(id);
                    }
                }
             }'
             class="flex flex-col lg:flex-row gap-8 lg:gap-12"
        >

            <!-- Main Content -->
            <div class="flex-1 space-y-4">
                @foreach(array_merge($mainGroups, $footerItems) as $item)
                    @if(isset($item['view']))
                        <div id="block-{{ $item['id'] }}"
                             class="bg-white dark:bg-gray-800 rounded-xl scroll-mt-24 dark:border-gray-700"
                             :class="activeSections.includes('{{ $item['id'] }}') ? 'summary-section-active' : 'summary-section-inactive'"
                        >
                            <button @click="toggle('{{ $item['id'] }}')"
                                    type="button"
                                    class="w-full flex items-center justify-between p-5 focus:outline-none"
                            >
                                <div
                                    class="flex items-center gap-4 text-gray-900 dark:text-gray-100 font-semibold text-[17px]">
                                    <span
                                        class="w-6 h-6 flex items-center justify-center shrink-0 text-gray-900 dark:text-gray-100">
                                        @icon($item['icon'], 'w-6 h-6')
                                    </span>
                                    <span class="truncate">{{ $item['label'] }}</span>
                                </div>

                                <div class="shrink-0 text-gray-400 dark:text-gray-500 transition-transform duration-300"
                                     :class="activeSections.includes('{{ $item['id'] }}') ? '' : '-rotate-90'"
                                >
                                    @icon('chevron-down', 'w-5 h-5')
                                </div>
                            </button>

                            <div x-show="activeSections.includes('{{ $item['id'] }}')" style="display: none;"
                                 class="px-5 pb-5">
                                @include($item['view'])
                            </div>
                        </div>
                    @endif
                @endforeach

                <div class="mt-4">
                    <fieldset class="fieldset-card p-4 sm:p-8 sm:pb-10 mb-4">
                        <legend class="legend">{{ __('forms.status.label') }}</legend>

                        <div class="flex flex-col sm:flex-row sm:items-end gap-6 mb-2">
                            <div class="form-group group flex-1">
                                <input type="text"
                                       id="ehealthStatus"
                                       class="input peer text-gray-500"
                                       value="{{ __('forms.status.signed') }}"
                                       readonly
                                       placeholder=" "
                                />
                                <label for="ehealthStatus" class="label">
                                    {{ __('forms.status.label') }}
                                </label>
                            </div>

                            <div class="mb-1">
                                <button type="button" class="button-primary px-8">
                                    {{ __('forms.update') }}
                                </button>
                            </div>
                        </div>
                    </fieldset>
                </div>

                <!-- Additional Actions -->
                @if(isset($encounterId))
                <div class="pt-10 mt-10 border-t border-gray-100 dark:border-gray-700">
                    <h3 class="text-[17px] font-bold text-gray-900 dark:text-gray-100 mb-6">
                        {{ __('patients.additional_actions') }}
                    </h3>

                    <div class="space-y-6">
                        <fieldset class="fieldset-card p-5">
                            <legend class="legend">{{ __('patients.prescriptions') }}</legend>
                            <button type="button"
                                    class="cursor-pointer text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1.5 font-medium text-sm transition-colors"
                            >
                                @icon('plus', 'w-4 h-4')
                                <span>{{ __('patients.add_prescription') }}</span>
                            </button>
                        </fieldset>

                        <fieldset class="fieldset-card p-5">
                            <legend class="legend">{{ __('patients.referrals') }}</legend>
                            <button type="button"
                                    class="cursor-pointer text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1.5 font-medium text-sm transition-colors"
                            >
                                @icon('plus', 'w-4 h-4')
                                <span>{{ __('patients.add_referral') }}</span>
                            </button>
                        </fieldset>

                        <fieldset class="fieldset-card p-5">
                            <legend class="legend">{{ __('patients.medical_reports') }}</legend>
                            <button type="button"
                                    class="cursor-pointer text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1.5 font-medium text-sm transition-colors"
                            >
                                @icon('plus', 'w-4 h-4')
                                <span>{{ __('patients.add_medical_report') }}</span>
                            </button>
                        </fieldset>

                        <fieldset class="fieldset-card p-5">
                            <legend class="legend">{{ __('patients.care_plans') }}</legend>
                            <a href="{{ route('care-plans.create-by-encounter', [legalEntity(), 'encounter' => $encounterId]) }}"
                               class="cursor-pointer text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1.5 font-medium text-sm transition-colors"
                            >
                                @icon('plus', 'w-4 h-4')
                                <span>{{ __('patients.add_care_plan') }}</span>
                            </a>
                        </fieldset>
                    </div>
                </div>
                @endif

                <!-- Actions -->
                <div class="pt-8">
                    <div class="flex flex-wrap gap-4">
                        <button type="button" class="button-primary-outline-red">
                            {{ __('patients.encounter_entered_in_error') }}
                        </button>
                        <button wire:click.prevent="save" type="submit" class="button-primary">
                            {{ __('forms.save') }}
                        </button>

                        <button type="submit" @click="$wire.showSignatureModal = true" class="button-primary">
                            {{ __('forms.save_and_send') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sidebar Navigation (Right) -->
            <div class="w-full lg:w-75 shrink-0 space-y-6 mt-4 lg:mt-0 sticky top-24 self-start">
                <div class="space-y-1">
                    @foreach($mainGroups as $item)
                        <button @click="
                                    if (!activeSections.includes('{{ $item['id'] }}')) toggle('{{ $item['id'] }}');
                                    document.getElementById('block-{{ $item['id'] }}').scrollIntoView({ behavior: 'smooth', block: 'start' });
                                "
                                type="button"
                                :class="activeSections.includes('{{ $item['id'] }}') ? 'summary-sidebar-btn-active' : 'summary-sidebar-btn-inactive'"
                                class="summary-sidebar-btn w-full"
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

    <x-signature-modal method="sign"/>
    <livewire:components.x-message :key="time()"/>
    <x-forms.loading/>
</x-layouts.patient>
