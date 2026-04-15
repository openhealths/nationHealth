<div>
    <div class="breadcrumb-form p-4 shift-content">
        <div x-data="{ activeSection: 'main-data' }" class="flex flex-col lg:flex-row gap-8 lg:gap-12">

            <!-- Main Content -->
            <div class="flex-1 space-y-6">
                <div id="main-data" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.encounter.parts.main-data')
                </div>

                <div id="reasons" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.encounter.parts.reasons')
                </div>

                <div id="conditions" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.encounter.parts.conditions')
                </div>

                <div id="immunizations" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.encounter.parts.immunizations')
                </div>

                <div id="diagnostic-reports" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.encounter.parts.diagnostic-reports')
                </div>

{{--                <div id="observations" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">--}}
{{--                    @include('livewire.encounter.parts.observations')--}}
{{--                </div>--}}

{{--                <div id="procedures" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">--}}
{{--                    @include('livewire.encounter.parts.procedures')--}}
{{--                </div>--}}

{{--                <div id="clinical-impressions" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">--}}
{{--                    @include('livewire.encounter.parts.clinical-impressions')--}}
{{--                </div>--}}

                <div id="care-plans" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm scroll-mt-6">
                    @include('livewire.encounter.parts.care-plan')
                </div>

                @include('livewire.encounter.parts.actions')
                @include('livewire.encounter.parts.additional-data')

                <div class="flex gap-4 pt-6 border-t border-gray-100 dark:border-gray-700">
                    <button wire:click.prevent="" type="submit" class="button-minor">
                        {{ __('forms.delete') }}
                    </button>

                    <button wire:click.prevent="save" type="submit" class="button-primary">
                        {{ __('forms.save') }}
                    </button>

                    <button type="submit" @click="$wire.showSignatureModal = true" class="button-primary">
                        {{ __('forms.save_and_send') }}
                    </button>
                </div>
            </div>

            <!-- Right Sidebar Navigation -->
            <div class="w-full lg:w-[280px] flex-shrink-0 space-y-1 mt-4 lg:mt-0 sticky top-6 self-start">
                @php
                    $navItems = [
                        ['id' => 'main-data', 'label' => __('patients.main_data'), 'icon' => 'pie-chart'],
                        ['id' => 'reasons', 'label' => __('patients.reasons_for_visit'), 'icon' => 'person'],
                        ['id' => 'conditions', 'label' => __('patients.diagnoses'), 'icon' => 'file'],
                        ['id' => 'immunizations', 'label' => __('patients.immunizations'), 'icon' => 'shield'],
                        ['id' => 'diagnostic-reports', 'label' => __('patients.diagnostic_reports'), 'icon' => 'activity'],
                        ['id' => 'observations', 'label' => __('patients.observation'), 'icon' => 'heart'],
                        ['id' => 'procedures', 'label' => __('patients.procedures'), 'icon' => 'settings'],
                        ['id' => 'clinical-impressions', 'label' => __('patients.clinical_impressions'), 'icon' => 'check'],
                        ['id' => 'care-plans', 'label' => __('patients.care_plans'), 'icon' => 'clipboard-document-list'],
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
    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</div>
