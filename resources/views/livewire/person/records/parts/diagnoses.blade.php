@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->diagnoses) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->diagnoses as $index => $diagnosis)
        <div
            class="record-inner-card"
            wire:key="diagnosis-{{ $index }}"
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
                    <button class="record-inner-action-btn cursor-pointer">
                        @icon('edit-user-outline', 'w-5 h-5')
                    </button>
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
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->diagnoses) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('patients.show_more') }}</button>
        </div>
    @endif
</div>
