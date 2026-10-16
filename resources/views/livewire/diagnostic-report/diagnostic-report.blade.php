@php
    use App\Livewire\DiagnosticReport\DiagnosticReportView;
    use App\Models\MedicalEvents\Sql\DiagnosticReport;

    $isReadonly = $isReadonly ?? ($this->isReadonly ?? false);
@endphp
<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">{{ __('diagnostic-reports.plural') }} - {{ $patientFullName }}</x-slot>
    </x-header-navigation>

    @if ($this instanceof DiagnosticReportView)
        <div class="form mb-4">
            <livewire:components.submission-status
                :recordType="DiagnosticReport::class"
                :recordId="$diagnosticReportId"
                :recordStatusLabel="$statusLabel"
                :editUrl="$editUrl"
            />
        </div>
    @endif

    <form
        class="form"
        x-data="{
            modalDiagnosticReport: new DiagnosticReport(@js($this->form->diagnosticReport), @js(auth()->user()->getDiagnosticReportWriterEmployee()?->uuid)),
            defaultDiagnosticReportPerformerEmployeeId: @js(auth()->user()->getDiagnosticReportWriterEmployee()?->uuid),
            equipmentOptions: @js($equipmentOptions),
            diagnosticReportEmployees: @js($employees),
            diagnosticReportCategoriesDictionary: $wire.dictionaries['eHealth/diagnostic_report_categories'],
            servicesDictionary: $wire.dictionaries['custom/services'],
            openServiceCatalog: false,
            selectedServiceFromCatalog: null,
            showSignatureModal: false,

            addUsedReference() {
                this.modalDiagnosticReport.usedReferences.push({
                    id: ''
                });
            },

            removeUsedReference(index) {
                this.modalDiagnosticReport.usedReferences.splice(index, 1);
            },

            selectDiagnosticReportService(service) {
                this.modalDiagnosticReport.categoryCode = service.category;
                this.modalDiagnosticReport.codeValue = service.id;
                this.selectedServiceFromCatalog = service;
                this.openServiceCatalog = false;
            },

            specimenOptions() {
                const specimenTypes = $wire.dictionaries['specimen_types'] ?? {};
                const selectedSpecimenIds = this.modalDiagnosticReport.specimenIds ?? [];

                return $wire.patientSpecimens
                    .filter((specimen) => specimen.status === 'available' || selectedSpecimenIds.includes(specimen.uuid))
                    .map((specimen) => ({
                        uuid: specimen.uuid,
                        name: specimenTypes[specimen.typeCode] || specimen.uuid
                    }));
            },

            setEffectiveType(type) {
                const now = new Date();

                const startTime = new Date(
                    now.getTime() - 15 * 60 * 1000
                );

                const toFormattedDate = (date) => {
                    const [yyyy, mm, dd] = date
                        .toISOString()
                        .split('T')[0]
                        .split('-');

                    return `${dd}.${mm}.${yyyy}`;
                };

                const timeOptions = {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                };

                this.modalDiagnosticReport.effectiveType = type;

                if (type === 'date_time') {
                    this.modalDiagnosticReport.effectiveDate =
                        this.modalDiagnosticReport.issuedDate
                        || toFormattedDate(now);

                    this.modalDiagnosticReport.effectiveTime =
                        this.modalDiagnosticReport.issuedTime
                        || now.toLocaleTimeString(
                            'uk-UA',
                            timeOptions
                        );

                    this.modalDiagnosticReport.effectivePeriodStartDate = '';
                    this.modalDiagnosticReport.effectivePeriodStartTime = '';
                    this.modalDiagnosticReport.effectivePeriodEndDate = '';
                    this.modalDiagnosticReport.effectivePeriodEndTime = '';

                    return;
                }

                if (type === 'period') {
                    this.modalDiagnosticReport.effectiveDate = '';
                    this.modalDiagnosticReport.effectiveTime = '';

                    this.modalDiagnosticReport.effectivePeriodStartDate =
                        toFormattedDate(startTime);

                    this.modalDiagnosticReport.effectivePeriodStartTime =
                        startTime.toLocaleTimeString(
                            'uk-UA',
                            timeOptions
                        );

                    this.modalDiagnosticReport.effectivePeriodEndDate =
                        toFormattedDate(now);

                    this.modalDiagnosticReport.effectivePeriodEndTime =
                        now.toLocaleTimeString(
                            'uk-UA',
                            timeOptions
                        );

                    return;
                }

                this.modalDiagnosticReport.effectiveDate = '';
                this.modalDiagnosticReport.effectiveTime = '';
                this.modalDiagnosticReport.effectivePeriodStartDate = '';
                this.modalDiagnosticReport.effectivePeriodStartTime = '';
                this.modalDiagnosticReport.effectivePeriodEndDate = '';
                this.modalDiagnosticReport.effectivePeriodEndTime = '';
            }
        }"
        @diagnostic-report-service-selected.window="selectDiagnosticReportService($event.detail.service)"
    >
        <fieldset @disabled($isReadonly) @class(['pointer-events-none opacity-80' => $isReadonly])>
            @include('livewire.encounter-package.diagnostic-report-parts.main-information', ['context' => 'diagnostic-report'])
            @include('livewire.encounter-package.diagnostic-report-parts.additional-information', ['context' => 'diagnostic-report'])
            <fieldset class="fieldset">
                <legend class="legend">{{ __('observations.plural') }}</legend>

                @include('livewire.encounter-package.parts.observations', ['context' => 'diagnostic-report'])
            </fieldset>
        </fieldset>

        @unless ($isReadonly)
            <x-dialog-drawer
                x-model="openServiceCatalog"
                onCloseClick="openServiceCatalog = false"
                maxWidth="4/5"
                overlayWidth="100%"
                zIndex="50"
            >
                <livewire:dictionary.service-catalog
                    :legal-entity="legalEntity()"
                    :selection-mode="true"
                    selection-event="diagnostic-report-service-selected"
                    :allowed-categories="array_keys($this->dictionaries['eHealth/diagnostic_report_categories'] ?? [])"
                    :key="'diagnostic-report-service-catalog'"
                />

                <div class="mt-8">
                    <button type="button" @click="openServiceCatalog = false" class="button-minor">
                        {{ __('forms.cancel') }}
                    </button>
                </div>
            </x-dialog-drawer>
        @endunless

        <div class="flex gap-8">
            <a href="{{ url()->previous() }}" type="submit" class="button-minor"> {{ __('forms.back') }} </a>

            @unless ($isReadonly)
                <button
                    @click.prevent="
                        modalDiagnosticReport.performerEmployeeIds =
                            modalDiagnosticReport.performerEmployeeIds.filter(Boolean);
                        modalDiagnosticReport.specimenIds = modalDiagnosticReport.specimenIds.filter(Boolean);

                        $wire.save(modalDiagnosticReport);
                    "
                    :disabled="['diagnostic_procedure', 'imaging'].includes(modalDiagnosticReport.categoryCode) &&
                    ! String(modalDiagnosticReport.resultsInterpreterEmployeeId ?? '').trim()"
                    type="submit"
                    class="button-primary-outline"
                >
                    {{ __('forms.save') }}
                </button>

                <button
                    @click="
                        modalDiagnosticReport.performerEmployeeIds =
                            modalDiagnosticReport.performerEmployeeIds.filter(Boolean);
                        modalDiagnosticReport.specimenIds = modalDiagnosticReport.specimenIds.filter(Boolean);

                        $wire.openSignatureModal(modalDiagnosticReport);
                    "
                    :disabled="['diagnostic_procedure', 'imaging'].includes(modalDiagnosticReport.categoryCode) &&
                    ! String(modalDiagnosticReport.resultsInterpreterEmployeeId ?? '').trim()"
                    type="button"
                    class="button-primary flex items-center gap-2"
                >
                    @icon('key', 'w-5 h-5')
                    {{ __('forms.complete_the_interaction_and_sign') }}
                    @icon('arrow-right', 'w-5 h-5')
                </button>
            @endunless
    </form>

    @unless ($isReadonly)
        <x-signature-modal method="sign" />
    @endunless

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</section>

<script>
    /**
     * Representation of the user's personal diagnostic report.
     */
    class DiagnosticReport {
        constructor(obj = null, defaultPerformerEmployeeId = '') {
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
            this.basedOnIdentifier = '';

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
            this.performerEmployeeIds = defaultPerformerEmployeeId ? [defaultPerformerEmployeeId] : [];
            this.resultsInterpreterEmployeeId = '';
            this.usedReferences = [];
            this.specimenIds = [];

            this.issuedDate = toFormattedDate(now);
            this.issuedTime = now.toLocaleTimeString('uk-UA', timeOptions);

            this.effectiveType = 'period';

            this.effectiveDate = '';
            this.effectiveTime = '';

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
