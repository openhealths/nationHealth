@php
    $class = $encounter->class;
    $type = $encounter->type?->coding->first();
    $cancellationReason = $encounter->cancellationReason?->coding->first();
    $hospitalization = $encounter->hospitalization;
    $paperReferral = $encounter->paperReferral;

    $title = collect([__('encounters.label'), $encounter->period?->start])
        ->filter()
        ->implode(' | ');
@endphp

<div>
    <section class="section-form p-6">
        <x-header-navigation class="breadcrumb-form" title="{{ $title }}">
            <x-slot name="title">{{ $title }}</x-slot>

            <x-slot name="actions">
                <button
                    wire:click.prevent="sync"
                    type="button"
                    class="button-sync flex items-center gap-2 px-4 py-2 text-sm shadow-sm"
                >
                    @icon('refresh', 'w-4 h-4')
                    <span>{{ __('forms.synchronise_with_eHealth') }}</span>
                </button>
            </x-slot>
        </x-header-navigation>

        <div class="form shift-content">
            <fieldset class="fieldset">
                <legend class="legend">{{ __('forms.main_information') }}</legend>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="status"
                            id="status"
                            class="input peer"
                            value="{{ $encounter->status->label() }}"
                            disabled
                        />
                        <label for="status" class="label">{{ __('forms.status.label') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="encounterId"
                            id="encounterId"
                            class="input peer"
                            value="{{ $encounter->uuid }}"
                            disabled
                        />
                        <label for="encounterId" class="label">{{ __('encounters.id') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="class"
                            id="class"
                            class="input peer"
                            value="{{ $dictionaries[$class?->system][$class?->code] ?? '-' }}"
                            disabled
                        />
                        <label for="class" class="label">{{ __('encounters.interaction_class') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="type"
                            id="type"
                            class="input peer"
                            value="{{ $dictionaries[$type?->system][$type?->code] ?? '-' }}"
                            disabled
                        />
                        <label for="type" class="label">{{ __('encounters.interaction_type') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="periodStart"
                            id="periodStart"
                            class="input peer"
                            value="{{ $encounter->period?->start ?? '-' }}"
                            disabled
                        />
                        <label for="periodStart" class="label">{{ __('encounters.period_start') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="periodEnd"
                            id="periodEnd"
                            class="input peer"
                            value="{{ $encounter->period?->end ?? '-' }}"
                            disabled
                        />
                        <label for="periodEnd" class="label">{{ __('encounters.period_end') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="performer"
                            id="performer"
                            class="input peer"
                            value="{{ $encounter->performer?->displayValue ?? $encounter->performer?->value ?? '-' }}"
                            disabled
                        />
                        <label for="performer" class="label">{{ __('medical-events.performer') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="division"
                            id="division"
                            class="input peer"
                            value="{{ $encounter->division?->displayValue ?? $encounter->division?->value ?? '-' }}"
                            disabled
                        />
                        <label for="division" class="label">{{ __('forms.division') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="episode"
                            id="episode"
                            class="input peer"
                            value="{{ $encounter->episode?->displayValue ?? $encounter->episode?->value ?? '-' }}"
                            disabled
                        />
                        <label for="episode" class="label">{{ __('episodes.label') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="visit"
                            id="visit"
                            class="input peer"
                            value="{{ $encounter->visit?->value ?? '-' }}"
                            disabled
                        />
                        <label for="visit" class="label">{{ __('encounters.visit') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="originEpisode"
                            id="originEpisode"
                            class="input peer"
                            value="{{ $encounter->originEpisode?->displayValue ?? $encounter->originEpisode?->value ?? '-' }}"
                            disabled
                        />
                        <label for="originEpisode" class="label">{{ __('encounters.origin_episode') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="incomingReferral"
                            id="incomingReferral"
                            class="input peer"
                            value="{{ $encounter->incomingReferral?->displayValue ?? $encounter->incomingReferral?->value ?? '-' }}"
                            disabled
                        />
                        <label for="incomingReferral" class="label">{{ __('encounters.incoming_referral') }}</label>
                    </div>
                </div>
            </fieldset>

            @if ($paperReferral)
                <fieldset class="fieldset mt-8">
                    <legend class="legend">{{ __('encounters.paper_referral') }}</legend>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="paperReferralRequisition"
                                id="paperReferralRequisition"
                                class="input peer"
                                value="{{ $paperReferral->requisition ?? '-' }}"
                                disabled
                            />
                            <label
                                for="paperReferralRequisition"
                                class="label"
                            >{{ __('encounters.referral_number') }}</label>
                        </div>
                        <div class="form-group group">
                            <input
                                type="text"
                                name="paperReferralDate"
                                id="paperReferralDate"
                                class="input peer"
                                value="{{ $paperReferral->serviceRequestDate ?? '-' }}"
                                disabled
                            />
                            <label
                                for="paperReferralDate"
                                class="label"
                            >{{ __('encounters.paper_referral_date') }}</label>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="paperReferralInstitution"
                                id="paperReferralInstitution"
                                class="input peer"
                                value="{{ $paperReferral->requesterLegalEntityName ?? '-' }}"
                                disabled
                            />
                            <label for="paperReferralInstitution" class="label">
                                {{ __('encounters.paper_referral_institution_short') }}
                            </label>
                        </div>
                        <div class="form-group group">
                            <input
                                type="text"
                                name="paperReferralEdrpou"
                                id="paperReferralEdrpou"
                                class="input peer"
                                value="{{ $paperReferral->requesterLegalEntityEdrpou ?? '-' }}"
                                disabled
                            />
                            <label
                                for="paperReferralEdrpou"
                                class="label"
                            >{{ __('encounters.paper_referral_edrpou_short') }}</label>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="paperReferralAuthor"
                                id="paperReferralAuthor"
                                class="input peer"
                                value="{{ $paperReferral->requesterEmployeeName ?? '-' }}"
                                disabled
                            />
                            <label
                                for="paperReferralAuthor"
                                class="label"
                            >{{ __('encounters.paper_referral_author') }}</label>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group group">
                            <label
                                for="paperReferralNote"
                                class="label-modal mb-1"
                            >{{ __('encounters.paper_referral_notes') }}</label>
                            <textarea
                                name="paperReferralNote"
                                id="paperReferralNote"
                                class="textarea"
                                disabled
                                rows="3"
                            >{{ $paperReferral->note }}</textarea>
                        </div>
                    </div>
                </fieldset>
            @endif

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('encounters.reasons_for_visit') }}</legend>

                @forelse ($encounter->reasons as $reason)
                    @php($reasonCoding = $reason->coding->first())
                    <div class="form-row-2" wire:key="reason-{{ $loop->index }}">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="reason{{ $loop->index }}"
                                id="reason{{ $loop->index }}"
                                class="input peer"
                                value="{{ $reasonCoding ? $reasonCoding->code . ' - ' . ($dictionaries[$reasonCoding->system][$reasonCoding->code] ?? '') : '-' }}"
                                disabled
                            />
                            <label
                                for="reason{{ $loop->index }}"
                                class="label"
                            >{{ __('encounters.reason_for_visit') }}</label>
                        </div>
                        <div class="form-group group">
                            <input
                                type="text"
                                name="reasonText{{ $loop->index }}"
                                id="reasonText{{ $loop->index }}"
                                class="input peer"
                                value="{{ $reason->text ?? '-' }}"
                                disabled
                            />
                            <label
                                for="reasonText{{ $loop->index }}"
                                class="label"
                            >{{ __('encounters.reason_text') }}</label>
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-gray-500 italic">{{ __('forms.no_data') }}</div>
                @endforelse
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('encounters.diagnoses') }}</legend>

                @forelse ($encounter->diagnoses as $diagnosis)
                    @php($role = $diagnosis->role?->coding->first())
                    <div class="form-row-3" wire:key="diagnosis-{{ $loop->index }}">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="diagnosisCondition{{ $loop->index }}"
                                id="diagnosisCondition{{ $loop->index }}"
                                class="input peer"
                                value="{{ $diagnosis->condition?->displayValue ?? $diagnosis->condition?->value ?? '-' }}"
                                disabled
                            />
                            <label
                                for="diagnosisCondition{{ $loop->index }}"
                                class="label"
                            >{{ __('encounters.diagnoses') }}</label>
                        </div>
                        <div class="form-group group">
                            <input
                                type="text"
                                name="diagnosisRole{{ $loop->index }}"
                                id="diagnosisRole{{ $loop->index }}"
                                class="input peer"
                                value="{{ $dictionaries[$role?->system][$role?->code] ?? '-' }}"
                                disabled
                            />
                            <label
                                for="diagnosisRole{{ $loop->index }}"
                                class="label"
                            >{{ __('conditions.role') }}</label>
                        </div>
                        <div class="form-group group">
                            <input
                                type="text"
                                name="diagnosisRank{{ $loop->index }}"
                                id="diagnosisRank{{ $loop->index }}"
                                class="input peer"
                                value="{{ $diagnosis->rank ?? '-' }}"
                                disabled
                            />
                            <label
                                for="diagnosisRank{{ $loop->index }}"
                                class="label"
                            >{{ __('conditions.rank') }}</label>
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-gray-500 italic">{{ __('forms.no_data') }}</div>
                @endforelse
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('encounters.actions') }}</legend>

                @forelse ($encounter->actions as $action)
                    @php($actionCoding = $action->coding->first())
                    <div class="form-row-2" wire:key="action-{{ $loop->index }}">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="action{{ $loop->index }}"
                                id="action{{ $loop->index }}"
                                class="input peer"
                                value="{{ $actionCoding ? $actionCoding->code . ' - ' . ($dictionaries[$actionCoding->system][$actionCoding->code] ?? '') : '-' }}"
                                disabled
                            />
                            <label for="action{{ $loop->index }}" class="label">{{ __('encounters.actions') }}</label>
                        </div>
                        <div class="form-group group">
                            <input
                                type="text"
                                name="actionText{{ $loop->index }}"
                                id="actionText{{ $loop->index }}"
                                class="input peer"
                                value="{{ $action->text ?? '-' }}"
                                disabled
                            />
                            <label
                                for="actionText{{ $loop->index }}"
                                class="label"
                            >{{ __('encounters.action_text') }}</label>
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-gray-500 italic">{{ __('forms.no_data') }}</div>
                @endforelse

                @foreach ($encounter->actionReferences as $actionReference)
                    <div class="form-row-2" wire:key="action-reference-{{ $loop->index }}">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="actionReference{{ $loop->index }}"
                                id="actionReference{{ $loop->index }}"
                                class="input peer"
                                value="{{ $actionReference->displayValue ?? $actionReference->value }}"
                                disabled
                            />
                            <label
                                for="actionReference{{ $loop->index }}"
                                class="label"
                            >{{ __('encounters.service') }}</label>
                        </div>
                    </div>
                @endforeach
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('encounters.assignments') }}</legend>

                <div class="form-row">
                    <div class="form-group group">
                        <label for="prescriptions" class="sr-only">{{ __('encounters.assignments') }}</label>
                        <textarea
                            name="prescriptions"
                            id="prescriptions"
                            class="textarea"
                            disabled
                            rows="3"
                        >{{ $encounter->prescriptions }}</textarea>
                    </div>
                </div>
            </fieldset>

            @if ($hospitalization)
                <fieldset class="fieldset mt-8">
                    <legend class="legend">{{ __('encounters.hospitalization.label') }}</legend>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="admitSource"
                                id="admitSource"
                                class="input peer"
                                value="{{ $dictionaries[$hospitalization->admitSource?->system][$hospitalization->admitSource?->code] ?? '-' }}"
                                disabled
                            />
                            <label
                                for="admitSource"
                                class="label"
                            >{{ __('encounters.hospitalization.admit_source') }}</label>
                        </div>
                        <div class="form-group group">
                            <input
                                type="text"
                                name="reAdmission"
                                id="reAdmission"
                                class="input peer"
                                value="{{ $dictionaries[$hospitalization->reAdmission?->system][$hospitalization->reAdmission?->code] ?? '-' }}"
                                disabled
                            />
                            <label
                                for="reAdmission"
                                class="label"
                            >{{ __('encounters.hospitalization.re_admission') }}</label>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="preAdmissionIdentifier"
                                id="preAdmissionIdentifier"
                                class="input peer"
                                value="{{ $hospitalization->pre_admission_identifier ?? '-' }}"
                                disabled
                            />
                            <label for="preAdmissionIdentifier" class="label">
                                {{ __('encounters.hospitalization.pre_admission_identifier') }}
                            </label>
                        </div>
                        <div class="form-group group">
                            <input
                                type="text"
                                name="destination"
                                id="destination"
                                class="input peer"
                                value="{{ $hospitalization->destination?->displayValue ?? $hospitalization->destination?->value ?? '-' }}"
                                disabled
                            />
                            <label
                                for="destination"
                                class="label"
                            >{{ __('encounters.hospitalization.destination') }}</label>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="dischargeDisposition"
                                id="dischargeDisposition"
                                class="input peer"
                                value="{{ $dictionaries[$hospitalization->dischargeDisposition?->system][$hospitalization->dischargeDisposition?->code] ?? '-' }}"
                                disabled
                            />
                            <label for="dischargeDisposition" class="label">
                                {{ __('encounters.hospitalization.discharge_disposition') }}
                            </label>
                        </div>
                        <div class="form-group group">
                            <input
                                type="text"
                                name="dischargeDepartment"
                                id="dischargeDepartment"
                                class="input peer"
                                value="{{ $dictionaries[$hospitalization->dischargeDepartment?->system][$hospitalization->dischargeDepartment?->code] ?? '-' }}"
                                disabled
                            />
                            <label for="dischargeDepartment" class="label">
                                {{ __('encounters.hospitalization.discharge_department') }}
                            </label>
                        </div>
                    </div>
                </fieldset>
            @endif

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('forms.additional_information') }}</legend>

                @foreach ($encounter->supportingInfo as $supportingInfo)
                    <div class="form-row-2" wire:key="supporting-info-{{ $loop->index }}">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="supportingInfo{{ $loop->index }}"
                                id="supportingInfo{{ $loop->index }}"
                                class="input peer"
                                value="{{ $supportingInfo->displayValue ?? $supportingInfo->value }}"
                                disabled
                            />
                            <label
                                for="supportingInfo{{ $loop->index }}"
                                class="label"
                            >{{ __('encounters.supporting_info') }}</label>
                        </div>
                    </div>
                @endforeach

                @foreach ($encounter->participants as $participant)
                    <div class="form-row-2" wire:key="participant-{{ $loop->index }}">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="participant{{ $loop->index }}"
                                id="participant{{ $loop->index }}"
                                class="input peer"
                                value="{{ $participant->displayValue ?? $participant->value }}"
                                disabled
                            />
                            <label
                                for="participant{{ $loop->index }}"
                                class="label"
                            >{{ __('encounters.coauthor') }}</label>
                        </div>
                    </div>
                @endforeach

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="ehealthInsertedAt"
                            id="ehealthInsertedAt"
                            class="input peer"
                            value="{{ $encounter->ehealth_inserted_at ?? '-' }}"
                            disabled
                        />
                        <label for="ehealthInsertedAt" class="label">{{ __('forms.inserted_at') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="ehealthUpdatedAt"
                            id="ehealthUpdatedAt"
                            class="input peer"
                            value="{{ $encounter->ehealth_updated_at ?? '-' }}"
                            disabled
                        />
                        <label for="ehealthUpdatedAt" class="label">{{ __('forms.updated_at') }}</label>
                    </div>
                </div>

                @if ($cancellationReason)
                    <div class="form-row-2">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="cancellationReason"
                                id="cancellationReason"
                                class="input peer"
                                value="{{ $dictionaries[$cancellationReason->system][$cancellationReason->code] ?? '-' }}"
                                disabled
                            />
                            <label
                                for="cancellationReason"
                                class="label"
                            >{{ __('medical-events.cancel_modal.reason_label') }}</label>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group group">
                            <label for="explanatoryLetter" class="label-modal mb-1">
                                {{ __('medical-events.cancel_modal.explanation_label') }}
                            </label>
                            <textarea
                                name="explanatoryLetter"
                                id="explanatoryLetter"
                                class="textarea"
                                disabled
                                rows="3"
                            >{{ $encounter->explanatory_letter }}</textarea>
                        </div>
                    </div>
                @endif
            </fieldset>

            <div class="mt-8">
                <a
                    href="{{ $personId ? route('persons.encounters', [legalEntity(), 'person' => $personId]) : route('prepersons.encounters', [legalEntity(), 'preperson' => $prepersonId]) }}"
                    class="button-minor px-6 py-2"
                >{{ __('forms.back') }}</a>
            </div>
        </div>
    </section>

    <livewire:components.x-message :key="now()->timestamp" />
</div>
