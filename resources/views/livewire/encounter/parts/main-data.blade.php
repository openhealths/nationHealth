<div
    class="p-4 sm:p-8"
    id="patient-data-section"
    @if ($this->prepersonId && !$this->isDischarge && !$this->isHospitalizationRefusal)
        x-init="$wire.set('form.encounter.typeCode', 'patient_identity')"
    @endif
>
    <div
        class="form-row-2"
        x-data="{
            allowedTypes(classCode = $wire.form.encounter.classCode) {
                return $wire.encounterClassTypes[classCode] ?? [];
            },

            syncTypeWithClass(classCode) {
                const types = this.allowedTypes(classCode);

                if (types.length === 1) {
                    $wire.set('form.encounter.typeCode', types[0], false);
                } else if (! types.includes($wire.form.encounter.typeCode)) {
                    $wire.set('form.encounter.typeCode', '', false);
                }
            },
        }"
    >
        <div class="form-group group">
            <select
                wire:model="form.encounter.classCode"
                @change="syncTypeWithClass($event.target.value)"
                id="interactionClass"
                class="input-select peer @error('form.encounter.classCode') input-error @enderror"
                required
                @disabled($this->isDischarge || $this->isHospitalizationRefusal)
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['eHealth/encounter_classes'] as $key => $encounterClass)
                    <option value="{{ $key }}">{{ $encounterClass }}</option>
                @endforeach
            </select>
            <label for="interactionClass" class="label required"> {{ __('encounters.interaction_class') }} </label>

            @error('form.encounter.classCode')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <select
                wire:model="form.encounter.typeCode"
                id="interactionType"
                class="input-select peer @error('form.encounter.typeCode') input-error @enderror"
                required
                @disabled($this->isDischarge || $this->isHospitalizationRefusal)
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['eHealth/encounter_types'] as $key => $encounterType)
                    <option
                        value="{{ $key }}"
                        x-show="allowedTypes().includes('{{ $key }}')"
                        :disabled="! allowedTypes().includes('{{ $key }}')"
                    >
                        {{ $encounterType }}
                    </option>
                @endforeach
            </select>
            <label for="interactionType" class="label required"> {{ __('encounters.interaction_type') }} </label>

            @error('form.encounter.typeCode')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div
        class="form-row-2 mt-8"
        x-data="{
            isPerformerAllowed(index) {
                const classCode = $wire.form.encounter.classCode;
                const typeCode = $wire.form.encounter.typeCode;
                const employee = $wire.performerEmployees[index];

                return (
                    (! classCode || employee.encounterClasses.includes(classCode)) &&
                    (! typeCode || employee.encounterTypes.includes(typeCode))
                );
            },

            syncPerformerParticipant() {
                const performer = $wire.performerEmployees.find(
                    (employee) => employee.uuid === $wire.form.encounter.performerId,
                );

                syncLocalEncounterParticipants('performer', performer ? [performer] : []);
            },
        }"
        x-init="syncPerformerParticipant()"
        x-effect="
            const selectedIndex = $wire.performerEmployees.findIndex(
                (employee) => employee.uuid === $wire.form.encounter.performerId,
            );

            if (selectedIndex !== -1 && ! isPerformerAllowed(selectedIndex)) {
                $wire.set('form.encounter.performerId', '', false);
                syncPerformerParticipant();
            }
        "
    >
        <div class="form-group group">
            <select
                wire:model="form.encounter.performerId"
                @change="syncPerformerParticipant()"
                id="encounterPerformer"
                class="input-select peer @error('form.encounter.performerId') input-error @enderror"
                required
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($performerEmployees as $employee)
                    <option
                        value="{{ $employee['uuid'] }}"
                        :hidden="! isPerformerAllowed({{ $loop->index }})"
                        :disabled="! isPerformerAllowed({{ $loop->index }})"
                    >
                        {{ $employee['name'] }} ({{ $this->dictionaries['POSITION'][$employee['position']] ?? $employee['position'] }})
                    </option>
                @endforeach
            </select>
            <label for="encounterPerformer" class="label required"> {{ __('medical-events.performer') }} </label>

            @error('form.encounter.performerId')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Select episode type --}}
    <div
        x-data="{
            episodeType: $wire.entangle('episodeType'),
            episodeId: $wire.entangle('form.episode.id'),
            episodeTypeCode: $wire.entangle('form.episode.typeCode'),
            episodeName: $wire.entangle('form.episode.name'),
        }"
        class="mt-8"
    >
        <div class="mb-6 flex items-center space-x-6">
            <span class="text-sm font-bold text-gray-900 dark:text-white">{{ __('episodes.label') }}</span>
            <div class="flex items-center">
                <input
                    @change="
                        episodeType = 'existing';
                        episodeTypeCode = '';
                        episodeName = '';
                    "
                    id="existingEpisode"
                    type="radio"
                    value="existing"
                    name="episode"
                    class="default-radio"
                    :checked="episodeType === 'existing'"
                    @disabled($this->isHospitalizationRefusal)
                />
                <label for="existingEpisode" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('encounters.episode_existing') }}
                </label>
            </div>
            <div class="flex items-center">
                <input
                    @change="
                        episodeType = 'new';
                        episodeId = '';
                        episodeTypeCode = '';
                        episodeName = '';
                    "
                    id="newEpisode"
                    type="radio"
                    value="new"
                    name="episode"
                    class="default-radio"
                    :checked="episodeType === 'new'"
                />
                <label for="newEpisode" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('encounters.episode_new') }}
                </label>
            </div>
        </div>

        <div x-show="episodeType === 'new'" x-transition>
            <div class="form-row-2">
                <div class="form-group group">
                    <input
                        wire:model="form.episode.name"
                        type="text"
                        name="episodeName"
                        id="episodeName"
                        class="input peer @error('form.episode.name') input-error @enderror"
                        placeholder=" "
                        required
                        autocomplete="off"
                    />
                    <label for="episodeName" class="label required"> {{ __('encounters.episode_name') }} </label>

                    @error('form.episode.name')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group group">
                    <select
                        wire:model="form.episode.typeCode"
                        id="episodeType"
                        class="input-select peer @error('form.episode.typeCode') input-error @enderror"
                        required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($this->dictionaries['eHealth/episode_types'] as $key => $episodeType)
                            <option value="{{ $key }}">{{ $episodeType }}</option>
                        @endforeach
                    </select>
                    <label for="episodeType" class="label required"> {{ __('encounters.episode_type') }} </label>

                    @error('form.episode.typeCode')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Existing episode type --}}
        <template x-if="episodeType === 'existing'">
            <div class="form-row-2" x-transition>
                <div class="form-group group">
                    <select
                        wire:model.live="form.episode.id"
                        id="existingEpisodeId"
                        class="input-select peer @error('form.episode.id') input-error @enderror"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($episodes as $key => $episode)
                            <option value="{{ $episode['uuid'] }}">{{ $episode['name'] }}</option>
                        @endforeach
                    </select>
                    <label for="existingEpisodeId" class="label required">
                        {{ __('encounters.episode_existing') }}
                    </label>

                    @error('form.episode.id')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </template>
    </div>
</div>
