<x-layouts.patient :id="$id" :uuid="$uuid" :patientFullName="$patientFullName">
    <div x-data="{ activeSection: 'doctors' }" class="flex flex-col lg:flex-row gap-6 relative" @scroll.window.throttle.50ms="
        const sections = ['doctors', 'patient_data', 'care_plan_data', 'condition_diagnosis', 'supporting_information', 'additional_info'];
        for (const section of sections) {
            const el = document.getElementById(section);
            if (el) {
                const rect = el.getBoundingClientRect();
                if (rect.top <= 150 && rect.bottom >= 150) {
                    activeSection = section;
                    break;
                }
            }
        }
    ">
        {{-- Sidebar Navigation --}}
        <div class="lg:w-1/4">
            <div class="summary-sidebar sticky top-24">
                <nav class="space-y-1">
                    <a @click.prevent="document.getElementById('doctors').scrollIntoView({behavior: 'smooth'})"
                       href="#doctors"
                       class="summary-tab w-full text-left"
                       :class="activeSection === 'doctors' ? 'summary-tab-active' : 'summary-tab-inactive'">
                        {{ __('treatment-plan.doctors') }}
                    </a>
                    <a @click.prevent="document.getElementById('patient_data').scrollIntoView({behavior: 'smooth'})"
                       href="#patient_data"
                       class="summary-tab w-full text-left"
                       :class="activeSection === 'patient_data' ? 'summary-tab-active' : 'summary-tab-inactive'">
                        {{ __('patients.patient_data') }}
                    </a>
                    <a @click.prevent="document.getElementById('care_plan_data').scrollIntoView({behavior: 'smooth'})"
                       href="#care_plan_data"
                       class="summary-tab w-full text-left"
                       :class="activeSection === 'care_plan_data' ? 'summary-tab-active' : 'summary-tab-inactive'">
                        {{ __('treatment-plan.care_plan_data') }}
                    </a>
                    <a @click.prevent="document.getElementById('condition_diagnosis').scrollIntoView({behavior: 'smooth'})"
                       href="#condition_diagnosis"
                       class="summary-tab w-full text-left"
                       :class="activeSection === 'condition_diagnosis' ? 'summary-tab-active' : 'summary-tab-inactive'">
                        {{ __('treatment-plan.condition_diagnosis') }}
                    </a>
                    <a @click.prevent="document.getElementById('supporting_information').scrollIntoView({behavior: 'smooth'})"
                       href="#supporting_information"
                       class="summary-tab w-full text-left"
                       :class="activeSection === 'supporting_information' ? 'summary-tab-active' : 'summary-tab-inactive'">
                        {{ __('treatment-plan.supporting_information') }}
                    </a>
                    <a @click.prevent="document.getElementById('additional_info').scrollIntoView({behavior: 'smooth'})"
                       href="#additional_info"
                       class="summary-tab w-full text-left"
                       :class="activeSection === 'additional_info' ? 'summary-tab-active' : 'summary-tab-inactive'">
                        {{ __('treatment-plan.additional_info') }}
                    </a>
                </nav>

                <div class="mt-6 pt-6 border-t border-gray-100 dark:border-gray-700">
                    <button type="button" @click="$wire.save()" class="w-full button-primary-outline flex items-center justify-center gap-2 mb-3">
                        @icon('archive', 'w-4 h-4')
                        {{ __('forms.save') }}
                    </button>
                    <button type="button" @click="$wire.set('showSignatureModal', true)" class="w-full button-primary flex items-center justify-center gap-2">
                        @icon('edit-linear', 'w-4 h-4')
                        {{ __('forms.sign_with_KEP') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Main Content --}}
        <div class="lg:w-3/4 space-y-6 pb-24">
            <div id="doctors" class="record-inner-card scroll-mt-24">
                <div class="record-inner-header">
                    <h3>@icon('doctor', 'w-5 h-5 inline mr-2') {{ __('treatment-plan.doctors') }}</h3>
                </div>
                <div class="p-6">
                    @include('livewire.care-plan.parts.doctors')
                </div>
            </div>

            <div id="patient_data" class="record-inner-card scroll-mt-24">
                <div class="record-inner-header">
                    <h3>@icon('patients', 'w-5 h-5 inline mr-2') {{ __('patients.patient_data') }}</h3>
                </div>
                <div class="p-6">
                    @include('livewire.care-plan.parts.patient_data')
                </div>
            </div>

            <div id="care_plan_data" class="record-inner-card scroll-mt-24">
                <div class="record-inner-header">
                    <h3>@icon('hugeicons-contracts', 'w-5 h-5 inline mr-2') {{ __('treatment-plan.care_plan_data') }}</h3>
                </div>
                <div class="p-6">
                    @include('livewire.care-plan.parts.care_plan_data')
                </div>
            </div>

            <div id="condition_diagnosis" class="record-inner-card scroll-mt-24">
                <div class="record-inner-header">
                    <h3>@icon('alert-circle', 'w-5 h-5 inline mr-2') {{ __('treatment-plan.condition_diagnosis') }}</h3>
                </div>
                <div class="p-6">
                    @include('livewire.care-plan.parts.condition_diagnosis')
                </div>
            </div>

            <div id="supporting_information" class="record-inner-card scroll-mt-24">
                <div class="record-inner-header">
                    <h3>@icon('file-text', 'w-5 h-5 inline mr-2') {{ __('treatment-plan.supporting_information') }}</h3>
                </div>
                <div class="p-6">
                    @include('livewire.care-plan.parts.supporting_information')
                </div>
            </div>

            <div id="additional_info" class="record-inner-card scroll-mt-24">
                <div class="record-inner-header">
                    <h3>@icon('settings', 'w-5 h-5 inline mr-2') {{ __('treatment-plan.additional_info') }}</h3>
                </div>
                <div class="p-6">
                    @include('livewire.care-plan.parts.additional_info', ['context' => 'create'])
                </div>
            </div>
        </div>

        @include('components.signature-modal', ['method' => 'sign'])
    </div>
</x-layouts.patient>
