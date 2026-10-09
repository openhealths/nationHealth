@php
    $detail = $deviceDispense->details->first();
    $whenHandedOver = explode(' ', (string) $deviceDispense->whenHandedOver, 2);
    $dispenseDate = $whenHandedOver[0] ?? '-';
    $dispenseTime = $whenHandedOver[1] ?? '-';
    $deviceCode = $detail?->deviceCode?->coding->first()?->code;
    $deviceSelectionType = $detail?->device ? 'model' : ($deviceCode ? 'type' : null);
@endphp

<div
    x-data="{
        supportingInfoName(supporting) {
            const dictName =
                $wire.dictionaries['eHealth/LOINC/observation_codes']?.[supporting.code] ||
                $wire.dictionaries['eHealth/ICF/classifiers']?.[supporting.code] ||
                $wire.dictionaries['eHealth/ICPC2/condition_codes']?.[supporting.code];

            if (dictName) {
                return `${supporting.code} - ${dictName}`;
            }

            const service = Object.values(
                $wire.dictionaries['custom/services'] ?? {}
            ).find(
                (service) => service.id === supporting.code
            );

            return service
                ? `${service.code} / ${service.name}`
                : supporting.code || supporting.uuid || '-';
        },

        supportingInfoTypeName(type) {
            const types = {
                episode: '{{ __('episodes.label') }}',
                encounter: '{{ __('encounters.label') }}',
                procedure: '{{ __('procedures.label') }}',
                diagnostic_report: '{{ __('diagnostic-reports.label') }}',
                condition: '{{ __('conditions.label') }}',
                observation: '{{ __('observations.label') }}'
            };

            return types[type] || '-';
        }
    }"
>
    <section class="section-form p-6">
        <x-header-navigation
            class="breadcrumb-form"
            title="{{ __('device-dispenses.medical_device') }}"
        >
            <x-slot name="title">
                {{ __('device-dispenses.medical_device') }}
            </x-slot>
        </x-header-navigation>

        <div class="form shift-content">
            <fieldset disabled>
                <div class="form-row-2">
                    <div class="form-group group">
                        <select
                            id="deviceDispenseBasedOn"
                            class="input-select peer"
                            disabled
                        >
                            <option selected>
                                {{ $deviceDispense->basedOn?->value ?? '-' }}
                            </option>
                        </select>

                        <label for="deviceDispenseBasedOn" class="label">
                            {{ __('device-dispenses.prescription_erequest') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select
                            id="deviceDispenseProcedure"
                            class="input-select peer"
                            disabled
                        >
                            <option selected>
                                {{ $procedureName ?: '-' }}
                            </option>
                        </select>

                        <label for="deviceDispenseProcedure" class="label">
                            {{ __('procedures.link') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-2 mt-6">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="deviceDispensePerformer"
                            class="input-select peer !cursor-not-allowed !text-gray-500 dark:!text-gray-400"
                            value="{{ $performerName ?? '-' }}"
                            disabled
                        />

                        <label for="deviceDispensePerformer" class="label">
                            {{ __('device-dispenses.employee') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select
                            id="deviceDispenseLocation"
                            class="input-select peer"
                            disabled
                        >
                            <option selected>
                                {{ $locationName ?? '-' }}
                            </option>
                        </select>

                        <label for="deviceDispenseLocation" class="label">
                            {{ __('device-dispenses.division') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-2 mt-6">
                    <div class="form-group group relative flex justify-between">
                        <div class="datepicker-wrapper flex-1">
                            <input
                                type="text"
                                id="deviceDispenseWhenHandedOverDate"
                                class="datepicker-input with-leading-icon input peer rounded-r-none border-r-0"
                                value="{{ $dispenseDate }}"
                                disabled
                            />

                            <label
                                for="deviceDispenseWhenHandedOverDate"
                                class="wrapped-label"
                            >
                                {{ __('device-dispenses.date_and_time') }}
                            </label>
                        </div>

                        <div class="relative -ml-px w-32">
                            <input
                                type="time"
                                id="deviceDispenseWhenHandedOverTime"
                                class="input peer rounded-l-none pl-10"
                                value="{{ $dispenseTime !== '-' ? $dispenseTime : '' }}"
                                disabled
                            />

                            @icon('clock', 'svg-input left-2.5 text-gray-400')
                        </div>
                    </div>

                    <div class="form-group group">
                        <input
                            type="number"
                            id="deviceDispenseQuantity"
                            class="input peer"
                            value="{{ $detail?->quantity?->value ?? '-' }}"
                            disabled
                        />

                        <label for="deviceDispenseQuantity" class="label">
                            {{ __('device-dispenses.quantity_integer') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-2 mt-6">
                    <div class="form-group group">
                        <select
                            id="deviceDispenseSelectionType"
                            class="input-select peer"
                            disabled
                        >
                            <option selected>
                                @if ($deviceSelectionType === 'model')
                                    {{ __('device-dispenses.model') }}
                                @elseif ($deviceSelectionType === 'type')
                                    {{ __('device-dispenses.type') }}
                                @else
                                    -
                                @endif
                            </option>
                        </select>

                        <label for="deviceDispenseSelectionType" class="label">
                            {{ __('device-dispenses.specify_type_or_model') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select
                            id="deviceDispenseDevice"
                            class="input-select peer"
                            disabled
                        >
                            <option selected>
                                {{ $deviceName ?? '-' }}
                            </option>
                        </select>

                        <label for="deviceDispenseDevice" class="label">
                            @if ($deviceSelectionType === 'model')
                                {{ __('device-dispenses.device_model') }}
                            @else
                                {{ __('device-dispenses.device_type') }}
                            @endif
                        </label>
                    </div>
                </div>

                <div class="form-row-1 mt-6">
                    <div>
                        <label
                            for="deviceDispenseNote"
                            class="label-modal mb-2 block"
                        >
                            {{ __('device-dispenses.note') }}
                        </label>

                        <textarea
                            id="deviceDispenseNote"
                            class="textarea"
                            rows="4"
                            disabled
                        >{{ $deviceDispense->note ?? '-' }}</textarea>
                    </div>
                </div>

                <div class="relative mt-10">
                    <fieldset class="fieldset">
                        <legend class="legend">
                            {{ __('device-dispenses.supporting_info') }}
                        </legend>

                        <table class="table-input w-inherit">
                            <thead class="thead-input">
                                <tr>
                                    <th scope="col" class="th-input">
                                        {{ __('forms.date') }}
                                    </th>

                                    <th scope="col" class="th-input">
                                        {{ __('forms.type') }}
                                    </th>

                                    <th scope="col" class="th-input">
                                        {{ __('care-plan.medical_record') }}
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                @forelse ($supportingInfo as $supporting)
                                    <tr x-data="{ supporting: @js($supporting) }">
                                        <td
                                            class="td-input"
                                            x-text="supporting.ehealthInsertedAt || '-'"
                                        ></td>

                                        <td
                                            class="td-input"
                                            x-text="supportingInfoTypeName(supporting.type)"
                                        ></td>

                                        <td
                                            class="td-input"
                                            x-text="supportingInfoName(supporting)"
                                        ></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="td-input">-</td>
                                        <td class="td-input">-</td>
                                        <td class="td-input">-</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </fieldset>
                </div>
            </fieldset>
        </div>
    </section>
</div>