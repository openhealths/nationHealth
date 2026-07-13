<div class="overflow-x-auto relative">
    <fieldset class="fieldset" id="section-doctor-specialities"
              :disabled="$wire.isPositionDataLocked ?? false"
              x-data="{
                  specialities: $wire.entangle('form.doctor.specialities'),
                  employeeType: $wire.entangle('form.employeeType'),
                  employeeTypeSpecialities: @js($this->employeeTypeSpecialities),
                  employeeTypeLevels: @js($this->employeeTypeLevels),
                  employeeTypeSpecQualifications: @js($this->employeeTypeSpecQualifications),
                  openModal: false,
                  modalSpeciality: new Speciality(),
                  newSpeciality: false,
                  item: 0,
                  specDict: $wire.dictionaries['SPECIALITY_TYPE'],
                  levelDict: $wire.dictionaries['SPECIALITY_LEVEL'],
                  qualTypeDict: $wire.dictionaries['QUALIFICATION_TYPE'],

                  isModalValid() {
                      return this.modalSpeciality.speciality
                          && this.modalSpeciality.attestationName
                          && this.modalSpeciality.level
                          && this.modalSpeciality.qualificationType
                          && this.modalSpeciality.attestationDate
                          && this.modalSpeciality.certificateNumber;
                  },
              }"
    >
        <legend class="legend">
            <h2>{{ __('forms.specialities') }}</h2>
        </legend>

        @error('form.doctor.specialities')
        <p class="text-error -mt-2 mb-4">{{ $message }}</p>
        @enderror

        <table class="table-input w-full">
            <thead class="thead-input">
            <tr>
                <th class="th-input">{{ __('forms.speciality') }}</th>
                <th class="th-input">{{ __('forms.issued_by') }}</th>
                <th class="th-input">{{ __('forms.speciality_level') }}</th>
                <th class="th-input">{{ __('forms.speciality_officio') }}</th>
                <th class="th-input">{{ __('forms.certificate_number') }}</th>
                <th class="th-input">{{ __('forms.attestation_date') }}</th>
                <th class="th-input">{{ __('forms.actions') }}</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(speciality, index) in specialities" :key="index">
                <tr>
                    <td class="td-input" x-text="specDict[speciality.speciality]"></td>
                    <td class="td-input" x-text="speciality.attestationName"></td>
                    <td class="td-input" x-text="levelDict[speciality.level]"></td>
                    <td class="td-input text-left">
                        <template x-if="speciality.specialityOfficio">
                            <svg class="w-6 h-6 text-green-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 11.917 9.724 16.5 19 7.5"/>
                            </svg>
                        </template>
                        <template x-if="!speciality.specialityOfficio">
                            <svg class="w-6 h-6 text-red-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 17.94 6M18 18 6.06 6"/>
                            </svg>
                        </template>
                    </td>
                    <td class="td-input" x-text="speciality.certificateNumber"></td>
                    <td class="td-input" x-text="speciality.attestationDate"></td>
                    <td class="td-input">
                        <div
                            x-data="{ openDropdown: false }"
                            @keydown.escape.prevent.stop="openDropdown = false"
                            @focusin.window="!$refs.panel.contains($event.target) && (openDropdown = false)"
                            x-id="['dropdown-button']"
                            class="relative"
                        >
                            <button
                                x-ref="button"
                                @click="openDropdown = !openDropdown"
                                :aria-expanded="openDropdown"
                                :aria-controls="$id('dropdown-button')"
                                type="button"
                                class="cursor-pointer"
                            >
                                <svg class="w-6 h-6 text-gray-800 dark:text-gray-200 svg-hover-action" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round" stroke-width="2" d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"></path>
                                </svg>
                            </button>

                            <div
                                x-ref="panel"
                                x-show="openDropdown"
                                x-transition.origin.top.left
                                @click.outside="openDropdown = false"
                                :id="$id('dropdown-button')"
                                class="dropdown-panel absolute"
                                style="left: -120%; display: none;"
                            >
                                <button
                                    @click.prevent="
                                        openModal = true;
                                        item = index;
                                        modalSpeciality = new Speciality(speciality);
                                        newSpeciality = false;
                                        openDropdown = false;
                                    "
                                    class="dropdown-button"
                                >
                                    {{ __('forms.edit') }}
                                </button>

                                <button
                                    @click.prevent="
                                        specialities.splice(index, 1);
                                        openDropdown = false;
                                    "
                                    class="dropdown-button dropdown-delete"
                                >
                                    {{ __('forms.delete') }}
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
            </template>
            </tbody>
        </table>

        <div>
            <button @click="
                        openModal = true;
                        newSpeciality = true;
                        modalSpeciality = new Speciality();
                    "
                    @click.prevent
                    class="item-add my-5"
            >
                {{ __('forms.addSpeciality') }}
            </button>

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

                    <div x-show="openModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

                    <div x-show="openModal"
                         x-transition
                         @click="openModal = false"
                         class="relative flex min-h-screen items-center justify-start pl-72 p-4"
                    >
                        <div @click.stop
                             x-trap.noscroll.inert="openModal"
                             class="modal-content h-fit w-full max-w-4xl rounded-2xl shadow-lg bg-white"
                        >

                            <h3 class="modal-header" :id="$id('modal-title')">
                                <span x-text="newSpeciality ? '{{ __('forms.addSpeciality') }}' : '{{ __('forms.edit') . ' ' . __('forms.speciality') }}'"></span>
                            </h3>
                            <form>
                                <div class="form-row-modal">
                                    <div>
                                        <label for="specialitySpeciality" class="label-modal">{{ __('forms.speciality') }} <span class="text-red-600"> *</span></label>
                                        <select x-model="modalSpeciality.speciality" id="specialitySpeciality" class="input-modal" required>
                                            <option value="">{{__('forms.select_speciality')}}</option>
                                            <template x-if="employeeType && employeeTypeSpecialities[employeeType]">
                                                <template x-for="(specName, specKey) in employeeTypeSpecialities[employeeType]" :key="specKey">
                                                    <option :value="specKey" x-text="specName"></option>
                                                </template>
                                            </template>
                                        </select>
                                    </div>

                                    <div class="flex flex-col justify-end">
                                        <label class="inline-flex items-center mt-6">
                                            <input type="checkbox" x-model="modalSpeciality.specialityOfficio"
                                                   class="h-4 w-4 text-blue-600 dark:text-blue-500 bg-gray-100 dark:bg-gray-700 border-gray-300 dark:border-gray-600 rounded focus:ring-blue-500 focus:ring-2">
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('forms.speciality_officio') }}</span>
                                        </label>
                                        <p class="text-red-500 dark:text-red-400 text-xs mt-1"
                                           x-show="modalSpeciality.specialityOfficio === null || modalSpeciality.specialityOfficio === undefined">
                                            {{ __('forms.field_empty') }}
                                        </p>
                                    </div>

                                    <div>
                                        <label for="specialityAttestationName" class="label-modal">{{ __('forms.issued_by') }} <span class="text-red-600"> *</span></label>
                                        <input x-model="modalSpeciality.attestationName" type="text"
                                               id="specialityAttestationName" class="input-modal" required>
                                    </div>

                                    <div>
                                        <label for="specialityLevel" class="label-modal">{{ __('forms.speciality_level') }} <span class="text-red-600"> *</span></label>
                                        <select x-model="modalSpeciality.level" id="specialityLevel"
                                                class="input-modal" required>
                                            <option value="">{{__('forms.select_level')}}</option>
                                            <template x-if="employeeType && employeeTypeLevels[employeeType]">
                                                <template x-for="(levelName, levelKey) in employeeTypeLevels[employeeType]" :key="levelKey">
                                                    <option :value="levelKey" x-text="levelName"></option>
                                                </template>
                                            </template>
                                        </select>
                                    </div>

                                    <div>
                                        <label for="specialityQualificationType" class="label-modal">{{ __('forms.qualificationType') }} <span class="text-red-600"> *</span></label>
                                        <select x-model="modalSpeciality.qualificationType" id="specialityQualificationType" class="input-modal" required>
                                            <option value="">{{__('forms.select_qualification_type')}}</option>
                                            <template x-if="employeeType && employeeTypeSpecQualifications[employeeType]">
                                                <template x-for="(qualName, qualKey) in employeeTypeSpecQualifications[employeeType]" :key="qualKey">
                                                    <option :value="qualKey" x-text="qualName"></option>
                                                </template>
                                            </template>
                                        </select>
                                    </div>

                                    <div>
                                        <label for="specialityCertificateNumber" class="label-modal">{{ __('forms.certificate_number') }} <span class="text-red-600"> *</span></label>
                                        <input x-model="modalSpeciality.certificateNumber" type="text"
                                               id="specialityCertificateNumber" class="input-modal">
                                    </div>
                                    <div class="relative">
                                        <svg class="svg-input absolute left-1 !top-2/3 transform -translate-y-1/2 pointer-events-none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M6 5V4a1 1 0 1 1 2 0v1h3V4a1 1 0 1 1 2 0v1h3V4a1 1 0 1 1 2 0v1h1a2 2 0 0 1 2 2v2H3V7a2 2 0 0 1 2-2h1ZM3 19v-8h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Zm5-6a1 1 0 1 0 0 2h8a1 1 0 1 0 0-2H8Z" clip-rule="evenodd"/>
                                        </svg>
                                        <label for="specialityAttestationDate" class="label-modal">{{ __('forms.attestation_date') }}<span class="text-red-600"> *</span></label>
                                        <input x-model="modalSpeciality.attestationDate" datepicker-format="{{ frontendDateFormat() }}" type="text" name="specialityAttestationDate" id="specialityAttestationDate" class="input-modal datepicker-input" autocomplete="off">
                                    </div>
                                </div>
                                <p class="text-sm text-gray-400 mb-2">{{ __('forms.form_required_note') }}</p>
                                <div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 pt-6">
                                    <button type="button" @click="openModal = false" class="button-minor">{{ __('forms.cancel') }}</button>

                                    <button @click.prevent="newSpeciality ? specialities.push(modalSpeciality) : specialities[item] = modalSpeciality; openModal = false"
                                            class="button-primary"
                                            :class="{ 'opacity-50 cursor-not-allowed': !isModalValid() }"
                                            :disabled="!isModalValid()">
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
    class Speciality {
        speciality = '';
        specialityOfficio = false;
        level = '';
        attestationName = '';
        attestationDate = '';
        certificateNumber = '';
        qualificationType = '';

        constructor(obj = null) {
            if (obj) Object.assign(this, obj);
        }
    }
</script>
