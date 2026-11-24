<div
    class="p-4 sm:p-8"
    id="reasons-section"
    x-data="{
        reasons: $wire.entangle('form.encounter.reasons'),
        openModal: false,
        showDuplicateCodeWarning: false,
        modalReason: new Reason(),
        newReason: false,
        item: 0,
        dictionary: $wire.dictionaries['eHealth/ICPC2/reasons'],
    }"
>
    <div class="space-y-4">
        <template x-for="(reason, index) in reasons" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <label :for="`reasonRecord${index}`" class="sr-only">{{ __('forms.select') }}</label>
                        <input type="checkbox" :id="`reasonRecord${index}`" class="default-checkbox h-5 w-5" disabled />
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('medical-events.code_and_name') }}</div>
                        <div
                            class="record-inner-value text-[16px]"
                            x-text="`${reason.code} - ${dictionary[reason.code]}`"
                        ></div>
                    </div>

                    <div class="record-inner-action-col">
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
                            @focusin.window="$refs.panel && ! $refs.panel.contains($event.target) && close()"
                            x-id="['dropdown-button']"
                            class="relative"
                        >
                            @if ($isReadonly)
                                <a
                                    href="#"
                                    @click.prevent="
                                        openModal = true;
                                        item = index;
                                        modalReason = new Reason(reason);
                                        newReason = false;
                                    "
                                    class="record-inner-action-btn"
                                    title="{{ __('forms.view') }}"
                                >
                                    @icon('eye', 'w-6 h-6')
                                    <span class="sr-only"> {{ __('forms.view') }} </span>
                                </a>
                            @else
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
                                <div class="absolute right-0 z-50">
                                    <div
                                        x-ref="panel"
                                        x-show="openDropdown"
                                        x-transition.origin.top.left
                                        @click.outside="close($refs.button)"
                                        :id="$id('dropdown-button')"
                                        x-cloak
                                        class="dropdown-panel relative"
                                    >
                                        <button
                                            @click.prevent="
                                                openModal = true;
                                                item = index;
                                                modalReason = new Reason(reason);
                                                newReason = false;
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            class="dropdown-delete"
                                            @click.prevent="
                                                reasons.splice(index, 1);
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.delete') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="record-inner-body" x-show="reason.text">
                    <div class="record-inner-grid-container">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <div class="record-inner-label">{{ __('encounters.reason_text') }}</div>
                                <div class="record-inner-subvalue" x-text="reason.text"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        {{-- Button to trigger the modal --}}
        <button
            @click.prevent="
                openModal = true;
                newReason = true;
                modalReason = new Reason();
            "
            class="item-add my-5"
        >
            {{ __('forms.add') }}
        </button>

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
                    <div @click.stop x-trap.noscroll.inert="openModal" class="modal-content h-fit w-full lg:max-w-4xl">
                        {{-- Title --}}
                        <h3 class="modal-header" :id="$id('modal-title')">{{ __('encounters.reason_for_visit') }}</h3>

                        {{-- Content --}}
                        <form>
                            <fieldset @disabled($isReadonly) @class(['pointer-events-none' => $isReadonly])>
                                <div class="form-row-modal">
                                    <div>
                                        <label for="reasonCode" class="label-modal">
                                            {{ __('medical-events.icpc2_status_code') }}
                                        </label>
                                        <x-select2
                                            modelPath="modalReason.code"
                                            dictionaryName="eHealth/ICPC2/reasons"
                                            id="reasonCode"
                                        />

                                        <p
                                            class="text-error text-xs"
                                            x-show="! Object.keys(dictionary).includes(modalReason.code)"
                                        >
                                            {{ __('forms.field_empty') }}
                                        </p>
                                    </div>

                                    <div>
                                        <label
                                            for="reasonComment"
                                            class="sr-only"
                                        >{{ __('encounters.reason_text') }}</label>
                                        <textarea
                                            x-model="modalReason.text"
                                            id="reasonComment"
                                            name="reasonComment"
                                            class="textarea"
                                            rows="4"
                                            placeholder="{{ __('forms.write_comment_here') }}"
                                        ></textarea>
                                    </div>
                                </div>
                            </fieldset>

                            <div class="mt-6 flex justify-between space-x-2">
                                <button type="button" @click="openModal = false" class="button-minor">
                                    {{ $isReadonly ? __('forms.close') : __('forms.cancel') }}
                                </button>

                                @unless ($isReadonly)
                                    <button
                                        @click.prevent="
                                            const newReasonCode = modalReason.code;
                                            const matchingReasonCodesCount = reasons.filter((reason, index) => {
                                                // If editing — ignore the current index
                                                if (newReason === false && index === item) return false;
                                                return reason.code === newReasonCode;
                                            }).length;

                                            if (matchingReasonCodesCount >= 1) {
                                                showDuplicateCodeWarning = true;
                                                return;
                                            }

                                            newReason !== false
                                                ? reasons.push(modalReason)
                                                : (reasons[item] = modalReason);

                                            showDuplicateCodeWarning = false;
                                            openModal = false;
                                        "
                                        class="button-primary"
                                        :disabled="! modalReason.code.trim()"
                                    >
                                        {{ __('forms.save') }}
                                    </button>
                                @endunless
                            </div>
                            <template x-if="showDuplicateCodeWarning">
                                <p class="text-error text-right">{!! __('medical-events.duplicate_code_warning') !!}</p>
                            </template>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

<script data-navigate-once>
    /**
     * Representation of the user's personal reason
     */
    class Reason {
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
