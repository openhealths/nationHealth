<div class="p-4 sm:p-8"
     id="procedures-section"
     x-data="{
          procedures: $wire.entangle('form.procedures'),
          modalProcedure: new Procedure(),
          newProcedure: false,
          openProcedureDrawer: false,
          item: 0,
          divisions: {{ json_encode($divisions) }}
      }"
>

    <div class="space-y-4">
        <template x-for="(procedure, index) in procedures" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input type="checkbox" class="default-checkbox w-5 h-5" disabled>
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('patients.procedure') }}</div>
                        <div class="record-inner-value text-[16px]" x-text="(() => {
                                const service = Object.values($wire.dictionaries['custom/services']).find(service => service.id === procedure.codeValue);
                                return service ? `${service.code} / ${service.name}` : '';
                            })()"></div>
                    </div>

                    <div class="record-inner-action-col">
                        <div x-data="{
                            openDropdown: false,
                            toggle() {
                                if (this.openDropdown) {
                                    return this.close();
                                }

                                this.$refs.button.focus();
                                this.openDropdown = true;
                            },
                            close(focusAfter) {
                                if (!this.openDropdown) return;

                                this.openDropdown = false;
                                focusAfter && focusAfter.focus();
                            }
                        }"
                             @keydown.escape.prevent.stop="close($refs.button)"
                             @focusin.window="! $refs.panel.contains($event.target) && close()"
                             x-id="['dropdown-button']"
                             class="relative"
                        >
                            {{-- Dropdown Button --}}
                            <button x-ref="button"
                                    @click="toggle()"
                                    :aria-expanded="openDropdown"
                                    :aria-controls="$id('dropdown-button')"
                                    type="button"
                                    class="record-inner-action-btn cursor-pointer"
                            >
                                <svg class="w-6 h-6 text-gray-800 dark:text-gray-200" aria-hidden="true"
                                     xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"
                                     viewBox="0 0 24 24"
                                >
                                    <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round"
                                          stroke-width="2"
                                          d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"
                                    />
                                </svg>
                            </button>

                            {{-- Dropdown Panel --}}
                            <div class="absolute right-0 z-50">
                                <div x-ref="panel"
                                     x-show="openDropdown"
                                     x-transition.origin.top.left
                                     @click.outside="close($refs.button)"
                                     :id="$id('dropdown-button')"
                                     x-cloak
                                     class="dropdown-panel relative"
                                >
                                    <button @click.prevent="
                                        item = index;
                                        modalProcedure = JSON.parse(JSON.stringify(procedures[index]));
                                        newProcedure = false;
                                        openProcedureDrawer = true;
                                        close($refs.button);
                                    "
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button class="dropdown-delete"
                                            @click.prevent="procedures.splice(index, 1); close($refs.button)">
                                        {{ __('forms.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-body">
                    <div class="record-inner-grid-container">
                        <div class="grid grid-cols-2 xl:grid-cols-4 gap-y-4 gap-x-4 w-full">
                            <div>
                                <div class="record-inner-label">{{ __('forms.category') }}</div>
                                <div class="record-inner-subvalue"
                                     x-text="$wire.dictionaries['eHealth/procedure_categories'][procedure.categoryCode] || '-'"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.date') }}</div>
                                <div class="record-inner-subvalue"
                                     x-text="`${procedure.performedPeriodStartDate} ${procedure.performedPeriodStartTime} - ${procedure.performedPeriodEndDate} ${procedure.performedPeriodEndTime}`"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.division_name') }}</div>
                                <div class="record-inner-subvalue" x-text="(() => {
                                    const div = divisions.find(d => d.uuid === procedure.divisionId);
                                    return div ? div.name : '-';
                                })()"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('patients.outcome_result') }}</div>
                                <div class="record-inner-subvalue"
                                     x-text="$wire.dictionaries['eHealth/procedure_outcomes'][procedure.outcomeCode] || '-'"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        {{-- Button to trigger the drawer --}}
        <button @click.prevent="
                    newProcedure = true;
                    modalProcedure = new Procedure();
                    openProcedureDrawer = true;
                "
                class="item-add my-5"
        >
            {{ __('forms.add') }}
        </button>

        <x-dialog-drawer x-model="openProcedureDrawer" maxWidth="4/5" wire:ignore>
            <x-slot name="title">
                {{ __('patients.procedure') }}
            </x-slot>

            {{-- Content --}}
            <form>
                @include('livewire.encounter.procedure-parts.main-information', ['context' => 'encounter'])
                @include('livewire.encounter.procedure-parts.additional-information', ['context' => 'encounter'])
                @include('livewire.encounter.procedure-parts.reason-references')
                @include('livewire.encounter.procedure-parts.used-codes')
                @include('livewire.encounter.procedure-parts.complication-details')

                <div class="mt-6 flex justify-between space-x-2">
                    <button type="button"
                            @click="openProcedureDrawer = false"
                            class="button-minor"
                    >
                        {{ __('forms.cancel') }}
                    </button>

                    <button @click.prevent="
                                newProcedure !== false
                                    ? procedures.push(modalProcedure)
                                    : procedures[item] = modalProcedure;
                                openProcedureDrawer = false;
                            "
                            class="button-primary"
                            :disabled="!(
                                modalProcedure.categoryCode.trim() &&
                                modalProcedure.codeValue.trim()
                            )"
                    >
                        {{ __('forms.save') }}
                    </button>
                </div>
            </form>
        </x-dialog-drawer>
    </div>
</div>

<script>
    /**
     * Representation of the user's personal procedure
     */
    class Procedure {
        constructor(obj = null) {
            const now = new Date();
            const startTime = new Date(now.getTime() - 15 * 60 * 1000);
            const toFormattedDate = (date) => {
                const [yyyy, mm, dd] = date.toISOString().split('T')[0].split('-');
                return `${dd}.${mm}.${yyyy}`;
            };
            const timeOptions = {hour: '2-digit', minute: '2-digit', hour12: false};

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

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
