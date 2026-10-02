@php
    $code = $observation->code?->coding->first();
    $category = $observation->categories->first()?->coding->first();
    $interpretation = $observation->interpretation?->coding->first();
    $method = $observation->method?->coding->first();
    $bodySite = $observation->bodySite?->coding->first();
    $reportOrigin = $observation->reportOrigin?->coding->first();
    $valueCoding = $observation->value?->valueCodeableConcept?->coding->first();

    $codeName = $dictionaries[$code?->system][$code?->code] ?? __('observations.label');
    $title = collect([$codeName, $observation->effectiveDate ?: $observation->effectivePeriodStartDate])
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
                <legend class="legend">{{ __('observations.result') }}</legend>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="value"
                            id="value"
                            class="input peer"
                            value="{{ $valueCoding ? $dictionaries[$valueCoding->system][$valueCoding->code] ?? '-' : $observation->value?->label ?? '-' }}"
                            disabled
                        />
                        <label for="value" class="label">{{ __('observations.result') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="interpretation"
                            id="interpretation"
                            class="input peer"
                            value="{{ $dictionaries[$interpretation?->system][$interpretation?->code] ?? '-' }}"
                            disabled
                        />
                        <label for="interpretation" class="label">{{ __('observations.interpretation') }}</label>
                    </div>
                </div>

                @if ($observation->referenceRanges->first()?->label)
                    <div class="form-row-2">
                        <div class="form-group group">
                            <input
                                type="text"
                                name="referenceRange"
                                id="referenceRange"
                                class="input peer"
                                value="{{ $observation->referenceRanges->first()->label }}"
                                disabled
                            />
                            <label for="referenceRange" class="label">{{ __('observations.reference_range') }}</label>
                        </div>
                    </div>
                @endif
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('observations.components') }}</legend>

                @forelse ($observation->components as $component)
                    @php
                        $componentCode = $component->code?->coding->first();
                        $componentInterpretation = $component->interpretation?->coding->first();
                        $componentValueCoding = $component->value?->valueCodeableConcept?->coding->first();
                    @endphp

                    <div wire:key="component-{{ $loop->index }}">
                        <h4 class="mb-6 font-semibold text-gray-700 dark:text-gray-200 {{ $loop->first ? '' : 'mt-10' }}">
                            {{ $dictionaries[$componentCode?->system][$componentCode?->code] ?? __('observations.component') }}
                        </h4>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <input
                                    type="text"
                                    name="componentValue{{ $loop->index }}"
                                    id="componentValue{{ $loop->index }}"
                                    class="input peer"
                                    value="{{ $componentValueCoding ? $dictionaries[$componentValueCoding->system][$componentValueCoding->code] ?? '-' : $component->value?->label ?? '-' }}"
                                    disabled
                                />
                                <label for="componentValue{{ $loop->index }}" class="label">
                                    {{ __('observations.result') }}
                                </label>
                            </div>
                            <div class="form-group group">
                                <input
                                    type="text"
                                    name="componentInterpretation{{ $loop->index }}"
                                    id="componentInterpretation{{ $loop->index }}"
                                    class="input peer"
                                    value="{{ $dictionaries[$componentInterpretation?->system][$componentInterpretation?->code] ?? '-' }}"
                                    disabled
                                />
                                <label for="componentInterpretation{{ $loop->index }}" class="label">
                                    {{ __('observations.interpretation') }}
                                </label>
                            </div>
                        </div>

                        @if ($component->referenceRanges->first()?->label)
                            <div class="form-row-2">
                                <div class="form-group group">
                                    <input
                                        type="text"
                                        name="componentReferenceRange{{ $loop->index }}"
                                        id="componentReferenceRange{{ $loop->index }}"
                                        class="input peer"
                                        value="{{ $component->referenceRanges->first()->label }}"
                                        disabled
                                    />
                                    <label for="componentReferenceRange{{ $loop->index }}" class="label">
                                        {{ __('observations.reference_range') }}
                                    </label>
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="text-sm text-gray-500 italic">{{ __('observations.no_components') }}</div>
                @endforelse
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('observations.general_info') }}</legend>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="category"
                            id="category"
                            class="input peer"
                            value="{{ $dictionaries[$category?->system][$category?->code] ?? '-' }}"
                            disabled
                        />
                        <label for="category" class="label">{{ __('observations.category') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="code"
                            id="code"
                            class="input peer"
                            value="{{ $code ? $code->code . ' - ' . $codeName : '-' }}"
                            disabled
                        />
                        <label for="code" class="label">{{ __('observations.code_and_name') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="status"
                            id="status"
                            class="input peer"
                            value="{{ $observation->status->label() }}"
                            disabled
                        />
                        <label for="status" class="label">{{ __('observations.status_label') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="method"
                            id="method"
                            class="input peer"
                            value="{{ $dictionaries[$method?->system][$method?->code] ?? '-' }}"
                            disabled
                        />
                        <label for="method" class="label">{{ __('observations.method_label') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="bodySite"
                            id="bodySite"
                            class="input peer"
                            value="{{ $dictionaries[$bodySite?->system][$bodySite?->code] ?? '-' }}"
                            disabled
                        />
                        <label for="bodySite" class="label">{{ __('observations.body_site_label') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="device"
                            id="device"
                            class="input peer"
                            value="{{ $deviceName ?? $observation->device?->value ?? '-' }}"
                            disabled
                        />
                        <label for="device" class="label">{{ __('observations.device') }}</label>
                    </div>
                </div>

                @if ($observation->effectivePeriod)
                    <div class="form-row-3">
                        <div class="form-group group">
                            <div class="datepicker-wrapper">
                                <input
                                    type="text"
                                    name="effectivePeriodStartDate"
                                    id="effectivePeriodStartDate"
                                    class="datepicker-input with-leading-icon input peer"
                                    value="{{ $observation->effectivePeriodStartDate ?: '-' }}"
                                    placeholder=" "
                                    disabled
                                />
                                <label for="effectivePeriodStartDate" class="wrapped-label">
                                    {{ __('observations.effective_period_start') }}
                                </label>
                            </div>
                        </div>
                        <div class="form-group group w-1/2!">
                            <div class="relative flex items-center">
                                @icon('mingcute-time-fill', 'svg-input left-2.5')
                                <input
                                    type="text"
                                    name="effectivePeriodStartTime"
                                    id="effectivePeriodStartTime"
                                    class="input peer pl-10!"
                                    value="{{ $observation->effectivePeriodStartTime ?: '-' }}"
                                    placeholder=" "
                                    disabled
                                />
                                <label for="effectivePeriodStartTime" class="sr-only">{{ __('forms.time') }}</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-row-3">
                        <div class="form-group group">
                            <div class="datepicker-wrapper">
                                <input
                                    type="text"
                                    name="effectivePeriodEndDate"
                                    id="effectivePeriodEndDate"
                                    class="datepicker-input with-leading-icon input peer"
                                    value="{{ $observation->effectivePeriodEndDate ?: '-' }}"
                                    placeholder=" "
                                    disabled
                                />
                                <label for="effectivePeriodEndDate" class="wrapped-label">
                                    {{ __('observations.effective_period_end') }}
                                </label>
                            </div>
                        </div>
                        <div class="form-group group w-1/2!">
                            <div class="relative flex items-center">
                                @icon('mingcute-time-fill', 'svg-input left-2.5')
                                <input
                                    type="text"
                                    name="effectivePeriodEndTime"
                                    id="effectivePeriodEndTime"
                                    class="input peer pl-10!"
                                    value="{{ $observation->effectivePeriodEndTime ?: '-' }}"
                                    placeholder=" "
                                    disabled
                                />
                                <label for="effectivePeriodEndTime" class="sr-only">{{ __('forms.time') }}</label>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="form-row-3">
                        <div class="form-group group">
                            <div class="datepicker-wrapper">
                                <input
                                    type="text"
                                    name="effectiveDate"
                                    id="effectiveDate"
                                    class="datepicker-input with-leading-icon input peer"
                                    value="{{ $observation->effectiveDate ?: '-' }}"
                                    placeholder=" "
                                    disabled
                                />
                                <label for="effectiveDate" class="wrapped-label">
                                    {{ __('observations.effective_date_label') }}
                                </label>
                            </div>
                        </div>
                        <div class="form-group group w-1/2!">
                            <div class="relative flex items-center">
                                @icon('mingcute-time-fill', 'svg-input left-2.5')
                                <input
                                    type="text"
                                    name="effectiveTime"
                                    id="effectiveTime"
                                    class="input peer pl-10!"
                                    value="{{ $observation->effectiveTime ?: '-' }}"
                                    placeholder=" "
                                    disabled
                                />
                                <label for="effectiveTime" class="sr-only">{{ __('forms.time') }}</label>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="form-row-3">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input
                                type="text"
                                name="issuedDate"
                                id="issuedDate"
                                class="datepicker-input with-leading-icon input peer"
                                value="{{ $observation->issuedDate ?: '-' }}"
                                placeholder=" "
                                disabled
                            />
                            <label for="issuedDate" class="wrapped-label">
                                {{ __('observations.result_received_at') }}
                            </label>
                        </div>
                    </div>
                    <div class="form-group group w-1/2!">
                        <div class="relative flex items-center">
                            @icon('mingcute-time-fill', 'svg-input left-2.5')
                            <input
                                type="text"
                                name="issuedTime"
                                id="issuedTime"
                                class="input peer pl-10!"
                                value="{{ $observation->issuedTime ?: '-' }}"
                                placeholder=" "
                                disabled
                            />
                            <label for="issuedTime" class="sr-only">{{ __('forms.time') }}</label>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group group">
                        <label for="comment" class="label-modal mb-1">{{ __('observations.comment') }}</label>
                        <textarea
                            name="comment"
                            id="comment"
                            class="textarea"
                            disabled
                            rows="3"
                        >{{ $observation->comment }}</textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group group">
                        <label for="explanatoryLetter" class="label-modal mb-1">
                            {{ __('observations.explanatory_letter') }}
                        </label>
                        <textarea
                            name="explanatoryLetter"
                            id="explanatoryLetter"
                            class="textarea"
                            disabled
                            rows="3"
                        >{{ $observation->explanatoryLetter }}</textarea>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="context"
                            id="context"
                            class="input peer"
                            value="{{ $observation->context?->value ?? '-' }}"
                            disabled
                        />
                        <label for="context" class="label">{{ __('observations.context') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="observationId"
                            id="observationId"
                            class="input peer"
                            value="{{ $observation->uuid }}"
                            disabled
                        />
                        <label for="observationId" class="label">{{ __('observations.id') }}</label>
                    </div>
                </div>
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('forms.additional_information') }}</legend>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="diagnosticReport"
                            id="diagnosticReport"
                            class="input peer"
                            value="{{ $observation->diagnosticReport?->value ?? '-' }}"
                            disabled
                        />
                        <label for="diagnosticReport" class="label">{{ __('observations.diagnostic_report') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            name="specimen"
                            id="specimen"
                            class="input peer"
                            value="{{ $observation->specimen?->value ?? '-' }}"
                            disabled
                        />
                        <label for="specimen" class="label">{{ __('observations.specimen') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="performer"
                            id="performer"
                            class="input peer"
                            value="{{ $observation->performer?->displayValue ?? $observation->performer?->value ?? '-' }}"
                            disabled
                        />
                        <label for="performer" class="label">{{ __('observations.performer') }}</label>
                    </div>
                </div>

                <div
                    class="form-row-2 mb-4"
                    x-data="{ isOtherSource: {{ $observation->primarySource ? 'false' : 'true' }} }"
                >
                    <div class="form-group group">
                        <div class="flex items-center gap-4 pt-2">
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                {{ __('devices.source_data') }}
                            </span>
                            <label for="isOtherSource" class="flex cursor-pointer items-center gap-2">
                                <input
                                    type="radio"
                                    name="isOtherSource"
                                    id="isOtherSource"
                                    :checked="isOtherSource"
                                    disabled
                                    class="default-radio"
                                />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('devices.other_source') }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-group group" x-show="isOtherSource">
                        <div class="relative flex-1">
                            <input
                                type="text"
                                name="reportOrigin"
                                id="reportOrigin"
                                class="input peer w-full"
                                value="{{ $dictionaries[$reportOrigin?->system][$reportOrigin?->code] ?? '-' }}"
                                disabled
                            />
                            <label for="reportOrigin" class="label">{{ __('devices.source_reference') }}</label>
                        </div>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input
                                type="text"
                                name="ehealthInsertedDate"
                                id="ehealthInsertedDate"
                                class="datepicker-input with-leading-icon input peer"
                                value="{{ $observation->ehealthInsertedDate ?: '-' }}"
                                placeholder=" "
                                disabled
                            />
                            <label for="ehealthInsertedDate" class="wrapped-label">
                                {{ __('observations.inserted_at_label') }}
                            </label>
                        </div>
                    </div>
                    <div class="form-group group w-1/2!">
                        <div class="relative flex items-center">
                            @icon('mingcute-time-fill', 'svg-input left-2.5')
                            <input
                                type="text"
                                name="ehealthInsertedTime"
                                id="ehealthInsertedTime"
                                class="input peer pl-10!"
                                value="{{ $observation->ehealthInsertedTime ?: '-' }}"
                                placeholder=" "
                                disabled
                            />
                            <label for="ehealthInsertedTime" class="sr-only">{{ __('forms.time') }}</label>
                        </div>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input
                                type="text"
                                name="ehealthUpdatedDate"
                                id="ehealthUpdatedDate"
                                class="datepicker-input with-leading-icon input peer"
                                value="{{ $observation->ehealthUpdatedDate ?: '-' }}"
                                placeholder=" "
                                disabled
                            />
                            <label for="ehealthUpdatedDate" class="wrapped-label">
                                {{ __('observations.updated_at_label') }}
                            </label>
                        </div>
                    </div>
                    <div class="form-group group w-1/2!">
                        <div class="relative flex items-center">
                            @icon('mingcute-time-fill', 'svg-input left-2.5')
                            <input
                                type="text"
                                name="ehealthUpdatedTime"
                                id="ehealthUpdatedTime"
                                class="input peer pl-10!"
                                value="{{ $observation->ehealthUpdatedTime ?: '-' }}"
                                placeholder=" "
                                disabled
                            />
                            <label for="ehealthUpdatedTime" class="sr-only">{{ __('forms.time') }}</label>
                        </div>
                    </div>
                </div>
            </fieldset>

            <div class="mt-8">
                <a
                    href="{{ $personId ? route('persons.observations', [legalEntity(), 'person' => $personId]) : route('prepersons.observations', [legalEntity(), 'preperson' => $prepersonId]) }}"
                    class="button-minor px-6 py-2"
                >{{ __('forms.back') }}</a>
            </div>
        </div>
    </section>

    <livewire:components.x-message :key="now()->timestamp" />
</div>
