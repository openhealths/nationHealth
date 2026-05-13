<div class="overflow-x-auto relative">
    <fieldset class="fieldset" id="section-documents"
              :disabled="$wire.isPersonalDataLocked ?? false"
              x-data="{
                  documents: $wire.entangle('form.documents'),
                  openModal: false,
                  modalDocument: new Doc(),
                  newDocument: false,
                  item: 0,
                  dictionary: @js($this->dictionaries['DOCUMENT_TYPE'])
              }"
    >
        <legend class="legend">
            <h2>{{__('forms.document')}}</h2>
        </legend>

        @error('form.documents')
        <p class="text-error -mt-2 mb-4">{{ $message }}</p>
        @enderror

        <table class="table-input w-inherit">
            <thead class="thead-input">
            <tr>
                <th scope="col" class="td-input">{{ __('forms.document_type') }}</th>
                <th scope="col" class="td-input">{{ __('forms.number') }} </th>
                <th scope="col" class="td-input">{{ __('forms.issued_by') }}</th>
                <th scope="col" class="td-input">{{ __('forms.issued_at') }}</th>
                <th scope="col" class="td-input">{{ __('forms.actions') }}</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(document, index) in documents" :key="index">
                <tr>
                    <td class="td-input" x-text="dictionary[document.type]"></td>
                    <td class="td-input" x-text="document.number"></td>
                    <td class="td-input" x-text="document.issuedBy"></td>
                    <td class="td-input" x-text="document.issuedAt"></td>
                    <td class="td-input">

                        <div x-data="{
                                 openDropdown: false,
                                 toggle() {
                                     if (this.openDropdown) {
                                         return this.close()
                                     }

                                     this.$refs.button.focus()

                                     this.openDropdown = true
                                 },
                                 close(focusAfter) {
                                     if (!this.openDropdown) return

                                     this.openDropdown = false

                                     focusAfter &amp;&amp; focusAfter.focus()
                                 }
                             }" @keydown.escape.prevent.stop="close($refs.button)" @focusin.window="!$refs.panel.contains($event.target) &amp;&amp; close()" x-id="['dropdown-button']" class="relative" bis_skin_checked="1">

                            <button x-ref="button" @click="toggle()" :aria-expanded="openDropdown" :aria-controls="$id('dropdown-button')" type="button" class="cursor-pointer" aria-expanded="false" aria-controls="dropdown-button-1">
                                <svg class="w-6 h-6 text-gray-800 dark:text-gray-200 svg-hover-action" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round" stroke-width="2" d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"></path>
                                </svg>
                            </button>


                            <div class="absolute" style="left: -120%" bis_skin_checked="1">
                                <div x-ref="panel" x-show="openDropdown" x-transition.origin.top.left="" @click.outside="close($refs.button)" :id="$id('dropdown-button')" class="dropdown-panel relative" style="left: -50%; display: none;" id="dropdown-button-1" bis_skin_checked="1">

                                <button @click.prevent="openModal = true; item = index; modalDocument = new Doc(document); newDocument = false; openDropdown = false;" class="dropdown-button">
                                    {{ __('forms.edit') }}
                                </button>

                                    <button @click.prevent="documents.splice(index, 1); close($refs.button);" class="dropdown-button dropdown-delete">
                                        Видалити
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
            <button @click="
                        openModal = true; {{-- Open the Modal --}}
                        newDocument = true; {{-- We are adding a new document --}}
                        modalDocument = new Doc(); {{-- Replace the data of the previous document with a new one--}}
                    "
                    @click.prevent
                    class="item-add my-5"
            >

                {{__('forms.add_document')}}
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
                             class="modal-content h-fit w-full max-w-6xl rounded-2xl shadow-lg bg-white"
                        >

                            {{-- Title --}}
                            <h3 class="modal-header" :id="$id('modal-title')">
                                <span x-text="newDocument ? '{{ __('forms.add_document') }}' : '{{ __('forms.edit') . ' ' . __('forms.document') }}'"></span>
                            </h3>

                            {{-- Content --}}
                            <form>
                                <div class="form-row-modal">
                                    <div>
                                        <label for="documentType" class="label-modal">{{__('forms.document_type')}}<span class="text-red-600"> *</span></label>
                                        <select x-model="modalDocument.type" id="documentType" class="input-modal"
                                                type="text" required>
                                            <option value="">{{__('forms.select_document_type')}}</option>
                                            @foreach($this->dictionaries['DOCUMENT_TYPE'] as $typeValue => $typeDescription)
                                                <option value="{{$typeValue}}">{{$typeDescription}}</option>
                                            @endforeach
                                        </select>

                                    </div>

                                    <div>
                                        <label for="documentNumber" class="label-modal">{{__('forms.document_number')}}<span class="text-red-600"> *</span></label>
                                        <input x-model="modalDocument.number" type="text" name="documentNumber"
                                               id="documentNumber" class="input-modal" required>
                                    </div>

                                    <div>
                                        <label for="documentIssuedBy" class="label-modal">{{__('forms.issued_by')}}<span class="text-red-600"></span></label>
                                        <input x-model="modalDocument.issuedBy" type="text" name="documentIssuedBy"
                                               id="documentIssuedBy" class="input-modal">
                                    </div>

                                    <div class="relative">
                                        <svg class="svg-input absolute left-1 !top-2/3 transform -translate-y-1/2 pointer-events-none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M6 5V4a1 1 0 1 1 2 0v1h3V4a1 1 0 1 1 2 0v1h3V4a1 1 0 1 1 2 0v1h1a2 2 0 0 1 2 2v2H3V7a2 2 0 0 1 2-2h1ZM3 19v-8h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Zm5-6a1 1 0 1 0 0 2h8a1 1 0 1 0 0-2H8Z" clip-rule="evenodd"/>
                                            </svg>
                                        <label for="documentIssuedAt" class="label-modal">{{__('forms.issued_at')}}<span class="text-red-600"> *</span></label>
                                        <input x-model="modalDocument.issuedAt"
                                               datepicker-format="{{ frontendDateFormat() }}"
                                               type="text" name="documentIssuedAt"
                                               id="documentIssuedAt"
                                               class="input-modal datepicker-input"
                                               autocomplete="off">
                                    </div>
                                </div>
                                <p class="text-sm text-gray-400 mb-2">{{ __('forms.form_required_note') }}</p>
                                <div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 pt-6">
                                    <button type="button"
                                            @click="openModal = false"
                                            class="button-minor"
                                    >
                                        {{__('forms.cancel')}}
                                    </button>

                                    <button @click.prevent="newDocument ? documents.push(modalDocument) : documents[item] = modalDocument; openModal = false"
                                            class="button-primary"
                                            :class="{ 'opacity-50 cursor-not-allowed': !(modalDocument.type && modalDocument.number && modalDocument.issuedAt) }"
                                            :disabled="!(modalDocument.type && modalDocument.number && modalDocument.issuedAt)">
                                        {{__('forms.save')}}
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
     * Representation of the user's personal document
     */
    class Doc {
        type = '';
        number = '';
        issuedBy = '';
        issuedAt = '';

        constructor(obj = null) {
            if (obj) {
                Object.assign(this, obj);
            }
        }
    }
</script>
