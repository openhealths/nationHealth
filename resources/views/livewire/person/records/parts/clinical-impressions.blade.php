@use('App\Enums\Person\ClinicalImpressionStatus')

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->clinicalImpressions) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->clinicalImpressions as $index => $clinicalImpression)
        <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('forms.code') }}</div>
                    <div class="record-inner-value text-[16px] font-semibold dark:text-gray-100">
                        {{ data_get($this->dictionaries, 'eHealth/clinical_impression_patient_categories.' . data_get($clinicalImpression, 'code.coding.0.code'), data_get($clinicalImpression, 'code.coding.0.code', '-')) }}
                    </div>
                </div>

                <div class="record-inner-column-bordered flex h-full w-full shrink-0 flex-col justify-center gap-1 md:w-36">
                    <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                    <div>
                        @php($status = ClinicalImpressionStatus::from(data_get($clinicalImpression, 'status')))
                        <span @class([$status->color()])> {{ $status->label() }} </span>
                    </div>
                </div>

                <div class="record-inner-action-col">
                    <div class="relative flex justify-center">
                        <div
                            x-data="{
                                open: false,
                                toggle() {
                                    if (this.open) {
                                        return this.close();
                                    }
                                    this.$refs.button.focus();
                                    this.open = true;
                                },
                                close(focusAfter) {
                                    if (! this.open) return;
                                    this.open = false;
                                    focusAfter && focusAfter.focus();
                                },
                            }"
                            @keydown.escape.prevent.stop="close($refs.button)"
                            @focusin.window="! $refs.panel.contains($event.target) && close()"
                            x-id="['dropdown-button']"
                            class="relative"
                        >
                            <button
                                @click="toggle()"
                                x-ref="button"
                                :aria-expanded="open"
                                :aria-controls="$id('dropdown-button')"
                                type="button"
                                class="record-inner-action-btn cursor-pointer"
                            >
                                @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                            </button>

                            <div
                                x-show="open"
                                x-cloak
                                x-ref="panel"
                                x-transition.origin.top.right
                                @click.outside="close($refs.button)"
                                :id="$id('dropdown-button')"
                                class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-600 dark:bg-gray-700"
                            >
                                <button
                                    @click="close($refs.button)"
                                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                >
                                    @icon('eye', 'w-5 h-5 text-gray-500')
                                    {{ __('patients.view_details') }}
                                </button>

                                <button
                                    @click="close($refs.button)"
                                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                >
                                    @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                    {{ __('clinical-impressions.status.entered_in_error') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="[&>div]:min-w-0 [&_.record-inner-value]:wrap-break-word grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-5">
                        <div>
                            <div class="record-inner-label">{{ __('patients.created') }}</div>
                            <div class="record-inner-value">
                                {{ data_get($clinicalImpression, 'ehealthInsertedAt', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('forms.start') }}</div>
                            <div class="record-inner-value">
                                {{ data_get($clinicalImpression, 'effectivePeriod.start', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('forms.end') }}</div>
                            <div class="record-inner-value">
                                {{ data_get($clinicalImpression, 'effectivePeriod.end', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                            <div class="record-inner-value">
                                {{ data_get($clinicalImpression, 'assessor.displayValue', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('clinical-impressions.conclusion') }}</div>
                            <div class="record-inner-value">{{ data_get($clinicalImpression, 'summary', '-') }}</div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">ID ECO3</div>
                        <div class="record-inner-id-value">{{ data_get($clinicalImpression, 'uuid', '-') }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('episodes.id') }}</div>
                        <div class="record-inner-id-value">
                            @php
                                $episodeValue = '';
                                foreach (data_get($clinicalImpression, 'supportingInfo', []) as $info) {
                                    $typeCode = data_get($info, 'identifier.type.coding.0.code') ?? data_get($info, 'identifier.type.0.coding.0.code');
                                    if ($typeCode === 'episode_of_care') {
                                        $episodeValue = data_get($info, 'identifier.value', '');
                                        break;
                                    }
                                }
                            @endphp
                            {{ $episodeValue ?: data_get($clinicalImpression, 'uuid', '-') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->clinicalImpressions) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('patients.show_more') }}</button>
        </div>
    @endif
</div>
