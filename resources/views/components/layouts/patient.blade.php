@props(['personId', 'patientFullName'])

@php
    use App\Models\DeclarationRequest;
    use App\Models\MedicalEvents\Sql\Encounter;
    use App\Models\Person\Person;
@endphp

<section>
    <x-header-navigation x-data="{ showFilter: true }" class="breadcrumb-form">
        <x-slot name="title">{{ $patientFullName }}</x-slot>

        @if(isset($headerActions))
            {{ $headerActions }}
        @else
            @can('create', Encounter::class)
                <a href="{{ route('encounter.create', [legalEntity(), 'personId' => $personId]) }}"
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
            <div class="space-y-1">
                <div class="summary-nav-row">
                    <a href="{{ route('persons.patient-data', [legalEntity(), 'personId' => $personId]) }}"
                       class="summary-tab {{ request()->routeIs('persons.patient-data') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                    >
                        {{ __('patients.patient_data') }}
                    </a>

                    @can('view', Person::class)
                        <a href="{{ route('persons.summary', [legalEntity(), 'personId' => $personId]) }}"
                           class="summary-tab {{ request()->routeIs('persons.summary') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.summary') }}
                        </a>
                    @endcan

                    <a href="{{ route('persons.episodes', [legalEntity(), 'personId' => $personId]) }}"
                       class="summary-tab {{ request()->routeIs('persons.episodes') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                    >
                        {{ __('patients.episodes') }}
                    </a>

                    <a href="{{ route('persons.observations', [legalEntity(), 'personId' => $personId]) }}"
                       class="summary-tab {{ request()->routeIs('persons.observations') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                    >
                        {{ __('patients.observation') }}
                    </a>

                    <a href="{{ route('persons.immunization', [legalEntity(), 'personId' => $personId]) }}"
                       class="summary-tab {{ request()->routeIs('persons.immunization') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                    >
                        {{ __('patients.immunizations') }}
                    </a>

                    <a href="{{ route('persons.condition', [legalEntity(), 'personId' => $personId]) }}"
                       class="summary-tab {{ request()->routeIs('persons.condition') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                    >
                        {{ __('patients.condition') }}
                    </a>

                    <a href="{{ route('persons.diagnoses', [legalEntity(), 'personId' => $personId]) }}"
                       class="summary-tab {{ request()->routeIs('persons.diagnoses') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                    >
                        {{ __('patients.diagnoses') }}
                    </a>

                    <a href="javascript:void(0)"
                       class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                    >
                        {{ __('patients.prescriptions') }}
                    </a>

                    <a href="{{ route('persons.diagnostic-reports', [legalEntity(), 'personId' => $personId]) }}"
                       class="summary-tab {{ request()->routeIs('persons.diagnostic-reports') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                    >
                        {{ __('patients.diagnostic_reports') }}
                    </a>
                </div>

                <div class="summary-nav-row">
                    <a href="{{ route('persons.clinical-impressions', [legalEntity(), 'personId' => $personId]) }}"
                       class="summary-tab {{ request()->routeIs('persons.clinical-impressions') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                    >
                        {{ __('patients.clinical_impressions') }}
                    </a>

                    <a href="javascript:void(0)"
                       class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                    >
                        {{ __('patients.medical_reports') }}
                    </a>

                    <a href="javascript:void(0)"
                       class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                    >
                        {{ __('patients.referrals') }}
                    </a>

                    <a href="{{ route('persons.care-plans', [legalEntity(), 'personId' => $personId]) }}"
                       class="summary-tab {{ request()->routeIs('persons.care-plans') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                    >
                        {{ __('patients.care_plans') }}
                    </a>

                    <a href="{{ route('persons.encounters', [legalEntity(), 'personId' => $personId]) }}"
                       class="summary-tab {{ request()->routeIs('persons.encounters') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                    >
                        {{ __('patients.encounters') }}
                    </a>

                    <div class="flex-1"></div>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    {{ $slot }}
    <livewire:components.x-message :key="time()" />
</section>
