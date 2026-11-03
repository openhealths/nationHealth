@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->diagnoses) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->diagnoses as $index => $diagnosis)
        <div
            class="record-inner-card"
            wire:key="diagnosis-{{ $index }}"
            x-data="{ detailsOpen: false }"
            @if ($hasLimit) x-show="limit > {{ $index }}" @endif
        >
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                @php
                    $system = data_get($diagnosis, 'code.coding.0.system');
                    $code = data_get($diagnosis, 'code.coding.0.code');
                    $role = data_get($diagnosis, 'role.coding.0.code');
                @endphp
                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('medical-events.code_and_name') }}</div>
                    <div class="record-inner-value text-[16px]">
                        {{ $code }} - {{ $this->dictionaries[$system][$code] ?? null }}
                    </div>
                </div>

                <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                    <div class="record-inner-label">{{ __('patients.diagnosis_role') }}</div>
                    <div class="record-inner-subvalue">
                        {{ $this->dictionaries['eHealth/diagnosis_roles'][$role] ?? null }}
                    </div>
                </div>

                <div class="record-inner-action-col">
                    <x-patient.record-actions>
                        <button
                            type="button"
                            @click="close(); detailsOpen = true"
                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                        >
                            @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                            {{ __('forms.view_details') }}
                        </button>
                    </x-patient.record-actions>
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="[&>div]:min-w-0 [&_.record-inner-subvalue]:wrap-break-word grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-4">
                        <div>
                            <div class="record-inner-label">{{ __('patients.diagnosis_rank') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($diagnosis, 'rank', '-') }}</div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('conditions.label') }}</div>
                        <div class="record-inner-id-value">
                            {{ data_get($diagnosis, 'condition.identifier.value', '-') }}
                        </div>
                    </div>
                </div>
            </div>

            <x-modal id="diagnosis-details-{{ $index }}" alpine-model="detailsOpen" max-width="lg">
                <div class="p-6" role="dialog" aria-modal="true" aria-labelledby="diagnosis-details-title-{{ $index }}">
                    <div class="mb-5 flex items-center justify-between gap-4">
                        <h2 id="diagnosis-details-title-{{ $index }}" class="text-lg font-semibold text-gray-900 dark:text-white">
                            {{ __('forms.view_details') }}
                        </h2>
                        <button
                            type="button"
                            @click="detailsOpen = false"
                            aria-label="{{ __('forms.close') }}"
                            class="record-inner-action-btn cursor-pointer"
                        >
                            @icon('close', 'w-5 h-5')
                        </button>
                    </div>
                    <dl class="grid gap-4">
                        <div>
                            <dt class="record-inner-label">
                                {{ __('medical-events.code_and_name') }}
                            </dt>
                            <dd class="record-inner-value">
                                {{ $code }} - {{ $this->dictionaries[$system][$code] ?? '-' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="record-inner-label">
                                {{ __('patients.diagnosis_role') }}
                            </dt>
                            <dd class="record-inner-value">
                                {{ $this->dictionaries['eHealth/diagnosis_roles'][$role] ?? '-' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="record-inner-label">
                                {{ __('patients.diagnosis_rank') }}
                            </dt>
                            <dd class="record-inner-value">
                                {{ data_get($diagnosis, 'rank', '-') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="record-inner-label">
                                {{ __('conditions.label') }}
                            </dt>
                            <dd class="record-inner-id-value">
                                {{ data_get($diagnosis, 'condition.identifier.value', '-') }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </x-modal>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->diagnoses) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('general.show_more') }}</button>
        </div>
    @endif
</div>
