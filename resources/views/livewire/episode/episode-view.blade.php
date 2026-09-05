<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :title="__('episodes.label') . ' ' . $episode->name"
>
    <x-slot name="headerActions"></x-slot>

    <div class="shift-content pl-3.5 mt-8 max-w-6xl">
        <fieldset class="fieldset">
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="{{ $episode->name }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('episodes.name') }}</label>
                </div>

                <div class="form-group group">
                    <input value="{{ $episode->uuid ?? '-' }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('episodes.ehealth_id') }}</label>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <input
                        value="{{ data_get($dictionaries, 'eHealth/episode_types.' . $episode->type?->code) ?? '-' }}"
                        type="text"
                        class="input peer"
                        disabled
                    />
                    <label class="label">{{ __('forms.type') }}</label>
                </div>

                <div class="hidden md:block"></div>
            </div>

            <div class="form-row-2">
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group datepicker-wrapper relative w-full">
                        <input
                            value="{{ $episode->ehealthInsertedDate ?: '-' }}"
                            type="text"
                            class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                            placeholder=" "
                            disabled
                        />
                        <label class="wrapped-label">{{ __('forms.created_at') }}</label>
                    </div>
                    <div class="form-group relative w-full">
                        @icon('clock', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                        <input
                            value="{{ $episode->ehealthInsertedTime ?: '-' }}"
                            type="text"
                            class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                            placeholder=" "
                            disabled
                        />
                        <label class="wrapped-label">{{ __('episodes.created_at_time') }}</label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group datepicker-wrapper relative w-full">
                        <input
                            value="{{ $episode->ehealthUpdatedDate ?: '-' }}"
                            type="text"
                            class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                            placeholder=" "
                            disabled
                        />
                        <label class="wrapped-label">{{ __('episodes.updated_at_date') }}</label>
                    </div>
                    <div class="form-group relative w-full">
                        @icon('clock', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                        <input
                            value="{{ $episode->ehealthUpdatedTime ?: '-' }}"
                            type="text"
                            class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                            placeholder=" "
                            disabled
                        />
                        <label class="wrapped-label">{{ __('episodes.updated_at_time') }}</label>
                    </div>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <input value="{{ $episode->status->label() }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('forms.status.label') }}</label>
                </div>
            </div>

            @php($statusReasonCoding = $episode->statusReason?->coding->first())

            <div class="form-row-2">
                <div class="form-group group">
                    <input
                        value="{{ data_get($dictionaries, $statusReasonCoding?->system . '.' . $statusReasonCoding?->code) ?? '-' }}"
                        type="text"
                        class="input peer"
                        disabled
                    />
                    <label class="label">{{ __('episodes.closing_reason') }}</label>
                </div>

                <div class="form-group group">
                    <input value="{{ $episode->closingSummary ?? '-' }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('episodes.close_summary_label') }}</label>
                </div>
            </div>

            <div class="form-row-2 mt-4">
                <div class="form-group group">
                    <input value="{{ $managingOrganizationName }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('episodes.managing_org') }}</label>
                </div>

                <div class="form-group group">
                    <input value="{{ $careManagerName }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('episodes.care_manager') }}</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">
                {{ __('episodes.period_title') }}
            </div>

            <div class="form-row-2">
                <div class="form-group datepicker-wrapper relative w-full">
                    <input
                        value="{{ convertToAppDateFormat($episode->period?->start) ?: '-' }}"
                        type="text"
                        class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                        placeholder=" "
                        disabled
                    />
                    <label class="wrapped-label">{{ __('episodes.period_start') }}</label>
                </div>

                <div class="form-group datepicker-wrapper relative w-full">
                    <input
                        value="{{ convertToAppDateFormat($episode->period?->end) ?: '-' }}"
                        type="text"
                        class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                        placeholder=" "
                        disabled
                    />
                    <label class="wrapped-label">{{ __('episodes.period_end') }}</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">
                {{ __('episodes.current_diagnosis_title') }}
            </div>

            @if($currentMainDiagnosis)
                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            value="{{ $currentMainDiagnosis->condition?->value ?? '-' }}"
                            type="text"
                            class="input peer"
                            disabled
                        />
                        <label class="label">{{ __('episodes.condition_ehealth_id') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            value="{{ $this->getDiagnosisDisplay($currentMainDiagnosis) }}"
                            type="text"
                            class="input peer"
                            disabled
                        />
                        <label class="label">{{ __('episodes.diagnosis_code') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            value="{{ $currentMainDiagnosis->role ? (data_get($dictionaries, 'eHealth/diagnosis_roles.' . $currentMainDiagnosis->role->coding->first()?->code) ?? '-') : '-' }}"
                            type="text"
                            class="input peer"
                            disabled
                        />
                        <label class="label">{{ __('episodes.diagnosis_role') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            value="{{ $currentMainDiagnosis->rank ?: '-' }}"
                            type="text"
                            class="input peer"
                            disabled
                        />
                        <label class="label">{{ __('episodes.diagnosis_rank') }}</label>
                    </div>
                </div>
            @else
                <div class="text-gray-500 dark:text-gray-400 py-2">
                    {{ __('episodes.no_current_diagnosis') }}
                </div>
            @endif

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">
                {{ __('episodes.diagnosis_history_title') }}
            </div>

            @forelse($episode->diagnosesHistory as $history)
                @foreach($history->diagnoses as $diagnose)
                    <div class="mb-8 last:mb-0 space-y-4" wire:key="diagnosis-{{ $history->id }}-{{ $diagnose->id }}">
                        <div class="form-row-2">
                            <div class="form-group datepicker-wrapper relative w-full">
                                <input
                                    value="{{ convertToAppDateFormat($history->date) ?: '-' }}"
                                    type="text"
                                    class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400"
                                    placeholder=" "
                                    disabled
                                />
                                <label class="wrapped-label">{{ __('episodes.diagnosis_date') }}</label>
                            </div>
                            <div class="hidden md:block"></div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <input
                                    value="{{ $diagnose->condition?->value ?? '-' }}"
                                    type="text"
                                    class="input peer"
                                    disabled
                                />
                                <label class="label">{{ __('episodes.condition_ehealth_id') }}</label>
                            </div>

                            <div class="form-group group">
                                <input
                                    value="{{ $this->getDiagnosisDisplay($diagnose) }}"
                                    type="text"
                                    class="input peer"
                                    disabled
                                />
                                <label class="label">{{ __('episodes.diagnosis_code') }}</label>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <input
                                    value="{{ $diagnose->role ? (data_get($dictionaries, 'eHealth/diagnosis_roles.' . $diagnose->role->coding->first()?->code) ?? '-') : '-' }}"
                                    type="text"
                                    class="input peer"
                                    disabled
                                />
                                <label class="label">{{ __('episodes.diagnosis_role') }}</label>
                            </div>

                            <div class="form-group group">
                                <input
                                    value="{{ $diagnose->rank ?: '-' }}"
                                    type="text"
                                    class="input peer"
                                    disabled
                                />
                                <label class="label">{{ __('episodes.diagnosis_rank') }}</label>
                            </div>
                        </div>
                    </div>
                @endforeach
            @empty
                <div class="text-gray-500 dark:text-gray-400 py-2">
                    {{ __('episodes.diagnosis_history_empty') }}
                </div>
            @endforelse

            <div class="flex gap-4 pt-8 mt-8 border-t border-gray-100 dark:border-gray-700">
                <button type="button" @click="history.back()" class="button-minor cursor-pointer">
                    {{ __('forms.back') }}
                </button>
            </div>
        </fieldset>
    </div>
</x-layouts.patient>
