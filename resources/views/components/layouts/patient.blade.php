@use('App\Models\DeclarationRequest')
@use('App\Models\MedicalEvents\Sql\Encounter')

<section>
    <x-header-navigation x-data="{ showFilter: true }" class="breadcrumb-form">
        <x-slot name="title">{{ $patientFullName }}</x-slot>

        @if(isset($headerActions))
            {{ $headerActions }}
        @else
            @can('create', Encounter::class)
                <a href="{{ route('encounter.create', [legalEntity(), 'id' => $id]) }}"
                   class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('patients.starts_interacting') }}
                </a>
            @endcan
        @endif

        <x-slot name="description">
            @if($this->declarationNumber)
                <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm font-semibold rounded-lg mt-1 border border-gray-100 dark:border-gray-700">
                    @icon('file-text', 'w-4 h-4 text-gray-400')
                    Декларація №{{ $this->declarationNumber }}
                </div>
            @endif
        </x-slot>

        <x-slot name="navigation">
            <div class="w-full flex items-center justify-between overflow-x-auto bg-gray-100 dark:bg-gray-800/50 p-1 px-2 xl:p-1.5 xl:px-3 rounded-xl text-[13px] xl:text-sm border border-transparent dark:border-gray-700/50">
                <a href="{{ route('persons.patient-data', [legalEntity(), 'id' => $id]) }}"
                   class="summary-tab {{ request()->routeIs('persons.patient-data') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                >
                    {{ __('patients.patient_data') }}
                </a>

                <a href="{{ route('persons.summary', [legalEntity(), 'id' => $id]) }}"
                   class="summary-tab {{ request()->routeIs('persons.summary') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                >
                    {{ __('patients.summary') }}
                </a>

                <a href="{{ route('persons.episodes', [legalEntity(), 'id' => $id]) }}"
                   class="summary-tab {{ request()->routeIs('persons.episodes') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                >
                    {{ __('patients.episodes') }}
                </a>

                <a href="{{ route('persons.examinations', [legalEntity(), 'id' => $id]) }}"
                   class="summary-tab {{ request()->routeIs('persons.examinations') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                >
                    {{ __('patients.observation') }}
                </a>

                <a href="javascript:void(0)"
                   class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                >
                    {{ __('patients.vaccinations') }}
                </a>

                <a href="javascript:void(0)"
                   class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                >
                    {{ __('patients.state') }}
                </a>

                <a href="javascript:void(0)"
                   class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                >
                    {{ __('patients.diagnoses') }}
                </a>

                <a href="javascript:void(0)"
                   class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                >
                    {{ __('patients.prescriptions') }}
                </a>

                <a href="javascript:void(0)"
                   class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                >
                    {{ __('patients.diagnostic_reports') }}
                </a>

                <button type="button"
                        class="inline-flex items-center px-2 py-1.5 text-gray-900 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg transition-colors ml-1"
                >
                    <span class="block px-2 flex items-center justify-center space-x-1">
                        <span class="w-1.5 h-1.5 bg-gray-700 dark:bg-gray-400 rounded-full"></span>
                        <span class="w-1.5 h-1.5 bg-gray-700 dark:bg-gray-400 rounded-full"></span>
                        <span class="w-1.5 h-1.5 bg-gray-700 dark:bg-gray-400 rounded-full"></span>
                    </span>
                </button>
            </div>
        </x-slot>
    </x-header-navigation>

    {{ $slot }}
    <livewire:components.x-message :key="time()" />
</section>
