@php
    $routePrefix = $prepersonId !== null ? 'prepersons' : 'persons';
    $routeParamKey = $prepersonId !== null ? 'preperson' : 'person';
    $recordId = $prepersonId ?? $personId;
@endphp

<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :title="__('patients.episode') . ' ' . ($episode?->name ?? '')"
>
    <x-slot name="headerActions"></x-slot>

    <div class="shift-content pl-3.5 mt-8 max-w-6xl">
        @if($episode)
        <fieldset class="fieldset">
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="{{ $episode->name }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('patients.episode_name') }}</label>
                </div>

                <div class="form-group group">
                    <input value="{{ $episode->uuid ?? '-' }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('patients.messages.episode_ehealth_id') }}</label>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <input value="{{ data_get($dictionaries, 'eHealth/episode_types.' . $episode->type?->code, $episode->type?->text ?? '-') }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('forms.type') }}</label>
                </div>

                <div class="hidden md:block"></div>
            </div>

            <div class="form-row-2">
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group datepicker-wrapper relative w-full">
                        <input value="{{ $episode->ehealthInsertedAt ? explode(' ', $episode->ehealthInsertedAt)[0] : '-' }}" type="text" class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400" placeholder=" " disabled />
                        <label class="wrapped-label">{{ __('forms.created_at') }}</label>
                    </div>
                    <div class="form-group relative w-full">
                        @icon('clock', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                        <input value="{{ $episode->ehealthInsertedAt ? (explode(' ', $episode->ehealthInsertedAt)[1] ?? '-') : '-' }}" type="text" class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400" placeholder=" " disabled />
                        <label class="wrapped-label">{{ __('patients.messages.episode_created_at_time') }}</label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group datepicker-wrapper relative w-full">
                        <input value="{{ $episode->ehealthUpdatedAt ? explode(' ', $episode->ehealthUpdatedAt)[0] : '-' }}" type="text" class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400" placeholder=" " disabled />
                        <label class="wrapped-label">{{ __('patients.messages.episode_updated_at_date') }}</label>
                    </div>
                    <div class="form-group relative w-full">
                        @icon('clock', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                        <input value="{{ $episode->ehealthUpdatedAt ? (explode(' ', $episode->ehealthUpdatedAt)[1] ?? '-') : '-' }}" type="text" class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400" placeholder=" " disabled />
                        <label class="wrapped-label">{{ __('patients.messages.episode_updated_at_time') }}</label>
                    </div>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <input value="{{ $statusLabel }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('forms.status.label') }}</label>
                </div>

                <div class="form-group group">
                    <input value="{{ $episode->explanatoryLetter ?? '-' }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('patients.messages.episode_status_reason') }}</label>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <input value="{{ $episode->statusReason ? (data_get($dictionaries, 'eHealth/episode_closing_reasons.' . $episode->statusReason->coding->first()?->code) ?? $episode->statusReason->text ?? '-') : '-' }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('patients.messages.episode_closing_reason') }}</label>
                </div>

                <div class="form-group group">
                    <input value="{{ $episode->closingSummary ?? '-' }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('patients.messages.episode_close_summary_label') }}</label>
                </div>
            </div>

            <div class="form-row-2 mt-4">
                <div class="form-group group">
                    <input value="{{ $managingOrganizationName }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('patients.messages.episode_managing_org') }}</label>
                </div>

                <div class="form-group group">
                    <input value="{{ $careManagerName }}" type="text" class="input peer" disabled />
                    <label class="label">{{ __('patients.messages.episode_care_manager') }}</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">
                {{ __('patients.messages.episode_period_title') }}
            </div>

            <div class="form-row-2">
                <div class="form-group datepicker-wrapper relative w-full">
                    <input value="{{ $episode->period?->start ? \Carbon\Carbon::parse($episode->period->start)->format('d.m.Y') : '-' }}" type="text" class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400" placeholder=" " disabled />
                    <label class="wrapped-label">{{ __('patients.messages.episode_period_start') }}</label>
                </div>

                <div class="form-group datepicker-wrapper relative w-full">
                    <input value="{{ $episode->period?->end ? \Carbon\Carbon::parse($episode->period->end)->format('d.m.Y') : '-' }}" type="text" class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400" placeholder=" " disabled />
                    <label class="wrapped-label">{{ __('patients.messages.episode_period_end') }}</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">
                {{ __('patients.messages.episode_current_diagnosis_title') }}
            </div>

            @if($currentMainDiagnosis)
                <div class="form-row-2">
                    <div class="form-group group">
                        <input value="{{ $currentMainDiagnosis->condition?->value ?? '-' }}" type="text" class="input peer" disabled />
                        <label class="label">{{ __('patients.messages.episode_condition_ehealth_id') }}</label>
                    </div>

                    <div class="form-group group">
                        <input value="{{ $this->getDiagnosisDisplay($currentMainDiagnosis) }}" type="text" class="input peer" disabled />
                        <label class="label">{{ __('patients.messages.episode_diagnosis_code') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input value="{{ $currentMainDiagnosis->role ? (data_get($dictionaries, 'eHealth/diagnosis_roles.' . $currentMainDiagnosis->role->coding->first()?->code) ?? $currentMainDiagnosis->role->text ?? '-') : '-' }}" type="text" class="input peer" disabled />
                        <label class="label">{{ __('patients.messages.episode_diagnosis_role') }}</label>
                    </div>

                    <div class="form-group group">
                        <input value="{{ $currentMainDiagnosis->rank ?? '-' }}" type="text" class="input peer" disabled />
                        <label class="label">{{ __('patients.messages.episode_diagnosis_rank') }}</label>
                    </div>
                </div>
            @else
                <div class="text-gray-500 dark:text-gray-400 py-2">
                    {{ __('patients.messages.episode_no_current_diagnosis') }}
                </div>
            @endif

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">
                {{ __('patients.messages.episode_diagnosis_history_title') }}
            </div>

            @forelse($episode->diagnosesHistory as $history)
                @foreach($history->diagnoses as $diag)
                    <div class="mb-8 last:mb-0 space-y-4">
                        <div class="form-row-2">
                            <div class="form-group datepicker-wrapper relative w-full">
                                <input value="{{ $history->date ? \Carbon\Carbon::parse($history->date)->format('d.m.Y') : '-' }}" type="text" class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400" placeholder=" " disabled />
                                <label class="wrapped-label">{{ __('patients.messages.episode_diagnosis_date') }}</label>
                            </div>
                            <div class="hidden md:block"></div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <input value="{{ $diag->condition?->value ?? '-' }}" type="text" class="input peer" disabled />
                                <label class="label">{{ __('patients.messages.episode_condition_ehealth_id') }}</label>
                            </div>

                            <div class="form-group group">
                                <input value="{{ $this->getDiagnosisDisplay($diag) }}" type="text" class="input peer" disabled />
                                <label class="label">{{ __('patients.messages.episode_diagnosis_code') }}</label>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <input value="{{ $diag->role ? (data_get($dictionaries, 'eHealth/diagnosis_roles.' . $diag->role->coding->first()?->code) ?? $diag->role->text ?? '-') : '-' }}" type="text" class="input peer" disabled />
                                <label class="label">{{ __('patients.messages.episode_diagnosis_role') }}</label>
                            </div>

                            <div class="form-group group">
                                <input value="{{ $diag->rank ?? '-' }}" type="text" class="input peer" disabled />
                                <label class="label">{{ __('patients.messages.episode_diagnosis_rank') }}</label>
                            </div>
                        </div>
                    </div>
                @endforeach
            @empty
                <div class="text-gray-500 dark:text-gray-400 py-2">
                    {{ __('patients.messages.episode_diagnosis_history_empty') }}
                </div>
            @endforelse

            <div class="flex gap-4 pt-8 mt-8 border-t border-gray-100 dark:border-gray-700">
                <button type="button" wire:click="back" class="button-minor">
                    {{ __('forms.back') }}
                </button>
            </div>
        </fieldset>
        @else
            <div class="text-gray-500 dark:text-gray-400 py-8 text-center">
                {{ __('patients.messages.episode_not_found') }}
            </div>
        @endif
    </div>
</x-layouts.patient>
