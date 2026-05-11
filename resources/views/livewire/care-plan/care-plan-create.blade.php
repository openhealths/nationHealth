<x-layouts.patient :personId="$personId" :uuid="$uuid" :patientFullName="$patientFullName">
    <div class="breadcrumb-form p-4 shift-content">
        <div x-data="{ activeSection: 'doctors' }" class="flex flex-col lg:flex-row gap-8 lg:gap-12">
            
            <!-- Main Content -->
            <div class="flex-1 space-y-6 pb-24">
                <div id="doctors" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    <div class="record-inner-header border-b border-gray-100 dark:border-gray-700 p-4">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">@icon('doctor', 'w-5 h-5 inline mr-2') {{ __('care-plan.doctors') }}</h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.doctors')
                    </div>
                </div>

                <div id="patient_data" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    <div class="record-inner-header border-b border-gray-100 dark:border-gray-700 p-4">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">@icon('patients', 'w-5 h-5 inline mr-2') {{ __('care-plan.patient_data') }}</h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.patient_data')
                    </div>
                </div>

                <div id="care_plan_data" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    <div class="record-inner-header border-b border-gray-100 dark:border-gray-700 p-4">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">@icon('contracts', 'w-5 h-5 inline mr-2') {{ __('care-plan.care_plan_data') }}</h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.care_plan_data')
                    </div>
                </div>

                <div id="condition_diagnosis" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    <div class="record-inner-header border-b border-gray-100 dark:border-gray-700 p-4">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">@icon('alert-circle', 'w-5 h-5 inline mr-2') {{ __('care-plan.condition_diagnosis') }}</h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.condition_diagnosis')
                    </div>
                </div>

                <div id="supporting_information" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    <div class="record-inner-header border-b border-gray-100 dark:border-gray-700 p-4">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">@icon('file-text', 'w-5 h-5 inline mr-2') {{ __('care-plan.supporting_information') }}</h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.supporting_information')
                    </div>
                </div>

                <div id="additional_info" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    <div class="record-inner-header border-b border-gray-100 dark:border-gray-700 p-4">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">@icon('settings', 'w-5 h-5 inline mr-2') {{ __('care-plan.additional_info') }}</h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.additional_info', ['context' => 'create'])
                    </div>
                </div>

                <div class="flex gap-4 pt-6 border-t border-gray-100 dark:border-gray-700">
                    <button wire:click.prevent="" type="submit" class="button-minor">
                        {{ __('forms.delete') }}
                    </button>

                    <button wire:click.prevent="save" type="submit" class="button-primary-outline">
                        {{ __('forms.save') }}
                    </button>

                    <button type="submit" @click="$wire.set('showSignatureModal', true)" class="button-primary">
                        {{ __('forms.save_and_send') }}
                    </button>
                </div>
            </div>

            <!-- Right Sidebar Navigation -->
            <div class="w-full lg:w-[280px] flex-shrink-0 space-y-1 mt-4 lg:mt-0 sticky top-6 self-start">
                @php
                    $navItems = [
                        ['id' => 'doctors', 'label' => __('care-plan.doctors'), 'icon' => 'doctor'],
                        ['id' => 'patient_data', 'label' => __('care-plan.patient_data'), 'icon' => 'patients'],
                        ['id' => 'care_plan_data', 'label' => __('care-plan.care_plan_data'), 'icon' => 'contracts'],
                        ['id' => 'condition_diagnosis', 'label' => __('care-plan.condition_diagnosis'), 'icon' => 'alert-circle'],
                        ['id' => 'supporting_information', 'label' => __('care-plan.supporting_information'), 'icon' => 'file-text'],
                        ['id' => 'additional_info', 'label' => __('care-plan.additional_info'), 'icon' => 'settings'],
                    ];
                @endphp

                @foreach($navItems as $item)
                    <button @click="
                                activeSection = '{{ $item['id'] }}';
                                document.getElementById('{{ $item['id'] }}').scrollIntoView({ behavior: 'smooth', block: 'start' });
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
        </div>
    </div>

    <x-signature-modal method="sign" />
</x-layouts.patient>
