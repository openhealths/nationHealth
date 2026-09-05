@php
    use App\Enums\JobStatus;
    use App\Models\MedicalEvents\Sql\Encounter;
    use App\Livewire\Person\Records\PatientSummary;
@endphp

<x-layouts.patient :personId="$personId" :prepersonId="$prepersonId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', Encounter::class)
            <a
                href="{{
                    $prepersonId
                    ? route('prepersons.encounter.create', [legalEntity(), 'preperson' => $prepersonId])
                    : route('encounter.create', [legalEntity(), 'person' => $personId])
                }}"
                class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.starts_interacting') }}
            </a>
        @endcan

        <button type="button" class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap">
            {{ __('patients.data_access') }}
        </button>

        <button type="button" class="button-sync flex items-center gap-2 px-5 py-2 text-sm whitespace-nowrap shadow-sm">
            @icon('refresh', 'w-4 h-4')
            {{ __('forms.synchronise_with_eHealth') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form shift-content space-y-6 p-4">
        @if ($personId)
            <div class="form-row-3 mt-5 items-center">
                <div class="form-group group relative">
                    <select class="input-select peer w-full">
                        <option value="" selected>{{ __('forms.empty') }}</option>
                        @foreach ($mergedPersons as $externalId)
                            <option value="{{ $loop->index }}">
                                {{ __('preperson.merged_patient', ['number' => $externalId]) }}
                            </option>
                        @endforeach
                    </select>

                    <label class="label"> {{ __('preperson.merged_persons') }} </label>
                </div>

                <div class="form-group group flex items-center">
                    <button
                        type="button"
                        wire:click="searchMergedPersons"
                        class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-700 dark:text-blue-400"
                    >
                        @icon('refresh', 'w-4 h-4')
                        <span>{{ __('forms.synchronise_with_eHealth') }}</span>
                    </button>
                </div>
            </div>
        @endif
        @php
            $navItems = [
                ['id' => 'episodes', 'action' => 'getEpisodes', 'syncAction' => 'syncEpisodes', 'label' => __('episodes.plural'), 'icon' => 'book', 'syncEntity' => PatientSummary::ENTITY_TYPE_EPISODE],
                ['id' => 'encounters', 'action' => 'getEncounters', 'syncAction' => 'syncEncounters', 'label' => __('encounters.plural'), 'icon' => 'users', 'syncEntity' => PatientSummary::ENTITY_TYPE_ENCOUNTER],
                ['id' => 'clinicalImpressions', 'action' => 'getClinicalImpressions', 'syncAction' => 'syncClinicalImpressions', 'label' => __('clinical-impressions.plural'), 'icon' => 'check', 'syncEntity' => PatientSummary::ENTITY_TYPE_CLINICAL_IMPRESSION],
                ['id' => 'immunizations', 'action' => 'getImmunizations', 'syncAction' => 'syncImmunizations', 'label' => __('immunizations.plural'), 'icon' => 'shield', 'syncEntity' => PatientSummary::ENTITY_TYPE_IMMUNIZATION],
                ['id' => 'observations', 'action' => 'getObservations', 'syncAction' => 'syncObservations', 'label' => __('observations.label'), 'icon' => 'heart', 'syncEntity' => PatientSummary::ENTITY_TYPE_OBSERVATION],
                ['id' => 'diagnoses', 'action' => 'getDiagnoses', 'syncAction' => 'syncDiagnoses', 'label' => __('patients.diagnoses'), 'icon' => 'file', 'syncEntity' => ''],
                ['id' => 'conditions', 'action' => 'getConditions', 'syncAction' => 'syncConditions', 'label' => __('conditions.plural'), 'icon' => 'file-minus', 'syncEntity' => PatientSummary::ENTITY_TYPE_CONDITION],
                ['id' => 'diagnosticReports', 'action' => 'getDiagnosticReports', 'syncAction' => 'syncDiagnosticReports', 'label' => __('diagnostic-reports.plural'), 'icon' => 'activity', 'syncEntity' => PatientSummary::ENTITY_TYPE_DIAGNOSTIC_REPORT],
                ['id' => 'procedures', 'action' => 'getProcedures', 'syncAction' => 'syncProcedures', 'label' => __('procedures.plural'), 'icon' => 'settings', 'syncEntity' => ''],
                ['id' => 'allergies', 'action' => 'syncAllergyIntolerances', 'syncAction' => 'syncAllergyIntolerances', 'label' => __('patients.allergies'), 'icon' => 'alert', 'syncEntity' => ''],
                ['id' => 'risk_assessments', 'action' => 'syncRiskAssessments', 'syncAction' => 'syncRiskAssessments', 'label' => __('patients.risk_assessments'), 'icon' => 'alert-octagon', 'syncEntity' => ''],
                ['id' => 'devices', 'action' => 'syncDevices', 'syncAction' => 'syncDevices', 'label' => __('patients.devices'), 'icon' => 'equipment', 'syncEntity' => ''],
                ['id' => 'medicines', 'action' => 'syncMedicationStatements', 'syncAction' => 'syncMedicationStatements', 'label' => __('patients.medicines'), 'icon' => 'pill-outline', 'syncEntity' => ''],
            ];
        @endphp

        <div x-data="{ activeSection: '' }" class="flex flex-col gap-8 lg:flex-row lg:gap-12">
            <div class="flex-1 space-y-4">
                @foreach ($navItems as $item)
                    <div
                        id="block-{{ $item['id'] }}"
                        class="scroll-mt-8 rounded-xl bg-white dark:bg-gray-800"
                        :class="activeSection === '{{ $item['id'] }}' ? 'summary-section-active' : 'summary-section-inactive'"
                    >
                        <button
                            @if ($item['action']) wire:click.once="{{ $item['action'] }}" @endif
                            @click="activeSection = activeSection === '{{ $item['id'] }}' ? '' : '{{ $item['id'] }}'"
                            type="button"
                            class="flex w-full items-center justify-between p-5 focus:outline-none"
                        >
                            <div class="flex items-center gap-4 text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center text-gray-900 dark:text-gray-100">
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

                                <span
                                    x-show="activeSection === '{{ $item['id'] }}'"
                                    @click.stop="@if(!$isEntitySync) $wire.{{ $item['syncAction'] }}() @endif"
                                    class="hidden sm:flex items-center gap-1.5 transition-colors
                                      @if($isEntitySync) text-gray-400 dark:text-gray-500 cursor-not-allowed @else text-blue-600 dark:text-blue-400 cursor-pointer hover:text-blue-700 dark:hover:text-blue-300 @endif"
                                >
                                    @icon('refresh', 'w-4 h-4')
                                    <span>{{ $item['syncEntity'] && $isRetryable ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
                                </span>
                                <div
                                    class="shrink-0 text-gray-400 transition-transform duration-300 dark:text-gray-500"
                                    :class="activeSection === '{{ $item['id'] }}' ? '' : '-rotate-90'"
                                >
                                    @icon('chevron-down', 'w-5 h-5')
                                </div>
                            </div>
                        </button>

                        <div x-show="activeSection === '{{ $item['id'] }}'" style="display: none" class="px-5 pb-5">
                            @if ($item['id'] === 'episodes')
                                @include('livewire.person.records.parts.episodes', ['episodes' => $episodes])
                            @elseif ($item['id'] === 'encounters')
                                @include('livewire.person.records.parts.encounters')
                            @elseif ($item['id'] === 'clinicalImpressions')
                                @include('livewire.person.records.parts.clinical-impressions')
                            @elseif ($item['id'] === 'immunizations')
                                @include('livewire.person.records.parts.immunizations')
                            @elseif ($item['id'] === 'observations')
                                @include('livewire.person.records.parts.observations')
                            @elseif ($item['id'] === 'diagnoses')
                                @include('livewire.person.records.parts.diagnoses')
                            @elseif ($item['id'] === 'conditions')
                                @include('livewire.person.records.parts.conditions')
                            @elseif ($item['id'] === 'diagnosticReports')
                                @include('livewire.person.records.parts.diagnostic-reports')
                            @elseif ($item['id'] === 'allergies')
                                @include('livewire.person.records.parts.allergies')
                            @elseif ($item['id'] === 'risk_assessments')
                                @include('livewire.person.records.parts.risk-assessments')
                            @elseif ($item['id'] === 'devices')
                                @include('livewire.person.records.parts.devices')
                            @elseif ($item['id'] === 'medicines')
                                @include('livewire.person.records.parts.medicines')
                            @elseif ($item['id'] === 'procedures')
                                @include('livewire.person.records.parts.procedures')
                            @else
                                <div class="mt-2 rounded-lg border border-dashed border-gray-200 bg-gray-50 py-12 dark:border-gray-700 dark:bg-gray-800/50">
                                    <div class="flex flex-col items-center justify-center text-gray-500 dark:text-gray-400">
                                        <div class="[&>svg]:w-full [&>svg]:h-full mb-4 flex h-8 w-8 items-center justify-center opacity-50">
                                            @icon($item['icon'])
                                        </div>
                                        <p class="text-[15px] font-medium">{{ __('forms.no_data') }}</p>
                                        <p class="mt-1 text-[13px] text-gray-400">
                                            В цьому розділі поки немає інформації
                                        </p>
                                    </div>
                                </div>
                            @endif

                            @if ($hasMore[$item['id']] ?? false)
                                <div class="mt-4 flex justify-start">
                                    <button type="button" wire:click="loadMore('{{ $item['id'] }}')" class="item-add">
                                        {{ __('patients.show_more') }}
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Right Sidebar Navigation -->
            <div class="sticky top-6 mt-4 w-full shrink-0 space-y-1 self-start lg:mt-0 lg:w-[320px]">
                @foreach ($navItems as $item)
                    <button
                        class="summary-sidebar-btn"
                        @click="
                                activeSection = '{{ $item['id'] }}';
                                setTimeout(() => { document.getElementById('block-{{ $item['id'] }}').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
                            "
                        type="button"
                        :class="activeSection === '{{ $item['id'] }}' ? 'summary-sidebar-btn-active' : 'summary-sidebar-btn-inactive'"
                        @if ($item['action']) wire:click.once="{{ $item['action'] }}" @endif
                    >
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center">
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
