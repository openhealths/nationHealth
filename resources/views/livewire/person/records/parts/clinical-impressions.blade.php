@use('App\Enums\Person\ClinicalImpressionStatus')

@foreach($this->clinicalImpressions as $clinicalImpression)
    <div class="record-inner-card">
        <div class="record-inner-header">
            <div class="record-inner-checkbox-col">
                <input type="checkbox" class="default-checkbox w-5 h-5">
            </div>

            <div class="record-inner-column flex-1">
                <div class="record-inner-label">{{ __('forms.code') }}</div>
                <div class="record-inner-value text-[16px] font-semibold dark:text-gray-100">
                    {{ data_get($this->dictionaries, 'eHealth/clinical_impression_patient_categories.' . data_get($clinicalImpression, 'code.coding.0.code'), data_get($clinicalImpression, 'code.coding.0.code', '-')) }}
                </div>
            </div>

            <div class="record-inner-column-bordered w-full md:w-36 shrink-0 h-full flex flex-col justify-center gap-1">
                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                <div>
                    <span class="badge-green">
                        {{ ClinicalImpressionStatus::from(data_get($clinicalImpression, 'status'))->label() }}
                    </span>
                </div>
            </div>

            <div class="record-inner-action-col">
                <div class="flex justify-center relative">
                    <div x-data="{
                             open: false,
                             toggle() {
                                 if (this.open) {
                                     return this.close();
                                 }
                                 this.$refs.button.focus();
                                 this.open = true;
                             },
                             close(focusAfter) {
                                 if (!this.open) return;
                                 this.open = false;
                                 focusAfter && focusAfter.focus()
                             }
                         }"
                         @keydown.escape.prevent.stop="close($refs.button)"
                         @focusin.window="!$refs.panel.contains($event.target) && close()"
                         x-id="['dropdown-button']"
                         class="relative"
                    >
                        <button @click="toggle()"
                                x-ref="button"
                                :aria-expanded="open"
                                :aria-controls="$id('dropdown-button')"
                                type="button"
                                class="record-inner-action-btn"
                        >
                            @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                        </button>

                        <div x-show="open"
                             x-cloak
                             x-ref="panel"
                             x-transition.origin.top.right
                             @click.outside="close($refs.button)"
                             :id="$id('dropdown-button')"
                             class="absolute right-0 mt-2 w-56 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-lg z-50 py-1"
                        >
                            <button @click="close($refs.button)"
                                    class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                            >
                                @icon('eye', 'w-5 h-5 text-gray-500')
                                {{ __('patients.view_details') }}
                            </button>

                            <button @click="close($refs.button)"
                                    class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                            >
                                @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                {{ __('patients.status.entered_in_error') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="record-inner-body">
            <div class="record-inner-grid-container">
                <div class="grid grid-cols-2 xl:grid-cols-5 gap-y-4 gap-x-4 w-full [&>div]:min-w-0 [&_.record-inner-value]:break-words">
                    <div>
                        <div class="record-inner-label">{{ __('patients.created') }}</div>
                        <div class="record-inner-value">{{ data_get($clinicalImpression, 'ehealthInsertedAt', '-') }}</div>
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
                        <div class="record-inner-label">{{ __('patients.clinical_impression_conclusion') }}</div>
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
                    <div class="record-inner-label">ID Епізоду</div>
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
