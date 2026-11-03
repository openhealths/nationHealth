@use('App\Models\Person\PersonRequest')

<div
    x-data="{
        showLeafletModal: $wire.entangle('showLeafletModal'),
        patientSigned: $wire.entangle('form.patientSigned'),
    }"
>
    <template x-teleport="body">
        <div
            x-show="showLeafletModal"
            style="display: none"
            @keydown.escape.prevent.stop="showLeafletModal = false"
            role="dialog"
            aria-modal="true"
            class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition @click="showLeafletModal = false" class="modal-wrapper">
                <div
                    @click.stop
                    x-trap.noscroll.inert="showLeafletModal"
                    class="modal-content mx-auto w-full max-w-4xl"
                >
                    <div
                        class="xl:flex-container mb-4.5 flex flex-col gap-6"
                        x-data="{
                            printContent() {
                                let printWindow = window.open('', '_blank');
                                printWindow.document.body.innerHTML = $wire.leafletContent;
                                printWindow.focus();
                                printWindow.print();
                            },
                        }"
                    >
                        <div class="patient-leaflet-document mb-4.5">
                            {!! $leafletContent !!}
                        </div>

                        <button
                            @click="printContent()"
                            type="button"
                            class="button-minor mb-6 max-w-full self-center gap-2"
                        >
                            @icon('printer', 'w-4 h-4 shrink-0')
                            <span>
                                {{ __('patients.print_leaflet_for_patient') }}
                            </span>
                        </button>

                        @can('sign', PersonRequest::class)
                            <div class="form-row">
                                <div class="form-group group">
                                    <input
                                        x-model="patientSigned"
                                        type="checkbox"
                                        name="patientSigned"
                                        id="patientSigned"
                                        class="default-checkbox"
                                    />
                                    <label class="default-p" for="patientSigned">
                                        {{ __('patients.leaflet.patient_signed_mark') }}
                                    </label>
                                </div>
                            </div>
                        @endcan

                        <div class="mb-4.5 flex flex-wrap items-center justify-center gap-6">
                            <button type="button" class="button-danger" wire:click="reject">
                                {{ __('patients.reject') }}
                            </button>
                            @can('sign', PersonRequest::class)
                                <button
                                    wire:click="openSignatureModal"
                                    type="button"
                                    class="button-primary"
                                    :disabled="! patientSigned"
                                >
                                    {{ __('forms.sign') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
