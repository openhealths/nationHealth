<div class="overflow-x-auto relative">
    <fieldset class="fieldset"
              id="section-external-contractors"
              x-data="{
              openModal: false,
              editingIndex: null,
              localExternalContractors: @js($externalContractors ?? []),
              externalContractor: {
                  legalEntityId: '',
                  legalEntityName: '',
                  number: '',
                  issuedAt: '',
                  expiresAt: '',
                  divisionId: '',
                  divisionName: '',
                  medicalService: ''
              },
              externalContractorFlag: @entangle('form.externalContractorFlag').defer,

              init() {
                  this.localExternalContractors = this.localExternalContractors.map(contractor => {
                      if (contractor.legalEntityId) {
                          return contractor;
                      }
                      return {
                          legalEntityId: contractor.legal_entity?.id || contractor.legalEntityId || '',
                          legalEntityName: contractor.legal_entity?.name || contractor.legalEntityName || '',
                          contract: contractor.contract || {},
                          divisions: contractor.divisions || {}
                      };
                  });
              },

              addExternalContractor() {
                  if (!this.externalContractor.legalEntityId || !this.externalContractor.number || !this.externalContractor.issuedAt) {
                      return;
                  }

                  const contractor = {
                      legalEntityId: this.externalContractor.legalEntityId,
                      legalEntityName: this.externalContractor.legalEntityName,
                      contract: {
                          number: this.externalContractor.number,
                          issuedAt: this.externalContractor.issuedAt,
                          expiresAt: this.externalContractor.expiresAt || ''
                      },
                      divisions: {
                          id: this.externalContractor.divisionId,
                          name: this.externalContractor.divisionName,
                          medicalService: this.externalContractor.medicalService
                      }
                  };

                  if (this.editingIndex !== null) {
                      this.localExternalContractors[this.editingIndex] = contractor;
                      this.editingIndex = null;
                  } else {
                      this.localExternalContractors.push(contractor);
                  }

                  this.resetForm();
                  this.openModal = false;
              },

              editExternalContractor(index) {
                  const contractor = this.localExternalContractors[index];
                  const legalEntityId = contractor.legalEntityId || contractor.legal_entity?.id || '';
                  const legalEntityName = contractor.legalEntityName || contractor.legal_entity?.name || '';

                  this.externalContractor = {
                      legalEntityId: legalEntityId,
                      legalEntityName: legalEntityName,
                      number: contractor.contract?.number || '',
                      issuedAt: contractor.contract?.issuedAt || '',
                      expiresAt: contractor.contract?.expiresAt || '',
                      divisionId: contractor.divisions?.id || '',
                      divisionName: contractor.divisions?.name || '',
                      medicalService: contractor.divisions?.medicalService || ''
                  };
                  this.editingIndex = index;
                  this.openModal = true;
              },

              deleteExternalContractor(index) {
                  this.localExternalContractors.splice(index, 1);
              },

              resetForm() {
                  this.externalContractor = {
                      legalEntityId: '',
                      legalEntityName: '',
                      number: '',
                      issuedAt: '',
                      expiresAt: '',
                      divisionId: '',
                      divisionName: '',
                      medicalService: ''
                  };
                  this.editingIndex = null;
              },

              openAddModal() {
                  this.resetForm();
                  this.openModal = true;
              },

              updateLegalEntityName() {
                  const select = document.getElementById('legalEntityData');
                  const selectedOption = select.options[select.selectedIndex];
                  if (selectedOption && selectedOption.text) {
                      this.externalContractor.legalEntityName = selectedOption.text.trim();
                  }
              },

              updateDivisionName() {
                  const select = document.getElementById('divisionName');
                  const selectedOption = select.options[select.selectedIndex];
                  if (selectedOption && selectedOption.text) {
                      this.externalContractor.divisionName = selectedOption.text.trim();
                  }
              },

              saveExternalContractorsToServer() {
                  @this.set('form.externalContractors', this.localExternalContractors);
              }
          }"
    >
        <legend class="legend">
            <h2>{{ __('contracts.external_contractor') }}</h2>
        </legend>

        <p class="default-p mb-6"> {{ __('contracts.external_contractor_info') }}</p>

        <div class="form-row">
            <div class="form-group">
                <input type="checkbox"
                       x-model="externalContractorFlag"
                       class="default-checkbox"
                       id="flag"
                       name="flag"
                >
                <label for="flag" class="default-p">
                    {{ __('contracts.external_contractor_flag') }}
                </label>
            </div>
        </div>

        <div x-show="externalContractorFlag" x-cloak>
            <table class="table-input w-inherit">
                <thead class="thead-input">
                <tr>
                    <th scope="col" class="td-input">{{ __('contracts.legal_entity_name') }}</th>
                    <th scope="col" class="td-input">{{ __('contracts.number') }}</th>
                    <th scope="col" class="td-input">{{ __('contracts.issued_at') }}</th>
                    <th scope="col" class="td-input">{{ __('contracts.expires_at') }}</th>
                    <th scope="col" class="td-input">{{ __('forms.actions') }}</th>
                </tr>
                </thead>
                <tbody>
                <template x-for="(contractor, index) in localExternalContractors" :key="index">
                    <tr>
                        <td class="td-input" x-text="contractor.legalEntityName || contractor.legal_entity?.name || ''"></td>
                        <td class="td-input" x-text="contractor.contract?.number || ''"></td>
                        <td class="td-input" x-text="contractor.contract?.issuedAt || ''"></td>
                        <td class="td-input" x-text="contractor.contract?.expiresAt || ''"></td>
                        <td class="td-input flex flex-row gap-2">
                            <button @click="editExternalContractor(index)" class="svg-hover-action">
                                @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                            </button>
                        </td>
                    </tr>
                </template>
                </tbody>
            </table>
        </div>

        <button type="button"
                class="item-add my-5"
                @click="openAddModal()"
        >
            <span>{{ __('contracts.add_external_contractor') }}</span>
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
                     class="relative flex min-h-screen items-center justify-center p-4"
                >
                    <div @click.stop
                         x-trap.noscroll.inert="openModal"
                         class="modal-content h-fit w-full max-w-6xl rounded-2xl shadow-lg bg-white"
                    >
                        <h3 class="modal-header" :id="$id('modal-title')">
                            <span x-text="editingIndex !== null ? '{{ __('contracts.edit_external_contractor') }}' : '{{ __('contracts.new_external_contractor') }}'"></span>
                        </h3>

                        <form>
                            <div class="form-row-modal">
                                <div class="form-group group">
                                    <label for="legalEntityData" class="label-modal">
                                        {{ __('contracts.legal_entity_data') }}
                                    </label>
                                    <select x-model="externalContractor.legalEntityId"
                                            @change="updateLegalEntityName()"
                                            type="text"
                                            name="legalEntityData"
                                            id="legalEntityData"
                                            class="input-modal"
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach($legalEntities as $legalEntity)
                                            <option value="{{ $legalEntity['id'] }}">
                                                {{ $legalEntity['edr']['public_name'] }}
                                                - {{ $legalEntity['edr']['edrpou'] }}
                                            </option>
                                        @endforeach
                                    </select>

                                    @error('form.externalContractors.legalEntityId')
                                    <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="number" class="label-modal">
                                        {{__('contracts.external_contractor_number')}}
                                        <span class="text-red-600"> *</span>
                                    </label>
                                    <input x-model="externalContractor.number"
                                           type="text"
                                           id="number"
                                           class="input-modal"
                                           required
                                    >

                                    @error('externalContractors.contract.number')
                                    <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="form-group">
                                    <label for="issuedAt" class="label-modal">
                                        {{__('contracts.start_date_label')}}<span class="text-red-600"> *</span>
                                    </label>
                                    <div class="datepicker-wrapper relative w-full">
                                        <input x-model="externalContractor.issuedAt"
                                               datepicker-format="{{ frontendDateFormat() }}"
                                               type="text"
                                               id="issuedAt"
                                               class="input-modal datepicker-input with-leading-icon !pl-10"
                                               autocomplete="off"
                                               placeholder=" "
                                               required
                                        >
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="expiresAt" class="label-modal">
                                        {{__('contracts.end_date_label')}}<span class="text-red-600"> *</span>
                                    </label>
                                    <div class="datepicker-wrapper relative w-full">
                                        <input x-model="externalContractor.expiresAt"
                                               datepicker-format="{{ frontendDateFormat() }}"
                                               type="text"
                                               id="expiresAt"
                                               class="input-modal datepicker-input with-leading-icon !pl-10"
                                               autocomplete="off"
                                               placeholder=" "
                                               required
                                        >
                                    </div>
                                </div>

                                <div class="form-group group">
                                    <label for="divisionName" class="label-modal">
                                        {{ __('forms.division_name') }}<span class="text-red-600"> *</span>
                                    </label>
                                    <select x-model="externalContractor.divisionId"
                                            @change="updateDivisionName()"
                                            type="text"
                                            name="divisionName"
                                            id="divisionName"
                                            class="input-modal"
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach($divisions as $division)
                                            <option value="{{ $division['id'] }}"> {{ $division['name'] }}</option>
                                        @endforeach
                                    </select>

                                    @error('form.externalContractors.divisions.id')
                                    <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="medicalService" class="label-modal">
                                        {{ __('forms.service') }}<span class="text-red-600"> *</span>
                                    </label>
                                    <select x-model="externalContractor.medicalService"
                                            type="text"
                                            name="medicalService"
                                            id="medicalService"
                                            class="input-modal"
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach($this->dictionaries['MEDICAL_SERVICE'] as $key => $medicalService)
                                            <option value="{{ $key }}"> {{ $medicalService }}</option>
                                        @endforeach
                                    </select>

                                    @error('form.externalContractors.divisions.medicalService')
                                    <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <p class="text-sm text-gray-400 mb-2">{{ __('forms.form_required_note') }}</p>

                            <div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 pt-6">
                                <button type="button"
                                        @click="openModal = false"
                                        class="button-minor"
                                >
                                    {{ __('forms.cancel') }}
                                </button>

                                <button type="submit"
                                        @click.prevent="addExternalContractor()"
                                        :class="{ 'opacity-50 cursor-not-allowed': !(externalContractor.legalEntityId && externalContractor.number && externalContractor.issuedAt) }"
                                        :disabled="!(externalContractor.legalEntityId && externalContractor.number && externalContractor.issuedAt)"
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
    </fieldset>
</div>
