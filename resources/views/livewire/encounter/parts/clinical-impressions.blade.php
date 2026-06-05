<div class="p-4 sm:p-8"
     id="clinical-impressions-section"
     x-data="{
         clinicalImpressions: $wire.entangle('form.clinicalImpressions'),
         modalClinicalImpression: new ClinicalImpression(),
         newClinicalImpression: false,
         openClinicalImpressionDrawer: false,
         item: 0,
         dictionary: $wire.dictionaries['eHealth/clinical_impression_patient_categories']
     }"
>

    <div class="space-y-4">
        <template x-for="(clinicalImpression, index) in clinicalImpressions" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input type="checkbox" class="default-checkbox w-5 h-5" disabled>
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('patients.clinical_impression') }}</div>
                        <div class="record-inner-value text-[16px]"
                             x-text="`${ clinicalImpression.codeCode } - ${ dictionary[clinicalImpression.codeCode] }`"></div>
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
                             @focusin.window="!$refs.panel.contains($event.target) && close()"
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
                                        modalClinicalImpression = JSON.parse(JSON.stringify(clinicalImpressions[index]));
                                        newClinicalImpression = false;
                                        openClinicalImpressionDrawer = true;
                                        close($refs.button);
                                    "
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button class="dropdown-delete"
                                            @click.prevent="clinicalImpressions.splice(index, 1); close($refs.button)">
                                        {{ __('forms.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-body">
                    <div class="record-inner-grid-container">
                        <div class="grid grid-cols-2 xl:grid-cols-3 gap-y-4 gap-x-4 w-full">
                            <div>
                                <div class="record-inner-label">{{ __('forms.date') }}</div>
                                <div class="record-inner-subvalue"
                                     x-text="`${clinicalImpression.effectivePeriodStartDate} ${clinicalImpression.effectivePeriodStartTime}`"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.description') }}</div>
                                <div class="record-inner-subvalue" x-text="clinicalImpression.description || '-'"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.comment') }}</div>
                                <div class="record-inner-subvalue" x-text="clinicalImpression.note || '-'"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        {{-- Button to trigger the modal --}}
        <button @click.prevent="
                    newClinicalImpression = true; {{-- We are adding a new clinicalImpression --}}
                    modalClinicalImpression = new ClinicalImpression(); {{-- Replace the data of the previous clinicalImpression with a new one--}}
                    $wire.problems = [];
                    $wire.findings = [];
                    openClinicalImpressionDrawer = true;
                "
                class="item-add my-5"
        >
            {{ __('forms.add') }}
        </button>

        {{-- Modal --}}
        <x-dialog-drawer x-model="openClinicalImpressionDrawer" maxWidth="4/5" wire:ignore>
            <x-slot name="title">
                {{ __('patients.clinical_impression') }}
            </x-slot>

            <form>
                @include('livewire.encounter.clinical-impression-parts.main-information')
                @include('livewire.encounter.clinical-impression-parts.problems')
                @include('livewire.encounter.clinical-impression-parts.findings')
                @include('livewire.encounter.clinical-impression-parts.supporting-info')
                @include('livewire.encounter.clinical-impression-parts.additional-information')

                <div class="mt-6 flex justify-between space-x-2">
                    <button type="button"
                            @click="openClinicalImpressionDrawer = false"
                            class="button-minor"
                    >
                        {{ __('forms.cancel') }}
                    </button>

                    <button @click.prevent="
                                newClinicalImpression !== false
                                    ? clinicalImpressions.push(modalClinicalImpression)
                                    : clinicalImpressions[item] = modalClinicalImpression;
                                openClinicalImpressionDrawer = false;
                            "
                            class="button-primary"
                            :disabled="!modalClinicalImpression.codeCode.trim()"
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
     * Representation of the user's personal clinicalImpression
     */
    class ClinicalImpression {
        constructor(obj = null) {
            const now = new Date();
            const endTime = new Date(now.getTime() + 15 * 60 * 1000);
            const toFormattedDate = (date) => {
                const [yyyy, mm, dd] = date.toISOString().split('T')[0].split('-');
                return `${dd}.${mm}.${yyyy}`;
            };
            const timeOptions = {hour: '2-digit', minute: '2-digit', hour12: false};

            this.codeCode = '';
            this.description = '';
            this.note = '';
            this.previous = [];
            this.problems = [];
            this.findings = [];
            this.supportingInfo = [];
            this.effectivePeriodStartDate = toFormattedDate(now);
            this.effectivePeriodStartTime = now.toLocaleTimeString('uk-UA', timeOptions);
            this.effectivePeriodEndDate = toFormattedDate(endTime);
            this.effectivePeriodEndTime = endTime.toLocaleTimeString('uk-UA', timeOptions);

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
