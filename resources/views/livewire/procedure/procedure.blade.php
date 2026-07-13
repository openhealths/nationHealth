<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            {{ __('patients.procedures') }} - {{ $patientFullName }}
        </x-slot>
    </x-header-navigation>

    <form class="form"
          x-data="{
                modalProcedure: new Procedure(@js($this->form->procedure)),

                prepareProcedureForSubmit() {
                    this.modalProcedure.usedReferences = this.modalProcedure.usedReferences
                        .filter(reference => reference.id);

                    return JSON.parse(JSON.stringify(this.modalProcedure));
                },

                addUsedReference() {
                    this.modalProcedure.usedReferences.push({ id: '' });
                },

                removeUsedReference(index) {
                    this.modalProcedure.usedReferences.splice(index, 1);
                }
          }"
    >

        <fieldset @disabled($isReadonly) @class(['pointer-events-none opacity-80' => $isReadonly])>
            @include('livewire.encounter.procedure-parts.main-information', ['context' => 'procedure'])
            @include('livewire.encounter.procedure-parts.additional-information', ['context' => 'procedure'])
            @include('livewire.encounter.procedure-parts.reason-references', ['wireProp' => 'reasonReferenceResults'])
            @include('livewire.encounter.procedure-parts.used-codes')
            @if(!empty(data_get($this->form->procedure, 'encounterId')))
                @include('livewire.encounter.procedure-parts.complication-details', ['context' => 'procedure'])
            @endif
        </fieldset>

        <div class="flex gap-8">
            <a href="{{ url()->previous() }}" type="submit" class="button-minor">
                {{ __('forms.back') }}
            </a>

            @unless($isReadonly)
                <button @click.prevent="$wire.save(prepareProcedureForSubmit())" type="submit" class="button-primary-outline">
                    {{ __('forms.save') }}
                </button>

                <button
                    @click="$wire.openSignatureModal(prepareProcedureForSubmit())"
                    type="button"
                    class="button-primary flex items-center gap-2"
                >
                    @icon('key', 'w-5 h-5')
                    {{ __('forms.complete_the_interaction_and_sign') }}
                    @icon('arrow-right', 'w-5 h-5')
                </button>
            @endunless
        </div>

        @unless($isReadonly)
            <x-signature-modal method="sign" />
        @endunless
    </form>

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</section>

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

            this.status = '';
            this.basedOnIdentifier = '';
            this.usedReferences = [];
            this.categoryCode = '';
            this.codeValue = '';
            this.divisionId = '';
            this.outcomeCode = '';
            this.primarySource = true;
            this.reportOriginCode = '';
            this.reportOriginText = '';
            this.isReferralAvailable = true;
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
            this.encounterId = '';
            this.performedPeriodStartDate = toFormattedDate(startTime);
            this.performedPeriodStartTime = startTime.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });
            this.performedPeriodEndDate = toFormattedDate(now);
            this.performedPeriodEndTime = now.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
