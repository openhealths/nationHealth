<div class="p-4 sm:p-8"
     id="actions-section"
     x-data="{
         actions: $wire.entangle('form.encounter.actions'),
         openModal: false,
         showDuplicateCodeWarning: false,
         modalAction: new Action(),
         newAction: false,
         item: 0,
         dictionary: $wire.dictionaries['eHealth/ICPC2/actions']
     }"
     x-show="$wire.form.encounter.classCode === 'PHC'"
>

    <div class="space-y-4">
        <template x-for="(action, index) in actions" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input type="checkbox" class="default-checkbox w-5 h-5" disabled>
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                        <div class="record-inner-value text-[16px]"
                             x-text="`${ action.code } - ${ dictionary[action.code] }`"></div>
                    </div>

                    <div class="record-inner-action-col">
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
                                    class="record-inner-action-btn cursor-pointer"
                            >
                                <svg class="w-6 h-6 text-gray-800 dark:text-gray-200" aria-hidden="true"
                                     xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"
                                     viewBox="0 0 24 24"
                                >
                                    <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round"
                                          stroke-width="2"
                                          d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"
                                    />
                                </svg>
                            </button>

                            {{-- Dropdown Panel --}}
                            <div class="absolute right-0 z-50">
                                <div x-ref="panel"
                                     x-show="openDropdown"
                                     x-transition.origin.top.left
                                     @click.outside="close($refs.button)"
                                     :id="$id('dropdown-button')"
                                     x-cloak
                                     class="dropdown-panel relative"
                                >
                                    <button @click.prevent="
                                        openModal = true;
                                        item = index;
                                        modalAction = new Action(action);
                                        newAction = false;
                                        close($refs.button);
                                    "
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button class="dropdown-delete"
                                            @click.prevent="actions.splice(index, 1); close($refs.button)">
                                        {{ __('forms.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-body" x-show="action.text">
                    <div class="record-inner-grid-container">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <div class="record-inner-label">{{ __('forms.comment') }}</div>
                                <div class="record-inner-subvalue" x-text="action.text"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        {{-- Button to trigger the modal --}}
        <button @click.prevent="
            openModal = true;
            newAction = true;
            modalAction = new Action();
        "
                class="item-add my-5"
        >
            {{ __('forms.add') }}
        </button>

        {{-- Modal --}}
        <template x-teleport="body">
            <div x-show="openModal"
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
                        <h3 class="modal-header" :id="$id('modal-title')">{{ __('forms.action') }}</h3>

                        {{-- Content --}}
                        <form>
                            <div class="form-row-modal">
                                <div>
                                    <label for="actionCode" class="label-modal">
                                        {{ __('patients.icpc-2_status_code') }}
                                    </label>
                                    <x-select2 modelPath="modalAction.code"
                                               dictionaryName="eHealth/ICPC2/actions"
                                               id="actionCode"
                                    />

                                    <p class="text-error text-xs"
                                       x-show="!Object.keys(dictionary).includes(modalAction.code)"
                                    >
                                        {{ __('forms.field_empty') }}
                                    </p>
                                </div>

                                <div>
                                        <textarea x-model="modalAction.text"
                                                  id="actionComment"
                                                  name="actionComment"
                                                  class="textarea"
                                                  rows="4"
                                                  placeholder="{{ __('forms.write_comment_here') }}"
                                        ></textarea>
                                </div>
                            </div>

                            <div class="mt-6 flex justify-between space-x-2">
                                <button type="button"
                                        @click="openModal = false"
                                        class="button-minor"
                                >
                                    {{ __('forms.cancel') }}
                                </button>

                                <button @click.prevent="
                                    const newActionCode = modalAction.code;
                                    const matchingActionCodesCount = actions.filter((action, index) => {
                                        if (newAction === false && index === item) return false;
                                        return action.code === newActionCode;
                                    }).length;

                                    if (matchingActionCodesCount >= 1) {
                                        showDuplicateCodeWarning = true;
                                        return;
                                    }

                                    newAction !== false
                                        ? actions.push(modalAction)
                                        : actions[item] = modalAction;

                                    showDuplicateCodeWarning = false;
                                    openModal = false;
                                "
                                        class="button-primary"
                                        :disabled="!modalAction.code.trim()"
                                >
                                    {{ __('forms.save') }}
                                </button>
                            </div>
                            <template x-if="showDuplicateCodeWarning">
                                <p class="text-error text-right">
                                    {!! __('patients.duplicate_code_warning') !!}
                                </p>
                            </template>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

<script>
    /**
     * Representation of the user's personal action
     */
    class Action {
        code = '';
        text = '';

        constructor(obj = null) {
            if (obj) {
                this.code = obj.code || '';
                this.text = obj.text || '';
            }
        }
    }
</script>
