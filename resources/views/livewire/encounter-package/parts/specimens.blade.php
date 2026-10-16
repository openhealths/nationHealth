<div
    class="p-4 sm:p-8"
    id="specimens-section"
    data-available-status="{{ __('specimens.statuses.available') }}"
    data-unavailable-status="{{ __('specimens.statuses.unavailable') }}"
    x-data="{
        specimens: $wire.entangle('specimenForm.specimens'),
        procedures: $wire.entangle('procedureForm.procedures'),
        selectedRecords: $wire.entangle('selectedRecords.specimens'),
        cancelledRecords: $wire.cancelledRecords.specimens,
        canCancelRecords: {{ ($canCancelRecords ?? false) ? 'true' : 'false' }},
        employees: @js($employees),
        currentEmployee: @js($deviceDispenseEmployee),
        specimenTypesDictionary: $wire.dictionaries['specimen_types'],
        containerTypesDictionary: $wire.dictionaries['specimen_container_types'],
        modalSpecimen: new Specimen(),
        newSpecimen: false,
        openSpecimenDrawer: false,
        item: 0,

        employeeName(id) {
            return this.employees.find((employee) => employee.uuid === id)?.name || this.currentEmployee.name || '-';
        },

        procedureLabel(procedure) {
            const service = Object.values($wire.dictionaries['custom/services'] ?? {}).find((service) => service.id === procedure.codeValue);

            return service ? `${service.code} / ${service.name}` : procedure.codeValue || procedure.uuid || '-';
        },

        isReferenced(specimenId) {
            const observations = $wire.observationForm.observations ?? [];
            const diagnosticReports = $wire.diagnosticReportForm.diagnosticReports ?? [];

            return observations.some((observation) => observation.specimenId === specimenId) ||
                diagnosticReports.some((diagnosticReport) => (diagnosticReport.specimenIds ?? []).includes(specimenId));
        },

        statusName(specimenId) {
            return this.isReferenced(specimenId)
                ? this.$root.dataset.unavailableStatus
                : this.$root.dataset.availableStatus;
        },

        collectedAt(specimen) {
            if (specimen.collectedType === 'period') {
                return `${specimen.collectedPeriodRange || '-'} ${specimen.collectedPeriodStartTime || ''}`;
            }

            return `${specimen.collectedDate || '-'} ${specimen.collectedTime || ''}`;
        },

        containersLabel(specimen) {
            return specimen.containers
                .map((container) => [container.identifier, this.containerTypesDictionary[container.typeCode]].filter(Boolean).join(' — '))
                .join(', ') || '-';
        },

        parentOptions() {
            const packageSpecimenIds = this.specimens.map((specimen) => specimen.uuid);

            return [
                ...this.specimens.filter((specimen) => specimen.uuid !== this.modalSpecimen.uuid),
                ...$wire.patientSpecimens.filter(
                    (specimen) => specimen.status === 'available' && ! packageSpecimenIds.includes(specimen.uuid)
                )
            ];
        },

        parentsLabel(specimen) {
            const parents = [...this.specimens, ...$wire.patientSpecimens];

            return specimen.parentIds
                .map((parentId) => this.specimenTypesDictionary[parents.find((parent) => parent.uuid === parentId)?.typeCode])
                .filter(Boolean)
                .join(', ') || '-';
        },

        changeCollectorType() {
            const collectorIds = {
                current: this.currentEmployee.uuid,
                patient: $wire.patientUuid
            };

            this.modalSpecimen.collectorId = collectorIds[this.modalSpecimen.collectorType] || '';
        },

        createSpecimen() {
            this.newSpecimen = true;
            this.modalSpecimen = new Specimen();
            this.modalSpecimen.collectorId = this.currentEmployee.uuid || '';
            this.modalSpecimen.collectedDate = $wire.form.encounter.periodDate || '';
            this.modalSpecimen.collectedTime = $wire.form.encounter.periodStart || '';
            this.openSpecimenDrawer = true;
        },

        editSpecimen(index) {
            this.item = index;
            this.modalSpecimen = new Specimen(this.specimens[index]);
            this.newSpecimen = false;
            this.openSpecimenDrawer = true;
        },

        saveSpecimen() {
            const specimen = JSON.parse(JSON.stringify(this.modalSpecimen));

            specimen.parentIds = specimen.parentIds.filter(Boolean);

            if (this.newSpecimen) {
                this.specimens.push(specimen);
            } else {
                this.specimens.splice(this.item, 1, specimen);
            }

            this.openSpecimenDrawer = false;
        },

        removeSpecimen(index) {
            const removedId = this.specimens[index].uuid;

            this.specimens.splice(index, 1);
            this.specimens.forEach((specimen) => {
                specimen.parentIds = specimen.parentIds.filter((parentId) => parentId !== removedId);
            });
        },

        canSaveSpecimen() {
            const collectedAtFilled = this.modalSpecimen.collectedType === 'period'
                ? this.modalSpecimen.collectedPeriodRange && this.modalSpecimen.collectedPeriodStartTime
                : this.modalSpecimen.collectedDate && this.modalSpecimen.collectedTime;

            return this.modalSpecimen.typeCode &&
                this.modalSpecimen.collectorId &&
                collectedAtFilled &&
                this.modalSpecimen.containers.length > 0 &&
                this.modalSpecimen.containers.every((container) => container.identifier.trim());
        }
    }"
>
    <div class="space-y-4">
        <template x-for="(specimen, index) in specimens" :key="specimen.uuid || index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <label :for="`specimenRecord${index}`" class="sr-only">{{ __('forms.select') }}</label>
                        <input
                            type="checkbox"
                            :id="`specimenRecord${index}`"
                            class="default-checkbox h-5 w-5"
                            :value="specimen.uuid"
                            x-model="selectedRecords"
                            :disabled="! canCancelRecords || ! specimen.uuid || cancelledRecords.includes(specimen.uuid)"
                        />
                    </div>

                    <template x-if="cancelledRecords.includes(specimen.uuid)">
                        <span class="record-inner-badge-error"> {{ __('specimens.statuses.entered_in_error') }} </span>
                    </template>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">
                            {{ __('specimens.specimen_number') }} <span x-text="index + 1"></span>
                        </div>
                        <div
                            class="record-inner-value text-[16px]"
                            x-text="specimenTypesDictionary[specimen.typeCode] || '-'"
                        ></div>
                    </div>

                    <div class="record-inner-action-col">
                        <div
                            x-data="{
                                openDropdown: false,
                                toggle() {
                                    if (this.openDropdown) {
                                        return this.close();
                                    }
                                    this.$refs.button.focus();
                                    this.openDropdown = true;
                                },
                                close(focusAfter) {
                                    if (! this.openDropdown) return;
                                    this.openDropdown = false;
                                    focusAfter && focusAfter.focus();
                                },
                            }"
                            @keydown.escape.prevent.stop="close($refs.button)"
                            @focusin.window="$refs.panel && ! $refs.panel.contains($event.target) && close()"
                            x-id="['dropdown-button']"
                            class="relative"
                        >
                            @if ($isReadonly ?? false)
                                <a
                                    href="#"
                                    @click.prevent="editSpecimen(index)"
                                    class="record-inner-action-btn cursor-pointer"
                                    title="{{ __('forms.view') }}"
                                >
                                    @icon('eye', 'w-6 h-6')
                                    <span class="sr-only">{{ __('forms.view') }}</span>
                                </a>
                            @else
                                <button
                                    x-ref="button"
                                    @click="toggle()"
                                    :aria-expanded="openDropdown"
                                    :aria-controls="$id('dropdown-button')"
                                    type="button"
                                    class="record-inner-action-btn cursor-pointer"
                                >
                                    <svg class="h-6 w-6 text-gray-800 dark:text-gray-200" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                        <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round" stroke-width="2" d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z" />
                                    </svg>
                                </button>

                                <div class="absolute right-0 z-50">
                                    <div
                                        x-ref="panel"
                                        x-show="openDropdown"
                                        x-transition.origin.top.left
                                        @click.outside="close($refs.button)"
                                        :id="$id('dropdown-button')"
                                        x-cloak
                                        class="dropdown-panel relative"
                                    >
                                        <button
                                            type="button"
                                            @click.prevent="
                                                editSpecimen(index);
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            type="button"
                                            class="dropdown-delete"
                                            @click.prevent="
                                                removeSpecimen(index);
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.delete') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="record-inner-body">
                    <div class="record-inner-grid-container">
                        <div class="grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-4">
                            <div>
                                <div class="record-inner-label uppercase">{{ __('forms.status.label') }}</div>
                                <div class="record-inner-subvalue" x-text="statusName(specimen.uuid)"></div>
                            </div>
                            <div>
                                <div class="record-inner-label uppercase">{{ __('specimens.collection') }}</div>
                                <div class="record-inner-subvalue" x-text="collectedAt(specimen)"></div>
                            </div>
                            <div>
                                <div class="record-inner-label uppercase">
                                    {{ __('specimens.containers_and_type') }}
                                </div>
                                <div class="record-inner-subvalue" x-text="containersLabel(specimen)"></div>
                            </div>
                            <div>
                                <div class="record-inner-label uppercase">{{ __('specimens.collector') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="specimen.collectorType === 'patient' ? '{{ __('forms.patient') }}' : employeeName(specimen.collectorId)"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label uppercase">{{ __('specimens.parent_specimen') }}</div>
                                <div class="record-inner-subvalue" x-text="parentsLabel(specimen)"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    @unless ($isReadonly ?? false)
        <button type="button" @click.prevent="createSpecimen()" class="item-add my-5">
            {{ __('specimens.add_specimen') }}
        </button>
    @endunless

    <x-dialog-drawer x-model="openSpecimenDrawer" maxWidth="4/5" wire:ignore>
        <x-slot name="title">
            <span x-text="newSpecimen ? '{{ __('specimens.new_specimen') }}' : '{{ __('specimens.edit_specimen') }}'"></span>
        </x-slot>

        <form>
            <fieldset @disabled($isReadonly ?? false) @class(['pointer-event-none' => $isReadonly ?? false])>
                @include('livewire.specimen.parts.general-info', ['context' => 'encounter'])
                @include('livewire.specimen.parts.material-collection', ['maxCollectedDate' => '$wire.form.encounter.periodDate', 'context' => 'encounter'])
                @include('livewire.specimen.parts.containers')

                <div class="mt-8 flex w-full justify-start space-x-4">
                    <button type="button" @click="openSpecimenDrawer = false" class="button-minor">
                        {{ __('forms.cancel') }}
                    </button>
                    @unless ($isReadonly ?? false)
                        <button
                            type="button"
                            @click="saveSpecimen()"
                            class="button-primary"
                            :disabled="! canSaveSpecimen()"
                        >
                            <span x-text="newSpecimen ? '{{ __('specimens.add_specimen_btn') }}' : '{{ __('forms.save') }}'"></span>
                        </button>
                    @endunless
                </div>
            </fieldset>
        </form>
    </x-dialog-drawer>
</div>

@include('livewire.specimen.parts.specimen-classes')
