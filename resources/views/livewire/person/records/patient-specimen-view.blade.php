@use(App\Enums\Specimen\Status as SpecimenStatus)

@php
    [$receivedDate, $receivedTime] = array_pad(explode(' ', (string) data_get($specimen, 'receivedTime')), 2, '');
    [$insertedDate, $insertedTime] = array_pad(explode(' ', (string) data_get($specimen, 'ehealthInsertedAt')), 2, '');
    [$updatedDate, $updatedTime] = array_pad(explode(' ', (string) data_get($specimen, 'ehealthUpdatedAt')), 2, '');
    $collectedAt = data_get($specimen, 'collection.collectedDateTime')
        ?? collect([
            data_get($specimen, 'collection.collectedPeriod.start'),
            data_get($specimen, 'collection.collectedPeriod.end')
        ])->filter()->implode(' - ');
@endphp

<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :showLegacyMessages="false"
    :hideNavigation="true"
    title="{{ __('specimens.specimen') }} {{ data_get($specimen, 'accessionIdentifier') }}"
    activeTab="specimens"
>
    <x-slot name="headerActions">
        <button
            wire:click.prevent="sync"
            type="button"
            class="button-sync flex items-center gap-2 px-4 py-2 text-sm shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            <span>{{ __('forms.synchronise_with_eHealth') }}</span>
        </button>
    </x-slot>

    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <div class="shift-content mt-8 max-w-6xl pl-3.5">
        <fieldset class="fieldset mb-6">
            <legend class="legend">{{ __('specimens.general_info') }}</legend>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenType"
                        class="input peer"
                        value="{{ $this->dictionaryLabel($specimen, 'type') }}"
                        disabled
                    />
                    <label for="specimenType" class="label">{{ __('specimens.specimen_type') }}</label>
                </div>
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenCondition"
                        class="input peer"
                        value="{{ $this->dictionaryLabel($specimen, 'condition') }}"
                        disabled
                    />
                    <label for="specimenCondition" class="label">{{ __('specimens.specimen_condition') }}</label>
                </div>
            </div>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenTypeText"
                        class="input peer"
                        value="{{ data_get($specimen, 'type.text') ?? '-' }}"
                        disabled
                    />
                    <label
                        for="specimenTypeText"
                        class="label"
                    >{{ __('specimens.specimen_type_clarification') }}</label>
                </div>
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenConditionText"
                        class="input peer"
                        value="{{ data_get($specimen, 'condition.text') ?? '-' }}"
                        disabled
                    />
                    <label for="specimenConditionText" class="label">
                        {{ __('specimens.specimen_condition_clarification') }}
                    </label>
                </div>
            </div>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenAccessionIdentifier"
                        class="input peer"
                        value="{{ data_get($specimen, 'accessionIdentifier') ?? '-' }}"
                        disabled
                    />
                    <label for="specimenAccessionIdentifier" class="label">{{ __('specimens.identifier') }}</label>
                </div>
                <div class="flex w-full items-start">
                    <div class="form-group group mb-0! min-w-0 flex-1">
                        <div class="datepicker-wrapper w-full">
                            <input
                                type="text"
                                id="specimenReceivedDate"
                                class="datepicker-input with-leading-icon input peer w-full"
                                value="{{ $receivedDate ?: '-' }}"
                                disabled
                            />
                            <label
                                for="specimenReceivedDate"
                                class="wrapped-label w-auto! max-w-none! overflow-visible!"
                            >
                                {{ __('specimens.date_time_received') }}
                            </label>
                        </div>
                    </div>
                    <div class="group relative mb-0! w-24! shrink-0">
                        <label for="specimenReceivedTime" class="sr-only">{{ __('forms.time') }}</label>
                        <div class="relative flex w-full items-center">
                            @icon('mingcute-time-fill', 'absolute top-2.5 right-12 w-4 h-4 text-gray-500')
                            <input
                                type="text"
                                id="specimenReceivedTime"
                                class="input peer w-full pr-1! pl-0! text-right"
                                value="{{ $receivedTime }}"
                                disabled
                            />
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-row-2 mb-6">
                <div class="flex w-full items-start">
                    <div class="form-group group mb-0! min-w-0 flex-1">
                        <div class="datepicker-wrapper w-full">
                            <input
                                type="text"
                                id="specimenInsertedDate"
                                class="datepicker-input with-leading-icon input peer w-full"
                                value="{{ $insertedDate ?: '-' }}"
                                disabled
                            />
                            <label
                                for="specimenInsertedDate"
                                class="wrapped-label w-auto! max-w-none! overflow-visible!"
                            >
                                {{ __('specimens.created_in_system') }}
                            </label>
                        </div>
                    </div>
                    <div class="group relative mb-0! w-24! shrink-0">
                        <label for="specimenInsertedTime" class="sr-only">{{ __('forms.time') }}</label>
                        <div class="relative flex w-full items-center">
                            @icon('mingcute-time-fill', 'absolute top-2.5 right-12 w-4 h-4 text-gray-500')
                            <input
                                type="text"
                                id="specimenInsertedTime"
                                class="input peer w-full pr-1! pl-0! text-right"
                                value="{{ $insertedTime }}"
                                disabled
                            />
                        </div>
                    </div>
                </div>
                <div class="flex w-full items-start">
                    <div class="form-group group mb-0! min-w-0 flex-1">
                        <div class="datepicker-wrapper w-full">
                            <input
                                type="text"
                                id="specimenUpdatedDate"
                                class="datepicker-input with-leading-icon input peer w-full"
                                value="{{ $updatedDate ?: '-' }}"
                                disabled
                            />
                            <label
                                for="specimenUpdatedDate"
                                class="wrapped-label w-auto! max-w-none! overflow-visible!"
                            >
                                {{ __('specimens.updated_in_system') }}
                            </label>
                        </div>
                    </div>
                    <div class="group relative mb-0! w-24! shrink-0">
                        <label for="specimenUpdatedTime" class="sr-only">{{ __('forms.time') }}</label>
                        <div class="relative flex w-full items-center">
                            @icon('mingcute-time-fill', 'absolute top-2.5 right-12 w-4 h-4 text-gray-500')
                            <input
                                type="text"
                                id="specimenUpdatedTime"
                                class="input peer w-full pr-1! pl-0! text-right"
                                value="{{ $updatedTime }}"
                                disabled
                            />
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenStatus"
                        class="input peer"
                        value="{{ SpecimenStatus::from(data_get($specimen, 'status'))->label() }}"
                        disabled
                    />
                    <label for="specimenStatus" class="label">{{ __('forms.status.label') }}</label>
                </div>
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenStatusReason"
                        class="input peer"
                        value="{{ $this->dictionaryLabel($specimen, 'statusReason') }}"
                        disabled
                    />
                    <label for="specimenStatusReason" class="label">{{ __('specimens.unavailability_reason') }}</label>
                </div>
            </div>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenRequest"
                        class="input peer"
                        value="{{ collect(data_get($specimen, 'request', []))->pluck('value')->implode(', ') ?: '-' }}"
                        disabled
                    />
                    <label for="specimenRequest" class="label">{{ __('specimens.electronic_referral') }}</label>
                </div>
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenParent"
                        class="input peer"
                        value="{{ collect(data_get($specimen, 'parent', []))->pluck('value')->implode(', ') ?: '-' }}"
                        disabled
                    />
                    <label for="specimenParent" class="label">{{ __('specimens.parent_specimen') }}</label>
                </div>
            </div>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenManagingOrganization"
                        class="input peer"
                        value="{{ data_get($specimen, 'managingOrganization.displayValue') ?? (data_get($specimen, 'managingOrganization.value') ?? '-') }}"
                        disabled
                    />
                    <label for="specimenManagingOrganization" class="label">
                        {{ __('specimens.healthcare_service_where_collected') }}
                    </label>
                </div>
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenRegisteredBy"
                        class="input peer"
                        value="{{ data_get($specimen, 'registeredBy.displayValue') ?? (data_get($specimen, 'registeredBy.value') ?? '-') }}"
                        disabled
                    />
                    <label
                        for="specimenRegisteredBy"
                        class="label"
                    >{{ __('specimens.employee_created_record') }}</label>
                </div>
            </div>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="specimenContext"
                        class="input peer"
                        value="{{ data_get($specimen, 'context.value') ?? '-' }}"
                        disabled
                    />
                    <label for="specimenContext" class="label">{{ __('specimens.related_encounter') }}</label>
                </div>
                <div></div>
            </div>
            <div class="mt-6">
                <div class="form-group">
                    <label
                        for="specimenNote"
                        class="peer appearance-none bg-white text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                    >
                        {{ __('specimens.note') }}
                    </label>
                    <textarea
                        id="specimenNote"
                        class="textarea mt-1 min-h-20"
                        disabled
                    >{{ data_get($specimen, 'note') }}</textarea>
                </div>
            </div>
        </fieldset>

        <fieldset class="fieldset mb-6">
            <legend class="legend">{{ __('specimens.collection_details') }}</legend>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="collectionCollector"
                        class="input peer"
                        value="{{ data_get($specimen, 'collection.collector.displayValue') ?? (data_get($specimen, 'collection.collector.value') ?? '-') }}"
                        disabled
                    />
                    <label for="collectionCollector" class="label">{{ __('specimens.collector') }}</label>
                </div>
                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input
                            type="text"
                            id="collectionCollectedAt"
                            class="datepicker-input with-leading-icon input peer"
                            value="{{ $collectedAt ?: '-' }}"
                            disabled
                        />
                        <label for="collectionCollectedAt" class="wrapped-label">
                            {{ __('specimens.collection_date_time_or_period') }}
                        </label>
                    </div>
                </div>
            </div>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="collectionMethod"
                        class="input peer"
                        value="{{ $this->dictionaryLabel($specimen, 'collection.method') }}"
                        disabled
                    />
                    <label for="collectionMethod" class="label">{{ __('specimens.collection_method') }}</label>
                </div>
                <div class="form-group group flex items-start gap-4">
                    <div class="relative flex-1">
                        <input
                            type="text"
                            id="collectionDurationValue"
                            class="input peer"
                            value="{{ data_get($specimen, 'collection.duration.value') ?? '-' }}"
                            disabled
                        />
                        <label
                            for="collectionDurationValue"
                            class="label"
                        >{{ __('specimens.collection_duration') }}</label>
                    </div>
                    <div class="w-32">
                        <label for="collectionDurationUnit" class="sr-only">{{ __('specimens.unit') }}</label>
                        <input
                            type="text"
                            id="collectionDurationUnit"
                            class="input peer"
                            value="{{ data_get($specimen, 'collection.duration.unit') }}"
                            disabled
                        />
                    </div>
                </div>
            </div>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="collectionBodySite"
                        class="input peer"
                        value="{{ $this->dictionaryLabel($specimen, 'collection.bodySite') }}"
                        disabled
                    />
                    <label for="collectionBodySite" class="label">{{ __('specimens.body_site') }}</label>
                </div>
                <div class="form-group group flex items-start gap-4">
                    <div class="relative flex-1">
                        <input
                            type="text"
                            id="collectionQuantityValue"
                            class="input peer"
                            value="{{ data_get($specimen, 'collection.quantity.value') ?? '-' }}"
                            disabled
                        />
                        <label for="collectionQuantityValue" class="label">{{ __('specimens.material_amount') }}</label>
                    </div>
                    <div class="w-32">
                        <label for="collectionQuantityUnit" class="sr-only">{{ __('specimens.unit') }}</label>
                        <input
                            type="text"
                            id="collectionQuantityUnit"
                            class="input peer"
                            value="{{ data_get($specimen, 'collection.quantity.unit') }}"
                            disabled
                        />
                    </div>
                </div>
            </div>
            <div class="form-row-2 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        id="collectionProcedure"
                        class="input peer"
                        value="{{ data_get($specimen, 'collection.procedure.value') ?? '-' }}"
                        disabled
                    />
                    <label
                        for="collectionProcedure"
                        class="label"
                    >{{ __('specimens.procedure_during_collection') }}</label>
                </div>
                <div class="form-group group">
                    <input
                        type="text"
                        id="collectionFastingStatus"
                        class="input peer"
                        value="{{ $this->dictionaryLabel($specimen, 'collection.fastingStatusCodeableConcept') }}"
                        disabled
                    />
                    <label for="collectionFastingStatus" class="label">{{ __('specimens.fasting_status') }}</label>
                </div>
            </div>
        </fieldset>

        @foreach (data_get($specimen, 'container', []) as $container)
            <fieldset class="fieldset mb-6" wire:key="container-{{ $loop->index }}">
                <legend class="legend">{{ __('specimens.container_number') }}{{ $loop->iteration }}</legend>
                <div class="form-row-2 mb-6">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="container{{ $loop->index }}Identifier"
                            class="input peer"
                            value="{{ data_get($container, 'identifier') }}"
                            disabled
                        />
                        <label
                            for="container{{ $loop->index }}Identifier"
                            class="label"
                        >{{ __('specimens.identifier') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            id="container{{ $loop->index }}Type"
                            class="input peer"
                            value="{{ $this->dictionaryLabel($container, 'type') }}"
                            disabled
                        />
                        <label
                            for="container{{ $loop->index }}Type"
                            class="label"
                        >{{ __('specimens.container_type') }}</label>
                    </div>
                </div>
                <div class="form-row-2 mb-6">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="container{{ $loop->index }}Description"
                            class="input peer"
                            value="{{ data_get($container, 'description') ?? '-' }}"
                            disabled
                        />
                        <label for="container{{ $loop->index }}Description" class="label">
                            {{ __('specimens.container_description') }}
                        </label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            id="container{{ $loop->index }}TypeText"
                            class="input peer"
                            value="{{ data_get($container, 'type.text') ?? '-' }}"
                            disabled
                        />
                        <label for="container{{ $loop->index }}TypeText" class="label">
                            {{ __('specimens.container_type_clarification') }}
                        </label>
                    </div>
                </div>
                <div class="form-row-2 mb-6">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="container{{ $loop->index }}Additive"
                            class="input peer"
                            value="{{ $this->dictionaryLabel($container, 'additiveCodeableConcept') }}"
                            disabled
                        />
                        <label
                            for="container{{ $loop->index }}Additive"
                            class="label"
                        >{{ __('specimens.additive') }}</label>
                    </div>
                    <div class="form-group group flex items-start gap-4">
                        <div class="relative flex-1">
                            <input
                                type="text"
                                id="container{{ $loop->index }}CapacityValue"
                                class="input peer"
                                value="{{ data_get($container, 'capacity.value') ?? '-' }}"
                                disabled
                            />
                            <label for="container{{ $loop->index }}CapacityValue" class="label">
                                {{ __('specimens.container_volume') }}
                            </label>
                        </div>
                        <div class="w-32">
                            <label
                                for="container{{ $loop->index }}CapacityUnit"
                                class="sr-only"
                            >{{ __('specimens.unit') }}</label>
                            <input
                                type="text"
                                id="container{{ $loop->index }}CapacityUnit"
                                class="input peer"
                                value="{{ data_get($container, 'capacity.unit') }}"
                                disabled
                            />
                        </div>
                    </div>
                </div>
                <div class="form-row-2 mb-6">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="container{{ $loop->index }}AdditiveText"
                            class="input peer"
                            value="{{ data_get($container, 'additiveCodeableConcept.text') ?? '-' }}"
                            disabled
                        />
                        <label for="container{{ $loop->index }}AdditiveText" class="label">
                            {{ __('specimens.additive_clarification') }}
                        </label>
                    </div>
                    <div class="form-group group flex items-start gap-4">
                        <div class="relative flex-1">
                            <input
                                type="text"
                                id="container{{ $loop->index }}QuantityValue"
                                class="input peer"
                                value="{{ data_get($container, 'specimenQuantity.value') ?? '-' }}"
                                disabled
                            />
                            <label for="container{{ $loop->index }}QuantityValue" class="label">
                                {{ __('specimens.biomaterial_amount_in_container') }}
                            </label>
                        </div>
                        <div class="w-32">
                            <label
                                for="container{{ $loop->index }}QuantityUnit"
                                class="sr-only"
                            >{{ __('specimens.unit') }}</label>
                            <input
                                type="text"
                                id="container{{ $loop->index }}QuantityUnit"
                                class="input peer"
                                value="{{ data_get($container, 'specimenQuantity.unit') }}"
                                disabled
                            />
                        </div>
                    </div>
                </div>
            </fieldset>
        @endforeach

        <div class="mt-8 mb-8 flex justify-start">
            <a
                href="{{ $personId ? route('persons.specimens', [legalEntity(), 'person' => $personId]) : route('prepersons.specimens', [legalEntity(), 'preperson' => $prepersonId]) }}"
                class="button-outline rounded-lg border border-gray-300 px-6 py-2.5"
            >{{ __('forms.back') }}</a>
        </div>
    </div>
    <livewire:referral.specimen-referral
        :specimen-id="$specimenId"
        :person-id="$personId"
        :preperson-id="$prepersonId"
    />
</x-layouts.patient>
