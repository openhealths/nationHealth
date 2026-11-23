<div
    class="p-4 sm:p-8"
    id="clinical-impressions-section"
    x-data="{
         clinicalImpressions: $wire.entangle('clinicalImpressionForm.clinicalImpressions'),
         selectedRecords: $wire.entangle('selectedRecords.clinicalImpressions'),
         cancelledRecords: $wire.cancelledRecords.clinicalImpressions,
         canCancelRecords: {{ ($canCancelRecords ?? false) ? 'true' : 'false' }},
         modalClinicalImpression: new ClinicalImpression(),
         newClinicalImpression: false,
         openClinicalImpressionDrawer: false,
         item: 0,
         dictionary: $wire.dictionaries['eHealth/clinical_impression_patient_categories'],

         setEffectiveType(type) {
             // The encounter period gives the defaults: its end for a moment, the whole of it for a period
             const defaults = new ClinicalImpression(null, this.$wire.form.encounter);

             this.modalClinicalImpression.effectiveDate = type === 'date_time' ? defaults.effectivePeriodEndDate : '';
             this.modalClinicalImpression.effectiveTime = type === 'date_time' ? defaults.effectivePeriodEndTime : '';
             this.modalClinicalImpression.effectivePeriodStartDate = type === 'period' ? defaults.effectivePeriodStartDate : '';
             this.modalClinicalImpression.effectivePeriodStartTime = type === 'period' ? defaults.effectivePeriodStartTime : '';
             this.modalClinicalImpression.effectivePeriodEndDate = type === 'period' ? defaults.effectivePeriodEndDate : '';
             this.modalClinicalImpression.effectivePeriodEndTime = type === 'period' ? defaults.effectivePeriodEndTime : '';
         }
     }"
>
    <div class="space-y-4">
        <template x-for="(clinicalImpression, index) in clinicalImpressions" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <label
                            :for="`clinicalImpressionRecord${index}`"
                            class="sr-only"
                        >{{ __('forms.select') }}</label>
                        <input
                            type="checkbox"
                            :id="`clinicalImpressionRecord${index}`"
                            class="default-checkbox h-5 w-5"
                            :value="clinicalImpression.uuid"
                            x-model="selectedRecords"
                            :disabled="! canCancelRecords ||
                            ! clinicalImpression.uuid ||
                            cancelledRecords.includes(clinicalImpression.uuid)"
                        />
                    </div>

                    <template x-if="cancelledRecords.includes(clinicalImpression.uuid)">
                        <span class="record-inner-badge-error">
                            {{ __('clinical-impressions.status.entered_in_error') }}
                        </span>
                    </template>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('clinical-impressions.label') }}</div>
                        <div
                            class="record-inner-value text-[16px]"
                            x-text="`${clinicalImpression.codeCode} - ${dictionary[clinicalImpression.codeCode]}`"
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
                                        modalClinicalImpression = JSON.parse(
                                            JSON.stringify(clinicalImpressions[index]),
                                        );
                                        newClinicalImpression = false;
                                        openClinicalImpressionDrawer = true;
                                    "
                                    class="record-inner-action-btn"
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
                                    class="record-inner-action-btn"
                                >
                                    @icon('edit-user-outline', 'svg-hover-action h-6 w-6')
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
                                                modalClinicalImpression = JSON.parse(
                                                    JSON.stringify(clinicalImpressions[index]),
                                                );
                                                newClinicalImpression = false;
                                                openClinicalImpressionDrawer = true;
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            class="dropdown-delete"
                                            @click.prevent="
                                                clinicalImpressions.splice(index, 1);
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
                        <div class="grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-3">
                            <div x-show="clinicalImpression.effectiveType === 'date_time'">
                                <div class="record-inner-label">
                                    {{ __('clinical-impressions.effective_date_time') }}
                                </div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="`${clinicalImpression.effectiveDate} ${clinicalImpression.effectiveTime}`"
                                ></div>
                            </div>
                            <div x-show="clinicalImpression.effectiveType !== 'date_time'">
                                <div class="record-inner-label">
                                    {{ __('clinical-impressions.effective_period_start') }}
                                </div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="
                                        `${clinicalImpression.effectivePeriodStartDate} ${clinicalImpression.effectivePeriodStartTime}`
                                    "
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('clinical-impressions.description') }}</div>
                                <div class="record-inner-subvalue" x-text="clinicalImpression.description || '-'"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('clinical-impressions.note') }}</div>
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
        @unless ($isReadonly)
            <button
                @click.prevent="
                    newClinicalImpression = true; {{-- We are adding a new clinicalImpression --}}
                    modalClinicalImpression = new ClinicalImpression(null, $wire.form.encounter); {{-- Replace the data of the previous clinicalImpression with a new one--}}
                    modalClinicalImpression.assessorEmployeeId = $wire.form.encounter.performerId;
                    $wire.findings = [];
                    openClinicalImpressionDrawer = true;
                "
                class="item-add my-5"
            >
                {{ __('forms.add') }}
            </button>
        @endunless

        {{-- Modal --}}
        <x-dialog-drawer x-model="openClinicalImpressionDrawer" maxWidth="4/5" wire:ignore>
            <x-slot name="title">{{ __('clinical-impressions.label') }}</x-slot>

            <form>
                <fieldset @disabled($isReadonly) @class(['pointer-event-none' => $isReadonly])>
                    @include('livewire.encounter-package.clinical-impression-parts.main-information')
                    @include('livewire.encounter-package.clinical-impression-parts.problems')
                    @include('livewire.encounter-package.clinical-impression-parts.findings')
                    @include('livewire.encounter-package.clinical-impression-parts.supporting-info')
                    @include('livewire.encounter-package.clinical-impression-parts.additional-information')

                    <div class="mt-6 flex justify-between space-x-2">
                        <button type="button" @click="openClinicalImpressionDrawer = false" class="button-minor">
                            {{ $isReadonly ? __('forms.close') : __('forms.cancel') }}
                        </button>

                        @unless ($isReadonly)
                            <button
                                @click.prevent="
                                    newClinicalImpression !== false
                                        ? clinicalImpressions.push(modalClinicalImpression)
                                        : (clinicalImpressions[item] = modalClinicalImpression);
                                    openClinicalImpressionDrawer = false;
                                "
                                class="button-primary"
                                :disabled="! modalClinicalImpression.codeCode.trim()"
                            >
                                {{ __('forms.save') }}
                            </button>
                        @endunless
                    </div>
                </fieldset>
            </form>
        </x-dialog-drawer>
    </div>
</div>

<script data-navigate-once>
    /**
     * Representation of the user's personal clinicalImpression
     */
    class ClinicalImpression {
        constructor(obj = null, encounter = null) {
            // Without the encounter period at hand, the same default period the encounter gets:
            // ending now, starting a quarter of an hour earlier within the day
            const now = new Date();
            const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const startTime = new Date(Math.max(now.getTime() - 15 * 60 * 1000, startOfDay.getTime()));
            const toFormattedDate = (date) => {
                const dd = String(date.getDate()).padStart(2, '0');
                const mm = String(date.getMonth() + 1).padStart(2, '0');

                return `${dd}.${mm}.${date.getFullYear()}`;
            };
            const timeOptions = { hour: '2-digit', minute: '2-digit', hour12: false };

            this.codeCode = '';
            this.assessorEmployeeId = '';
            this.description = '';
            this.note = '';
            this.summary = '';
            this.previous = [];
            this.problems = [];
            this.findings = [];
            this.supportingInfo = [];
            // The clinical impression is made within the encounter, so it defaults to the encounter period
            const encounterDate = encounter?.periodDate;

            this.effectiveType = 'period';
            this.effectiveDate = '';
            this.effectiveTime = '';
            this.effectivePeriodStartDate = encounterDate || toFormattedDate(startTime);
            this.effectivePeriodStartTime =
                (encounterDate && encounter?.periodStart) || startTime.toLocaleTimeString('uk-UA', timeOptions);
            this.effectivePeriodEndDate = encounterDate || toFormattedDate(now);
            this.effectivePeriodEndTime =
                (encounterDate && encounter?.periodEnd) || now.toLocaleTimeString('uk-UA', timeOptions);

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
