<template x-for="(container, containerIndex) in modalSpecimen.containers" :key="containerIndex">
    <fieldset class="fieldset-card relative mb-6 p-4 sm:p-8 sm:pb-10">
        <legend class="legend">{{ __('specimens.container_number') }}<span x-text="containerIndex + 1"></span></legend>

        <template x-if="containerIndex > 0">
            <button
                type="button"
                @click="modalSpecimen.containers.splice(containerIndex, 1)"
                class="absolute -top-5 right-4 bg-white px-2 text-gray-400 transition-colors hover:text-red-500 sm:right-8 dark:bg-slate-900 dark:text-gray-500 dark:hover:text-red-500"
            >
                @icon('delete', 'w-6 h-6')
            </button>
        </template>

        <div class="form-row-2">
            <div class="form-group group relative">
                <input
                    x-model="container.identifier"
                    type="text"
                    :id="`containerIdentifier${containerIndex}`"
                    class="input peer"
                    placeholder=" "
                    required
                />
                <label
                    :for="`containerIdentifier${containerIndex}`"
                    class="label"
                >{{ __('specimens.identifier') }}</label>
            </div>

            <div class="form-group group relative">
                <input
                    x-model="container.description"
                    type="text"
                    :id="`containerDescription${containerIndex}`"
                    class="input peer"
                    placeholder=" "
                />
                <label
                    :for="`containerDescription${containerIndex}`"
                    class="label"
                >{{ __('specimens.container_description') }}</label>
            </div>
        </div>

        <div class="form-row-2 mt-6">
            <div class="form-group group">
                <select
                    x-model="container.typeCode"
                    @change="if (! container.typeCode) container.typeText = '';"
                    :id="`containerType${containerIndex}`"
                    class="input-select peer"
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach ($this->dictionaries['specimen_container_types'] as $code => $containerType)
                        <option value="{{ $code }}">{{ $containerType }}</option>
                    @endforeach
                </select>
                <label
                    :for="`containerType${containerIndex}`"
                    class="label"
                >{{ __('specimens.container_type') }}</label>
            </div>

            <div class="form-group group">
                <select
                    x-model="container.additiveCode"
                    @change="if (! container.additiveCode) container.additiveText = '';"
                    :id="`containerAdditive${containerIndex}`"
                    class="input-select peer"
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach ($this->dictionaries['specimen_container_additives'] as $code => $containerAdditive)
                        <option value="{{ $code }}">{{ $containerAdditive }}</option>
                    @endforeach
                </select>
                <label :for="`containerAdditive${containerIndex}`" class="label">{{ __('specimens.additive') }}</label>
            </div>
        </div>

        <div class="form-row-2 mt-6">
            <div class="form-group group">
                <input
                    x-model="container.typeText"
                    :disabled="! container.typeCode"
                    type="text"
                    :id="`containerTypeText${containerIndex}`"
                    class="input peer"
                    placeholder=" "
                    autocomplete="off"
                />
                <label
                    :for="`containerTypeText${containerIndex}`"
                    class="label"
                >{{ __('specimens.container_type_clarification') }}</label>
            </div>

            <div class="form-group group">
                <input
                    x-model="container.additiveText"
                    :disabled="! container.additiveCode"
                    type="text"
                    :id="`containerAdditiveText${containerIndex}`"
                    class="input peer"
                    placeholder=" "
                    autocomplete="off"
                />
                <label
                    :for="`containerAdditiveText${containerIndex}`"
                    class="label"
                >{{ __('specimens.additive_clarification') }}</label>
            </div>
        </div>

        <div class="form-row-2 mt-6">
            <div class="form-group group flex items-start gap-2">
                <div class="relative flex-1">
                    <input
                        x-model="container.capacityValue"
                        type="number"
                        min="0"
                        step="any"
                        :id="`containerCapacityValue${containerIndex}`"
                        class="input peer"
                        placeholder=" "
                    />
                    <label
                        :for="`containerCapacityValue${containerIndex}`"
                        class="label"
                    >{{ __('specimens.container_volume') }}</label>
                </div>
                <div class="w-40">
                    <label :for="`containerCapacityCode${containerIndex}`" class="sr-only">
                        {{ __('specimens.unit') }}
                    </label>
                    <select
                        x-model="container.capacityCode"
                        :id="`containerCapacityCode${containerIndex}`"
                        class="input-select peer"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($this->dictionaries['eHealth/ucum/units'] as $code => $unit)
                            <option value="{{ $code }}">{{ $unit }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-group group flex items-start gap-2">
                <div class="relative flex-1">
                    <input
                        x-model="container.specimenQuantityValue"
                        type="number"
                        min="0"
                        step="any"
                        :id="`containerSpecimenQuantityValue${containerIndex}`"
                        class="input peer"
                        placeholder=" "
                    />
                    <label
                        :for="`containerSpecimenQuantityValue${containerIndex}`"
                        class="label"
                    >{{ __('specimens.biomaterial_amount_in_container') }}</label>
                </div>
                <div class="w-40">
                    <label :for="`containerSpecimenQuantityCode${containerIndex}`" class="sr-only">
                        {{ __('specimens.unit') }}
                    </label>
                    <select
                        x-model="container.specimenQuantityCode"
                        :id="`containerSpecimenQuantityCode${containerIndex}`"
                        class="input-select peer"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($this->dictionaries['eHealth/ucum/units'] as $code => $unit)
                            <option value="{{ $code }}">{{ $unit }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </fieldset>
</template>

<div class="mb-6">
    <button
        type="button"
        @click="modalSpecimen.containers.push(new SpecimenContainer())"
        class="cursor-pointer text-sm font-medium text-blue-600 hover:text-blue-800"
    >
        {{ __('specimens.add_container') }}
    </button>
</div>
