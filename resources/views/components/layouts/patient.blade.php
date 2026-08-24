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
    use App\Models\Relations\PersonVerificationDetail;

    $routePrefix = !is_null($prepersonId) ? 'prepersons' : 'persons';
    $routeParamKey = !is_null($prepersonId) ? 'preperson' : 'person';
    $recordId = $prepersonId ?? $personId;
@endphp

<section>
    <x-header-navigation x-data="{ showFilter: true }" class="breadcrumb-form" :breadcrumbs="$breadcrumbs">
        <x-slot name="title">{{ $title ?? $patientFullName }}</x-slot>

        @if (isset($headerActions))
            {{ $headerActions }}
        @elseif ($personId)
            @can('create', Encounter::class)
                <a
                    href="{{ route('encounter.create', [legalEntity(), 'person' => $personId]) }}"
                    class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('patients.starts_interacting') }}
                </a>
            @endcan
        @endif

        <x-slot name="description">
            @if (isset($description))
                {{ $description }}
            @elseif (isset($this->declarationNumber) && $this->declarationNumber)
                <div class="mt-1 inline-flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 px-3 py-1.5 text-sm font-semibold text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    @icon('file-text', 'w-4 h-4 text-gray-400')
                    Декларація №{{ $this->declarationNumber }}
                </div>
            @endif
        </x-slot>

        @if (!$hideNavigation)
            <x-slot name="navigation">
                <div class="space-y-1">
                    <div class="summary-nav-row">
                        <a
                            href="{{ route("$routePrefix.patient-data", [legalEntity(), $routeParamKey => $recordId]) }}"
                            class="summary-tab {{ ($activeTab === 'patient-data' || request()->routeIs("$routePrefix.patient-data")) ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.patient_data') }}
                        </a>

                        @if ($personId)
                            @can('view', PersonVerificationDetail::class)
                                <a
                                    href="{{ route('persons.verification', [legalEntity(), 'person' => $personId]) }}"
                                    class="summary-tab {{ ($activeTab === 'verification' || request()->routeIs('persons.verification')) ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                                >
                                    {{ __('patients.verification') }}
                                </a>
                            @endcan
                        @endif

                        @can('view', Person::class)
                            <a
                                href="{{ route("$routePrefix.summary", [legalEntity(), $routeParamKey => $recordId]) }}"
                                class="summary-tab {{ request()->routeIs("$routePrefix.summary") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                            >
                                {{ __('patients.summary') }}
                            </a>
                        @endcan

                        <a
                            href="{{ route("$routePrefix.episodes", [legalEntity(), $routeParamKey => $recordId]) }}"
                            class="summary-tab {{ request()->routeIs("$routePrefix.episodes") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('episodes.plural') }}
                        </a>

                        <a
                            href="{{ route("$routePrefix.observations", [legalEntity(), $routeParamKey => $recordId]) }}"
                            class="summary-tab {{ request()->routeIs("$routePrefix.observations") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.observation') }}
                        </a>

                        <a
                            href="{{ route("$routePrefix.immunizations", [legalEntity(), $routeParamKey => $recordId]) }}"
                            class="summary-tab {{ request()->routeIs("$routePrefix.immunization") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.immunizations') }}
                        </a>

                        <a
                            href="{{ route("$routePrefix.conditions", [legalEntity(), $routeParamKey => $recordId]) }}"
                            class="summary-tab {{ request()->routeIs("$routePrefix.condition") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.conditions') }}
                        </a>

                        @if ($prepersonId)
                            <a
                                href="javascript:void(0)"
                                class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                            >
                                {{ __('patients.prescriptions') }}
                            </a>
                        @else
                            <a
                                href="{{ route('persons.medication-requests', [legalEntity(), 'person' => $personId]) }}"
                                class="summary-tab {{ request()->routeIs('persons.medication-requests') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                            >
                                {{ __('patients.prescriptions') }}
                            </a>
                        @endif

                        <a
                            href="{{ route("$routePrefix.diagnostic-reports", [legalEntity(), $routeParamKey => $recordId]) }}"
                            class="summary-tab {{ request()->routeIs("$routePrefix.diagnostic-reports") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.diagnostic_reports') }}
                        </a>
                    </div>

                    <div class="summary-nav-row">
                        <a
                            href="{{ route("$routePrefix.clinical-impressions", [legalEntity(), $routeParamKey => $recordId]) }}"
                            class="summary-tab {{ request()->routeIs("$routePrefix.clinical-impressions") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.clinical_impressions') }}
                        </a>

                        <a
                            href="javascript:void(0)"
                            class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                        >
                            {{ __('patients.medical_reports') }}
                        </a>

                        @if ($prepersonId)
                            <a
                                href="javascript:void(0)"
                                class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                            >
                                {{ __('patients.referrals') }}
                            </a>
                        @else
                            <a
                                href="{{ route('persons.referrals', [legalEntity(), 'person' => $personId]) }}"
                                class="summary-tab {{ request()->routeIs('persons.referrals') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                            >
                                {{ __('patients.referrals') }}
                            </a>
                        @endif

                        @if ($prepersonId)
                            <a
                                href="javascript:void(0)"
                                class="summary-tab summary-tab-inactive cursor-not-allowed opacity-60"
                            >
                                {{ __('patients.care_plans') }}
                            </a>
                        @else
                            <a
                                href="{{ route('persons.care-plans', [legalEntity(), 'person' => $personId]) }}"
                                class="summary-tab {{ request()->routeIs('persons.care-plans') ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                            >
                                {{ __('patients.care_plans') }}
                            </a>
                        @endif

                        <a
                            href="{{ route("$routePrefix.encounters", [legalEntity(), $routeParamKey => $recordId]) }}"
                            class="summary-tab {{ request()->routeIs("$routePrefix.encounters") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.encounters') }}
                        </a>

                        <a
                            href="{{ route("$routePrefix.procedures", [legalEntity(), $routeParamKey => $recordId]) }}"
                            class="summary-tab {{ request()->routeIs("$routePrefix.procedures") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('patients.procedures') }}
                        </a>

                        <a
                            href="{{ route("$routePrefix.devices", [legalEntity(), $routeParamKey => $recordId]) }}"
                            class="summary-tab {{ request()->routeIs("$routePrefix.devices") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                        >
                            {{ __('care-plan.medical_devices') }}
                        </a>

                        {{-- Only primary and outpatient care may issue medical conclusions, so the
                             tab stays hidden elsewhere rather than leading to a 403. --}}
                        @can('viewAny', \App\Models\MedicalEvents\Sql\Composition::class)
                            <a href="{{ route("$routePrefix.compositions", [legalEntity(), $routeParamKey => $recordId]) }}"
                               class="summary-tab {{ request()->routeIs("$routePrefix.compositions") ? 'summary-tab-active' : 'summary-tab-inactive' }}"
                            >
                                {{ __('compositions.title') }}
                            </a>
                        @endcan

                        <div class="flex-1"></div>
                    </div>
                </div>
            </x-slot>
        @endif
    </x-header-navigation>

    {{ $slot }}
    <livewire:components.x-message :listen-async="true" :key="time()" />
</section>
