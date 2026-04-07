<div class="relative"> {{-- This required for table overflow scrolling --}}
    <fieldset class="fieldset"
              {{-- Binding vaccinationProtocolCodes to Alpine, it will be re-used in the modal.
                Note that it's necessary for modal to work properly --}}
              x-data="{
                  openModal: false,
                  modalUsedCode: new UsedCode(),
                  newUsedCode: false,
                  item: 0,
                  dictionary: $wire.dictionaries['eHealth/assistive_products']
              }"
    >
        <legend class="legend">
            <h2>{{ __('patients.rehabilitation_aids') }}</h2>
        </legend>

        <table class="table-input w-inherit">
            <thead class="thead-input">
            <tr>
                <th scope="col" class="th-input">{{ __('forms.code') }}</th>
                <th scope="col" class="th-input">{{ __('patients.code_and_name') }}</th>
                <th scope="col" class="th-input">{{ __('forms.action') }}</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(usedCode, index) in modalProcedure.usedCodes">
                <tr>
                    <td class="td-input" x-text="usedCode.coding[0].code"></td>
                    <td class="td-input" x-text="dictionary[usedCode.coding[0].code]"
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
                             @focusin.window="! $refs.panel.contains($event.target) && close()"
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

                                    <button @click="
                                                openModal = true; {{-- Open the modal --}}
                                                item = index; {{-- Identify the item we are corrently editing --}}
                                                {{-- Replace the previous usedCode with the current, don't assign object directly (modalUsedCode = usedCode) to avoid reactiveness --}}
                                                modalUsedCode = new UsedCode(usedCode);
                                                newUsedCode = false; {{-- This usedCode is already created --}}
                                            "
                                            @click.prevent
                                            class="dropdown-button"
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button @click.prevent="modalProcedure.usedCodes.splice(index, 1); close($refs.button)"
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
                        openModal = true; {{-- Open the Modal --}}
                        newUsedCode = true; {{-- We are adding a new usedCode --}}
                        modalUsedCode = new UsedCode(); {{-- Replace the data of the previous usedCode with a new one--}}
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
                                <div class="form-row-modal">
                                    <div class="form-group group">
                                        <label for="usedCode" class="label-modal">
                                            {{ __('patients.code_and_name') }}
                                        </label>
                                        <x-select2 modelPath="modalUsedCode.coding[0].code"
                                                   dictionaryName="eHealth/assistive_products"
                                                   id="usedCode"
                                                   class="input-modal"
                                        />

                                        <p class="text-error text-xs"
                                           x-show="!Object.keys(dictionary).includes(modalUsedCode.coding[0].code)"
                                        >
                                            {{ __('forms.field_empty') }}
                                        </p>
                                    </div>
                                </div>

                                {{-- Actions button --}}
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
                                                newUsedCode !== false
                                                    ? modalProcedure.usedCodes.push(modalUsedCode)
                                                    : modalProcedure.usedCodes[item] = modalUsedCode;

                                                openModal = false;
                                            "
                                            class="button-primary"
                                            :disabled="!modalUsedCode.coding[0].code.trim()"
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
     * Representation of the user's personal UsedCode
     */
    class UsedCode {
        coding = [{ system: 'eHealth/assistive_products', code: '' }];

        constructor(obj = null) {
            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
