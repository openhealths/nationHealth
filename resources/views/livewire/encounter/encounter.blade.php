@php
    use App\Livewire\Encounter\EncounterEdit;

    $patientName = $patientFullName ?? __('forms.patient');
    $title = __('encounters.label') . ' - ' . $patientName;
    $isReadonly = $this instanceof EncounterEdit && $this->isReadonly;
    $canCancelRecords = $this instanceof EncounterEdit && $this->canBeCancelled;

    $mainGroups = [
        ['id' => 'referral', 'label' => __('patients.referrals'), 'icon' => 'arrow-right', 'view' => 'livewire.encounter.parts.referral'],
        ['id' => 'main-data', 'label' => __('forms.main_information'), 'icon' => 'pie-chart', 'view' => 'livewire.encounter.parts.main-data'],
        ['id' => 'conditions', 'label' => __('patients.diagnoses'), 'icon' => 'file', 'view' => 'livewire.encounter.parts.conditions'],
        ['id' => 'reasons', 'label' => __('encounters.reasons_for_visit'), 'icon' => 'person', 'view' => 'livewire.encounter.parts.reasons'],
        ['id' => 'actions', 'label' => __('forms.actions'), 'icon' => 'check-box', 'view' => 'livewire.encounter.parts.actions'],
        ['id' => 'additional-data', 'label' => __('encounters.additional_data'), 'icon' => 'Edit3', 'view' => 'livewire.encounter.parts.additional-data'],
        ['id' => 'observations', 'label' => __('observations.label'), 'icon' => 'heart', 'view' => 'livewire.encounter.parts.observations', 'holdsCancellableRecords' => true],
        ['id' => 'immunizations', 'label' => __('immunizations.plural'), 'icon' => 'shield', 'view' => 'livewire.encounter.parts.immunizations', 'holdsCancellableRecords' => true],
        ['id' => 'procedures', 'label' => __('procedures.plural'), 'icon' => 'settings', 'view' => 'livewire.encounter.parts.procedures', 'holdsCancellableRecords' => true],
        ['id' => 'diagnostic-reports', 'label' => __('diagnostic-reports.plural'), 'icon' => 'activity', 'view' => 'livewire.encounter.parts.diagnostic-reports', 'holdsCancellableRecords' => true],
        ['id' => 'clinical-impressions', 'label' => __('clinical-impressions.plural'), 'icon' => 'check', 'view' => 'livewire.encounter.parts.clinical-impressions', 'holdsCancellableRecords' => true],
        ['id' => 'devices', 'label' => __('devices.label'), 'icon' => 'equipment', 'view' => 'livewire.encounter.parts.devices', 'holdsCancellableRecords' => true],
        ['id' => 'device-association', 'label' => __('device-associations.label'), 'icon' => 'boxicons-plug-connect-filled', 'view' => 'livewire.encounter.parts.device-association', 'holdsCancellableRecords' => true],
        ['id' => 'detected-issue', 'label' => __('detected-issues.label'), 'icon' => 'alert-octagon', 'view' => 'livewire.encounter.parts.detected-issue', 'holdsCancellableRecords' => true],
        ['id' => 'device-dispense', 'label' => 'Видачі медичних виробів', 'icon' => 'solid-notes-medical', 'view' => 'livewire.encounter.parts.device-dispense', 'holdsCancellableRecords' => true],
    ];

    $footerItems = [];

    $participantEmployeeNames = collect([...$this->employees, ...$this->diagnosticReportEmployees])
        ->filter(static fn (array $employee): bool => !empty($employee['uuid']))
        ->pluck('name', 'uuid')
        ->all();

    $escapeForAlpineAttribute = static function (mixed $value): string {
        return e(json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        ));
    };
@endphp

<x-layouts.patient
    :personId="$personId"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :title="$title"
    :breadcrumbs="[
        ['label' => __('general.home'), 'url' => route('dashboard', [legalEntity()])],
        ['label' => $patientName]
    ]"
>
    <x-slot name="headerActions">
        <div class="flex w-full justify-start lg:w-75">
            @if ($canCancelRecords)
                <div
                    class="relative inline-block"
                    x-data="{ openGroupActions: false }"
                    @click.outside="openGroupActions = false"
                >
                    <button
                        type="button"
                        @click="openGroupActions = ! openGroupActions"
                        class="button-primary-outline px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.group_actions') }}
                    </button>

                    <div
                        x-show="openGroupActions"
                        x-transition
                        x-cloak
                        class="absolute top-full left-0 z-10 mt-2 w-60 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-700"
                    >
                        <div class="py-1">
                            <button
                                type="button"
                                @click="openGroupActions = false"
                                wire:click="openRecordsCancellation"
                                class="dropdown-button !flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm transition-colors hover:bg-gray-50 dark:hover:bg-gray-600"
                            >
                                <span class="!text-red-500 dark:!text-red-400">
                                    @icon('close', 'w-4 h-4')
                                </span>
                                <span class="!text-red-500 dark:!text-red-400"> {{ __('forms.mark_as_error') }} </span>
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        @php
            $allBlockIds = array_column(array_merge($mainGroups, $footerItems), 'id');
            $initialActiveSections = isset($encounterId) ? [] : $allBlockIds;
        @endphp
        <div
            @scroll-to-error.window="
                setTimeout(() => {
                    const errorElement = $el.querySelector('.text-error, .is-invalid, [aria-invalid=\'true\']');
                    if (! errorElement) return;
                    const block = errorElement.closest('[id^=\'block-\']');
                    if (block) {
                        const sectionId = block.id.replace('block-', '');
                        if (! activeSections.includes(sectionId)) {
                            activeSections.push(sectionId);
                        }
                    }
                    $nextTick(() => {
                        errorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        const input =
                            errorElement.previousElementSibling ||
                            errorElement.closest('div')?.querySelector('input, select, textarea');
                        if (input && typeof input.focus === 'function') {
                            input.focus();
                        }
                    });
                }, 50)
            "
            x-data="{
                activeSections: {!! $escapeForAlpineAttribute($initialActiveSections) !!},
                typeCode: $wire.entangle('form.encounter.typeCode'),
                coAuthors: $wire.entangle('form.encounter.participant'),

                conditionPerformer: {
                    uuid: {!!
                        $escapeForAlpineAttribute(
                            auth()->user()
                                ->getEncounterWriterEmployee(
                                    data_get($this->form->encounter, 'classCode')
                                )
                                ?->uuid
                        )
                    !!},
                    name: {!! $escapeForAlpineAttribute($employeeFullName) !!},
                },

                participantSourceLabels: {
                    diagnosis: {!! $escapeForAlpineAttribute(__('patients.diagnosis_performer')) !!},
                    procedure: {!! $escapeForAlpineAttribute(__('procedures.performer')) !!},
                    diagnosticReport: {!! $escapeForAlpineAttribute(__('diagnostic-reports.performer')) !!},
                    participantEmployeeNames: {!! $escapeForAlpineAttribute($participantEmployeeNames) !!},
                },

                participantName(participant) {
                    const uuid = participant?.uuid ?? '';

                    return participant?.name || this.participantEmployeeNames[uuid] || (String(this.conditionPerformer.uuid) === String(uuid) ? this.conditionPerformer.name : uuid);
                },

                sortEncounterParticipants(participants = []) {
                    const normalizedParticipants = Array.isArray(participants) ? participants : [];

                    return [
                        ...normalizedParticipants.filter(participant => participant?.locked === true),
                        ...normalizedParticipants.filter(participant => participant?.locked !== true),
                    ];
                },

                init() {
                    const participants = (Array.isArray(this.coAuthors) ? this.coAuthors : [])
                        .map(participant => ({
                            ...participant,
                            uuid: participant?.uuid ?? '',
                            name: participant?.name ?? '',
                            locked: participant?.locked === true,
                            manual: participant?.manual ?? (participant?.locked !== true),
                            sources: Array.isArray(participant?.sources) ? participant.sources : [],
                        }));

                    this.coAuthors = this.sortEncounterParticipants(participants);
                },

                syncLocalEncounterParticipants(source, performers = []) {
                    const uniquePerformers = Array.from(
                        new Map(
                            (Array.isArray(performers) ? performers : [])
                                .filter(performer => performer?.uuid)
                                .map(performer => [String(performer.uuid), performer])
                        ).values()
                    );

                    let participants = (Array.isArray(this.coAuthors) ? this.coAuthors : [])
                        .map(participant => ({
                            ...participant,
                            uuid: participant?.uuid ?? '',
                            name: participant?.name ?? '',
                            locked: participant?.locked === true,
                            manual: participant?.manual ?? (participant?.locked !== true),
                            sources: Array.isArray(participant?.sources) ? [...participant.sources] : [],
                        }))
                        .map(participant => {
                            if (! participant.locked || ! participant.sources.includes(source)) {
                                return participant;
                            }

                            const sources = participant.sources.filter(participantSource => participantSource !== source);

                            if (sources.length) {
                                return { ...participant, sources };
                            }

                            return participant.manual ? { ...participant, locked: false, sources: [] } : null;
                        })
                        .filter(Boolean);

                    uniquePerformers.forEach(performer => {
                        const index = participants.findIndex(
                            participant => String(participant.uuid) === String(performer.uuid)
                        );

                        if (index === -1) {
                            participants.push({
                                uuid: performer.uuid,
                                name: performer.name || performer.uuid,
                                locked: true,
                                manual: false,
                                sources: [source],
                            });

                            return;
                        }

                        const participant = participants[index];

                        participants[index] = {
                            ...participant,
                            name: performer.name || participant.name || performer.uuid,
                            locked: true,
                            manual: participant.manual ?? (participant.locked !== true),
                            sources: Array.from(new Set([...(participant.sources ?? []), source])),
                        };
                    });

                    this.coAuthors = this.sortEncounterParticipants(participants);
                },

                participantLabel(participant) {
                    const labels = (participant?.sources ?? [])
                        .map(source => this.participantSourceLabels[source])
                        .filter(Boolean);

                    const baseLabel = {!! $escapeForAlpineAttribute(__('encounters.coauthor')) !!};

                    return labels.length ? baseLabel + ' - ' + labels.join(', ') : baseLabel;
                },
                toggle(id) {
                    if (this.activeSections.includes(id)) {
                        this.activeSections = this.activeSections.filter(i => i !== id);
                    } else {
                        this.activeSections.push(id);
                    }
                }
             }"
            class="flex flex-col gap-8 lg:flex-row lg:gap-12"
        >
            <!-- Main Content -->
            <div class="flex-1 space-y-4">
                @foreach (array_merge($mainGroups, $footerItems) as $item)
                    @if (isset($item['view']))
                        @if ($item['id'] === 'observations')
                            <div
                                x-show="typeCode === 'patient_identity'"
                                x-cloak
                                class="mb-4 flex items-start gap-4 rounded-xl border border-[#d2e4f9] bg-[#e8f1fc] p-5 dark:border-blue-900 dark:bg-blue-950/40"
                            >
                                <span class="mt-0.5 shrink-0 text-[#2563eb] dark:text-[#60a5fa]">
                                    @icon('info-circle', 'w-5 h-5')
                                </span>
                                <div class="flex-1 space-y-1">
                                    <h4 class="text-sm font-bold text-[#1e40af] dark:text-[#93c5fd]">
                                        {{ __('observations.preperson_alert_title') }}
                                    </h4>
                                    <p class="text-xs text-[#2563eb] dark:text-[#60a5fa]">
                                        {{ __('observations.preperson_alert_text') }}
                                    </p>
                                </div>
                            </div>
                        @endif
                        <div
                            id="block-{{ $item['id'] }}"
                            class="scroll-mt-16 rounded-xl bg-white dark:border-gray-700 dark:bg-gray-800"
                            :class="activeSections.includes('{{ $item['id'] }}') ? 'summary-section-active' : 'summary-section-inactive'"
                        >
                            <button
                                @click="toggle('{{ $item['id'] }}')"
                                type="button"
                                class="flex w-full items-center justify-between p-5 focus:outline-none"
                            >
                                <div class="flex items-center gap-4 text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center text-gray-900 dark:text-gray-100">
                                        @icon($item['icon'], 'w-6 h-6')
                                    </span>
                                    <span class="truncate">{{ $item['label'] }}</span>
                                </div>

                                <div
                                    class="shrink-0 text-gray-400 transition-transform duration-300 dark:text-gray-500"
                                    :class="activeSections.includes('{{ $item['id'] }}') ? '' : '-rotate-90'"
                                >
                                    @icon('chevron-down', 'w-5 h-5')
                                </div>
                            </button>

                            <div
                                x-show="activeSections.includes('{{ $item['id'] }}')"
                                style="display: none"
                                class="px-5 pb-5"
                            >
                                {{-- A disabled fieldset disables every control inside it, the record cancellation
                                     checkboxes included, so a section holding records to pick is left to the
                                     readonly handling it carries of its own --}}
                                <fieldset @disabled($isReadonly && !($canCancelRecords && ($item['holdsCancellableRecords'] ?? false)))>
                                    @include($item['view'])
                                </fieldset>
                            </div>
                        </div>
                    @endif
                @endforeach

                @if ($this instanceof EncounterEdit)
                    <div class="mt-4">
                        <fieldset class="fieldset-card mb-4 p-4 sm:p-8 sm:pb-10">
                            <legend class="legend">{{ __('forms.status.label') }}</legend>

                            <div class="mb-2 flex flex-col gap-6 sm:flex-row sm:items-end">
                                <div class="form-group group flex-1">
                                    <input
                                        type="text"
                                        id="ehealthStatus"
                                        class="input peer text-gray-500"
                                        value="{{ __('forms.status.signed') }}"
                                        readonly
                                        placeholder=" "
                                    />
                                    <label for="ehealthStatus" class="label"> {{ __('forms.status.label') }} </label>
                                </div>

                                @unless ($isReadonly)
                                    <div class="mb-1">
                                        <button type="button" class="button-primary px-8">
                                            {{ __('forms.update') }}
                                        </button>
                                    </div>
                                @endunless
                            </div>
                        </fieldset>
                    </div>
                @endif

                <!-- Additional Actions -->
                @if (isset($encounterId))
                    <div class="mt-10 border-t border-gray-100 pt-10 dark:border-gray-700">
                        <h3 class="mb-6 text-[17px] font-bold text-gray-900 dark:text-gray-100">
                            {{ __('encounters.additional_actions') }}
                        </h3>

                        <div class="space-y-6">
                            <fieldset class="fieldset-card p-5">
                                <legend class="legend">{{ __('patients.prescriptions') }}</legend>
                                <button
                                    wire:click="openEncounterEPrescriptionDrawer"
                                    type="button"
                                    class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    @icon('plus', 'w-4 h-4')
                                    <span>{{ __('encounters.add_prescription') }}</span>
                                </button>
                            </fieldset>

                            <fieldset class="fieldset-card p-5">
                                <legend class="legend">{{ __('patients.referrals') }}</legend>
                                <button
                                    wire:click="openEncounterReferralDrawer"
                                    type="button"
                                    class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    @icon('plus', 'w-4 h-4')
                                    <span>{{ __('encounters.add_referral') }}</span>
                                </button>
                            </fieldset>

                            <fieldset class="fieldset-card p-5">
                                <legend class="legend">{{ __('patients.medical_reports') }}</legend>
                                <button
                                    type="button"
                                    class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    @icon('plus', 'w-4 h-4')
                                    <span>{{ __('encounters.add_medical_report') }}</span>
                                </button>
                            </fieldset>

                            <fieldset class="fieldset-card p-5">
                                <legend class="legend">{{ __('patients.care_plans') }}</legend>
                                <a
                                    href="{{ route('care-plans.create-by-encounter', [legalEntity(), 'encounter' => $encounterId]) }}"
                                    class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    @icon('plus', 'w-4 h-4')
                                    <span>{{ __('encounters.add_care_plan') }}</span>
                                </a>
                            </fieldset>
                        </div>
                    </div>
                @endif

                <!-- Actions -->
                <div class="pt-8">
                    <div class="flex flex-wrap gap-4">
                        @if ($this instanceof EncounterEdit && $this->canBeCancelled)
                            <button
                                wire:click="openPackageCancellation"
                                type="button"
                                class="button-primary-outline-red"
                            >
                                {{ __('forms.delete') }}
                            </button>

                            <button
                                wire:click="openRecordsCancellation"
                                type="button"
                                class="button-primary-outline-red"
                            >
                                {{ __('encounters.records_entered_in_error') }}
                            </button>
                        @endif

                        @if ($this instanceof EncounterEdit)
                            <button
                                wire:click="openEncounterEPrescriptionDrawer"
                                type="button"
                                class="button-primary-outline flex items-center gap-2"
                            >
                                @icon('plus', 'w-4 h-4')
                                <span>Додати рецепт</span>
                            </button>
                            <button
                                wire:click="openEncounterReferralDrawer"
                                type="button"
                                class="button-primary-outline flex items-center gap-2"
                            >
                                @icon('plus', 'w-4 h-4')
                                <span>Додати направлення</span>
                            </button>
                        @endif

                        @unless ($isReadonly)
                            <button
                                wire:click.prevent="save"
                                type="submit"
                                class="button-primary-outline flex items-center gap-2"
                            >
                                @icon('archive', 'w-4 h-4')
                                {{ __('forms.save') }}
                            </button>

                            <button
                                type="submit"
                                @click="
                                    $wire.set('actionType', null);
                                    $wire.showSignatureModal = true;
                                "
                                class="button-primary"
                            >
                                {{ __('forms.complete_the_interaction_and_sign') }}
                            </button>
                        @endunless
                    </div>
                </div>
            </div>

            <!-- Sidebar Navigation (Right) -->
            <div class="sticky top-24 mt-4 w-full shrink-0 space-y-6 self-start lg:mt-0 lg:w-75">
                <div class="space-y-1">
                    @foreach ($mainGroups as $item)
                        <button
                            @click="
                                if (! activeSections.includes('{{ $item['id'] }}')) toggle('{{ $item['id'] }}');
                                document.getElementById('block-{{ $item['id'] }}').scrollIntoView({ behavior: 'smooth', block: 'start' });
                            "
                            type="button"
                            :class="activeSections.includes('{{ $item['id'] }}') ? 'summary-sidebar-btn-active' : 'summary-sidebar-btn-inactive'"
                            class="summary-sidebar-btn w-full"
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
    </div>

    @if ($this instanceof EncounterEdit || !$isReadonly)
        <x-signature-modal method="sign" :except-actions="['cancel_encounter']" />
    @endif

    @if ($this instanceof EncounterEdit && $this->canBeCancelled)
        @include('livewire.encounter.encounter-cancellation', [
                                    'formPath' => 'cancellationForm',
                                    'description' => array_filter($this->selectedRecords)
                                        ? __('encounters.messages.records_cancel_modal_description')
                                        : __('encounters.messages.cancel_modal_description')
                                ])
    @endif

    @if ($this instanceof EncounterEdit)
        @include('livewire.encounter.parts.encounter-eprescription-drawer')
        @include('livewire.encounter.parts.encounter-referral-drawer')
    @endif
    <livewire:components.x-message :listen-async="true" :key="time()" />
    <x-forms.loading />
</x-layouts.patient>
