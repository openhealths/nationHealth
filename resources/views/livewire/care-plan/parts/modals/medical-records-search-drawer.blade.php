@php
    $recordTypes = [
        'Condition' => __('care-plan.diagnoses_conditions'),
        'Observation' => __('care-plan.observations'),
        'DiagnosticReport' => __('care-plan.diagnostic_reports'),
    ];
    $searchRecords = collect($availableConditions ?? [])
        ->map(fn ($item) => array_merge($item, ['type' => 'Condition']))
        ->concat(collect($availableObservations ?? [])->map(fn ($item) => array_merge($item, ['type' => 'Observation'])))
        ->concat(collect($availableReports ?? [])->map(fn ($item) => array_merge($item, ['type' => 'DiagnosticReport'])));
    $searchRecords = $searchRecords->map(function ($item) use ($recordTypes) {
        $episode = $item['episode'] ?? null;
        $id = $item['episode_id'] ?? (is_array($episode) ? ($episode['id'] ?? $episode['uuid'] ?? '') : $episode);

        return array_merge($item, [
            'typeName' => $recordTypes[$item['type']],
            'episodeId' => (string) $id,
            'episodeName' => $item['episode_name'] ?? (is_array($episode) ? ($episode['name'] ?? $id) : $episode) ?? $id,
        ]);
    })->values();
    $episodeOptions = $searchRecords->filter(fn ($record) => $record['episodeId'] !== '')
        ->pluck('episodeName', 'episodeId');
    $searchRecords = $searchRecords->all();
@endphp
<x-dialog-drawer
    id="care-plan-medical-record-search"
    x-model="showMedicalRecordsSearchDrawer"
    :stopClickPropagation="true"
    topClass="top-[57px]"
    maxWidth="3/5"
    zIndex="46"
    overlayWidth="100%"
    onCloseClick="showMedicalRecordsSearchDrawer = false"
>
    <x-slot name="title">{{ __('care-plan.search_medical_records') }}</x-slot>
    <div class="care-plan-record-search" wire:key="medical-record-search-{{ $carePlan->id }}-{{ md5(json_encode($searchRecords)) }}"
        x-data="{ query: '', selectedType: 'Condition', episode: '', records: @js($searchRecords),
            get filteredRecords() {
                return this.records.filter(record => (!this.selectedType || record.type === this.selectedType)
                    && (!this.query || record.name.toLocaleLowerCase().includes(this.query.toLocaleLowerCase()))
                    && (!this.episode || record.episodeId === this.episode));
            }
        }">
        <fieldset>
            <legend>{{ __('care-plan.search_medical_records') }}</legend>
            <div class="mb-6 flex items-center gap-2 text-sm">@icon('search', 'w-4 h-4') {{ __('forms.search') }}</div>
            <div class="form-row-2">
                <div class="form-group group">
                    <select id="care-plan-record-type" x-model="selectedType" class="input-select peer">
                        <option value="">{{ __('care-plan.all_types') }}</option>
                        @foreach ($recordTypes as $type => $typeLabel)<option value="{{ $type }}">{{ $typeLabel }}</option>@endforeach
                    </select>
                    <label for="care-plan-record-type" class="label">{{ __('care-plan.medical_record_type') }}</label>
                </div>
                <div class="form-group group">
                    <select id="care-plan-record-episode" x-model="episode" @disabled($episodeOptions->isEmpty()) class="input-select peer">
                        <option value="">{{ __('care-plan.all_episodes') }}</option>
                        @foreach ($episodeOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    <label for="care-plan-record-episode" class="label">{{ __('care-plan.episode') }}</label>
                </div>
            </div>
            <div class="form-group group mt-6 max-w-sm">
                <input id="care-plan-record-query" type="search" x-model="query" class="input peer" placeholder=" " />
                <label for="care-plan-record-query" class="label">{{ __('care-plan.record_name_search') }}</label>
            </div>
        </fieldset>
        <div class="table-scroll mt-12">
            <table class="table-input w-full">
                <thead class="thead-input"><tr>
                    <th class="th-input">{{ __('care-plan.date') }}</th>
                    <th class="th-input">{{ __('care-plan.type') }}</th>
                    <th class="th-input">{{ __('care-plan.name') }}</th>
                    <th class="th-input text-right">{{ __('care-plan.action') }}</th>
                </tr></thead>
                <tbody>
                    <template x-for="record in filteredRecords" :key="record.type + record.uuid">
                        <tr>
                            <td class="td-input whitespace-nowrap" x-text="record.date"></td>
                            <td class="td-input" x-text="record.typeName"></td>
                            <td class="td-input" x-text="record.name"></td>
                            <td class="td-input text-right">
                                <button type="button" aria-label="{{ __('care-plan.add_medical_record') }}" class="cursor-pointer"
                                    @click="$wire.addLinkedGround(record.type, record.uuid); showMedicalRecordsSearchDrawer = false">
                                    @icon('plus-circle', 'w-5 h-5')
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="filteredRecords.length === 0"><td colspan="4" class="td-input text-center text-gray-400">{{ __('care-plan.nothing_found_by_filters') }}</td></tr>
                </tbody>
            </table>
        </div>
        <button type="button" class="button-minor mt-12" @click="showMedicalRecordsSearchDrawer = false">{{ __('forms.cancel') }}</button>
    </div>
</x-dialog-drawer>
