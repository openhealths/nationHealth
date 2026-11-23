<div class="relative">
    {{-- This required for table overflow scrolling --}}
    <fieldset class="fieldset">
        <legend class="legend">
            <h2>{{ __('conditions.evidence') }}</h2>
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
                <template x-for="(detail, index) in modalCondition.evidenceDetails">
                    <tr>
                        <td class="td-input" x-text="detail.ehealthInsertedAt || ''"></td>
                        <td
                            class="td-input"
                            x-text="
                                `${detail.codeCode} - ${
                                    $wire.dictionaries['eHealth/LOINC/observation_codes'][detail.codeCode] ||
                                    $wire.dictionaries['eHealth/ICF/classifiers'][detail.codeCode] ||
                                    $wire.dictionaries['eHealth/ICD10_AM/condition_codes'][detail.codeCode] ||
                                    $wire.dictionaries['eHealth/ICPC2/condition_codes'][detail.codeCode] ||
                                    detail.description ||
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
                                                modalCondition.evidenceDetails.splice(index, 1);
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
            {{-- Button to trigger the evidence search drawer --}}
            <button @click.prevent="openEvidenceDrawer = true" class="item-add my-5">{{ __('forms.add') }}</button>
        </div>
    </fieldset>
</div>
