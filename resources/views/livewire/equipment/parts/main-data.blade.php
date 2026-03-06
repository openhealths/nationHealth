@use('App\Enums\Equipment\{Status, Type}')

<fieldset class="fieldset form">
    <legend class="legend">
        {{ __('forms.main_information') }}
    </legend>

    <div class="space-y-4"
         x-data="{ names: $wire.entangle('form.names'), types: @js(Type::allowedForEquipment()), errors: @js($errors->getMessages()) }"
         x-init="if (!Array.isArray(names) || names.length === 0) { names = [{ name: '', type: '' }] }"
         x-id="['name']"
    >
        <template x-for="(name, index) in names" :key="index">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 items-center" :key="index">
                <div class="form-group group">
                    <input x-model="names[index].name"
                           type="text"
                           :name="$id('name', 'name' + index)"
                           :id="$id('name', 'name' + index)"
                           placeholder=" "
                           required
                           class="peer input"
                           :class="{ 'input-error': errors[`form.names.${index}.name`] }"
                    >
                    <label :for="$id('name', 'name' + index)" class="label">
                        {{ __('equipments.name_medical_product') }}
                    </label>

                    <template x-if="errors[`form.names.${index}.name`]">
                        <p class="text-error" x-text="errors[`form.names.${index}.name`]"></p>
                    </template>
                </div>

                <div class="form-group group">
                    <select x-model="names[index].type"
                            :name="$id('name', 'type' + index)"
                            :id="$id('name', 'type' + index)"
                            required
                            class="peer input-select"
                            :class="{ 'input-error': errors[`form.names.${index}.type`] }"
                    >
                        <option value="" :selected="names[index].type == ''">{{ __('forms.select') }}</option>
                        <template x-for="[key, typeName] in Object.entries(types)" :key="key">
                            <option :value="key" :selected="names[index].type == key" x-text="typeName"></option>
                        </template>
                    </select>

                    <label :for="$id('name', 'type' + index)"
                           class="label peer-focus:text-blue-600 peer-valid:text-blue-600"
                    >
                        {{ __('equipments.name_type') }}
                    </label>
                    <template x-if="errors[`form.names.${index}.type`]">
                        <p class="text-error" x-text="errors[`form.names.${index}.type`]"></p>
                    </template>
                </div>

                <div class="flex items-center space-x-4">
                    <template x-if="names.length > 1">
                        <button type="button"
                                @click.prevent="names.splice(index, 1)"
                                class="text-red-600 hover:text-red-800 item-remove justify-self-start">
                            @icon('delete', 'w-5 h-5 text-red-600')
                        </button>
                    </template>

                    <template x-if="index === names.length - 1">
                        <button type="button"
                                @click.prevent="names.push({ name: '', type: '' })"
                                class="text-indigo-600 hover:text-indigo-800 item-add">
                            {{ __('equipments.add_name') }}
                        </button>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <div class="form-row-2 mt-6">
        <div class="form-group group">
            <select wire:model="form.type"
                    name="typeMedicalDevice"
                    id="typeMedicalDevice"
                    required
                    class="peer input-select"
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach($this->dictionaries['device_definition_classification_type'] as $key => $type)
                    <option value="{{ $key }}">{{ $type }}</option>
                @endforeach
            </select>
            <label for="typeMedicalDevice" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                {{ __('equipments.type_medical_device') }}
            </label>

            @error('form.type')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input wire:model="form.serialNumber"
                   type="text"
                   name="serialNumber"
                   id="serialNumber"
                   placeholder=" "
                   class="peer input"
            >
            <label for="serialNumber" class="label">
                {{ __('equipments.serial_number') }}
            </label>

            @error('form.serialNumber')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group group">
            <input value="{{ Status::from($form->status)->label() }}"
                   type="text"
                   name="status"
                   id="status"
                   placeholder=" "
                   class="peer input"
                   disabled
                   readonly
            >
            <label for="status" class="label">
                {{ __('forms.status.label') }}
            </label>

            @error('form.status')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input value="{{ $recorderFullName }}"
                   type="text"
                   name="recorder"
                   id="recorder"
                   placeholder=" "
                   class="peer input"
                   disabled
            >
            <label for="recorder" class="label">
                {{ __('equipments.recorder') }}
            </label>

            @error('form.recorder')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>
</fieldset>
