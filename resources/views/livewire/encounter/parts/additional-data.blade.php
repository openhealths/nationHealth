<div
    class="space-y-6 p-5"
    x-data="{
        showReferencesDrawer: false,
        selectedType: '',
        searchQuery: '',
        selectedReferences: $wire.entangle('form.encounter.supportingInfo'),
        allRecords: [],
        medicalRecordsLoading: false,
        hasSearchedMedicalRecords: false,

        init() {
            this.selectedReferences = (this.selectedReferences ?? []).filter(reference => reference?.uuid && reference?.type);
        },

        openReferencesDrawer() {
            this.showReferencesDrawer = true;
            this.selectedType = '';
            this.searchQuery = '';
            this.allRecords = [];
            this.medicalRecordsLoading = false;
            this.hasSearchedMedicalRecords = false;
        },

        loadMedicalRecords() {
            if (this.selectedType === '') {
                this.allRecords = [];
                this.medicalRecordsLoading = false;
                this.hasSearchedMedicalRecords = false;

                return;
            }

            this.medicalRecordsLoading = true;
            this.hasSearchedMedicalRecords = true;
            this.allRecords = [];

            const typeLabels = {
                condition: '{{ __("conditions.condition_or_diagnosis") }}',
                observation: '{{ __("observations.medical_label") }}',
                diagnosticReport: '{{ __("diagnostic-reports.label") }}',
            };

            $wire.searchSupportingInfo(this.selectedType)
                .then(() => {
                    const dicts = $wire.dictionaries;
                    const lookupName = (code) =>
                        dicts['eHealth/ICPC2/condition_codes']?.[code] ||
                        dicts['eHealth/ICD10_AM/condition_codes']?.[code] ||
                        dicts['eHealth/LOINC/observation_codes']?.[code] ||
                        dicts['eHealth/custom/observation_codes']?.[code] ||
                        dicts['eHealth/ICF/classifiers']?.[code] ||
                        '';

                    this.allRecords = ($wire.supportingInfoResults ?? []).map(result => ({
                        uuid: result.uuid,
                        type: result.type,
                        typeLabel: typeLabels[this.selectedType] || result.type,
                        code: result.code,
                        name: lookupName(result.code),
                        date: result.ehealthInsertedAt,
                    }));
                })
                .finally(() => {
                    this.medicalRecordsLoading = false;
                });
        },

        addReference(record) {
            const alreadySelected = this.selectedReferences.some(
                reference => reference.uuid === record.uuid && reference.type === record.type
            );

            if (!alreadySelected) {
                this.selectedReferences = [...this.selectedReferences, {
                    uuid: record.uuid,
                    type: record.type,
                    typeLabel: record.typeLabel,
                    code: record.code,
                    name: record.name,
                    date: record.date,
                }];
            }

            this.showReferencesDrawer = false;
            this.searchQuery = '';
        },

        cancelSelection() {
            this.showReferencesDrawer = false;
            this.searchQuery = '';
        },

        removeReference(uuid, type) {
            this.selectedReferences = this.selectedReferences.filter(
                reference => !(reference.uuid === uuid && reference.type === type)
            );
        },

        filteredRecords() {
            return this.allRecords.filter((record) => {
                if (this.searchQuery) {
                    const query = this.searchQuery.toLowerCase();
                    const matchesSearch = [record.code, record.name, record.typeLabel]
                        .filter(Boolean)
                        .some((value) => String(value).toLowerCase().includes(query));

                    if (!matchesSearch) {
                        return false;
                    }
                }

                return true;
            });
        },

     }"
>
    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
        <div class="form-group group">
            <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                    @icon('calendar-week', 'w-5 h-5 text-gray-400')
                </div>
                <input
                    wire:model="form.encounter.periodDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    datepicker-autoselect-today
                    type="text"
                    name="date"
                    id="date"
                    class="datepicker-input with-leading-icon input peer @error('form.encounter.periodDate') input-error @enderror"
                    placeholder=" "
                    required
                    autocomplete="off"
                />
                <label for="date" class="wrapped-label required"> {{ __('forms.date') }} </label>
            </div>
            @error('form.encounter.periodDate')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                    @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                </div>
                <input
                    wire:model="form.encounter.periodStart"
                    type="text"
                    name="periodStart"
                    id="periodStart"
                    class="timepicker-uk with-leading-icon input peer @error('form.encounter.periodStart') input-error @enderror"
                    placeholder=" "
                    required
                    autocomplete="off"
                />
                <label for="periodStart" class="wrapped-label required"> {{ __('forms.start_time') }} </label>
            </div>
            @error('form.encounter.periodStart')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                    @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                </div>
                <input
                    wire:model="form.encounter.periodEnd"
                    type="text"
                    name="periodEnd"
                    id="periodEnd"
                    class="timepicker-uk with-leading-icon input peer @error('form.encounter.periodEnd') input-error @enderror"
                    placeholder=" "
                    required
                    autocomplete="off"
                />
                <label for="periodEnd" class="wrapped-label required"> {{ __('encounters.period_end') }} </label>
            </div>
            @error('form.encounter.periodEnd')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-group group">
        <select
            wire:model="form.encounter.divisionId"
            x-on:change="
                $dispatch('encounter-division-changed', {
                    divisionId: $event.target.value,
                })
            "
            id="divisionNames"
            class="input-select peer @error('form.encounter.divisionId') input-error @enderror"
        >
            <option value="" selected>{{ __('forms.select') }} {{ mb_strtolower(__('forms.division_name')) }}</option>
            @foreach ($divisions as $key => $division)
                <option value="{{ $division['uuid'] }}">{{ $division['name'] }}</option>
            @endforeach
        </select>
        <label for="divisionNames" class="label"> {{ __('forms.division_name') }} </label>
        @error('form.encounter.divisionId')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <select
            wire:model="form.encounter.priorityCode"
            id="priority"
            class="input-select peer @error('form.encounter.priorityCode') input-error @enderror"
        >
            <option value="" selected>{{ __('forms.select') }} {{ mb_strtolower(__('patients.priority')) }}</option>
            @foreach ($this->dictionaries['eHealth/encounter_priority'] as $key => $encounterPriority)
                <option value="{{ $key }}">{{ $encounterPriority }}</option>
            @endforeach
        </select>
        <label for="priority" class="label"> {{ __('patients.priority') }} </label>
        @error('form.encounter.priorityCode')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-2">
        <label for="encounterPrescriptions" class="ml-1 text-[13px] font-medium text-gray-500 dark:text-gray-400">
            {{ __('encounters.assignments') }}
        </label>
        <textarea
            wire:model="form.encounter.prescriptions"
            id="encounterPrescriptions"
            class="w-full min-h-30 p-4 text-[15px] text-gray-900 dark:text-white bg-gray-50/50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all outline-none resize-none @error('form.encounter.prescriptions') input-error @enderror"
            placeholder="{{ __('encounters.write_assignments_here') }}"
        ></textarea>
    </div>

    <div
        x-data="{
            services: $wire.entangle('form.encounter.actionReferences'),
            serviceOptions: [],
            serviceSearches: [],
            serviceDropdowns: [],
            serviceFilteredOptions: [],

            init() {
                this.serviceOptions = Object.entries($wire.dictionaries['custom/services'] ?? {})
                    .map(([key, service]) => ({
                        id: String(typeof service === 'object' ? (service.id ?? key) : key),
                        code: String(typeof service === 'object' ? (service.code ?? '') : ''),
                        name: String(typeof service === 'object' ? (service.name ?? '') : service),
                        category: String(typeof service === 'object' ? (service.category ?? '') : ''),
                        searchText: (
                            String(typeof service === 'object' ? (service.name ?? '') : service) +
                            ' ' +
                            String(typeof service === 'object' ? (service.code ?? '') : '')
                        ).toLowerCase(),
                    }))
                    .filter((service) => service.id && service.category);

                this.services = Array.isArray(this.services) && this.services.length ? this.services : [{ uuid: '' }];
                this.coAuthors = Array.isArray(this.coAuthors) ? this.coAuthors : [];

                this.serviceSearches = this.services.map((service) => {
                    if (! service.uuid) {
                        return '';
                    }
                    const option = this.serviceOptions.find((opt) => opt.id === service.uuid);
                    return option ? this.serviceLabel(option) : '';
                });
                this.serviceDropdowns = this.services.map(() => false);
                this.serviceFilteredOptions = this.services.map(() => []);
            },

            addService() {
                this.services = [...(Array.isArray(this.services) ? this.services : []), { uuid: '' }];
                this.serviceSearches = [...this.serviceSearches, ''];
                this.serviceDropdowns = [...this.serviceDropdowns, false];
                this.serviceFilteredOptions = [...this.serviceFilteredOptions, []];
            },

            removeService(index) {
                this.services = this.services.filter((_, rowIndex) => rowIndex !== index);
                this.serviceSearches = this.serviceSearches.filter((_, rowIndex) => rowIndex !== index);
                this.serviceDropdowns = this.serviceDropdowns.filter((_, rowIndex) => rowIndex !== index);
                this.serviceFilteredOptions = this.serviceFilteredOptions.filter((_, rowIndex) => rowIndex !== index);

                if (! this.services.length) {
                    this.services = [{ uuid: '' }];
                    this.serviceSearches = [''];
                    this.serviceDropdowns = [false];
                    this.serviceFilteredOptions = [[]];
                }
            },

            serviceLabel(service) {
                return [service.code, service.name].filter(Boolean).join(' / ');
            },

            updateFilteredOptions(index) {
                const query = String(this.serviceSearches[index] ?? '')
                    .toLowerCase()
                    .trim();
                const MAX = 200;
                const results = [];
                const onlyCounselling = $wire.form.encounter.classCode === 'AMB';

                if (query) {
                    for (const service of this.serviceOptions) {
                        if (results.length >= MAX) break;
                        if (onlyCounselling && service.category !== 'counselling') continue;
                        if (service.searchText.includes(query)) {
                            results.push(service);
                        }
                    }
                }

                const updated = [...this.serviceFilteredOptions];
                updated[index] = results;
                this.serviceFilteredOptions = updated;
            },

            selectService(index, service) {
                this.services[index] = { uuid: service.id };
                this.serviceSearches[index] = this.serviceLabel(service);
                this.serviceDropdowns[index] = false;
            },

            clearService(index) {
                this.services[index] = { uuid: '' };
                this.serviceSearches[index] = '';
                this.serviceDropdowns[index] = true;
                this.updateFilteredOptions(index);
            },

            isCoAuthorAlreadySelected(employeeUuid, currentIndex) {
                if (! employeeUuid) {
                    return false;
                }

                return (Array.isArray(this.coAuthors) ? this.coAuthors : []).some(
                    (coAuthor, index) =>
                        index !== currentIndex && coAuthor?.uuid && String(coAuthor.uuid) === String(employeeUuid),
                );
            },

            validateCoAuthorSelection(index) {
                const selectedUuid = this.coAuthors[index]?.uuid;

                if (selectedUuid && this.isCoAuthorAlreadySelected(selectedUuid, index)) {
                    this.coAuthors[index].uuid = '';
                }
            },

            addCoAuthor() {
                this.coAuthors = [
                    ...(Array.isArray(this.coAuthors) ? this.coAuthors : []),
                    {
                        uuid: '',
                        name: '',
                        locked: false,
                        manual: true,
                        sources: [],
                    },
                ];
            },

            removeCoAuthor(index) {
                if (this.coAuthors[index]?.locked) {
                    return;
                }

                this.coAuthors = this.coAuthors.filter((_, rowIndex) => rowIndex !== index);
            },
        }"
        class="space-y-6"
    >
        <div
            class="space-y-3"
            x-show="$wire.form.encounter.classCode !== 'PHC'"
            x-effect="
                if ($wire.form.encounter.classCode === 'PHC' && services.some((service) => service.uuid)) {
                    services = [{ uuid: '' }];
                    serviceSearches = [''];
                    serviceDropdowns = [false];
                    serviceFilteredOptions = [[]];
                }
            "
        >
            <template x-for="(service, index) in services" :key="index">
                <div class="relative pr-10">
                    <div class="form-group group relative" @click.away="serviceDropdowns[index] = false">
                        <input
                            type="text"
                            class="input peer @error('form.encounter.actionReferences.0') input-error @enderror"
                            :id="'service_' + index"
                            x-model="serviceSearches[index]"
                            @input.debounce.150ms="
                                services[index] = { uuid: '' };
                                serviceDropdowns[index] = true;
                                updateFilteredOptions(index);
                            "
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label :for="'service_' + index" class="label">{{ __('care-plan.services') }}</label>

                        <div
                            x-show="serviceDropdowns[index]"
                            x-cloak
                            class="absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                        >
                            <template x-if="! serviceSearches[index]">
                                <div class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('forms.type_to_search') }}
                                </div>
                            </template>

                            <template x-if="serviceSearches[index] && (serviceFilteredOptions[index] || []).length === 0">
                                <div class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('forms.not_found') }}
                                </div>
                            </template>

                            <template
                                x-for="serviceOption in (serviceFilteredOptions[index] || [])"
                                :key="serviceOption.id"
                            >
                                <button
                                    type="button"
                                    class="block w-full cursor-pointer px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                                    @click="selectService(index, serviceOption)"
                                >
                                    <span x-text="serviceLabel(serviceOption)"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                    <button
                        type="button"
                        x-show="index > 0"
                        x-cloak
                        @click="removeService(index)"
                        class="absolute top-3 right-0 text-gray-400 transition-colors hover:text-red-500 dark:text-gray-500"
                    >
                        @icon('delete', 'w-6 h-6')
                    </button>
                </div>
            </template>

            <button
                type="button"
                @click="addService()"
                class="ml-1 flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
            >
                @icon('plus', 'w-4 h-4')
                <span>{{ __('care-plan.add_service') }}</span>
            </button>
            @error('form.encounter.actionReferences.0')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-3">
            <template x-for="(coAuthor, index) in coAuthors" :key="`${coAuthor.uuid || 'manual'}-${index}`">
                <div class="relative pr-10">
                    <template x-if="coAuthor.locked || {{ $isReadonly ? 'true' : 'false' }}">
                        <div class="form-group group">
                            <input
                                type="text"
                                class="input peer"
                                :id="'coAuthor_' + index"
                                :value="participantName(coAuthor)"
                                disabled
                                placeholder=" "
                            />
                            <label :for="'coAuthor_' + index" class="label" x-text="participantLabel(coAuthor)"></label>
                        </div>
                    </template>

                    <template x-if="! coAuthor.locked && ! {{ $isReadonly ? 'true' : 'false' }}">
                        <div class="form-group group">
                            <select
                                class="input-select peer @error('form.encounter.participant.0') input-error @enderror"
                                :id="'coAuthor_' + index"
                                x-model="coAuthors[index].uuid"
                                @change="validateCoAuthorSelection(index)"
                            >
                                <option value="">{{ __('encounters.find_doctor') }}</option>

                                @foreach ($this->employees as $employee)
                                    <option
                                        value="{{ $employee['uuid'] }}"
                                        :disabled="isCoAuthorAlreadySelected(
                                            @js((string) $employee['uuid']),
                                            index
                                        )"
                                    >
                                        {{ $employee['name'] }}
                                    </option>
                                @endforeach
                            </select>

                            <label :for="'coAuthor_' + index" class="label"> {{ __('encounters.coauthor') }} </label>
                        </div>
                    </template>

                    <button
                        type="button"
                        x-show="! coAuthor.locked && ! {{ $isReadonly ? 'true' : 'false' }}"
                        x-cloak
                        @click="removeCoAuthor(index)"
                        class="absolute top-3 right-0 text-gray-400 transition-colors hover:text-red-500 dark:text-gray-500"
                    >
                        @icon('delete', 'w-6 h-6')
                    </button>
                </div>
            </template>

            @unless ($isReadonly)
                <button
                    type="button"
                    @click="addCoAuthor()"
                    class="ml-1 flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                >
                    @icon('plus', 'w-4 h-4')
                    <span>{{ __('encounters.add_coauthor') }}</span>
                </button>
            @endunless
            @error('form.encounter.participant.0')
                <p class="text-error">{{ $message }}</p>
            @enderror
            @error('form.encounter.participant')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="space-y-4 border-t border-gray-100 pt-4 dark:border-gray-700">
        <h3 class="text-[15px] font-bold text-gray-900 dark:text-white">
            {{ __('care-plan.search_medical_records') }}
        </h3>

        <div x-show="selectedReferences.length > 0" x-cloak class="my-3 overflow-x-auto">
            <table class="table-input w-inherit">
                <thead class="thead-input">
                    <tr>
                        <th scope="col" class="th-input w-[15%] uppercase">{{ mb_strtoupper(__('forms.date')) }}</th>
                        <th scope="col" class="th-input w-[75%] uppercase">{{ mb_strtoupper(__('forms.name')) }}</th>
                        <th scope="col" class="th-input w-[10%] pr-8 text-right uppercase">
                            {{ mb_strtoupper(__('forms.actions')) }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="ref in selectedReferences" :key="ref.type + '-' + ref.uuid">
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <td
                                class="td-input text-[14px] text-gray-900 dark:text-gray-300"
                                x-text="ref.date || '—'"
                            ></td>
                            <td
                                class="td-input text-[14px] text-gray-900 dark:text-white"
                                x-text="
                                    (() => {
                                        const dictName =
                                            $wire.dictionaries['eHealth/LOINC/observation_codes'][ref.code] ||
                                            $wire.dictionaries['eHealth/ICF/classifiers'][ref.code] ||
                                            $wire.dictionaries['eHealth/ICPC2/condition_codes'][ref.code];

                                        const codeName = dictName
                                            ? `${ref.code} - ${dictName}`
                                            : (() => {
                                                  const serviceOption = Object.values(
                                                      $wire.dictionaries['custom/services'],
                                                  ).find((serviceOption) => serviceOption.id === ref.code);
                                                  return serviceOption
                                                      ? `${serviceOption.code} / ${serviceOption.name}`
                                                      : ref.name || ref.code || '—';
                                              })();

                                        return [ref.typeLabel, codeName].filter(Boolean).join(' ');
                                    })()
                                "
                            ></td>
                            <td class="td-input pr-8 text-right">
                                <button
                                    type="button"
                                    @click="removeReference(ref.uuid, ref.type)"
                                    class="p-1 text-gray-400 transition-colors hover:text-red-500 dark:text-gray-500"
                                >
                                    @icon('delete', 'w-5 h-5')
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <button
            type="button"
            @click="openReferencesDrawer()"
            class="block flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
        >
            @icon('plus', 'w-4 h-4')
            <span>{{ __('encounters.add_observations_reports_conditions') }}</span>
        </button>

        @error('form.encounter.supportingInfo.0.uuid')
            <p class="text-error">{{ $message }}</p>
        @enderror
        @error('form.encounter.supportingInfo.0.type')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    @include('livewire.encounter.parts.references-drawer')
</div>
