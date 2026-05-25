<script>
    window.encounterMockRecords = [
        { id: 'rec-1', date: '08.05.2025', type: 'condition', typeLabel: @json(__('patients.condition_or_diagnosis')), code: 'A98', name: @json(__('patients.mock.name_A98')), episode: 'ep-1', episodeLabel: @json(__('patients.mock.episode_1')) },
        { id: 'rec-2', date: '08.05.2025', type: 'observation', typeLabel: @json(__('patients.medical_observation')), code: '59041-4', name: @json(__('patients.mock.name_59041_4')), episode: 'ep-1', episodeLabel: @json(__('patients.mock.episode_1')) },
        { id: 'rec-3', date: '08.05.2025', type: 'diagnostic-report', typeLabel: @json(__('patients.medical_diagnostic_report')), code: '11502-2', name: @json(__('patients.mock.name_11502_2')), episode: 'ep-1', episodeLabel: @json(__('patients.mock.episode_1')) },
        { id: 'rec-4', date: '01.05.2025', type: 'condition', typeLabel: @json(__('patients.condition_or_diagnosis')), code: 'I10', name: @json(__('patients.mock.name_I10')), episode: 'ep-2', episodeLabel: @json(__('patients.mock.episode_2')) },
        { id: 'rec-5', date: '01.05.2025', type: 'observation', typeLabel: @json(__('patients.medical_observation')), code: '85354-9', name: @json(__('patients.mock.name_85354_9')), episode: 'ep-2', episodeLabel: @json(__('patients.mock.episode_2')) }
    ];
</script>

<div class="p-5 space-y-6" x-data="{
    showReferencesDrawer: false,
    selectedType: 'condition',
    selectedEpisode: 'ep-1',
    searchQuery: '',
    selectedReferences: [],

    allRecords: window.encounterMockRecords,

    addReference(record) {
        if (!this.selectedReferences.some(r => r.id === record.id)) {
            this.selectedReferences.push(record);
        }
        this.showReferencesDrawer = false;
        this.searchQuery = '';
    },
    cancelSelection() {
        this.showReferencesDrawer = false;
        this.searchQuery = '';
    },
    removeReference(id) {
        this.selectedReferences = this.selectedReferences.filter(r => r.id !== id);
    },
    filteredRecords() {
        return this.allRecords.filter(rec => {
            if (this.searchQuery) {
                const query = this.searchQuery.toLowerCase();
                const matchesSearch = rec.name.toLowerCase().includes(query) || rec.code.toLowerCase().includes(query);
                if (!matchesSearch) return false;
            }
            if (this.selectedType !== 'ALL') {
                if (rec.type !== this.selectedType) return false;
            }
            if (this.selectedEpisode !== 'ALL') {
                if (rec.episode !== this.selectedEpisode) return false;
            }
            return true;
        });
    }
}">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="form-group group">
            <div class="relative">
                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                    @icon('calendar-week', 'w-5 h-5 text-gray-400')
                </div>
                <input wire:model="form.encounter.periodDate"
                       datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                       datepicker-autoselect-today
                       type="text"
                       name="date"
                       id="date"
                       class="datepicker-input with-leading-icon input peer @error('form.encounter.periodDate') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                >
                <label for="date" class="wrapped-label required">
                    {{ __('forms.date') }}
                </label>
            </div>
            @error('form.encounter.periodDate')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <div class="relative">
                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                    @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                </div>
                <input wire:model="form.encounter.periodStart"
                       type="text"
                       name="periodStart"
                       id="periodStart"
                       class="timepicker-uk with-leading-icon input peer @error('form.encounter.periodStart') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                />
                <label for="periodStart" class="wrapped-label required">
                    {{ __('patients.period_start') }}
                </label>
            </div>
            @error('form.encounter.periodStart')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <div class="relative">
                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                    @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                </div>
                <input wire:model="form.encounter.periodEnd"
                       type="text"
                       name="periodEnd"
                       id="periodEnd"
                       class="timepicker-uk with-leading-icon input peer @error('form.encounter.periodEnd') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                />
                <label for="periodEnd" class="wrapped-label required">
                    {{ __('patients.period_end') }}
                </label>
            </div>
            @error('form.encounter.periodEnd')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-group group">
        <select wire:model="form.encounter.divisionId"
                id="divisionNames"
                class="input-select peer @error('form.encounter.divisionId') input-error @enderror"
        >
            <option value="" selected>
                {{ __('forms.select') }} {{ mb_strtolower(__('forms.division_name')) }}
            </option>
            @foreach($divisions as $key => $division)
                <option value="{{ $division['uuid'] }}">{{ $division['name'] }}</option>
            @endforeach
        </select>
        <label for="divisionNames" class="label">
            {{ __('forms.division_name') }}
        </label>
        @error('form.encounter.divisionId')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <select wire:model="form.encounter.priorityCode"
                id="priority"
                class="input-select peer @error('form.encounter.priorityCode') input-error @enderror"
                required
        >
            <option value="" selected>{{ __('forms.select') }} {{ mb_strtolower(__('patients.priority')) }}</option>
            @foreach($this->dictionaries['eHealth/encounter_priority'] as $key => $encounterPriority)
                <option value="{{ $key }}">{{ $encounterPriority }}</option>
            @endforeach
        </select>
        <label for="priority" class="label required">
            {{ __('patients.priority') }}
        </label>
        @error('form.encounter.priorityCode')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-2">
        <label class="text-[13px] font-medium text-gray-500 dark:text-gray-400 ml-1">
            {{ __('patients.assignments') }}
        </label>
        <textarea
            class="w-full min-h-[120px] p-4 text-[15px] text-gray-900 dark:text-white bg-gray-50/50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all outline-none resize-none"
            placeholder="{{ __('patients.write_assignments_here') }}"
        ></textarea>
    </div>

    <div x-data="{ services: [''], coAuthors: [''] }" class="space-y-6">
        <div class="space-y-3">
            <template x-for="(service, index) in services" :key="index">
                <div class="relative pr-10">
                    <div class="form-group group">
                        <select class="input-select peer" :id="'service_' + index" x-model="services[index]">
                            <option value="" selected>{{ __('dictionaries.service_catalog.search_services') }}</option>
                            @foreach($this->dictionaries['custom/services'] ?? [] as $key => $service)
                                <option value="{{ $service['id'] ?? $key }}">{{ $service['name'] ?? $service }}</option>
                            @endforeach
                        </select>
                        <label :for="'service_' + index" class="label">{{ __('care-plan.services') }}</label>
                    </div>
                    <button type="button"
                            x-show="index > 0"
                            @click="services.splice(index, 1)"
                            class="absolute right-0 top-3 text-gray-400 dark:text-gray-500 hover:text-red-500 transition-colors">
                        @icon('delete', 'w-6 h-6')
                    </button>
                </div>
            </template>
            <button type="button" @click="services.push('')" class="cursor-pointer text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1.5 font-medium text-sm transition-colors ml-1">
                @icon('plus', 'w-4 h-4')
                <span>{{ __('care-plan.add_service') }}</span>
            </button>
        </div>

        <div class="space-y-3">
            <template x-for="(coAuthor, index) in coAuthors" :key="index">
                <div class="relative pr-10">
                    <div class="form-group group">
                        <select class="input-select peer" :id="'coAuthor_' + index" x-model="coAuthors[index]">
                            <option value="" selected>{{ __('patients.find_doctor') }}</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee['uuid'] }}">{{ $employee['name'] }}</option>
                            @endforeach
                        </select>
                        <label :for="'coAuthor_' + index" class="label">{{ __('patients.coauthor') }}</label>
                    </div>
                    <button type="button"
                            x-show="index > 0"
                            @click="coAuthors.splice(index, 1)"
                            class="absolute right-0 top-3 text-gray-400 dark:text-gray-500 hover:text-red-500 transition-colors">
                        @icon('delete', 'w-6 h-6')
                    </button>
                </div>
            </template>
            <button type="button" @click="coAuthors.push('')" class="cursor-pointer text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1.5 font-medium text-sm transition-colors ml-1">
                @icon('plus', 'w-4 h-4')
                <span>{{ __('patients.add_coauthor') }}</span>
            </button>
        </div>
    </div>

    <div class="pt-4 border-t border-gray-100 dark:border-gray-700 space-y-4">
        <h3 class="text-[15px] font-bold text-gray-900 dark:text-white">
            {{ __('care-plan.search_medical_records') }}
        </h3>

        <div x-show="selectedReferences.length > 0" x-cloak class="my-3 overflow-x-auto">
            <table class="table-input w-inherit">
                <thead class="thead-input">
                    <tr>
                        <th scope="col" class="th-input w-[15%] uppercase">{{ mb_strtoupper(__('forms.date')) }}</th>
                        <th scope="col" class="th-input w-[75%] uppercase">{{ mb_strtoupper(__('forms.name')) }}</th>
                        <th scope="col" class="th-input text-right pr-8 w-[10%] uppercase">{{ mb_strtoupper(__('forms.actions')) }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="ref in selectedReferences" :key="ref.id">
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <td class="td-input text-[14px] text-gray-900 dark:text-gray-300" x-text="ref.date"></td>
                            <td class="td-input text-[14px] text-gray-900 dark:text-white" x-text="ref.typeLabel + ' ' + ref.name"></td>
                            <td class="td-input text-right pr-8">
                                <button type="button"
                                        @click="removeReference(ref.id)"
                                        class="text-gray-400 dark:text-gray-500 hover:text-red-500 transition-colors p-1"
                                >
                                    @icon('delete', 'w-5 h-5')
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <button type="button"
                @click="showReferencesDrawer = true"
                class="cursor-pointer text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1.5 font-medium text-sm transition-colors block">
            @icon('plus', 'w-4 h-4')
            <span>{{ __('patients.add_observations_reports_conditions') }}</span>
        </button>

        @include('livewire.encounter.parts.references-drawer')
    </div>
</div>
