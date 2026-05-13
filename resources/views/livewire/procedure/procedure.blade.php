<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            {{ __('patients.procedures') }} - {{ $patientFullName }}
        </x-slot>
    </x-header-navigation>

    <form class="form"
          x-data="{
              procedures: $wire.entangle('form.procedures'),
              modalProcedure: new Procedure(),
              showSignatureModal: false
          }"
    >

        @include('livewire.encounter.procedure-parts.main-information', ['context' => 'procedure'])
        @include('livewire.encounter.procedure-parts.additional-information', ['context' => 'procedure'])
        @include('livewire.encounter.procedure-parts.reason-references', ['wireProp' => 'reasonReferenceResults'])
        @include('livewire.encounter.procedure-parts.used-codes')

        <div class="flex gap-8">
            <a href="{{ url()->previous() }}" type="submit" class="button-minor">
                {{ __('forms.back') }}
            </a>

            <button @click.prevent="$wire.save(modalProcedure)" type="submit" class="button-primary-outline">
                {{ __('forms.save') }}
            </button>

            <button @click="showSignatureModal = true"
                    type="button"
                    class="button-primary flex items-center gap-2"
            >
                @icon('key', 'w-5 h-5')
                {{ __('forms.complete_the_interaction_and_sign') }}
                @icon('arrow-right', 'w-5 h-5')
            </button>
        </div>

        <template x-if="showSignatureModal">
            @include('livewire.procedure.modals.signature')
        </template>
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
            this.performedPeriodStartDate = startTime.toISOString().split('T')[0];
            this.performedPeriodStartTime = startTime.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });
            this.performedPeriodEndDate = now.toISOString().split('T')[0];
            this.performedPeriodEndTime = now.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
