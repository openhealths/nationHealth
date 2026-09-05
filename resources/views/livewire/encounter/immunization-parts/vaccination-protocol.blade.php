<div class="relative">
    {{-- This required for table overflow scrolling --}}
    <fieldset
        class="fieldset"
        {{-- Binding vaccinationProtocolCodes to Alpine, it will be re-used in the modal.
                Note that it's necessary for modal to work properly --}}
        x-data="{
            openModal: false,
            modalVaccinationProtocol: new VaccinationProtocol(),
            newVaccinationProtocol: false,
            item: 0,
            vaccinationTargetDiseasesDictionary: $wire.dictionaries['eHealth/vaccination_target_diseases'],

            targetDiseaseCodesFromOtherProtocols() {
                return this.modalImmunization.vaccinationProtocols
                    .filter((protocol, protocolIndex) => this.newVaccinationProtocol || protocolIndex !== this.item)
                    .flatMap((protocol) =>
                        Array.isArray(protocol.targetDiseaseCodes) ? protocol.targetDiseaseCodes.filter(Boolean) : [],
                    );
            },

            isTargetDiseaseAlreadySelected(targetDiseaseCode, currentIndex) {
                if (! targetDiseaseCode) {
                    return false;
                }

                const selectedInCurrentProtocol = this.modalVaccinationProtocol.targetDiseaseCodes.some(
                    (selectedCode, index) => index !== currentIndex && selectedCode === targetDiseaseCode,
                );

                const selectedInOtherProtocol = this.targetDiseaseCodesFromOtherProtocols().includes(targetDiseaseCode);

                return selectedInCurrentProtocol || selectedInOtherProtocol;
            },

            hasDuplicateTargetDiseases() {
                const selectedCodes = this.modalVaccinationProtocol.targetDiseaseCodes.filter(Boolean);
                const hasDuplicatesInsideCurrentProtocol = new Set(selectedCodes).size !== selectedCodes.length;
                const otherProtocolCodes = new Set(this.targetDiseaseCodesFromOtherProtocols());
                const isUsedInAnotherProtocol = selectedCodes.some((targetDiseaseCode) =>
                    otherProtocolCodes.has(targetDiseaseCode),
                );

                return hasDuplicatesInsideCurrentProtocol || isUsedInAnotherProtocol;
            },

            hasInvalidTargetDiseasesInCurrentProtocol() {
                return this.modalVaccinationProtocol.targetDiseaseCodes.some(
                    (targetDiseaseCode) => ! targetDiseaseCode || ! this.isTargetDiseaseAllowed(targetDiseaseCode),
                );
            },

            canAddTargetDiseaseField() {
                const unavailableCodes = new Set([
                    ...this.targetDiseaseCodesFromOtherProtocols(),
                    ...this.modalVaccinationProtocol.targetDiseaseCodes.filter(Boolean),
                ]);

                return this.allowedTargetDiseases().some((targetDisease) => ! unavailableCodes.has(targetDisease.code));
            },

            canAddVaccinationProtocol() {
                const usedCodes = new Set(
                    this.modalImmunization.vaccinationProtocols.flatMap((protocol) =>
                        Array.isArray(protocol.targetDiseaseCodes) ? protocol.targetDiseaseCodes.filter(Boolean) : [],
                    ),
                );

                return this.allowedTargetDiseases().some((targetDisease) => ! usedCodes.has(targetDisease.code));
            },
        }"
    >
        <legend class="legend">
            <h2>{{ __('immunizations.vaccination_protocol') }}</h2>
        </legend>

        <table class="table-input w-inherit">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="th-input">{{ __('immunizations.dose_sequence') }}</th>
                    <th scope="col" class="th-input">{{ __('immunizations.series') }}</th>
                    <th scope="col" class="th-input">{{ __('immunizations.target_diseases') }}</th>
                    <th scope="col" class="th-input">{{ __('forms.action') }}</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(vaccinationProtocol, index) in modalImmunization.vaccinationProtocols">
                    <tr>
                        <td class="td-input" x-text="vaccinationProtocol.doseSequence"></td>
                        <td class="td-input" x-text="vaccinationProtocol.series"></td>
                        <td
                            class="td-input"
                            x-text="
                                vaccinationProtocol.targetDiseaseCodes
                                    .map(
                                        (targetDiseaseCode) =>
                                            vaccinationTargetDiseasesDictionary[targetDiseaseCode] ?? targetDiseaseCode,
                                    )
                                    .join(', ')
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
                                            @click="
                                                openModal = true; {{-- Open the modal --}}
                                                item = index; {{-- Identify the item we are corrently editing --}}
                                                {{-- Replace the previous vaccinationProtocol with the current, don't assign object directly (modalVaccinationProtocol = vaccinationProtocol) to avoid reactiveness --}}
                                                modalVaccinationProtocol = new VaccinationProtocol(vaccinationProtocol);
                                                newVaccinationProtocol = false; {{-- This vaccinationProtocol is already created --}}
                                            "
                                            @click.prevent
                                            class="dropdown-button"
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            class="dropdown-button dropdown-delete"
                                            @click.prevent="
                                                modalImmunization.vaccinationProtocols.splice(index, 1);
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

        <p class="text-error mt-1 text-xs" x-show="modalImmunization.vaccinationProtocols.length === 0">
            {{ __('immunizations.vaccination_protocol_required') }}
        </p>

        <p
            class="text-error mt-1 text-xs"
            x-show="
                modalImmunization.primarySource &&
                modalImmunization.vaccinationProtocols.some(
                    (protocol) => ! protocol.doseSequence || ! protocol.series || ! protocol.seriesDoses,
                )
            "
        >
            {{ __('immunizations.vaccination_protocol_required_fields') }}
        </p>

        <div>
            {{-- Button to trigger the modal --}}
            <button
                @click.prevent="
                    openModal = true;
                    newVaccinationProtocol = true;
                    modalVaccinationProtocol = new VaccinationProtocol();
                "
                class="item-add my-5"
                :disabled="! modalImmunization.vaccineCode || ! canAddVaccinationProtocol()"
            >
                {{ __('forms.add') }}
            </button>

            {{-- Modal --}}
            <template x-teleport="body">
                {{-- This moves the modal at the end of the body tag --}}
                <div
                    x-show="openModal"
                    style="display: none"
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
                            <form>
                                <template
                                    x-for="(targetDiseaseCode, index) in modalVaccinationProtocol.targetDiseaseCodes"
                                    :key="index"
                                >
                                    <div class="form-row-modal md:mb-0">
                                        <div class="form-group group">
                                            <label :for="'vaccinationTargetDisease-' + index" class="label-modal">
                                                {{ __('immunizations.target_diseases') }}
                                            </label>
                                            <select
                                                x-model="modalVaccinationProtocol.targetDiseaseCodes[index]"
                                                :id="'vaccinationTargetDisease-' + index"
                                                class="input-modal"
                                                required
                                            >
                                                <option value="">{{ __('forms.select') }}</option>

                                                <template
                                                    x-for="targetDisease in allowedTargetDiseases()"
                                                    :key="targetDisease.code"
                                                >
                                                    <option
                                                        :value="targetDisease.code"
                                                        :disabled="isTargetDiseaseAlreadySelected(
                                                            targetDisease.code,
                                                            index,
                                                        )"
                                                        x-text="targetDisease.name"
                                                    ></option>
                                                </template>
                                            </select>

                                            <p
                                                class="text-error text-xs"
                                                x-show="
                                                    ! Object.keys(vaccinationTargetDiseasesDictionary).includes(
                                                        modalVaccinationProtocol.targetDiseaseCodes[index],
                                                    )
                                                "
                                            >
                                                {{ __('forms.field_empty') }}
                                            </p>

                                            <p
                                                class="text-error text-xs"
                                                x-show="
                                                    modalVaccinationProtocol.targetDiseaseCodes[index] &&
                                                    ! isTargetDiseaseAllowed(
                                                        modalVaccinationProtocol.targetDiseaseCodes[index],
                                                    )
                                                "
                                            >
                                                {{ __('immunizations.vaccine_target_disease_mismatch') }}
                                            </p>

                                            <p
                                                class="text-error text-xs"
                                                x-show="
                                                    modalVaccinationProtocol.targetDiseaseCodes[index] &&
                                                    isTargetDiseaseAlreadySelected(
                                                        modalVaccinationProtocol.targetDiseaseCodes[index],
                                                        index,
                                                    )
                                                "
                                            >
                                                {{ __('immunizations.duplicate_target_disease_in_protocol') }}
                                            </p>
                                        </div>

                                        <!-- Remove Button -->
                                        <template
                                            x-if="
                                                index == modalVaccinationProtocol.targetDiseaseCodes.length - 1 &&
                                                index != 0
                                            "
                                        >
                                            <button
                                                type="button"
                                                @click="(modalVaccinationProtocol.targetDiseaseCodes.pop(), index--)"
                                                class="item-remove"
                                            >
                                                {{ __('forms.delete') }}
                                            </button>
                                        </template>
                                        <!-- Add Button -->
                                        <template x-if="index === modalVaccinationProtocol.targetDiseaseCodes.length - 1">
                                            <button
                                                type="button"
                                                @click="modalVaccinationProtocol.targetDiseaseCodes.push('')"
                                                class="item-add lg:justify-self-start"
                                                :class="{
                                                    'lg:justify-self-start': index > 0,
                                                }"
                                                :disabled="! canAddTargetDiseaseField()"
                                            >
                                                {{ __('forms.add') }}
                                            </button>
                                        </template>
                                    </div>
                                </template>

                                <div class="form-row-modal">
                                    <div>
                                        <label for="authority" class="label-modal">
                                            {{ __('immunizations.protocol_author') }}
                                        </label>
                                        <select
                                            x-model="modalVaccinationProtocol.authorityCode"
                                            id="authority"
                                            class="input-modal"
                                            type="text"
                                            required
                                        >
                                            <option selected>{{ __('forms.select') }}</option>
                                            @foreach ($this->dictionaries['eHealth/vaccination_authorities'] as $key => $vaccinationAuthority)
                                                <option value="{{ $key }}">{{ $vaccinationAuthority }}</option>
                                            @endforeach
                                        </select>

                                        {{-- Check if the picked value is the one from the dictionary --}}
                                        <p
                                            class="text-error text-xs"
                                            x-show="
                                                ! Object.keys(
                                                    $wire.dictionaries['eHealth/vaccination_authorities'],
                                                ).includes(modalVaccinationProtocol.authorityCode)
                                            "
                                        >
                                            {{ __('forms.field_empty') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="form-row-modal">
                                    <div class="form-group group">
                                        <label for="doseSequence" class="label-modal">
                                            {{ __('immunizations.dose_sequence') }}
                                        </label>
                                        <input
                                            x-model.number="modalVaccinationProtocol.doseSequence"
                                            type="number"
                                            name="doseSequence"
                                            id="doseSequence"
                                            class="input-modal"
                                            autocomplete="off"
                                            required
                                        />

                                        <p
                                            class="text-error text-xs"
                                            x-show="
                                                (modalVaccinationProtocol.authorityCode === 'MoH' ||
                                                    modalImmunization.primarySource) &&
                                                ! modalVaccinationProtocol.doseSequence
                                            "
                                        >
                                            {{ __('forms.field_empty') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="form-row-modal">
                                    <div class="form-group group">
                                        <label for="series" class="label-modal">
                                            {{ __('immunizations.series') }}
                                        </label>
                                        <input
                                            x-model="modalVaccinationProtocol.series"
                                            type="text"
                                            name="series"
                                            id="series"
                                            class="input-modal"
                                            autocomplete="off"
                                            required
                                        />

                                        <p
                                            class="text-error text-xs"
                                            x-show="
                                                (modalVaccinationProtocol.authorityCode === 'MoH' ||
                                                    modalImmunization.primarySource) &&
                                                ! modalVaccinationProtocol.series
                                            "
                                        >
                                            {{ __('forms.field_empty') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="form-row-modal">
                                    <div class="form-group group">
                                        <label for="seriesDoses" class="label-modal">
                                            {{ __('immunizations.series_of_doses_by_protocol') }}
                                        </label>
                                        <input
                                            x-model.number="modalVaccinationProtocol.seriesDoses"
                                            type="number"
                                            name="seriesDoses"
                                            id="seriesDoses"
                                            class="input-modal"
                                            autocomplete="off"
                                            required
                                        />

                                        <p
                                            class="text-error text-xs"
                                            x-show="
                                                (modalVaccinationProtocol.authorityCode === 'MoH' ||
                                                    modalImmunization.primarySource) &&
                                                ! modalVaccinationProtocol.seriesDoses
                                            "
                                        >
                                            {{ __('forms.field_empty') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="form-row-modal">
                                    <div class="form-group group">
                                        <label for="description" class="label-modal">
                                            {{ __('immunizations.protocol_description') }}
                                        </label>
                                        <textarea
                                            class="textarea"
                                            x-model="modalVaccinationProtocol.description"
                                            id="description"
                                            name="description"
                                            rows="4"
                                            placeholder="{{ __('forms.write_comment_here') }}"
                                        ></textarea>
                                    </div>
                                </div>

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
                                            newVaccinationProtocol !== false
                                                ? modalImmunization.vaccinationProtocols.push(modalVaccinationProtocol)
                                                : (modalImmunization.vaccinationProtocols[item] =
                                                      modalVaccinationProtocol);

                                            openModal = false;
                                        "
                                        class="button-primary"
                                        :disabled="modalVaccinationProtocol.targetDiseaseCodes.length === 0 ||
                                        hasInvalidTargetDiseasesInCurrentProtocol() ||
                                        hasDuplicateTargetDiseases() ||
                                        ! modalVaccinationProtocol.authorityCode.trim() ||
                                        ((modalVaccinationProtocol.authorityCode === 'MoH' ||
                                            modalImmunization.primarySource) &&
                                            (! modalVaccinationProtocol.doseSequence ||
                                                ! modalVaccinationProtocol.series ||
                                                ! modalVaccinationProtocol.seriesDoses))"
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
     * Representation of the user's personal VaccinationProtocol
     */
    class VaccinationProtocol {
        constructor(obj = null) {
            this.doseSequence = '';
            this.description = '';
            this.authorityCode = '';
            this.series = '';
            this.seriesDoses = '';
            this.targetDiseaseCodes = [''];

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
