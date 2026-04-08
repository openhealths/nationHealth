<x-layouts.patient :id="$id" :patientFullName="$patientFullName">
    <div class="breadcrumb-form p-4 shift-content">
        <div x-data="{ activeSection: 'doctors' }" class="flex flex-col lg:flex-row gap-8 lg:gap-12">
            
            <!-- Main Content -->
            <div class="flex-1 space-y-6">
                <div id="doctors" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.treatment-plan.parts.doctors')
                </div>

                <div id="patient-data" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.treatment-plan.parts.patient_data')
                </div>

                <div id="treatment-plan-data" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.treatment-plan.parts.treatment_plan_data')
                </div>

                <div id="condition-diagnosis" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.treatment-plan.parts.condition_diagnosis')
                </div>

                <div id="supporting-information" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.treatment-plan.parts.supporting_information')
                </div>

                <div id="additional-info" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.treatment-plan.parts.additional_info', ['context' => 'create'])
                </div>

                <div class="mt-6 flex flex-row items-center gap-4 pt-6 border-t border-gray-100 dark:border-gray-700">
                    <div class="flex items-center space-x-3">
                        <a href=" " class="button-primary-outline-red">
                            {{ __('Видалити') }}
                        </a>

                        @if(get_class($this) === \App\Livewire\TreatmentPlan\TreatmentPlanCreate::class)
                            <button type="submit"
                                    class="button-primary-outline flex items-center gap-2 px-4 py-2"
                                    wire:click="createLocally"
                            >
                                @icon('archive', 'w-4 h-4')
                                {{ __('forms.save') }}
                            </button>
                        @endif

                        <button type="button" wire:click="create" class="button-primary">
                            {{ __('Створити план лікування') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right Sidebar Navigation -->
            <div class="w-full lg:w-[280px] flex-shrink-0 space-y-1 mt-4 lg:mt-0 sticky top-6 self-start">
                @php
                    $navItems = [
                        ['id' => 'doctors', 'label' => __('treatment-plan.doctors'), 'icon' => 'users'],
                        ['id' => 'patient-data', 'label' => __('patients.patient_data'), 'icon' => 'person'],
                        ['id' => 'treatment-plan-data', 'label' => __('treatment-plan.treatment_plan_data'), 'icon' => 'book'],
                        ['id' => 'condition-diagnosis', 'label' => __('patients.condition'), 'icon' => 'file-minus'],
                        ['id' => 'supporting-information', 'label' => __('treatment-plan.supporting_information'), 'icon' => 'activity'],
                        ['id' => 'additional-info', 'label' => __('treatment-plan.additional_info'), 'icon' => 'alert'],
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
</x-layouts.patient>
