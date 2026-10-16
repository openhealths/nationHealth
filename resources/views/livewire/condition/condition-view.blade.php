@php
    $code = $condition->code?->coding->first();
    $severity = $condition->severity?->coding->first();
    $stage = $condition->stageSummary?->coding->first();
    $reportOrigin = $condition->reportOrigin?->coding->first();

    $title = collect([$code?->code, $condition->codeDisplay])
        ->filter()
        ->implode(' - ') ?: __('conditions.label');
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
                            name="code"
                            id="code"
                            class="input peer"
                            value="{{ $code ? $code->code . ' - ' . $condition->codeDisplay : '-' }}"
                            disabled
                        />
                        <label for="code" class="label">{{ __('medical-events.code_and_name') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="conditionId"
                            id="conditionId"
                            class="input peer"
                            value="{{ $condition->uuid }}"
                            disabled
                        />
                        <label for="conditionId" class="label">{{ __('conditions.id') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="clinicalStatus"
                            id="clinicalStatus"
                            class="input peer"
                            value="{{ $condition->clinicalStatus?->label() ?? '-' }}"
                            disabled
                        />
                        <label for="clinicalStatus" class="label">{{ __('conditions.clinical_status') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="verificationStatus"
                            id="verificationStatus"
                            class="input peer"
                            value="{{ $condition->verificationStatus?->label() ?? '-' }}"
                            disabled
                        />
                        <label for="verificationStatus" class="label">{{ __('conditions.verification_status') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="severity"
                            id="severity"
                            class="input peer"
                            value="{{ $dictionaries[$severity?->system][$severity?->code] ?? '-' }}"
                            disabled
                        />
                        <label for="severity" class="label">{{ __('conditions.severity') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="stage"
                            id="stage"
                            class="input peer"
                            value="{{ $dictionaries[$stage?->system][$stage?->code] ?? '-' }}"
                            disabled
                        />
                        <label for="stage" class="label">{{ __('conditions.stage') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="onsetDate"
                            id="onsetDate"
                            class="input peer"
                            value="{{ $condition->onsetDate ?? '-' }}"
                            disabled
                        />
                        <label for="onsetDate" class="label">{{ __('conditions.onset_date') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="assertedDate"
                            id="assertedDate"
                            class="input peer"
                            value="{{ $condition->assertedDate ?? '-' }}"
                            disabled
                        />
                        <label for="assertedDate" class="label">{{ __('conditions.entry_date') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="context"
                            id="context"
                            class="input peer"
                            value="{{ $condition->context?->value ?? '-' }}"
                            disabled
                        />
                        <label for="context" class="label">{{ __('encounters.label') }}</label>
                    </div>
                </div>

                @foreach ($condition->bodySites as $bodySite)
                    @php($bodySiteCoding = $bodySite->coding->first())
                    <div class="form-row-2" wire:key="body-site-{{ $loop->index }}">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="bodySite{{ $loop->index }}"
                                id="bodySite{{ $loop->index }}"
                                class="input peer"
                                value="{{ $dictionaries[$bodySiteCoding?->system][$bodySiteCoding?->code] ?? '-' }}"
                                disabled
                            />
                            <label
                                for="bodySite{{ $loop->index }}"
                                class="label"
                            >{{ __('conditions.body_sites') }}</label>
                        </div>
                    </div>
                @endforeach
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('conditions.evidence') }}</legend>

                @forelse ($condition->evidencesRelation as $evidence)
                    @php($evidenceCoding = $evidence->codes?->coding->first())
                    <div class="form-row-2" wire:key="evidence-{{ $loop->index }}">
                        @if ($evidenceCoding)
                            <div class="form-group group">
                                <input
                                    type="text"
                                    name="evidenceCode{{ $loop->index }}"
                                    id="evidenceCode{{ $loop->index }}"
                                    class="input peer"
                                    value="{{ $evidenceCoding->code . ' - ' . ($dictionaries[$evidenceCoding->system][$evidenceCoding->code] ?? '') }}"
                                    disabled
                                />
                                <label
                                    for="evidenceCode{{ $loop->index }}"
                                    class="label"
                                >{{ __('conditions.code') }}</label>
                            </div>
                        @endif
                        @if ($evidence->details)
                            <div class="form-group group">
                                <input
                                    type="text"
                                    name="evidenceDetail{{ $loop->index }}"
                                    id="evidenceDetail{{ $loop->index }}"
                                    class="input peer"
                                    value="{{ $evidence->details->displayValue ?? $evidence->details->value }}"
                                    disabled
                                />
                                <label
                                    for="evidenceDetail{{ $loop->index }}"
                                    class="label"
                                >{{ __('conditions.evidence_observations') }}</label>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="text-sm text-gray-500 italic">{{ __('forms.no_data') }}</div>
                @endforelse
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('forms.additional_information') }}</legend>

                <div
                    class="form-row-2 mb-4"
                    x-data="{ isOtherSource: {{ $condition->primarySource ? 'false' : 'true' }} }"
                >
                    <div class="form-group group">
                        <div class="flex items-center gap-4 pt-2">
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                {{ __('conditions.primary_source') }}
                            </span>
                            <label for="primarySource" class="flex cursor-pointer items-center gap-2">
                                <input
                                    type="checkbox"
                                    name="primarySource"
                                    id="primarySource"
                                    :checked="! isOtherSource"
                                    disabled
                                    class="default-checkbox"
                                />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('forms.yes') }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-group group" x-show="! isOtherSource">
                        <input
                            type="text"
                            name="asserter"
                            id="asserter"
                            class="input peer"
                            value="{{ $condition->asserter?->displayValue ?? $condition->asserter?->value ?? '-' }}"
                            disabled
                        />
                        <label for="asserter" class="label">{{ __('conditions.doctor') }}</label>
                    </div>
                    <div class="form-group group" x-show="isOtherSource">
                        <input
                            type="text"
                            name="reportOrigin"
                            id="reportOrigin"
                            class="input peer"
                            value="{{ $dictionaries[$reportOrigin?->system][$reportOrigin?->code] ?? '-' }}"
                            disabled
                        />
                        <label for="reportOrigin" class="label">{{ __('medical-events.information_source') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="ehealthInsertedAt"
                            id="ehealthInsertedAt"
                            class="input peer"
                            value="{{ $condition->ehealthInsertedAt ?? '-' }}"
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
                            value="{{ $condition->ehealthUpdatedAt ?? '-' }}"
                            disabled
                        />
                        <label for="ehealthUpdatedAt" class="label">{{ __('forms.updated_at') }}</label>
                    </div>
                </div>

                @if ($condition->explanatoryLetter)
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
                            >{{ $condition->explanatoryLetter }}</textarea>
                        </div>
                    </div>
                @endif
            </fieldset>

            <div class="mt-8">
                <a
                    href="{{ $personId ? route('persons.conditions', [legalEntity(), 'person' => $personId]) : route('prepersons.conditions', [legalEntity(), 'preperson' => $prepersonId]) }}"
                    class="button-minor px-6 py-2"
                >{{ __('forms.back') }}</a>
            </div>
        </div>
    </section>

    <livewire:components.x-message :key="now()->timestamp" />
</div>
