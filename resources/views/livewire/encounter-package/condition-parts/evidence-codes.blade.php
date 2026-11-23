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

        <div class="table-scroll">
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
        </div>

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

<script data-navigate-once>
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
