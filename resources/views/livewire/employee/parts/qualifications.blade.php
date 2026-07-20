<div class="overflow-x-auto relative">
    <fieldset class="fieldset" id="section-doctor-qualifications"
              :disabled="$wire.isPositionDataLocked ?? false"
              x-data="{
                  qualifications: $wire.entangle('form.doctor.qualifications'),
                  employeeType: $wire.entangle('form.employeeType'),
                  employeeTypeSpecialities: @js($this->employeeTypeSpecialities),
                  employeeTypeQualifications: @js($this->employeeTypeQualifications),
                  openModal: false,
                  modalQualification: new Qualification(),
                  newQualification: false,
                  item: 0,
                  qualTypeDict: $wire.dictionaries['QUALIFICATION_TYPE'],
                  qualSpecDict: $wire.dictionaries['SPECIALITY_TYPE'],
                  isModalValid() {
                      return this.modalQualification.type
                          && this.modalQualification.institutionName
                          && this.modalQualification.speciality
                          && this.modalQualification.certificateNumber
                          && this.modalQualification.issuedDate;
                  }
              }"
    >
        <legend class="legend">
            <h2>{{ __('forms.qualifications') }}</h2>
        </legend>

        @error('form.doctor.qualifications')
        <p class="text-error -mt-2 mb-4">{{ $message }}</p>
        @enderror

        <table class="table-input w-inherit">
            <thead class="thead-input">
            <tr>
                <th scope="col" class="th-input">{{ __('forms.document_type') }}</th>
                <th scope="col" class="th-input">{{ __('forms.institutionName') }}</th>
                <th scope="col" class="th-input">{{ __('forms.speciality') }}</th>
                <th scope="col" class="th-input">{{ __('forms.certificateNumber') }}</th>
                <th scope="col" class="th-input">{{ __('forms.actions') }}</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(qualification, index) in qualifications" :key="index">
                <tr>
                    <td class="td-input" x-text="qualTypeDict[qualification.type]"></td>
                    <td class="td-input" x-text="qualification.institutionName"></td>
                    <td class="td-input" x-text="qualSpecDict[qualification.speciality]"></td>
                    <td class="td-input" x-text="qualification.certificateNumber"></td>
                    <td class="td-input">
                        <div
                            x-data="{ openDropdown: false }"
                            @keydown.escape.prevent.stop="openDropdown = false"
                            @focusin.window="!$refs.panel.contains($event.target) && (openDropdown = false)"
                            x-id="['dropdown-button']"
                            class="relative"
                        >
                            {{-- Main button to toggle the dropdown --}}
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

                            {{-- Dropdown Panel --}}
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
                                        modalQualification = new Qualification(qualification);
                                        newQualification = false;
                                        openDropdown = false;
                                    "
                                    class="dropdown-button"
                                >
                                    {{ __('forms.edit') }}
                                </button>

                                <button
                                    @click.prevent="
                                        qualifications.splice(index, 1);
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
                        newQualification = true;
                        modalQualification = new Qualification();
                    "
                    @click.prevent
                    class="item-add my-5"
            >
                {{ __('forms.addQualification') }}
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
                                <span x-text="newQualification ? '{{ __('forms.addQualification') }}' : '{{ __('forms.edit') . ' ' . __('forms.qualification') }}'"></span>
                            </h3>

                            <form>
                                <div class="form-row-modal">
                                    <div>
                                        <label for="qualificationType" class="label-modal">{{ __('forms.qualificationType') }} <span class="text-red-600"> *</span></label>
                                        <select x-model="modalQualification.type" id="qualificationType" class="input-modal" required>
                                            <option value="">{{__('forms.select_qualification_type')}}</option>
                                            <template x-if="employeeType && employeeTypeQualifications[employeeType]">
                                                <template x-for="(qualName, qualKey) in employeeTypeQualifications[employeeType]" :key="qualKey">
                                                    <option :value="qualKey" x-text="qualName"></option>
                                                </template>
                                            </template>
                                        </select>
                                    </div>

                                    <div>
                                        <label for="qualificationInstitutionName" class="label-modal">{{ __('forms.institutionName') }} <span class="text-red-600"> *</span></label>
                                        <input x-model="modalQualification.institutionName" type="text"
                                               id="qualificationInstitutionName" class="input-modal" required>
                                    </div>
                                    <div>
                                        <label for="qualificationSpeciality" class="label-modal">{{ __('forms.speciality') }} <span class="text-red-600"> *</span></label>
                                        <select x-model="modalQualification.speciality" id="qualificationSpeciality" class="input-modal" required>
                                            <option value="">{{__('forms.select_speciality')}}</option>
                                            <template x-if="employeeType && employeeTypeSpecialities[employeeType]">
                                                <template x-for="(specName, specKey) in employeeTypeSpecialities[employeeType]" :key="specKey">
                                                    <option :value="specKey" x-text="specName"></option>
                                                </template>
                                            </template>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="qualificationCertificateNumber" class="label-modal">{{ __('forms.certificateNumber') }} <span class="text-red-600"> *</span></label>
                                        <input x-model="modalQualification.certificateNumber" type="text"
                                               id="qualificationCertificateNumber" class="input-modal">
                                    </div>
                                    <div class="relative">
                                        <svg class="svg-input absolute left-1 !top-2/3 transform -translate-y-1/2 pointer-events-none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M6 5V4a1 1 0 1 1 2 0v1h3V4a1 1 0 1 1 2 0v1h3V4a1 1 0 1 1 2 0v1h1a2 2 0 0 1 2 2v2H3V7a2 2 0 0 1 2-2h1ZM3 19v-8h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Zm5-6a1 1 0 1 0 0 2h8a1 1 0 1 0 0-2H8Z" clip-rule="evenodd"/>
                                        </svg>
                                        <label for="qualificationIssuedDate" class="label-modal">{{ __('forms.issuedDate') }}<span class="text-red-600"> *</span></label>
                                        <input x-model="modalQualification.issuedDate" datepicker-format="{{ frontendDateFormat() }}" type="text" name="qualificationIssuedDate" id="qualificationIssuedDate" class="input-modal datepicker-input" autocomplete="off">
                                    </div>
                                </div>
                                <p class="text-sm text-gray-400 mb-2">{{ __('forms.form_required_note') }}</p>
                                <button @click.prevent="newQualification ? qualifications.push(modalQualification) : qualifications[item] = modalQualification; openModal = false"
                                        class="button-primary"
                                        :class="{ 'opacity-50 cursor-not-allowed': !isModalValid() }"
                                        :disabled="!isModalValid()">
                                    {{ __('forms.save') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </template>

        </div>
    </fieldset>
</div>

<script>
    class Qualification {
        type = '';
        institutionName = '';
        speciality = '';
        certificateNumber = '';
        issuedDate = '';

        constructor(obj = null) {
            if (obj) Object.assign(this, obj);
        }
    }
</script>
