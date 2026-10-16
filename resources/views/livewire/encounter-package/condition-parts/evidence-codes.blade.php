{{-- Component to input values to the table through the Modal, built with Alpine --}}
<div class="relative">
    {{-- This required for table overflow scrolling --}}
    <fieldset
        class="fieldset"
        {{-- Binding evidenceCodes to Alpine, it will be re-used in the modal.
                Note that it's necessary for modal to work properly --}}
        x-data="{
            openModal: false,
            modalEvidenceCode: new EvidenceCode(),
            newEvidenceCode: false,
            item: 0,
            dictionary: $wire.dictionaries['eHealth/ICPC2/condition_codes'],
        }"
    >
        <legend class="legend">
            <h2>{{ __('conditions.plural') }}</h2>
        </legend>

        <table class="table-input w-inherit">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="th-input">{{ __('conditions.label') }}</th>
                    <th scope="col" class="th-input">{{ __('forms.action') }}</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(evidence, index) in modalCondition.evidenceCodes">
                    <tr>
                        <td class="td-input" x-text="`${evidence.code} - ${dictionary[evidence.code]}`"></td>
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
                                                openModal = true;
                                                item = index;
                                                modalEvidenceCode = new EvidenceCode({
                                                    code: evidence.code,
                                                    system: evidence.system,
                                                });
                                                newEvidenceCode = false;
                                            "
                                            class="dropdown-button"
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            @click.prevent="
                                                modalCondition.evidenceCodes.splice(index, 1);
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
            {{-- Button to trigger the drawer --}}
            <button
                @click.prevent="
                    openModal = true;
                    newEvidenceCode = true;
                    modalEvidenceCode = new EvidenceCode();
                "
                class="item-add my-5"
            >
                {{ __('forms.add') }}
            </button>

            <x-dialog-drawer
                x-model="openModal"
                maxWidth="3/5"
                overlayWidth="100%"
                zIndex="45"
                stopClickPropagation="true"
                wire:ignore
            >
                <x-slot name="title">{{ __('conditions.new_evidence_condition') }}</x-slot>

                <form class="mt-4 space-y-6">
                    <div class="mb-6 grid grid-cols-1 gap-x-8 gap-y-6 md:grid-cols-2">
                        <div>
                            <label
                                for="evidenceCode"
                                class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                            >
                                {{ __('medical-events.icpc2_status_code') }}<span class="text-red-600"> *</span>
                            </label>
                            <div class="relative">
                                <x-select2
                                    modelPath="modalEvidenceCode.code"
                                    dictionaryName="eHealth/ICPC2/condition_codes"
                                    id="evidenceCode"
                                    class="input w-full"
                                />
                                @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex space-x-2">
                        <button type="button" @click.prevent @click="openModal = false" class="button-minor">
                            {{ __('forms.cancel') }}
                        </button>

                        <button
                            @click.prevent="
                                if (newEvidenceCode !== false) {
                                    modalCondition.evidenceCodes.push({
                                        code: modalEvidenceCode.code,
                                        system: modalEvidenceCode.system,
                                    });
                                } else {
                                    modalCondition.evidenceCodes[item] = {
                                        code: modalEvidenceCode.code,
                                        system: modalEvidenceCode.system,
                                    };
                                }

                                openModal = false;
                            "
                            class="button-primary"
                            :disabled="! modalEvidenceCode.code.trim()"
                        >
                            {{ __('forms.add') }}
                        </button>
                    </div>
                </form>
            </x-dialog-drawer>
        </div>
    </fieldset>
</div>

<script>
    /**
     * Representation of the user's personal evidenceCode
     */
    class EvidenceCode {
        constructor(obj = null) {
            this.code = '';
            this.system = 'eHealth/ICPC2/reasons';

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
