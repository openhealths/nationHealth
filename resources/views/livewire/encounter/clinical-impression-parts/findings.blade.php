@use('Carbon\CarbonImmutable')

<div class="relative">
    {{-- This required for table overflow scrolling --}}
    <fieldset
        class="fieldset"
        x-data="{
            openModal: false,

            isFindingAdded(recordId) {
                return modalClinicalImpression.findings.some((finding) => finding.id === recordId);
            },

            addFinding(record) {
                if (this.isFindingAdded(record.id)) {
                    return;
                }

                modalClinicalImpression.findings = modalClinicalImpression.findings.concat({
                    id: record.id,
                    ehealthInsertedAt: record.ehealthInsertedAt,
                    codeCode: record.codeCode,
                    type: record.type,
                    description: record.description,
                    basis: '',
                });
            },
        }"
        @clinical-impression-finding-selected.window="addFinding($event.detail.record)"
    >
        <legend class="legend">
            <h2>{{ __('clinical-impressions.findings') }}</h2>
        </legend>

        <table class="table-input w-inherit">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                    <th scope="col" class="th-input">{{ __('medical-events.code_and_name') }}</th>
                    <th scope="col" class="th-input">{{ __('clinical-impressions.findings_basis') }}</th>
                    <th scope="col" class="th-input">{{ __('forms.action') }}</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(finding, index) in modalClinicalImpression.findings">
                    <tr>
                        <td class="td-input" x-text="finding.ehealthInsertedAt || ''"></td>
                        <td
                            class="td-input"
                            x-text="
                                `${finding.codeCode} - ${
                                    $wire.dictionaries['eHealth/LOINC/observation_codes'][finding.codeCode] ||
                                    $wire.dictionaries['eHealth/ICF/classifiers'][finding.codeCode] ||
                                    $wire.dictionaries['eHealth/ICD10_AM/condition_codes'][finding.codeCode] ||
                                    $wire.dictionaries['eHealth/ICPC2/condition_codes'][finding.codeCode] ||
                                    finding.description ||
                                    ''
                                }`
                            "
                        ></td>
                        <td class="td-input">
                            <label :for="`findingBasis${index}`" class="sr-only">
                                {{ __('clinical-impressions.findings_basis') }}
                            </label>
                            <input
                                x-model="finding.basis"
                                :id="`findingBasis${index}`"
                                type="text"
                                class="input"
                                maxlength="255"
                                autocomplete="off"
                            />
                        </td>
                        <td class="td-input">
                            {{-- That all that is needed for the dropdown --}}
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
                                @focusin.window="! $refs.panel.contains($event.target) && close()"
                                x-id="['dropdown-button']"
                                class="relative"
                            >
                                {{-- Dropdown Button --}}
                                <button
                                    x-ref="button"
                                    @click="toggle()"
                                    :aria-expanded="openDropdown"
                                    :aria-controls="$id('dropdown-button')"
                                    type="button"
                                >
                                    <svg
                                        class="h-6 w-6 cursor-pointer text-gray-800 dark:text-gray-200"
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
                                <div class="absolute" style="left: 50%">
                                    {{-- Center a dropdown panel --}}
                                    <div
                                        x-ref="panel"
                                        x-show="openDropdown"
                                        x-transition.origin.top.left
                                        @click.outside="close($refs.button)"
                                        :id="$id('dropdown-button')"
                                        x-cloak
                                        class="dropdown-panel relative"
                                        style="left: -50%"
                                        {{-- Center a dropdown panel --}}
                                    >
                                        <button
                                            @click.prevent="
                                                modalClinicalImpression.findings.splice(index, 1);
                                                close($refs.button);
                                            "
                                            class="dropdown-button dropdown-delete"
                                        >
                                            {{ __('forms.delete') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <div>
            {{-- Button to trigger the modal --}}
            <button @click.prevent="openModal = true" class="item-add my-5">{{ __('forms.add') }}</button>

            {{-- Modal --}}
            <template x-teleport="body">
                {{-- This moves the modal at the end of the body tag --}}
                <div
                    x-show="openModal"
                    style="display: none"
                    @keydown.escape.prevent.stop="openModal = false"
                    role="dialog"
                    aria-modal="true"
                    x-id="['modal-title']"
                    :aria-labelledby="$id('modal-title')"
                    class="modal"
                >
                    {{-- Overlay --}}
                    <div x-show="openModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

                    {{-- Panel --}}
                    <div
                        x-show="openModal"
                        x-transition
                        @click="openModal = false"
                        class="relative flex min-h-screen items-center justify-center p-4"
                    >
                        <div
                            @click.stop
                            x-trap.noscroll.inert="openModal"
                            class="modal-content h-fit w-full lg:max-w-4xl"
                        >
                            {{-- Title --}}
                            <h3 class="modal-header" :id="$id('modal-title')">{{ __('forms.add') }}</h3>

                            {{-- Content --}}
                            <livewire:encounter.medical-record-search
                                :patient-uuid="$patientUuid"
                                selection-event="clinical-impression-finding-selected"
                                is-added-check="isFindingAdded"
                                :episodes="$episodes"
                                :key="'clinical-impression-finding-search'"
                            />

                            <div class="mt-6 flex justify-between space-x-2">
                                <button type="button" @click="openModal = false" class="button-minor cursor-pointer">
                                    {{ __('forms.close') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </fieldset>
</div>
