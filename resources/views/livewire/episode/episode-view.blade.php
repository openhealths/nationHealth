<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :title="__('episodes.label') . ' ' . $episode->name"
>
    <x-slot name="headerActions">
        <button
            wire:click.prevent="sync"
            type="button"
            class="button-sync flex items-center gap-2 px-5 py-2 text-sm whitespace-nowrap shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('forms.synchronise_with_eHealth') }}
        </button>
    </x-slot>

    <div class="shift-content mt-8 max-w-6xl pl-3.5">
        <fieldset class="fieldset">
            <div class="form-row-2">
                <div class="form-group group">
                    <input
                        value="{{ $episode->name }}"
                        type="text"
                        name="name"
                        id="name"
                        class="input peer"
                        autocomplete="off"
                        disabled
                    />
                    <label for="name" class="label">{{ __('episodes.name') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        value="{{ $episode->uuid ?? '-' }}"
                        type="text"
                        name="episodeId"
                        id="episodeId"
                        class="input peer"
                        disabled
                    />
                    <label for="episodeId" class="label">{{ __('episodes.ehealth_id') }}</label>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <input
                        value="{{ data_get($dictionaries, 'eHealth/episode_types.' . $episode->type?->code) ?? '-' }}"
                        type="text"
                        name="type"
                        id="type"
                        class="input peer"
                        disabled
                    />
                    <label for="type" class="label">{{ __('episodes.type') }}</label>
                </div>

                <div class="hidden md:block"></div>
            </div>

            <div class="form-row-2">
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group datepicker-wrapper relative w-full">
                        <input
                            value="{{ $episode->ehealthInsertedDate ?: '-' }}"
                            type="text"
                            name="ehealthInsertedDate"
                            id="ehealthInsertedDate"
                            class="peer input appearance-none pl-10 text-gray-500 dark:text-gray-400"
                            placeholder=" "
                            disabled
                        />
                        <label for="ehealthInsertedDate" class="wrapped-label">
                            {{ __('episodes.created_at_date') }}
                        </label>
                    </div>
                    <div class="form-group relative w-full">
                        @icon('clock', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                        <input
                            value="{{ $episode->ehealthInsertedTime ?: '-' }}"
                            type="text"
                            name="ehealthInsertedTime"
                            id="ehealthInsertedTime"
                            class="peer input appearance-none pl-10 text-gray-500 dark:text-gray-400"
                            placeholder=" "
                            disabled
                        />
                        <label for="ehealthInsertedTime" class="wrapped-label">
                            {{ __('episodes.created_at_time') }}
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group datepicker-wrapper relative w-full">
                        <input
                            value="{{ $episode->ehealthUpdatedDate ?: '-' }}"
                            type="text"
                            name="ehealthUpdatedDate"
                            id="ehealthUpdatedDate"
                            class="peer input appearance-none pl-10 text-gray-500 dark:text-gray-400"
                            placeholder=" "
                            disabled
                        />
                        <label for="ehealthUpdatedDate" class="wrapped-label">
                            {{ __('episodes.updated_at_date') }}
                        </label>
                    </div>
                    <div class="form-group relative w-full">
                        @icon('clock', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                        <input
                            value="{{ $episode->ehealthUpdatedTime ?: '-' }}"
                            type="text"
                            name="ehealthUpdatedTime"
                            id="ehealthUpdatedTime"
                            class="peer input appearance-none pl-10 text-gray-500 dark:text-gray-400"
                            placeholder=" "
                            disabled
                        />
                        <label for="ehealthUpdatedTime" class="wrapped-label">
                            {{ __('episodes.updated_at_time') }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <input
                        value="{{ $episode->status->label() }}"
                        type="text"
                        name="status"
                        id="status"
                        class="input peer"
                        disabled
                    />
                    <label for="status" class="label">{{ __('forms.status.label') }}</label>
                </div>
            </div>

            @php($statusReasonCoding = $episode->statusReason?->coding->first())

            <div class="form-row-2">
                <div class="form-group group">
                    <input
                        value="{{ data_get($dictionaries, $statusReasonCoding?->system . '.' . $statusReasonCoding?->code) ?? '-' }}"
                        type="text"
                        name="statusReason"
                        id="statusReason"
                        class="input peer"
                        disabled
                    />
                    <label for="statusReason" class="label">{{ __('episodes.closing_reason') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        value="{{ $episode->closingSummary ?? '-' }}"
                        type="text"
                        name="closingSummary"
                        id="closingSummary"
                        class="input peer"
                        disabled
                    />
                    <label for="closingSummary" class="label">{{ __('episodes.close_summary_label') }}</label>
                </div>
            </div>

            <div class="form-row-2 mt-4">
                <div class="form-group group">
                    <input
                        value="{{ $managingOrganizationName }}"
                        type="text"
                        name="managingOrganization"
                        id="managingOrganization"
                        class="input peer"
                        disabled
                    />
                    <label for="managingOrganization" class="label">{{ __('episodes.managing_org') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        value="{{ $careManagerName }}"
                        type="text"
                        name="careManager"
                        id="careManager"
                        class="input peer"
                        disabled
                    />
                    <label for="careManager" class="label">{{ __('episodes.care_manager') }}</label>
                </div>
            </div>

            <div class="mt-10 mb-6 text-xl font-bold text-gray-800 dark:text-gray-200">
                {{ __('episodes.period_title') }}
            </div>

            <div class="form-row-2">
                <div class="form-group datepicker-wrapper relative w-full">
                    <input
                        value="{{ convertToAppDateFormat($episode->period?->start) ?: '-' }}"
                        type="text"
                        name="periodStart"
                        id="periodStart"
                        class="peer input appearance-none pl-10 text-gray-500 dark:text-gray-400"
                        placeholder=" "
                        disabled
                    />
                    <label for="periodStart" class="wrapped-label">{{ __('episodes.period_start') }}</label>
                </div>

                <div class="form-group datepicker-wrapper relative w-full">
                    <input
                        value="{{ convertToAppDateFormat($episode->period?->end) ?: '-' }}"
                        type="text"
                        name="periodEnd"
                        id="periodEnd"
                        class="peer input appearance-none pl-10 text-gray-500 dark:text-gray-400"
                        placeholder=" "
                        disabled
                    />
                    <label for="periodEnd" class="wrapped-label">{{ __('episodes.period_end') }}</label>
                </div>
            </div>

            <div class="mt-10 mb-6 text-xl font-bold text-gray-800 dark:text-gray-200">
                {{ __('episodes.current_diagnosis_title') }}
            </div>

            @if ($currentMainDiagnosis)
                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            value="{{ $currentMainDiagnosis->condition?->value ?? '-' }}"
                            type="text"
                            name="currentConditionId"
                            id="currentConditionId"
                            class="input peer"
                            disabled
                        />
                        <label for="currentConditionId" class="label">{{ __('episodes.condition_ehealth_id') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            value="{{ $this->getDiagnosisDisplay($currentMainDiagnosis) }}"
                            type="text"
                            name="currentDiagnosisCode"
                            id="currentDiagnosisCode"
                            class="input peer"
                            disabled
                        />
                        <label for="currentDiagnosisCode" class="label">{{ __('episodes.diagnosis_code') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            value="{{ $currentMainDiagnosis->role ? (data_get($dictionaries, 'eHealth/diagnosis_roles.' . $currentMainDiagnosis->role->coding->first()?->code) ?? '-') : '-' }}"
                            type="text"
                            name="currentDiagnosisRole"
                            id="currentDiagnosisRole"
                            class="input peer"
                            disabled
                        />
                        <label for="currentDiagnosisRole" class="label">{{ __('episodes.diagnosis_role') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            value="{{ $currentMainDiagnosis->rank ?: '-' }}"
                            type="text"
                            name="currentDiagnosisRank"
                            id="currentDiagnosisRank"
                            class="input peer"
                            disabled
                        />
                        <label for="currentDiagnosisRank" class="label">{{ __('episodes.diagnosis_rank') }}</label>
                    </div>
                </div>
            @else
                <div class="py-2 text-gray-500 dark:text-gray-400">{{ __('episodes.no_current_diagnosis') }}</div>
            @endif

            <div class="mt-10 mb-6 text-xl font-bold text-gray-800 dark:text-gray-200">
                {{ __('episodes.diagnosis_history_title') }}
            </div>

            @forelse ($episode->diagnosesHistory as $history)
                @foreach ($history->diagnoses as $diagnose)
                    @php($suffix = $history->id . '-' . $diagnose->id)
                    <div class="mb-8 space-y-4 last:mb-0" wire:key="diagnosis-{{ $suffix }}">
                        <div class="form-row-2">
                            <div class="form-group datepicker-wrapper relative w-full">
                                <input
                                    value="{{ convertToAppDateFormat($history->date) ?: '-' }}"
                                    type="text"
                                    name="diagnosisDate[]"
                                    id="diagnosisDate-{{ $suffix }}"
                                    class="peer input appearance-none pl-10 text-gray-500 dark:text-gray-400"
                                    placeholder=" "
                                    disabled
                                />
                                <label for="diagnosisDate-{{ $suffix }}" class="wrapped-label">
                                    {{ __('episodes.diagnosis_date') }}
                                </label>
                            </div>
                            <div class="hidden md:block"></div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <input
                                    value="{{ $diagnose->condition?->value ?? '-' }}"
                                    type="text"
                                    name="conditionId[]"
                                    id="conditionId-{{ $suffix }}"
                                    class="input peer"
                                    disabled
                                />
                                <label for="conditionId-{{ $suffix }}" class="label">
                                    {{ __('episodes.condition_ehealth_id') }}
                                </label>
                            </div>

                            <div class="form-group group">
                                <input
                                    value="{{ $this->getDiagnosisDisplay($diagnose) }}"
                                    type="text"
                                    name="diagnosisCode[]"
                                    id="diagnosisCode-{{ $suffix }}"
                                    class="input peer"
                                    disabled
                                />
                                <label for="diagnosisCode-{{ $suffix }}" class="label">
                                    {{ __('episodes.diagnosis_code') }}
                                </label>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <input
                                    value="{{ $diagnose->role ? (data_get($dictionaries, 'eHealth/diagnosis_roles.' . $diagnose->role->coding->first()?->code) ?? '-') : '-' }}"
                                    type="text"
                                    name="diagnosisRole[]"
                                    id="diagnosisRole-{{ $suffix }}"
                                    class="input peer"
                                    disabled
                                />
                                <label for="diagnosisRole-{{ $suffix }}" class="label">
                                    {{ __('episodes.diagnosis_role') }}
                                </label>
                            </div>

                            <div class="form-group group">
                                <input
                                    value="{{ $diagnose->rank ?: '-' }}"
                                    type="text"
                                    name="diagnosisRank[]"
                                    id="diagnosisRank-{{ $suffix }}"
                                    class="input peer"
                                    disabled
                                />
                                <label for="diagnosisRank-{{ $suffix }}" class="label">
                                    {{ __('episodes.diagnosis_rank') }}
                                </label>
                            </div>
                        </div>
                    </div>
                @endforeach
            @empty
                <div class="py-2 text-gray-500 dark:text-gray-400">{{ __('episodes.diagnosis_history_empty') }}</div>
            @endforelse

            <div class="mt-8 flex gap-4 border-t border-gray-100 pt-8 dark:border-gray-700">
                <button type="button" @click="history.back()" class="button-minor cursor-pointer">
                    {{ __('forms.back') }}
                </button>
            </div>
        </fieldset>
    </div>

    <x-forms.loading />
</x-layouts.patient>
