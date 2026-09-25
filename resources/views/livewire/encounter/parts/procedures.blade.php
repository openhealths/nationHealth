<div
    class="p-4 sm:p-8"
    id="procedures-section"
    x-data="{
        procedures: $wire.entangle('procedureForm.procedures'),
        encounter: $wire.entangle('form.encounter'),
        selectedRecords: $wire.entangle('selectedRecords.procedures'),
        cancelledRecords: $wire.cancelledRecords.procedures,
        canCancelRecords: {{ ($canCancelRecords ?? false) ? 'true' : 'false' }},
        conditions: $wire.entangle('conditionForm.conditions'),
        modalProcedure: new Procedure(),
        newProcedure: false,
        openProcedureDrawer: false,
        openServiceCatalog: false,
        selectedServiceFromCatalog: null,
        item: 0,
        divisions: {{ json_encode($divisions) }},
        equipmentOptions: @js($equipmentOptions),
        procedureEmployees: @js($procedureEmployees),

        syncProcedureParticipants() {
            const performers = this.procedures
                .filter(procedure => procedure.primarySource === true && procedure.performerEmployeeId)
                .map(procedure => {
                    const employee = this.procedureEmployees.find(
                        employee => String(employee.uuid) === String(procedure.performerEmployeeId)
                    );

                    return {
                        uuid: procedure.performerEmployeeId,
                        name: employee?.name || procedure.performerEmployeeId,
                    };
                });

            this.syncLocalEncounterParticipants('procedure', performers);
        },

        complicationOptions() {
            return this.conditions
                .filter(condition => condition.uuid && condition.codeCode)
                .map(condition => ({
                    id: condition.uuid,
                    ehealthInsertedAt: condition.assertedDate || condition.onsetDate || '',
                    codeCode: condition.codeCode,
                    codeSystem: condition.codeSystem,
                    type: 'condition',
                }));
        },

        clearWrongComplicationDetails() {
            const availableIds = this.complicationOptions().map(condition => condition.id);

            this.modalProcedure.complicationDetails = this.modalProcedure.complicationDetails
                .filter(complicationDetail => availableIds.includes(complicationDetail.id));
        },

        addUsedReference() {
            this.modalProcedure.usedReferences.push({ id: '' });
        },

        removeUsedReference(index) {
            this.modalProcedure.usedReferences.splice(index, 1);
        },

        selectProcedureService(service) {
            this.modalProcedure.categoryCode = service.category;
            this.modalProcedure.codeValue = service.id;
            this.selectedServiceFromCatalog = service;
            this.openServiceCatalog = false;
        },

        setPerformedType(type) {
            this.modalProcedure.performedType = type;

            if (type === 'date_time') {
                this.modalProcedure.performedDate = this.encounter.periodDate || '';
                this.modalProcedure.performedTime = this.encounter.periodStart || '';

                this.modalProcedure.performedPeriodStartDate = '';
                this.modalProcedure.performedPeriodStartTime = '';
                this.modalProcedure.performedPeriodEndDate = '';
                this.modalProcedure.performedPeriodEndTime = '';

                return;
            }

            if (type === 'period') {
                this.modalProcedure.performedDate = '';
                this.modalProcedure.performedTime = '';

                this.modalProcedure.performedPeriodStartDate = this.encounter.periodDate || '';
                this.modalProcedure.performedPeriodStartTime = this.encounter.periodStart || '';
                this.modalProcedure.performedPeriodEndDate = this.encounter.periodDate || '';
                this.modalProcedure.performedPeriodEndTime = this.encounter.periodEnd || '';

                return;
            }

            this.modalProcedure.performedDate = '';
            this.modalProcedure.performedTime = '';
            this.modalProcedure.performedPeriodStartDate = '';
            this.modalProcedure.performedPeriodStartTime = '';
            this.modalProcedure.performedPeriodEndDate = '';
            this.modalProcedure.performedPeriodEndTime = '';
        },
        
        parseProcedureDateTime(date, time) {
            if (!date || !time) {
                return null;
            }

            const dateParts = date.split('.').map(Number);
            const timeParts = time.split(':').map(Number);

            if (dateParts.length !== 3 || timeParts.length !== 2) {
                return null;
            }

            const [day, month, year] = dateParts;
            const [hours, minutes] = timeParts;

            return new Date(year, month - 1, day, hours, minutes);
        },

        procedurePerformedOutsideEncounterPeriod() {
            if (this.modalProcedure.status !== 'completed') {
                return false;
            }

            const encounterStart = this.parseProcedureDateTime(
                this.encounter.periodDate,
                this.encounter.periodStart
            );
            const encounterEnd = this.parseProcedureDateTime(
                this.encounter.periodDate,
                this.encounter.periodEnd
            );

            if (!encounterStart || !encounterEnd) {
                return false;
            }

            if (this.modalProcedure.performedType === 'date_time') {
                const performed = this.parseProcedureDateTime(this.modalProcedure.performedDate, this.modalProcedure.performedTime);

                return Boolean(performed && (performed < encounterStart || performed > encounterEnd));
            }

            if (this.modalProcedure.performedType === 'period') {
                const start = this.parseProcedureDateTime(this.modalProcedure.performedPeriodStartDate, this.modalProcedure.performedPeriodStartTime);
                const end = this.parseProcedureDateTime(this.modalProcedure.performedPeriodEndDate, this.modalProcedure.performedPeriodEndTime);

                return Boolean(start && end && (start < encounterStart || start > encounterEnd || end < encounterStart || end > encounterEnd));
            }

            return false;
        }
      }"
      @procedure-service-selected.window="selectProcedureService($event.detail.service)"
>
    <div class="space-y-4">
        <template x-for="(procedure, index) in procedures" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <label :for="`procedureRecord${index}`" class="sr-only">{{ __('forms.select') }}</label>
                        <input
                            type="checkbox"
                            :id="`procedureRecord${index}`"
                            class="default-checkbox h-5 w-5"
                            :value="procedure.uuid"
                            x-model="selectedRecords"
                            :disabled="! canCancelRecords ||
                            ! procedure.uuid ||
                            cancelledRecords.includes(procedure.uuid)"
                        />
                    </div>

                    <template x-if="cancelledRecords.includes(procedure.uuid)">
                        <span class="record-inner-badge-error"> {{ __('procedures.status.entered_in_error') }} </span>
                    </template>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('procedures.label') }}</div>
                        <div
                            class="record-inner-value text-[16px]"
                            x-text="
                                (() => {
                                    const service = Object.values($wire.dictionaries['custom/services']).find(
                                        (service) => service.id === procedure.codeValue,
                                    );
                                    return service ? `${service.code} / ${service.name}` : '';
                                })()
                            "
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
                            @if ($isReadonly)
                                <a
                                    href="#"
                                    @click.prevent="
                                        item = index;
                                        modalProcedure = JSON.parse(JSON.stringify(procedures[index]));
                                        newProcedure = false;
                                        openProcedureDrawer = true;
                                    "
                                    class="record-inner-action-btn cursor-pointer"
                                    title="{{ __('forms.view') }}"
                                >
                                    @icon('eye', 'w-6 h-6')
                                    <span class="sr-only"> {{ __('forms.view') }} </span>
                                </a>
                            @else
                                {{-- Dropdown Button --}}
                                <button
                                    x-ref="button"
                                    @click="toggle()"
                                    :aria-expanded="openDropdown"
                                    :aria-controls="$id('dropdown-button')"
                                    type="button"
                                    class="record-inner-action-btn cursor-pointer"
                                >
                                    <svg
                                        class="h-6 w-6 text-gray-800 dark:text-gray-200"
                                        aria-hidden="true"
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="24"
                                        height="24"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke="currentColor"
                                            stroke-linecap="square"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"
                                        />
                                    </svg>
                                </button>

                                {{-- Dropdown Panel --}}
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
                                            @click.prevent="
                                                item = index;
                                                modalProcedure = JSON.parse(JSON.stringify(procedures[index]));
                                                newProcedure = false;
                                                openProcedureDrawer = true;
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            class="dropdown-delete"
                                            @click.prevent="
                                                procedures.splice(index, 1);
                                                syncProcedureParticipants();
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
                                <div class="record-inner-label">{{ __('procedures.category') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="
                                        $wire.dictionaries['eHealth/procedure_categories'][procedure.categoryCode] ||
                                        '-'
                                    "
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('procedures.performed_date') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="
                                        procedure.status !== 'completed'
                                            ? '-'
                                            : procedure.performedType === 'date_time'
                                              ? `${procedure.performedDate}
                                                    ${procedure.performedTime}`
                                              : `${procedure.performedPeriodStartDate}
                                                    ${procedure.performedPeriodStartTime} -
                                                    ${procedure.performedPeriodEndDate}
                                                    ${procedure.performedPeriodEndTime}`
                                    "
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('procedures.division') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="
                                        (() => {
                                            const div = divisions.find((d) => d.uuid === procedure.divisionId);
                                            return div ? div.name : '-';
                                        })()
                                    "
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('procedures.outcome_result') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="
                                        $wire.dictionaries['eHealth/procedure_outcomes'][procedure.outcomeCode] || '-'
                                    "
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="procedure.status === 'not_done' ? '{{ __('procedures.status.not_done') }}' : '{{ __('procedures.status.completed') }}'"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        {{-- Button to trigger the drawer --}}
        @unless ($isReadonly)
            <button
                @click.prevent="
                    newProcedure = true;
                    modalProcedure = new Procedure();
                    openProcedureDrawer = true;
                "
                class="item-add my-5"
            >
                {{ __('forms.add') }}
            </button>
        @endunless

        <x-dialog-drawer x-model="openProcedureDrawer" maxWidth="4/5" wire:ignore>
            <x-slot name="title">{{ __('procedures.label') }}</x-slot>

            {{-- Content --}}
            <form>
                <fieldset @disabled($isReadonly) @class(['pointer-event-none' => $isReadonly])>
                    @include('livewire.encounter.procedure-parts.main-information', ['context' => 'encounter'])
                    @include('livewire.encounter.procedure-parts.additional-information', ['context' => 'encounter'])
                    @include('livewire.encounter.procedure-parts.reason-references')
                    @include('livewire.encounter.procedure-parts.used-codes')
                    @include('livewire.encounter.procedure-parts.complication-details')

                    <div class="mt-6 flex justify-between space-x-2">
                        <button type="button" @click="openProcedureDrawer = false" class="button-minor">
                            {{ $isReadonly ? __('forms.close') : __('forms.cancel') }}
                        </button>

                        @unless ($isReadonly)
                            <button
                                @click.prevent="
                                    clearWrongComplicationDetails();
                                    newProcedure !== false
                                        ? procedures.push(modalProcedure)
                                        : (procedures[item] = modalProcedure);
                                    syncProcedureParticipants();
                                    openProcedureDrawer = false;
                                "
                                class="button-primary"
                                :disabled="!(modalProcedure.categoryCode.trim() && modalProcedure.codeValue.trim() 
                                    && (modalProcedure.primarySource !== true || modalProcedure.performerEmployeeId)) || procedurePerformedOutsideEncounterPeriod()
                                "
                            >
                                {{ __('forms.save') }}
                            </button>
                        @endunless
                    </div>
                </fieldset>
            </form>
        </x-dialog-drawer>
        @unless ($isReadonly)
            <x-dialog-drawer
                x-model="openServiceCatalog"
                onCloseClick="openServiceCatalog = false"
                maxWidth="4/5"
                overlayWidth="100%"
                backdropClickThrough="true"
                stopClickPropagation="true"
                zIndex="50"
            >
                <livewire:dictionary.service-catalog
                    :legal-entity="legalEntity()"
                    :selection-mode="true"
                    selection-event="procedure-service-selected"
                    :allowed-categories="array_keys($this->dictionaries['eHealth/procedure_categories'] ?? [])"
                    :key="'encounter-procedure-service-catalog'"
                />

                <div class="mt-8">
                    <button
                        type="button"
                        @click="openServiceCatalog = false"
                        class="button-minor"
                    >
                        {{ __('forms.cancel') }}
                    </button>
                </div>
            </x-dialog-drawer>
        @endunless
    </div>
</div>

<script>
    /**
     * Representation of the user's personal procedure
     */
    class Procedure {
        constructor(obj = null) {
            this.uuid = crypto.randomUUID();
            const now = new Date();
            const startTime = new Date(now.getTime() - 15 * 60 * 1000);
            const toFormattedDate = (date) => {
                const [yyyy, mm, dd] = date.toISOString().split('T')[0].split('-');
                return `${dd}.${mm}.${yyyy}`;
            };
            const timeOptions = { hour: '2-digit', minute: '2-digit', hour12: false };

            this.status = '';
            this.basedOnIdentifier = '';
            this.referralNumber = '';
            this.usedReferences = [];
            this.categoryCode = '';
            this.codeValue = '';
            this.divisionId = '';
            this.outcomeCode = '';
            this.primarySource = true;
            this.reportOriginCode = '';
            this.reportOriginText = '';
            this.isReferralAvailable = false;
            this.referralType = '';
            this.paperReferralRequisition = '';
            this.paperReferralRequesterEmployeeName = '';
            this.paperReferralRequesterLegalEntityEdrpou = '';
            this.paperReferralRequesterLegalEntityName = '';
            this.paperReferralServiceRequestDate = '';
            this.paperReferralNote = '';
            this.note = '';
            this.reasonReferences = [];
            this.usedCodes = [];
            this.complicationDetails = [];
            this.performedPeriodStartDate = toFormattedDate(startTime);
            this.performedPeriodStartTime = startTime.toLocaleTimeString('uk-UA', timeOptions);
            this.performedPeriodEndDate = toFormattedDate(now);
            this.performedPeriodEndTime = now.toLocaleTimeString('uk-UA', timeOptions);
            this.performerEmployeeId = '';
            this.performedType = 'period';
            this.performedDate = '';
            this.performedTime = '';

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
