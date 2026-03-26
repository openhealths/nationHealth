<x-layouts.patient :id="$id" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\MedicalEvents\Sql\Encounter::class)
            <a href="{{ route('encounter.create', [legalEntity(), 'patientId' => $id]) }}"
               class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.start_interacting') }}
            </a>
        @endcan

        <button type="button"
                class="button-primary-outline whitespace-nowrap px-5 py-2 text-sm"
        >
            {{ __('patients.data_access') }}
        </button>

        <button wire:click.prevent="syncEpisodes"
                type="button"
                class="button-sync flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('patients.sync_ehealth_data') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form p-4 shift-content">

        <div x-data="{ activeTab: 'summary' }"
             class="w-full flex items-center justify-between overflow-x-auto bg-gray-100 dark:bg-gray-800/50 p-1 px-2 xl:p-1.5 xl:px-3 rounded-xl mb-10 text-[13px] xl:text-sm border border-transparent dark:border-gray-700/50"
        >
            <a href="{{ route('persons.patient-data', [legalEntity(), 'id' => $id]) }}"
               :class="activeTab === 'patient-data' ? 'bg-blue-600 text-white shadow' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700'"
               @click.prevent="activeTab = 'patient-data'; window.location.href = this.href"
               class="summary-tab"
            >
                {{ __('patients.patient_data') }}
            </a>

            <button type="button"
                    @click.prevent="activeTab = 'summary'"
                    :class="activeTab === 'summary' ? 'bg-blue-600 text-white shadow' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700'"
                    class="summary-tab"
            >
                {{ __('patients.summary') }}
            </button>

            <button type="button"
                    wire:click.once="getDiagnoses"
                    @click.prevent="activeTab = 'diagnoses'"
                    :class="activeTab === 'diagnoses' ? 'bg-blue-600 text-white shadow' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700'"
                    class="summary-tab"
            >
                {{ __('patients.diagnoses') }}
            </button>

            <button type="button"
                    wire:click.once="getObservations"
                    @click.prevent="activeTab = 'observations'"
                    :class="activeTab === 'observations' ? 'bg-blue-600 text-white shadow' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700'"
                    class="summary-tab"
            >
                {{ __('patients.observation') }}
            </button>

            <button type="button"
                    @click.prevent="activeTab = 'vaccinations'"
                    :class="activeTab === 'vaccinations' ? 'bg-blue-600 text-white shadow' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700'"
                    class="summary-tab"
            >
                {{ __('patients.vaccinations') }}
            </button>

            <button type="button"
                    @click.prevent="activeTab = 'procedures'"
                    :class="activeTab === 'procedures' ? 'bg-blue-600 text-white shadow' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700'"
                    class="summary-tab"
            >
                {{ __('patients.procedures') }}
            </button>

            <button type="button"
                    @click.prevent="activeTab = 'prescriptions'"
                    :class="activeTab === 'prescriptions' ? 'bg-blue-600 text-white shadow' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700'"
                    class="summary-tab"
            >
                {{ __('patients.prescriptions') }}
            </button>

            <button type="button"
                    @click.prevent="activeTab = 'treatment_plans'"
                    :class="activeTab === 'treatment_plans' ? 'bg-blue-600 text-white shadow' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700'"
                    class="inline-flex items-center px-2.5 xl:px-3 py-1.5 font-medium rounded-lg whitespace-nowrap transition-colors"
            >
                {{ __('patients.treatment_plans') }}
            </button>

            <button type="button"
                    @click.prevent="activeTab = 'diagnostic_reports'"
                    :class="activeTab === 'diagnostic_reports' ? 'bg-blue-600 text-white shadow' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700'"
                    class="inline-flex items-center px-2.5 xl:px-3 py-1.5 font-medium rounded-lg whitespace-nowrap transition-colors"
            >
                {{ __('patients.diagnostic_reports') }}
            </button>

            <button type="button" class="inline-flex items-center px-2 py-1.5 text-gray-900 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg transition-colors ml-1">
                <span class="block px-2 flex items-center justify-center space-x-1">
                    <span class="w-1.5 h-1.5 bg-gray-700 dark:bg-gray-400 rounded-full"></span>
                    <span class="w-1.5 h-1.5 bg-gray-700 dark:bg-gray-400 rounded-full"></span>
                    <span class="w-1.5 h-1.5 bg-gray-700 dark:bg-gray-400 rounded-full"></span>
                </span>
            </button>
        </div>

        @php
            $navItems = [
                ['id' => 'episodes', 'action' => 'getEpisodes', 'label' => __('patients.episodes'), 'icon' => 'book'],
                ['id' => 'interaction', 'action' => '', 'label' => __('patients.interaction'), 'icon' => 'users'],
                ['id' => 'clinical_impressions', 'action' => '', 'label' => __('patients.clinical_impressions'), 'icon' => 'check'],
                ['id' => 'vaccinations', 'action' => '', 'label' => __('patients.vaccinations'), 'icon' => 'shield'],
                ['id' => 'observation', 'action' => 'getObservations', 'label' => __('patients.observation'), 'icon' => 'heart'],
                ['id' => 'diagnoses', 'action' => 'getDiagnoses', 'label' => __('patients.diagnoses'), 'icon' => 'file'],
                ['id' => 'condition', 'action' => '', 'label' => __('patients.condition'), 'icon' => 'file-minus'],
                ['id' => 'diagnostic_reports', 'action' => '', 'label' => __('patients.diagnostic_reports'), 'icon' => 'activity'],
                ['id' => 'allergies', 'action' => '', 'label' => __('patients.allergies'), 'icon' => 'alert'],
                ['id' => 'risk_assessments', 'action' => '', 'label' => __('patients.risk_assessments'), 'icon' => 'alert-octagon'],
                ['id' => 'devices', 'action' => '', 'label' => __('patients.devices'), 'icon' => 'equipment'],
                ['id' => 'medicines', 'action' => '', 'label' => __('patients.medicines'), 'icon' => 'pill-outline'],
            ];
        @endphp

        <div x-data="{ activeSection: '' }" class="flex flex-col lg:flex-row gap-8 lg:gap-12">


            <div class="flex-1 space-y-4">
                @foreach($navItems as $item)
                    <div id="block-{{ $item['id'] }}"
                         class="bg-white dark:bg-gray-800 border dark:border-gray-700 rounded-xl transition-all scroll-mt-8"
                         :class="activeSection === '{{ $item['id'] }}' ? 'border-gray-200 dark:border-gray-600 shadow-md' : 'border-gray-100 hover:shadow-md hover:bg-gray-50 dark:hover:bg-gray-700/80'"
                    >
                        <button @if($item['action']) wire:click.once="{{ $item['action'] }}" @endif
                        @click="activeSection = activeSection === '{{ $item['id'] }}' ? '' : '{{ $item['id'] }}'"
                                type="button"
                                class="w-full flex items-center justify-between p-5 focus:outline-none"
                        >
                            <div class="flex items-center gap-4 text-gray-900 dark:text-gray-100 font-medium text-[15px]">
                                <span class="w-6 h-6 flex items-center justify-center shrink-0 text-gray-900 dark:text-gray-100">
                                    @icon($item['icon'], 'w-6 h-6')
                                </span>
                                <span class="truncate">{{ $item['label'] }}</span>
                            </div>

                            <div class="flex items-center gap-4 text-sm font-medium">
                                <span x-show="activeSection === '{{ $item['id'] }}'"
                                      class="hidden sm:flex text-blue-600 dark:text-blue-400 items-center gap-1.5 hover:text-blue-700 dark:hover:text-blue-300 transition-colors"
                                      @click.stop=""
                                >
                                    @icon('refresh', 'w-4 h-4')
                                    {{ __('patients.sync_ehealth_data') }}
                                </span>
                                <div class="shrink-0 text-gray-400 dark:text-gray-500 transition-transform duration-300"
                                     :class="activeSection === '{{ $item['id'] }}' ? '' : '-rotate-90'"
                                >
                                    @icon('chevron-down', 'w-5 h-5')
                                </div>
                            </div>
                        </button>

                        <div x-show="activeSection === '{{ $item['id'] }}'" style="display: none;" class="px-5 pb-5">

                            @if($item['id'] === 'episodes')
                                @include('livewire.person.records.parts.episodes')
                            @elseif($item['id'] === 'interaction')
                                @include('livewire.person.records.parts.interaction')
                            @elseif($item['id'] === 'clinical_impressions')
                                @include('livewire.person.records.parts.clinical_impressions')
                            @elseif($item['id'] === 'vaccinations')
                                @include('livewire.person.records.parts.vaccinations')
                            @elseif($item['id'] === 'observation')
                                @include('livewire.person.records.parts.observation')
                            @elseif($item['id'] === 'diagnoses')
                                @include('livewire.person.records.parts.diagnoses')
                            @elseif($item['id'] === 'condition')
                                @include('livewire.person.records.parts.condition')
                            @elseif($item['id'] === 'diagnostic_reports')
                                @include('livewire.person.records.parts.diagnostic_reports')
                            @elseif($item['id'] === 'allergies')
                                @include('livewire.person.records.parts.allergies')
                            @elseif($item['id'] === 'risk_assessments')
                                @include('livewire.person.records.parts.risk_assessments')
                            @elseif($item['id'] === 'devices')
                                @include('livewire.person.records.parts.devices')
                            @elseif($item['id'] === 'medicines')
                                @include('livewire.person.records.parts.medicines')
                            @else
                                <div class="py-12 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-dashed border-gray-200 dark:border-gray-700 mt-2">
                                    <div class="flex flex-col items-center justify-center text-gray-500 dark:text-gray-400">
                                        <div class="w-8 h-8 mb-4 opacity-50 flex items-center justify-center [&>svg]:w-full [&>svg]:h-full">
                                            @icon($item['icon'])
                                        </div>
                                        <p class="text-[15px] font-medium">Дані відсутні</p>
                                        <p class="text-[13px] mt-1 text-gray-400">В цьому розділі поки немає інформації</p>
                                    </div>
                                </div>
                            @endif

                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Right Sidebar Navigation -->
            <div class="w-full lg:w-[320px] flex-shrink-0 space-y-1 mt-4 lg:mt-0 sticky top-6 self-start">
                @foreach($navItems as $item)
                    <button @if($item['action']) wire:click.once="{{ $item['action'] }}" @endif
                    @click="
                                activeSection = '{{ $item['id'] }}';
                                setTimeout(() => { document.getElementById('block-{{ $item['id'] }}').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
                            "
                            type="button"
                            :class="activeSection === '{{ $item['id'] }}' ? 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white' : 'text-gray-800 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800/50 hover:text-gray-900 dark:hover:text-gray-200'"
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

    <x-forms.loading />
</x-layouts.patient>
