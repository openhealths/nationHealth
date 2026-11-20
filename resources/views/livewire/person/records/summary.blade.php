@php
    use App\Enums\JobStatus;
    use App\Models\MedicalEvents\Sql\Encounter;
    use App\Livewire\Person\Records\PatientSummary;
    use App\View\PatientSummaryCards;
@endphp

<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :showLegacyMessages="false"
>
    <livewire:components.x-message
        :consume-messages="true"
        :key="(string) str()->uuid()"
    />
    <x-slot name="headerActions">
        @can ('create', Encounter::class)
            <a
                href="{{
                    $prepersonId
                    ? route('prepersons.encounters.create', [legalEntity(), 'preperson' => $prepersonId])
                    : route('persons.encounters.create', [legalEntity(), 'person' => $personId])
                }}"
                class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon ('plus', 'w-4 h-4')
                {{ __('encounters.new') }}
            </a>
        @endcan
    </x-slot>

    <div class="breadcrumb-form shift-content space-y-6 p-4">
        @if ($personId)
            <div class="form-row-3 mt-5 items-center">
                <div class="form-group group relative">
                    <select
                        wire:model.live="selectedMergedPerson"
                        name="selectedMergedPerson"
                        id="selectedMergedPerson"
                        class="input-select peer w-full"
                    >
                        <option value="" selected>
                            {{ __('forms.select') }}
                        </option>
                        @foreach ($mergedPersons as $mergedPerson)
                            <option value="{{ $mergedPerson['uuid'] }}">
                                {{ $mergedPerson['name'] }}
                            </option>
                        @endforeach
                    </select>

                    <label
                        for="selectedMergedPerson"
                        class="label"
                        >{{ __('preperson.merged_persons') }}</label
                    >
                </div>

                <div class="form-group group flex items-center">
                    <button
                        type="button"
                        wire:click="searchMergedPersons"
                        class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-700 dark:text-blue-400"
                    >
                        @icon ('refresh', 'w-4 h-4')
                        <span>{{ __('forms.synchronise_with_eHealth') }}</span>
                    </button>
                </div>
            </div>
        @endif
        @php
            $cardFormatter = new PatientSummaryCards($this->dictionaries);
            $navItems = [
                'episodes' => ['label' => 'episodes.plural', 'icon' => 'book', 'load' => 'getEpisodes', 'sync' => 'syncEpisodes', 'entity' => PatientSummary::ENTITY_TYPE_EPISODE],
                'encounters' => ['label' => 'encounters.plural', 'icon' => 'users', 'load' => 'getEncounters', 'sync' => 'syncEncounters', 'entity' => PatientSummary::ENTITY_TYPE_ENCOUNTER],
                'clinicalImpressions' => ['label' => 'clinical-impressions.plural', 'icon' => 'check', 'load' => 'getClinicalImpressions', 'sync' => 'syncClinicalImpressions', 'entity' => PatientSummary::ENTITY_TYPE_CLINICAL_IMPRESSION],
                'immunizations' => ['label' => 'immunizations.plural', 'icon' => 'shield', 'load' => 'getImmunizations', 'sync' => 'syncImmunizations', 'entity' => PatientSummary::ENTITY_TYPE_IMMUNIZATION],
                'observations' => ['label' => 'observations.label', 'icon' => 'heart', 'load' => 'getObservations', 'sync' => 'syncObservations', 'entity' => PatientSummary::ENTITY_TYPE_OBSERVATION],
                'diagnoses' => ['label' => 'patients.diagnoses', 'icon' => 'file', 'load' => 'getDiagnoses', 'sync' => 'syncDiagnoses'],
                'conditions' => ['label' => 'conditions.plural', 'icon' => 'file-minus', 'load' => 'getConditions', 'sync' => 'syncConditions', 'entity' => PatientSummary::ENTITY_TYPE_CONDITION],
                'diagnosticReports' => ['label' => 'diagnostic-reports.plural', 'icon' => 'activity', 'load' => 'getDiagnosticReports', 'sync' => 'syncDiagnosticReports', 'entity' => PatientSummary::ENTITY_TYPE_DIAGNOSTIC_REPORT],
                'care-plans' => ['label' => 'patients.care_plans', 'icon' => 'file-text'],
                'devices' => ['label' => 'patient-summary.associated_devices', 'icon' => 'monitor', 'load' => 'getDevices', 'sync' => 'syncDevices', 'entity' => PatientSummary::ENTITY_TYPE_DEVICE],
                'device-association' => ['label' => 'device-associations.label', 'icon' => 'boxicons-plug-connect-filled'],
                'detected-issue' => ['label' => 'detected-issues.label', 'icon' => 'alert-octagon'],
                'device-dispense' => ['label' => 'device-dispenses.label', 'icon' => 'solid-notes-medical'],
                'specimens' => ['label' => 'specimens.sidebar_title', 'icon' => 'sample-outline'],
                'medication-statements' => ['label' => 'patient-summary.medication_statements', 'icon' => 'pill-outline'],
                'allergies' => ['label' => 'patient-summary.allergies', 'icon' => 'alert'],
                'risk-assessments' => ['label' => 'patient-summary.risk_assessments', 'icon' => 'alert-octagon'],
                'procedures' => ['label' => 'procedures.plural', 'icon' => 'settings', 'load' => 'getProcedures', 'sync' => 'syncProcedures'],
                'prescriptions' => ['label' => 'patients.prescriptions', 'icon' => 'pill-outline'],
                'medical-reports' => ['label' => 'patients.medical_reports', 'icon' => 'book-open'],
                'outgoing-referrals' => ['label' => 'patients.referrals', 'icon' => 'referrals'],
                'device-requests' => ['label' => 'device-requests.labels.patient_tab', 'icon' => 'equipment'],
            ];

            $actionSections = [];
            foreach ($navItems as $section => $item) {
                if (isset($item['load'])) {
                    $actionSections[$item['load']] = $actionSections[$item['sync']] = $section;
                }
            }
        @endphp

        <div
            x-data="{
                activeSection: '',
                viewedRecord: null,
                showSummaryDetails: false,
                failedRequests: {},
                stopWatching: null,
                init() {
                    const sections = @js($actionSections);
                    this.stopWatching = this.$wire.intercept(({ action, onSend, onError, onFailure }) => {
                        const id = action.name === 'loadMore' ? action.params[0] : sections[action.name];
                        if (!id) return;

                        onSend(() => delete this.failedRequests[id]);
                        const fail = () => { this.failedRequests[id] = action.name; };
                        onFailure(fail);
                        onError(({ response, preventDefault }) => {
                            if (response.status >= 500) {
                                preventDefault();
                                fail();
                            }
                        });
                    });
                },
                destroy() { this.stopWatching?.(); },
                goToSection(id) {
                    if (this.activeSection !== id) document.getElementById('summary-toggle-' + id).click();
                    this.$nextTick(() => document.getElementById('block-' + id).scrollIntoView({ behavior: 'smooth', block: 'start' }));
                },
            }"
            class="flex flex-col gap-8 lg:flex-row lg:gap-12"
        >
            <div class="min-w-0 flex-1 space-y-4">
                @foreach ($navItems as $id => $item)
                    <section
                        id="block-{{ $id }}"
                        aria-labelledby="summary-toggle-{{ $id }}"
                        class="scroll-mt-16 rounded-xl bg-white dark:border-gray-700 dark:bg-gray-800"
                        :class="activeSection === '{{ $id }}' ? 'summary-section-active' : 'summary-section-inactive'"
                    >
                        <div class="flex items-center gap-2 pr-5">
                            <button
                                id="summary-toggle-{{ $id }}"
                                type="button"
                                @if (isset($item['load'])) wire:click.once="{{ $item['load'] }}" @endif
                                @click="activeSection = activeSection === '{{ $id }}' ? '' : '{{ $id }}'"
                                :aria-expanded="activeSection === '{{ $id }}'"
                                aria-controls="summary-content-{{ $id }}"
                                class="flex min-w-0 flex-1 items-center gap-4 p-5 text-left text-[17px] font-semibold text-gray-900 dark:text-gray-100"
                            >
                                @icon ($item['icon'], 'h-6 w-6 shrink-0')
                                <span class="min-w-0 flex-1 break-words">{{ __($item['label']) }}</span>
                                <span
                                    class="shrink-0 text-gray-400 transition-transform duration-300 dark:text-gray-500"
                                    :class="activeSection === '{{ $id }}' ? '' : '-rotate-90'"
                                >
                                    @icon ('chevron-down', 'w-5 h-5')
                                </span>
                            </button>
                            @isset ($item['sync'])
                                @php
                                    $entity = $item['entity'] ?? null;
                                    $syncing = $entity && $this->isEntitySyncing($entity);
                                    $status = $syncStatuses[$entity] ?? null;
                                    $retry = in_array($status instanceof JobStatus ? $status->value : $status, [JobStatus::PAUSED->value, JobStatus::FAILED->value], true);
                                    $syncLabel = __($retry ? 'forms.sync_retry' : 'forms.synchronise_with_eHealth');
                                @endphp
                                <button
                                    type="button"
                                    x-show="activeSection === '{{ $id }}'"
                                    x-cloak
                                    wire:click="{{ $item['sync'] }}"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $item['sync'] }}"
                                    @disabled($syncing)
                                    aria-label="{{ $syncLabel }}"
                                    title="{{ $syncLabel }}"
                                    class="flex shrink-0 items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700 disabled:cursor-not-allowed disabled:text-gray-400 dark:text-blue-400"
                                >
                                    @icon ('refresh', 'w-4 h-4')
                                    <span class="hidden sm:inline">{{ $syncLabel }}</span>
                                </button>
                            @endisset
                        </div>

                        <div
                            id="summary-content-{{ $id }}"
                            x-show="activeSection === '{{ $id }}'"
                            style="display: none"
                            class="px-5 pb-5"
                        >
                            @isset ($item['load'])
                                @php
                                    $sectionRecords = $this->{$id};
                                    $loadingActions = $item['load'].','.$item['sync'].",loadMore('$id')";
                                @endphp
                                <p wire:loading wire:target="{{ $loadingActions }}" style="display: none" role="status" class="text-sm text-gray-500 dark:text-gray-400">{{ __('forms.loading') }}</p>
                                <div x-cloak x-show="failedRequests['{{ $id }}']" role="alert" class="mb-3 text-sm text-gray-700 dark:text-gray-300">
                                    {{ __('patient-summary.request_failed') }}
                                    <button
                                        x-show="failedRequests['{{ $id }}'] === '{{ $item['load'] }}'"
                                        type="button"
                                        wire:click="{{ $item['load'] }}"
                                        class="ml-2 text-blue-600 underline dark:text-blue-400"
                                    >{{ __('patient-summary.retry') }}</button>
                                </div>
                                @if ($sectionRecords === [])
                                    <div x-show="!failedRequests['{{ $id }}']" wire:loading.remove wire:target="{{ $loadingActions }}">
                                        <x-patient.empty-records />
                                    </div>
                                @else
                                    @include ($id === 'episodes' ? 'livewire.person.records.parts.episodes' : 'livewire.person.records.parts.summary-records', [
                                        'summaryCards' => $cardFormatter->collection($id, $sectionRecords, $personId, $prepersonId),
                                    ])
                                @endif

                                @if ($hasMore[$id] ?? false)
                                    <div class="mt-4 flex justify-start">
                                        <button
                                            type="button"
                                            wire:click="loadMore('{{ $id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $loadingActions }}"
                                            class="item-add"
                                        >
                                            {{ __('general.show_more') }}
                                        </button>
                                    </div>
                                @endif
                            @else
                                <p data-summary-unavailable class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ __('patient-summary.section_unavailable') }}
                                </p>
                            @endisset
                        </div>
                    </section>
                @endforeach
            </div>

            <div class="sticky top-6 mt-4 w-full shrink-0 space-y-1 self-start lg:mt-0 lg:w-[320px]">
                @foreach ($navItems as $id => $item)
                    <button
                        class="summary-sidebar-btn text-left"
                        @click="goToSection('{{ $id }}')"
                        type="button"
                        :class="activeSection === '{{ $id }}' ? 'summary-sidebar-btn-active' : 'summary-sidebar-btn-inactive'"
                    >
                        @icon ($item['icon'], 'h-5 w-5 shrink-0')
                        <span class="min-w-0 break-words">{{ __($item['label']) }}</span>
                    </button>
                @endforeach
            </div>
            <x-dialog-drawer
                x-model="showSummaryDetails"
                maxWidth="3/5"
                overlayWidth="100%"
                :noTeleport="true"
                onCloseClick="showSummaryDetails = false"
                zIndex="50"
            >
                <x-slot name="title">
                    {{ __('forms.view_details') }}
                </x-slot>
                <button
                    type="button"
                    @click="showSummaryDetails = false"
                    class="button-primary-outline mb-4"
                >
                    {{ __('forms.close') }}
                </button>
                <template x-if="viewedRecord">
                    <div>
                        <h3
                            class="mb-4 text-lg font-semibold text-gray-900 dark:text-white"
                            x-text="viewedRecord.title"
                        ></h3>
                        <p
                            class="mb-4 text-sm text-gray-700 dark:text-gray-300"
                            x-text="
                                viewedRecord.statusLabel +
                                ': ' +
                                viewedRecord.status
                            "
                        ></p>
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <template
                                x-for="
                                    (field, index) in
                                    [
                                        ...viewedRecord.fields,
                                        ...viewedRecord.ids,
                                    ]
                                "
                                :key="index"
                            >
                                <div class="min-w-0">
                                    <dt
                                        class="record-inner-label"
                                        x-text="field.label"
                                    ></dt>
                                    <dd
                                        class="record-inner-subvalue break-words whitespace-pre-line"
                                        x-text="field.value"
                                    ></dd>
                                </div>
                            </template>
                        </dl>
                        <template
                            x-for="(group, index) in viewedRecord.groups"
                            :key="index"
                        >
                            <section
                                class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-700"
                            >
                                <h4
                                    class="record-inner-label mb-3"
                                    x-text="group.label"
                                ></h4>
                                <dl
                                    class="grid grid-cols-1 gap-4 sm:grid-cols-2"
                                >
                                    <template
                                        x-for="
                                            (field, fieldIndex) in group.fields
                                        "
                                        :key="fieldIndex"
                                    >
                                        <div class="min-w-0">
                                            <dt
                                                class="record-inner-label"
                                                x-text="field.label"
                                            ></dt>
                                            <dd
                                                class="record-inner-subvalue break-words whitespace-pre-line"
                                                x-text="field.value"
                                            ></dd>
                                        </div>
                                    </template>
                                </dl>
                            </section>
                        </template>
                    </div>
                </template>
            </x-dialog-drawer>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
