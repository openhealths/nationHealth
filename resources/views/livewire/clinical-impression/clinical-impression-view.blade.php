@php
    $code = $clinicalImpression->code?->coding->first();
    $categoryName = $code ? $dictionaries['eHealth/clinical_impression_patient_categories'][$code->code] : null;

    $title = collect([$code?->code, $categoryName])->filter()->implode(' - ') ?: __('clinical-impressions.label');
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
                        <input type="text" name="code" id="code" class="input peer" value="{{ $title }}" disabled />
                        <label for="code" class="label">{{ __('clinical-impressions.code') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="clinicalImpressionId"
                            id="clinicalImpressionId"
                            class="input peer"
                            value="{{ $clinicalImpression->uuid }}"
                            disabled
                        />
                        <label for="clinicalImpressionId" class="label">{{ __('forms.ehealth_id') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="status"
                            id="status"
                            class="input peer"
                            value="{{ $clinicalImpression->status->label() }}"
                            disabled
                        />
                        <label for="status" class="label">{{ __('forms.status.label') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="assessor"
                            id="assessor"
                            class="input peer"
                            value="{{ $clinicalImpression->assessor?->displayValue ?? $clinicalImpression->assessor?->value ?? '-' }}"
                            disabled
                        />
                        <label for="assessor" class="label">{{ __('clinical-impressions.assessor') }}</label>
                    </div>
                </div>

                {{-- An impression is made either at a moment or over a period --}}
                @if ($clinicalImpression->effectiveDateTime)
                    <div class="form-row-2">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="effectiveDateTime"
                                id="effectiveDateTime"
                                class="input peer"
                                value="{{ $clinicalImpression->effectiveDate }} {{ $clinicalImpression->effectiveTime }}"
                                disabled
                            />
                            <label
                                for="effectiveDateTime"
                                class="label"
                            >{{ __('clinical-impressions.effective_date_time') }}</label>
                        </div>
                    </div>
                @else
                    <div class="form-row-2">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="effectivePeriodStart"
                                id="effectivePeriodStart"
                                class="input peer"
                                value="{{ $clinicalImpression->effectivePeriodStartDate ? $clinicalImpression->effectivePeriodStartDate . ' ' . $clinicalImpression->effectivePeriodStartTime : '-' }}"
                                disabled
                            />
                            <label
                                for="effectivePeriodStart"
                                class="label"
                            >{{ __('clinical-impressions.effective_period_start') }}</label>
                        </div>
                        <div class="form-group group">
                            <input
                                type="text"
                                name="effectivePeriodEnd"
                                id="effectivePeriodEnd"
                                class="input peer"
                                value="{{ $clinicalImpression->effectivePeriodEndDate ? $clinicalImpression->effectivePeriodEndDate . ' ' . $clinicalImpression->effectivePeriodEndTime : '-' }}"
                                disabled
                            />
                            <label
                                for="effectivePeriodEnd"
                                class="label"
                            >{{ __('clinical-impressions.effective_period_end') }}</label>
                        </div>
                    </div>
                @endif

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="encounter"
                            id="encounter"
                            class="input peer"
                            value="{{ $clinicalImpression->encounter?->value ?? '-' }}"
                            disabled
                        />
                        <label for="encounter" class="label">{{ __('encounters.label') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="previous"
                            id="previous"
                            class="input peer"
                            value="{{ $clinicalImpression->getRelation('previous')?->value ?? '-' }}"
                            disabled
                        />
                        <label for="previous" class="label">{{ __('clinical-impressions.previous') }}</label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group group">
                        <label
                            for="description"
                            class="label-modal mb-1"
                        >{{ __('clinical-impressions.description') }}</label>
                        <textarea
                            name="description"
                            id="description"
                            class="textarea"
                            rows="2"
                            disabled
                        >{{ $clinicalImpression->description }}</textarea>
                    </div>
                </div>
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('clinical-impressions.problems') }}</legend>

                @if ($problems === [])
                    <div class="text-sm text-gray-500 italic">{{ __('forms.no_data') }}</div>
                @else
                    <table class="table-input w-inherit">
                        <thead class="thead-input">
                            <tr>
                                <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                                <th scope="col" class="th-input">{{ __('medical-events.code_and_name') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($problems as $problem)
                                <tr wire:key="problem-{{ $loop->index }}">
                                    <td class="td-input">{{ $problem['ehealthInsertedAt'] ?: '-' }}</td>
                                    <td class="td-input">{{ $problem['name'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('clinical-impressions.findings') }}</legend>

                @if ($findings === [])
                    <div class="text-sm text-gray-500 italic">{{ __('forms.no_data') }}</div>
                @else
                    <table class="table-input w-inherit">
                        <thead class="thead-input">
                            <tr>
                                <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                                <th scope="col" class="th-input">{{ __('forms.type') }}</th>
                                <th scope="col" class="th-input">{{ __('medical-events.code_and_name') }}</th>
                                <th scope="col" class="th-input">{{ __('clinical-impressions.findings_basis') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($findings as $finding)
                                <tr wire:key="finding-{{ $loop->index }}">
                                    <td class="td-input">{{ $finding['ehealthInsertedAt'] ?: '-' }}</td>
                                    <td class="td-input">
                                        {{ $dictionaries['eHealth/resources'][$finding['type']] ?? '-' }}
                                    </td>
                                    <td class="td-input">{{ $finding['name'] }}</td>
                                    <td class="td-input">{{ $finding['basis'] ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('clinical-impressions.supporting_info') }}</legend>

                @if ($supportingInfo === [])
                    <div class="text-sm text-gray-500 italic">{{ __('forms.no_data') }}</div>
                @else
                    <table class="table-input w-inherit">
                        <thead class="thead-input">
                            <tr>
                                <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                                <th scope="col" class="th-input">{{ __('forms.type') }}</th>
                                <th scope="col" class="th-input">{{ __('medical-events.code_and_name') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($supportingInfo as $supporting)
                                <tr wire:key="supporting-info-{{ $loop->index }}">
                                    <td class="td-input">{{ $supporting['ehealthInsertedAt'] ?: '-' }}</td>
                                    <td class="td-input">
                                        {{ $dictionaries['eHealth/resources'][$supporting['type']] ?? '-' }}
                                    </td>
                                    <td class="td-input">{{ $supporting['name'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('forms.additional_information') }}</legend>

                <div class="form-row">
                    <div class="form-group group">
                        <label for="summary" class="label-modal mb-1">{{ __('clinical-impressions.summary') }}</label>
                        <textarea
                            name="summary"
                            id="summary"
                            class="textarea"
                            rows="3"
                            disabled
                        >{{ $clinicalImpression->summary }}</textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group group">
                        <label for="note" class="label-modal mb-1">{{ __('clinical-impressions.note') }}</label>
                        <textarea
                            name="note"
                            id="note"
                            class="textarea"
                            rows="3"
                            disabled
                        >{{ $clinicalImpression->note }}</textarea>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="ehealthInsertedAt"
                            id="ehealthInsertedAt"
                            class="input peer"
                            value="{{ $clinicalImpression->ehealthInsertedAt ?? '-' }}"
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
                            value="{{ $clinicalImpression->ehealthUpdatedAt ?? '-' }}"
                            disabled
                        />
                        <label for="ehealthUpdatedAt" class="label">{{ __('forms.updated_at') }}</label>
                    </div>
                </div>

                @if ($clinicalImpression->explanatoryLetter)
                    <div class="form-row">
                        <div class="form-group group">
                            <label for="explanatoryLetter" class="label-modal mb-1">
                                {{ __('medical-events.cancel_modal.explanation_label') }}
                            </label>
                            <textarea
                                name="explanatoryLetter"
                                id="explanatoryLetter"
                                class="textarea"
                                rows="3"
                                disabled
                            >{{ $clinicalImpression->explanatoryLetter }}</textarea>
                        </div>
                    </div>
                @endif
            </fieldset>

            <div class="mt-8">
                <a
                    href="{{ $personId ? route('persons.clinical-impressions', [legalEntity(), 'person' => $personId]) : route('prepersons.clinical-impressions', [legalEntity(), 'preperson' => $prepersonId]) }}"
                    class="button-minor px-6 py-2"
                >{{ __('forms.back') }}</a>
            </div>
        </div>
    </section>

    <livewire:components.x-message :key="now()->timestamp" />
</div>
