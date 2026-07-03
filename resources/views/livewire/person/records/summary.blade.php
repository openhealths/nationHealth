@php
    use App\Enums\JobStatus;
    use App\Models\MedicalEvents\Sql\Encounter;
    use App\Livewire\Person\Records\PatientSummary;
@endphp

<x-layouts.patient :personId="$personId" :prepersonId="$prepersonId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', Encounter::class)
            <a href="{{ $prepersonId
                ? route('prepersons.encounter.create', [legalEntity(), 'preperson' => $prepersonId])
                : route('encounter.create', [legalEntity(), 'person' => $personId]) }}"
               class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.starts_interacting') }}
            </a>
        @endcan

        <button type="button"
                class="button-primary-outline whitespace-nowrap px-5 py-2 text-sm"
        >
            {{ __('patients.data_access') }}
        </button>

        <button type="button"
                class="button-sync flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('patients.sync_ehealth_data') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form p-4 shift-content">
        @php
            $navItems = [
                ['id' => 'episodes', 'action' => 'getEpisodes', 'syncAction' => 'syncEpisodes', 'label' => __('patients.episodes'), 'icon' => 'book', 'syncEntity' => PatientSummary::ENTITY_TYPE_EPISODE],
                ['id' => 'encounters', 'action' => 'getEncounters', 'syncAction' => 'syncEncounters', 'label' => __('patients.encounters'), 'icon' => 'users', 'syncEntity' => PatientSummary::ENTITY_TYPE_ENCOUNTER],
                ['id' => 'clinicalImpressions', 'action' => 'getClinicalImpressions', 'syncAction' => 'syncClinicalImpressions', 'label' => __('patients.clinical_impressions'), 'icon' => 'check', 'syncEntity' => PatientSummary::ENTITY_TYPE_CLINICAL_IMPRESSION],
                ['id' => 'immunizations', 'action' => 'getImmunizations', 'syncAction' => 'syncImmunizations', 'label' => __('patients.immunizations'), 'icon' => 'shield', 'syncEntity' => PatientSummary::ENTITY_TYPE_IMMUNIZATION],
                ['id' => 'observations', 'action' => 'getObservations', 'syncAction' => 'syncObservations', 'label' => __('patients.observation'), 'icon' => 'heart', 'syncEntity' => PatientSummary::ENTITY_TYPE_OBSERVATION],
                ['id' => 'diagnoses', 'action' => 'getDiagnoses', 'syncAction' => 'syncDiagnoses', 'label' => __('patients.diagnoses'), 'icon' => 'file', 'syncEntity' => ''],
                ['id' => 'conditions', 'action' => 'getConditions', 'syncAction' => 'syncConditions', 'label' => __('patients.conditions'), 'icon' => 'file-minus', 'syncEntity' => PatientSummary::ENTITY_TYPE_CONDITION],
                ['id' => 'diagnosticReports', 'action' => 'getDiagnosticReports', 'syncAction' => 'syncDiagnosticReports', 'label' => __('patients.diagnostic_reports'), 'icon' => 'activity', 'syncEntity' => PatientSummary::ENTITY_TYPE_DIAGNOSTIC_REPORT],
                ['id' => 'procedures', 'action' => 'getProcedures', 'syncAction' => 'syncProcedures', 'label' => __('patients.procedures'), 'icon' => 'settings', 'syncEntity' => ''],
                ['id' => 'allergies', 'action' => 'syncAllergyIntolerances', 'syncAction' => 'syncAllergyIntolerances', 'label' => __('patients.allergies'), 'icon' => 'alert', 'syncEntity' => ''],
                ['id' => 'risk_assessments', 'action' => 'syncRiskAssessments', 'syncAction' => 'syncRiskAssessments', 'label' => __('patients.risk_assessments'), 'icon' => 'alert-octagon', 'syncEntity' => ''],
                ['id' => 'devices', 'action' => 'syncDevices', 'syncAction' => 'syncDevices', 'label' => __('patients.devices'), 'icon' => 'equipment', 'syncEntity' => ''],
                ['id' => 'medicines', 'action' => 'syncMedicationStatements', 'syncAction' => 'syncMedicationStatements', 'label' => __('patients.medicines'), 'icon' => 'pill-outline', 'syncEntity' => ''],
            ];
        @endphp

        <div x-data="{ activeSection: '' }" class="flex flex-col lg:flex-row gap-8 lg:gap-12">
            <div class="flex-1 space-y-4">
                @foreach($navItems as $item)
                    <div id="block-{{ $item['id'] }}"
                         class="bg-white dark:bg-gray-800 rounded-xl scroll-mt-8"
                         :class="activeSection === '{{ $item['id'] }}' ? 'summary-section-active' : 'summary-section-inactive'"
                    >
                        <button @if($item['action']) wire:click.once="{{ $item['action'] }}" @endif
                        @click="activeSection = activeSection === '{{ $item['id'] }}' ? '' : '{{ $item['id'] }}'"
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

                            <div class="flex items-center gap-4 text-sm font-medium">
                                @php
                                    $isEntitySync = $item['syncEntity'] ? $this->isEntitySyncing($item['syncEntity']) : false;
                                    $entitySyncStatus = $item['syncEntity'] ? $this->syncStatuses[$item['syncEntity']] ?? null : null;
                                    $isRetryable = $entitySyncStatus === JobStatus::PAUSED->value || $entitySyncStatus === JobStatus::FAILED->value;
                                @endphp

                                <span x-show="activeSection === '{{ $item['id'] }}'"
                                      @click.stop="@if(!$isEntitySync) $wire.{{ $item['syncAction'] }}() @endif"
                                      class="hidden sm:flex items-center gap-1.5 transition-colors
                                      @if($isEntitySync) text-gray-400 dark:text-gray-500 cursor-not-allowed @else text-blue-600 dark:text-blue-400 cursor-pointer hover:text-blue-700 dark:hover:text-blue-300 @endif"
                                >
                                    @icon('refresh', 'w-4 h-4')
                                    <span>{{ $item['syncEntity'] && $isRetryable ? __('forms.sync_retry') : __('patients.sync_ehealth_data') }}</span>
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
                                @include('livewire.person.records.parts.episodes', ['episodes' => $episodes])
                            @elseif($item['id'] === 'encounters')
                                @include('livewire.person.records.parts.encounters')
                            @elseif($item['id'] === 'clinicalImpressions')
                                @include('livewire.person.records.parts.clinical-impressions')
                            @elseif($item['id'] === 'immunizations')
                                @include('livewire.person.records.parts.immunizations')
                            @elseif($item['id'] === 'observations')
                                @include('livewire.person.records.parts.observations')
                            @elseif($item['id'] === 'diagnoses')
                                @include('livewire.person.records.parts.diagnoses')
                            @elseif($item['id'] === 'conditions')
                                @include('livewire.person.records.parts.conditions')
                            @elseif($item['id'] === 'diagnosticReports')
                                @include('livewire.person.records.parts.diagnostic-reports')
                            @elseif($item['id'] === 'allergies')
                                @include('livewire.person.records.parts.allergies')
                            @elseif($item['id'] === 'risk_assessments')
                                @include('livewire.person.records.parts.risk-assessments')
                            @elseif($item['id'] === 'devices')
                                @include('livewire.person.records.parts.devices')
                            @elseif($item['id'] === 'medicines')
                                @include('livewire.person.records.parts.medicines')
                            @elseif($item['id'] === 'procedures')
                                @include('livewire.person.records.parts.procedures')
                            @else
                                <div
                                    class="py-12 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-dashed border-gray-200 dark:border-gray-700 mt-2">
                                    <div
                                        class="flex flex-col items-center justify-center text-gray-500 dark:text-gray-400">
                                        <div
                                            class="w-8 h-8 mb-4 opacity-50 flex items-center justify-center [&>svg]:w-full [&>svg]:h-full">
                                            @icon($item['icon'])
                                        </div>
                                        <p class="text-[15px] font-medium">Дані відсутні</p>
                                        <p class="text-[13px] mt-1 text-gray-400">В цьому розділі поки немає
                                            інформації</p>
                                    </div>
                                </div>
                            @endif
                            
                            @if($hasMore[$item['id']] ?? false)
                                <div class="flex justify-start mt-4">
                                    <button type="button"
                                            wire:click="loadMore('{{ $item['id'] }}')"
                                            class="item-add"
                                    >
                                        {{ __('patients.show_more') }}
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Right Sidebar Navigation -->
            <div class="w-full lg:w-[320px] shrink-0 space-y-1 mt-4 lg:mt-0 sticky top-6 self-start">
                @foreach($navItems as $item)
                    <button class="summary-sidebar-btn"
                            @click="
                                activeSection = '{{ $item['id'] }}';
                                setTimeout(() => { document.getElementById('block-{{ $item['id'] }}').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
                            "
                            type="button"
                            :class="activeSection === '{{ $item['id'] }}' ? 'summary-sidebar-btn-active' : 'summary-sidebar-btn-inactive'"
                            @if($item['action']) wire:click.once="{{ $item['action'] }}" @endif
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

    <x-forms.loading/>
</x-layouts.patient>
