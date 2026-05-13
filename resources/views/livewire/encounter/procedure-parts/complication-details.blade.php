
<div class="relative"> {{-- This required for table overflow scrolling --}}
    <fieldset class="fieldset"
              {{-- Binding ComplicationDetail to Alpine, it will be re-used in the modal.
                Note that it's necessary for modal to work properly --}}
              x-data="{
                  openModal: false,
                  modalComplicationDetail: new ComplicationDetail(),
                  newComplicationDetail: false,
                  item: 0,
                  searchResults: [],
                  selectedComplicationDetailIds: []
              }"
    >
        <legend class="legend">
            <h2>{{ __('patients.complications_arising_during_the_procedure') }}</h2>
        </legend>

        <table class="table-input w-inherit">
            <thead class="thead-input">
            <tr>
                <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                <th scope="col" class="th-input">{{ __('patients.code_and_name') }}</th>
                <th scope="col" class="th-input">{{ __('forms.action') }}</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(complicationDetail, index) in modalProcedure.complicationDetails">
                <tr>
                    <td class="td-input"
                        x-text="complicationDetail.insertedAt ? new Date(complicationDetail.insertedAt).toLocaleDateString('uk-UA') : ''"
                    ></td>
                    <td class="td-input"
                        x-text="`${ complicationDetail.codeCode } - ${
                            $wire.dictionaries['eHealth/LOINC/observation_codes']?.[complicationDetail.codeCode] ||
                            $wire.dictionaries['eHealth/ICF/classifiers']?.[complicationDetail.codeCode] ||
                            $wire.dictionaries['eHealth/ICPC2/condition_codes']?.[complicationDetail.codeCode] ||
                            $wire.dictionaries['eHealth/ICD10_AM/condition_codes']?.[complicationDetail.codeCode]
                        }`"
                    ></td>
                    <td class="td-input">
                        {{-- That all that is needed for the dropdown --}}
                        <div x-data="{
                                 openDropdown: false,
                                 toggle() {
                                     if (this.openDropdown) {
                                         return this.close();
                                     }

                                     this.$refs.button.focus();

                                     this.openDropdown = true;
                                 },
                                 close(focusAfter) {
                                     if (!this.openDropdown) return;

                                     this.openDropdown = false;

                                     focusAfter && focusAfter.focus()
                                 }
                             }"
                             @keydown.escape.prevent.stop="close($refs.button)"
                             @focusin.window="!$refs.panel.contains($event.target) && close()"
                             x-id="['dropdown-button']"
                             class="relative"
                        >
                            {{-- Dropdown Button --}}
                            <button x-ref="button"
                                    @click="toggle()"
                                    :aria-expanded="openDropdown"
                                    :aria-controls="$id('dropdown-button')"
                                    type="button"
                            >
                                <svg class="w-6 h-6 text-gray-800 dark:text-gray-200 cursor-pointer" aria-hidden="true"
                                     xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"
                                     viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round"
                                          stroke-width="2"
                                          d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"/>
                                </svg>
                            </button>

                            {{-- Dropdown Panel --}}
                            <div class="absolute" style="left: 50%"> {{-- Center a dropdown panel --}}
                                <div x-ref="panel"
                                     x-show="openDropdown"
                                     x-transition.origin.top.left
                                     @click.outside="close($refs.button)"
                                     :id="$id('dropdown-button')"
                                     x-cloak
                                     class="dropdown-panel relative"
                                     style="left: -50%" {{-- Center a dropdown panel --}}
                                >
                                    <button @click.prevent="modalProcedure.complicationDetails.splice(index, 1); close($refs.button);"
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
            <button @click.prevent="
                        openModal = true;
                        newComplicationDetail = true;
                        modalComplicationDetail = new ComplicationDetail();
                        selectedComplicationDetailIds = [];
                        $wire.searchConditionsOrObservations('condition').then(() => {
                            searchResults = JSON.parse(JSON.stringify($wire.evidenceDetails));
                        });
                    "
                    class="item-add my-5"
            >
                {{ __('forms.add') }}
            </button>

            {{-- Modal --}}
            <template x-teleport="body"> {{-- This moves the modal at the end of the body tag --}}
                <div x-show="openModal"
                     style="display: none"
                     @keydown.escape.prevent.stop="openModal = false"
                     role="dialog"
                     aria-modal="true"
                     x-id="['modal-title']"
                     :aria-labelledby="$id('modal-title')" {{-- This associates the modal with unique ID --}}
                     class="modal"
                >

                    {{-- Overlay --}}
                    <div x-show="openModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

                    {{-- Panel --}}
                    <div x-show="openModal"
                         x-transition
                         @click="openModal = false"
                         class="relative flex min-h-screen items-center justify-center p-4"
                    >
                        <div @click.stop
                             x-trap.noscroll.inert="openModal"
                             class="modal-content h-fit w-full lg:max-w-4xl"
                        >
                            {{-- Title --}}
                            <h3 class="modal-header" :id="$id('modal-title')">{{ __('forms.add') }}</h3>

                            {{-- Content --}}
                            <form>
                                <x-forms.loading/>

                                {{-- A table that shows the results of the found data --}}
                                <template x-if="searchResults.length > 0">
                                    <div class="table-container">
                                        <div class="overflow-visible">
                                            <table class="table-base">
                                                <thead class="table-header">
                                                <tr>
                                                    <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                                                    <th scope="col" class="th-input">{{ __('patients.code_and_name') }}</th>
                                                    <th scope="col" class="th-input">{{ __('forms.action') }}</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <template x-for="result in searchResults" :key="result.id">
                                                    <tr class="border-b dark:border-gray-700">
                                                        <th scope="row" class="table-cell-primary">
                                                            <div class="text-base"
                                                                 x-text="result.insertedAt ? new Date(result.insertedAt).toLocaleDateString('uk-UA') : ''"
                                                            ></div>
                                                        </th>
                                                        <td class="td-input"
                                                            x-text="`${ result.codeCode } - ${
                                                                $wire.dictionaries['eHealth/LOINC/observation_codes']?.[result.codeCode] ||
                                                                $wire.dictionaries['eHealth/ICF/classifiers']?.[result.codeCode] ||
                                                                $wire.dictionaries['eHealth/ICPC2/condition_codes']?.[result.codeCode] ||
                                                                $wire.dictionaries['eHealth/ICD10_AM/condition_codes']?.[result.codeCode]
                                                            }`"
                                                        ></td>
                                                        <td class="td-input">
                                                            <button @click.prevent="
                                                                        const id = result.id;
                                                                        const index = selectedComplicationDetailIds.indexOf(id);

                                                                        if (index === -1) {
                                                                            selectedComplicationDetailIds.push(id);
                                                                        } else {
                                                                            selectedComplicationDetailIds.splice(index, 1);
                                                                        }
                                                                    "
                                                                    class="button-primary w-28"
                                                                    x-text="selectedComplicationDetailIds.includes(result.id)
                                                                        ? '{{ __('patients.added') }}'
                                                                        : '{{ __('forms.add') }}'"
                                                            >
                                                            </button>
                                                        </td>
                                                    </tr>
                                                </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="searchResults.length <= 0">
                                    <p class="default-p">{{ __('forms.nothing_found') }}</p>
                                </template>

                                {{-- Action buttons --}}
                                <div class="mt-6 flex justify-between space-x-2">
                                    <button @click.prevent
                                            type="button"
                                            @click="openModal = false"
                                            class="button-minor"
                                    >
                                        {{ __('forms.cancel') }}
                                    </button>

                                    <button @click.prevent
                                            @click="
                                                const existingIds = modalProcedure.complicationDetails.map(cd => cd.id);

                                                const newDetails = searchResults
                                                    .filter(r => selectedComplicationDetailIds.includes(r.id) && !existingIds.includes(r.id));

                                                modalProcedure.complicationDetails = modalProcedure.complicationDetails.concat(newDetails);

                                                openModal = false;
                                                searchResults = [];
                                            "
                                            class="button-primary"
                                    >
                                        {{ __('forms.save') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </fieldset>
</div>

<script>
    /**
     * Representation of the user's personal ComplicationDetail
     */
    class ComplicationDetail {
        constructor(obj = null) {
            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
