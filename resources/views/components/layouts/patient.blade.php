@props([
    'personId' => null,
    'prepersonId' => null,
    'patientFullName',
    'hideNavigation' => false,
    'title' => null,
    'breadcrumbs' => [],
    'activeTab' => null
])

@php
    use App\Models\DeclarationRequest;
    use App\Models\MedicalEvents\Sql\Encounter;
    use App\Models\Person\Person;

    $routePrefix = !is_null($prepersonId) ? 'prepersons' : 'persons';
    $routeParamKey = !is_null($prepersonId) ? 'preperson' : 'person';
    $recordId = $prepersonId ?? $personId;
@endphp

<section>
    <x-header-navigation
        x-data="{ showFilter: true }"
        class="breadcrumb-form"
        :breadcrumbs="$breadcrumbs"
    >
        <x-slot name="title">{{ $title ?? $patientFullName }}</x-slot>

        @if(isset($headerActions))
            {{ $headerActions }}
        @elseif($personId)
            @can('create', Encounter::class)
                <a href="{{ route('encounter.create', [legalEntity(), 'person' => $personId]) }}"
                   class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('patients.starts_interacting') }}
                </a>
            @endcan
        @endif

        <x-slot name="description">
            @if(isset($description))
                {{ $description }}
            @elseif(isset($this->declarationNumber) && $this->declarationNumber)
                <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm font-semibold rounded-lg mt-1 border border-gray-100 dark:border-gray-700">
                    @icon('file-text', 'w-4 h-4 text-gray-400')
                    Декларація №{{ $this->declarationNumber }}
                </div>
            @endif
        </x-slot>

        @if(!$hideNavigation)
            <x-slot name="navigation">
                <div class="space-y-1">
                    <div class="summary-nav-row">
                        <a href="{{ route("$routePrefix.patient-data", [legalEntity(), $routeParamKey => $recordId]) }}"
                           class="summary-tab {{ ($activeTab === 'patient-data' || request()->routeIs("$routePrefix.patient-data")) ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.patient_data') }}
                        </a>

                        @can('view', Person::class)
                            <a href="{{ route("$routePrefix.summary", [legalEntity(), $routeParamKey => $recordId]) }}"
                               class="summary-tab {{ request()->routeIs("$routePrefix.summary") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                            >
                                {{ __('patients.summary') }}
                            </a>
                        @endcan

                        <a href="{{ route("$routePrefix.episodes", [legalEntity(), $routeParamKey => $recordId]) }}"
                           class="summary-tab {{ request()->routeIs("$routePrefix.episodes") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.episodes') }}
                        </a>

                        <a href="{{ route("$routePrefix.observations", [legalEntity(), $routeParamKey => $recordId]) }}"
                           class="summary-tab {{ request()->routeIs("$routePrefix.observations") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.observation') }}
                        </a>

                        <a href="{{ route("$routePrefix.immunizations", [legalEntity(), $routeParamKey => $recordId]) }}"
                           class="summary-tab {{ request()->routeIs("$routePrefix.immunization") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.immunizations') }}
                        </a>

                        <a href="{{ route("$routePrefix.conditions", [legalEntity(), $routeParamKey => $recordId]) }}"
                           class="summary-tab {{ request()->routeIs("$routePrefix.condition") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.conditions') }}
                        </a>

                        <a href="{{ route("$routePrefix.diagnoses", [legalEntity(), $routeParamKey => $recordId]) }}"
                           class="summary-tab {{ request()->routeIs("$routePrefix.diagnoses") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.diagnoses') }}
                        </a>

                        <a href="javascript:void(0)"
                           class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                        >
                            {{ __('patients.prescriptions') }}
                        </a>

                        <a href="{{ route("$routePrefix.diagnostic-reports", [legalEntity(), $routeParamKey => $recordId]) }}"
                           class="summary-tab {{ request()->routeIs("$routePrefix.diagnostic-reports") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.diagnostic_reports') }}
                        </a>
                    </div>

                    <div class="summary-nav-row">
                        <a href="{{ route("$routePrefix.clinical-impressions", [legalEntity(), $routeParamKey => $recordId]) }}"
                           class="summary-tab {{ request()->routeIs("$routePrefix.clinical-impressions") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
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

                        @if($prepersonId)
                            <a href="javascript:void(0)"
                               class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                            >
                                {{ __('patients.care_plans') }}
                            </a>
                        @else
                            <a href="{{ route('persons.care-plans', [legalEntity(), 'person' => $personId]) }}"
                               class="summary-tab {{ request()->routeIs('persons.care-plans') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                            >
                                {{ __('patients.care_plans') }}
                            </a>
                        @endif

                        <a href="{{ route("$routePrefix.encounters", [legalEntity(), $routeParamKey => $recordId]) }}"
                           class="summary-tab {{ request()->routeIs("$routePrefix.encounters") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.encounters') }}
                        </a>

                        <div class="flex-1"></div>
                    </div>
                </div>
            </x-slot>
        @endif
    </x-header-navigation>

    {{ $slot }}
    <livewire:components.x-message :key="time()" />
</section>
