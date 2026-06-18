<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            {{ __('patients.diagnostic_reports') }} - {{ $patientFullName }}
        </x-slot>
    </x-header-navigation>

    <form
        class="form"
        x-data="{
            modalDiagnosticReport: new DiagnosticReport(@js($this->form->diagnosticReport)),
            equipmentOptions: @js($equipmentOptions),
            diagnosticReportCategoriesDictionary: $wire.dictionaries['eHealth/diagnostic_report_categories'],
            servicesDictionary: $wire.dictionaries['custom/services'],
            showSignatureModal: false,

            addUsedReference() {
                this.modalDiagnosticReport.usedReferences.push({
                    id: ''
                });
            },

            removeUsedReference(index) {
                this.modalDiagnosticReport.usedReferences.splice(index, 1);
            }
        }"
    >

        @include('livewire.encounter.diagnostic-report-parts.main-information', ['context' => 'diagnostic-report'])
        @include('livewire.encounter.diagnostic-report-parts.additional-information', ['context' => 'diagnostic-report'])
        @include('livewire.encounter.parts.observations')

        <div class="flex gap-8">
            <a href="{{ url()->previous() }}" type="submit" class="button-minor">
                {{ __('forms.back') }}
            </a>

            <button @click.prevent="$wire.save(modalDiagnosticReport)" type="submit" class="button-primary-outline">
                {{ __('forms.save') }}
            </button>

            <button
                @click="$wire.openSignatureModal(modalDiagnosticReport)"
                type="button"
                class="button-primary flex items-center gap-2"
            >
                @icon('key', 'w-5 h-5')
                {{ __('forms.complete_the_interaction_and_sign') }}
                @icon('arrow-right', 'w-5 h-5')
            </button>
        </div>
    </form>

    <x-signature-modal method="sign" />
    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</section>

<script>
    /**
     * Representation of the user's personal diagnostic report.
     */
    class DiagnosticReport {
        constructor(obj = null) {
            const now = new Date();
            const startTime = new Date(now.getTime() - 15 * 60 * 1000);

            const toFormattedDate = (date) => {
                const [yyyy, mm, dd] = date.toISOString().split('T')[0].split('-');
                return `${dd}.${mm}.${yyyy}`;
            };

            const timeOptions = { hour: '2-digit', minute: '2-digit', hour12: false };

            this.categoryCode = '';
            this.codeValue = '';

            this.isReferralAvailable = false;
            this.referralType = '';

            this.paperReferralRequisition = '';
            this.paperReferralRequesterEmployeeName = '';
            this.paperReferralRequesterLegalEntityEdrpou = '';
            this.paperReferralRequesterLegalEntityName = '';
            this.paperReferralServiceRequestDate = '';
            this.paperReferralNote = '';

            this.conclusionCode = '';
            this.conclusionCodeLabel = '';
            this.conclusion = '';

            this.primarySource = true;
            this.reportOriginCode = '';
            this.reportOriginText = '';

            this.divisionId = '';
            this.resultsInterpreterEmployeeId = '';
            this.usedReferences = [];

            this.issuedDate = toFormattedDate(now);
            this.issuedTime = now.toLocaleTimeString('uk-UA', timeOptions);

            this.effectivePeriodStartDate = toFormattedDate(startTime);
            this.effectivePeriodStartTime = startTime.toLocaleTimeString('uk-UA', timeOptions);

            this.effectivePeriodEndDate = toFormattedDate(now);
            this.effectivePeriodEndTime = now.toLocaleTimeString('uk-UA', timeOptions);

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
