<fieldset class="fieldset" id="patient-data-section">
    <legend class="legend">
        {{ __('patients.main_data') }}
    </legend>

    <div x-data="{ isReferralAvailable: false }">
        <div class="form-row-3">
            <div class="form-group group">
                <input @click="isReferralAvailable = !isReferralAvailable"
                       type="checkbox"
                       name="isReferralAvailable"
                       id="isReferralAvailable"
                       class="default-checkbox mb-1"
                />
                <label class="default-p" for="isReferralAvailable">
                    {{ __('patients.referral_available') }}
                </label>
            </div>

            {{-- When referral is avaliable --}}
            <div x-show="isReferralAvailable" class="form-group group" x-cloak>
                <input wire:model="form.referralNumber"
                       type="text"
                       name="requisitionNumber"
                       id="requisitionNumber"
                       class="input peer @error('form.referralNumber') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                />
                <label for="requisitionNumber" class="label">
                    {{ __('patients.referral_number') }}
                </label>

                @error('form.referralNumber')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>

            <div x-show="isReferralAvailable" class="form-group group" x-cloak>
                <button wire:click.prevent="searchForReferralNumber"
                        class="flex items-center gap-2 button-primary"
                >
                    @icon('search', 'w-4 h-4')
                    <span>{{ __('patients.search_for_referral') }}</span>
                </button>
            </div>
        </div>
    </div>

    <div class="form-row-3">
        <div class="form-group group">
            <select wire:model="form.encounter.classCode"
                    id="interactionClass"
                    class="input-select peer @error('form.encounter.classCode') input-error @enderror"
                    required
            >
                <option value="" selected>{{ __('forms.select') }} {{ mb_strtolower(__('patients.interaction_class')) }}*
                </option>
                @foreach($this->dictionaries['eHealth/encounter_classes'] as $key => $encounterClass)
                    <option value="{{ $key }}">{{ $encounterClass }}</option>
                @endforeach
            </select>

            @error('form.encounter.classCode')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>

        <div class="form-group group">
            <select wire:model="form.encounter.typeCode"
                    id="interactionType"
                    class="input-select peer @error('form.encounter.typeCode') input-error @enderror"
                    required
            >
                <option value="" selected>{{ __('forms.select') }} {{ mb_strtolower(__('patients.interaction_type')) }}*
                </option>
                @foreach($this->dictionaries['eHealth/encounter_types'] as $key => $encounterType)
                    <option value="{{ $key }}">{{ $encounterType }}</option>
                @endforeach
            </select>

            @error('form.encounter.typeCode')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>
    </div>

    {{-- Select episode type --}}
    <div x-data="{
            episodeType: $wire.entangle('episodeType'),
            episodeId: $wire.entangle('form.episode.id'),
            episodeTypeCode: $wire.entangle('form.episode.typeCode'),
            episodeName: $wire.entangle('form.episode.name')
         }"
         class="mt-8"
    >
        <div class="form-row-3">
            <div class="flex items-center">
                <input @change="episodeType = 'existing'; episodeTypeCode = ''; episodeName = ''"
                       id="existingEpisode"
                       type="radio"
                       value="existing"
                       name="episode"
                       class="default-radio"
                       :checked="episodeType === 'existing'"
                >
                <label for="existingEpisode" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('patients.existing_episode') }}
                </label>
            </div>
            <div class="flex items-center">
                <input @change="episodeType = 'new'; episodeId = ''; episodeTypeCode = ''; episodeName = ''"
                       id="newEpisode"
                       type="radio"
                       value="new"
                       name="episode"
                       class="default-radio"
                       :checked="episodeType === 'new'"
                >
                <label for="newEpisode" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('patients.new_episode') }}
                </label>
            </div>
        </div>

        <div x-show="episodeType === 'new'" x-transition>
            <div class="form-row-3">
                <div class="form-group group">
                    <input wire:model="form.episode.name"
                           type="text"
                           name="episodeName"
                           id="episodeName"
                           class="input peer @error('form.episode.name') input-error @enderror"
                           placeholder=" "
                           required
                           autocomplete="off"
                    />
                    <label for="episodeName" class="label">
                        {{ __('patients.episode_name') }}
                    </label>

                    @error('form.episode.name')
                    <p class="text-error">
                        {{ $message }}
                    </p>
                    @enderror
                </div>

                <div class="form-group group">
                    <select wire:model="form.episode.typeCode"
                            id="episodeType"
                            class="input-select peer @error('form.episode.typeCode') input-error @enderror"
                            required
                    >
                        <option value="" selected>{{ __('forms.select') }} {{ mb_strtolower(__('patients.episode_type')) }}*
                        </option>
                        @foreach($this->dictionaries['eHealth/episode_types'] as $key => $episodeType)
                            <option value="{{ $key }}">{{ $episodeType }}</option>
                        @endforeach
                    </select>

                    @error('form.episode.typeCode')
                    <p class="text-error">
                        {{ $message }}
                    </p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Existing episode type --}}
        <template x-if="episodeType === 'existing'">
            <div class="form-row-3" x-transition>
                <div class="form-group group">
                    <select wire:model="form.episode.id"
                            id="existingEpisodeId"
                            class="input-select peer @error('form.episode.id') input-error @enderror"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($episodes as $key => $episode)
                            <option value="{{ $episode['uuid'] }}">{{ $episode['name'] }}</option>
                        @endforeach
                    </select>

                    @error('form.episode.id')
                    <p class="text-error">
                        {{ $message }}
                    </p>
                    @enderror
                </div>
            </div>
        </template>
    </div>
</fieldset>
