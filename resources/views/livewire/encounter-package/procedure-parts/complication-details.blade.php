<div class="relative">
    <fieldset
        class="fieldset"
        x-data="{
            openModal: false,
            selectedComplicationDetailIds: [],
        }"
    >
        <legend class="legend">
            <h2>{{ __('procedures.complications') }}</h2>
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
                <template x-for="(complicationDetail, index) in modalProcedure.complicationDetails">
                    <tr>
                        <td class="td-input" x-text="complicationDetail.ehealthInsertedAt || ''"></td>
                        <td
                            class="td-input"
                            x-text="
                                `${complicationDetail.codeCode} - ${
                                    $wire.dictionaries['eHealth/ICD10_AM/condition_codes'][
                                        complicationDetail.codeCode
                                    ] ||
                                    $wire.dictionaries['eHealth/ICPC2/condition_codes'][complicationDetail.codeCode]
                                }`
                            "
                        ></td>
                        <td class="td-input">
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
                                                modalProcedure.complicationDetails.splice(index, 1);
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
            <button
                @click.prevent="
                    openModal = true;
                    const availableIds = complicationOptions().map((option) => option.id);
                    selectedComplicationDetailIds = modalProcedure.complicationDetails
                        .map((complicationDetail) => complicationDetail.id)
                        .filter((id) => availableIds.includes(id));
                "
                class="item-add my-5"
            >
                {{ __('forms.add') }}
            </button>

            <template x-teleport="body">
                <div
                    x-show="openModal"
                    x-cloak
                    @keydown.escape.prevent.stop="openModal = false"
                    role="dialog"
                    aria-modal="true"
                    x-id="['modal-title']"
                    :aria-labelledby="$id('modal-title')"
                    class="modal"
                >
                    <div x-show="openModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

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
                            <h3 class="modal-header" :id="$id('modal-title')">{{ __('forms.add') }}</h3>

                            <form>
                                <x-forms.loading />

                                <template x-if="complicationOptions().length > 0">
                                    <div class="table-container">
                                        <div class="overflow-visible">
                                            <table class="table-base">
                                                <thead class="table-header">
                                                    <tr>
                                                        <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                                                        <th scope="col" class="th-input">
                                                            {{ __('medical-events.code_and_name') }}
                                                        </th>
                                                        <th scope="col" class="th-input">{{ __('forms.action') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="result in complicationOptions()" :key="result.id">
                                                        <tr class="border-b dark:border-gray-700">
                                                            <th scope="row" class="table-cell-primary">
                                                                <div
                                                                    class="text-base"
                                                                    x-text="result.ehealthInsertedAt || ''"
                                                                ></div>
                                                            </th>
                                                            <td
                                                                class="td-input"
                                                                x-text="
                                                                    `${result.codeCode} - ${
                                                                        $wire.dictionaries[
                                                                            'eHealth/ICD10_AM/condition_codes'
                                                                        ][result.codeCode] ||
                                                                        $wire.dictionaries[
                                                                            'eHealth/ICPC2/condition_codes'
                                                                        ][result.codeCode]
                                                                    }`
                                                                "
                                                            ></td>
                                                            <td class="td-input">
                                                                <button
                                                                    @click.prevent="
                                                                        const id = result.id;
                                                                        const index =
                                                                            selectedComplicationDetailIds.indexOf(id);

                                                                        if (index === -1) {
                                                                            selectedComplicationDetailIds.push(id);
                                                                        } else {
                                                                            selectedComplicationDetailIds.splice(
                                                                                index,
                                                                                1,
                                                                            );
                                                                        }
                                                                    "
                                                                    class="button-primary w-28"
                                                                    x-text="selectedComplicationDetailIds.includes(result.id)
                                                                        ? '{{ __('medical-events.added') }}'
                                                                        : '{{ __('forms.add') }}'"
                                                                ></button>
                                                            </td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="complicationOptions().length <= 0">
                                    <p class="default-p">{{ __('forms.nothing_found') }}</p>
                                </template>

                                <div class="mt-6 flex justify-between space-x-2">
                                    <button
                                        @click.prevent
                                        type="button"
                                        @click="openModal = false"
                                        class="button-minor"
                                    >
                                        {{ __('forms.cancel') }}
                                    </button>

                                    <button
                                        @click.prevent
                                        @click="
                                            modalProcedure.complicationDetails = complicationOptions().filter(
                                                (complicationDetail) =>
                                                    selectedComplicationDetailIds.includes(complicationDetail.id),
                                            );

                                            openModal = false;
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
