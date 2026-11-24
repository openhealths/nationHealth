@use('Carbon\CarbonImmutable')

<div class="relative">
    {{-- This required for table overflow scrolling --}}
    <fieldset
        class="fieldset"
        x-data="{
            openModal: false,

            isProblemAdded(recordId) {
                return modalClinicalImpression.problems.some((problem) => problem.id === recordId);
            },

            addProblem(record) {
                if (this.isProblemAdded(record.id)) {
                    return;
                }

                modalClinicalImpression.problems = modalClinicalImpression.problems.concat({
                    id: record.id,
                    ehealthInsertedAt: record.ehealthInsertedAt,
                    codeCode: record.codeCode,
                    description: record.description,
                });
            },
        }"
        @clinical-impression-problem-selected.window="addProblem($event.detail.record)"
    >
        <legend class="legend">
            <h2>{{ __('clinical-impressions.problems') }}</h2>
        </legend>

        <div class="table-scroll">
        <table class="table-input w-inherit">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                    <th scope="col" class="th-input">{{ __('medical-events.code_and_name') }}</th>
                    <th scope="col" class="th-input">{{ __('forms.action') }}</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(problem, index) in modalClinicalImpression.problems">
                    <tr>
                        <td class="td-input" x-text="problem.ehealthInsertedAt || ''"></td>
                        <td
                            class="td-input"
                            x-text="
                                `${problem.codeCode} - ${
                                    $wire.dictionaries['eHealth/ICD10_AM/condition_codes'][problem.codeCode] ||
                                    $wire.dictionaries['eHealth/ICPC2/condition_codes'][problem.codeCode] ||
                                    problem.description ||
                                    ''
                                }`
                            "
                        ></td>
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
                                    class="record-inner-action-btn"
                                >
                                    @icon('edit-user-outline', 'svg-hover-action h-6 w-6')
                                </button>

                                {{-- Dropdown Panel --}}
                                <div class="absolute left-1/2">
                                    {{-- Center a dropdown panel --}}
                                    <div
                                        x-ref="panel"
                                        x-show="openDropdown"
                                        x-transition.origin.top.left
                                        @click.outside="close($refs.button)"
                                        :id="$id('dropdown-button')"
                                        x-cloak
                                        class="dropdown-panel relative -left-1/2!"
                                        {{-- Center a dropdown panel --}}
                                    >
                                        <button
                                            class="dropdown-button dropdown-delete"
                                            @click.prevent="
                                                modalClinicalImpression.problems.splice(index, 1);
                                                close($refs.button);
                                            "
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
        </div>

        <div>
            {{-- Button to trigger the modal --}}
            <button @click.prevent="openModal = true" class="item-add my-5">{{ __('forms.add') }}</button>

            {{-- Modal --}}
            <template x-teleport="body">
                {{-- This moves the modal at the end of the body tag --}}
                <div
                    x-show="openModal"
                    x-cloak
                    @keydown.escape.prevent.stop="openModal = false"
                    role="dialog"
                    aria-modal="true"
                    x-id="['modal-title']"
                    :aria-labelledby="$id('modal-title')"
                    {{-- This associates the modal with unique ID --}}
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
                            <livewire:encounter-package.medical-record-search
                                :patient-uuid="$patientUuid"
                                selection-event="clinical-impression-problem-selected"
                                is-added-check="isProblemAdded"
                                fixed-record-type="condition"
                                :key="'clinical-impression-problem-search'"
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
