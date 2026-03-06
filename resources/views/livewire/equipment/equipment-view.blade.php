@use('App\Enums\Equipment\{Status, Type}')

<section class="section-form">
    <x-header-navigation x-data="{ showFilter: false }">
        <x-slot name="title">{{ $equipment->names()->first()->name }}</x-slot>
    </x-header-navigation>

    <div class="form shift-content">
    <fieldset class="fieldset form">
        <legend class="legend">
            {{ __('forms.main_information') }}
        </legend>

        @foreach($equipment->names as $key => $name)
            <div class="form-row-2">
                <div class="form-group group">
                    <input wire:model="form.names.{{ $key }}.name"
                           type="text"
                           name="equipmentName"
                           id="equipmentName"
                           placeholder=" "
                           required
                           class="peer input"
                           disabled
                    >
                    <label for="equipmentName" class="label">
                        {{ __('equipments.name_medical_product') }}
                    </label>
                </div>

                <div class="form-group group">
                    <select wire:model="form.names.{{ $key }}.type"
                            name="typeName"
                            id="typeName"
                            required
                            class="peer input-select"
                            disabled
                    >
                        <option value="">{{ __('forms.select') }}</option>
                        @foreach(Type::options() as $key => $nameType)
                            <option value="{{ $key }}">{{ $nameType }}</option>
                        @endforeach
                    </select>
                    <label for="typeName" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                        {{ __('equipments.name_type') }}
                    </label>
                </div>
            </div>
        @endforeach

        <div class="form-row-2">
            <div class="form-group group">
                <select wire:model="form.type"
                        name="typeMedicalDevice"
                        id="typeMedicalDevice"
                        required
                        class="peer input-select"
                        disabled
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($this->dictionaries['device_definition_classification_type'] as $key => $type)
                        <option value="{{ $key }}">{{ $type }}</option>
                    @endforeach
                </select>
                <label for="typeMedicalDevice" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                    {{ __('equipments.type_medical_device') }}
                </label>
            </div>

            <div class="form-group group">
                <input wire:model="form.serialNumber"
                       type="text"
                       name="serialNumber"
                       id="serialNumber"
                       placeholder=" "
                       class="peer input"
                       disabled
                >
                <label for="serialNumber" class="label">
                    {{ __('equipments.serial_number') }}
                </label>
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
            </div>

            <div class="form-group group">
                <input type="text"
                       value="{{ $form->errorReason ? ($this->dictionaries['device_definition_classification_type'][$form->errorReason] ?? $form->errorReason) : '' }}"
                       name="errorReason"
                       id="errorReason"
                       placeholder=" "
                       class="peer input"
                       disabled
                       readonly
                >
                <label for="errorReason" class="label">
                    {{ __('equipments.reason_for_status_change') }}
                </label>
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group group">
                <input value="{{ $form->uuid }}"
                       type="text"
                       name="uuid"
                       id="uuid"
                       placeholder=" "
                       class="peer input"
                       disabled
                       readonly
                >
                <label for="uuid" class="label">
                    {{ __('equipments.id') }}
                </label>
            </div>

            <div class="form-group datepicker-wrapper relative w-full">
                <input type="text"
                       value="{{ $equipment->ehealthInsertedAt?->format('d.m.Y') ?? $equipment->createdAt->format('d.m.Y') }}"
                       name="insertedAt"
                       id="insertedAt"
                       placeholder=" "
                       class="peer input pl-10"
                       disabled
                       readonly
                >
                <label for="insertedAt" class="wrapped-label">
                    {{ __('equipments.inserted_at') }}
                </label>
            </div>
        </div>

        <div class="form-row-2">
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
                    {{ __('equipments.recorded_by') }}
                </label>
            </div>
        </div>
    </fieldset>

    @include('livewire.equipment.parts.additional-data', ['context' => 'view'])

    <div class="mt-6 flex flex-row items-center gap-4 pt-6">
        <div class="flex items-center space-x-3">
            <a href="{{ url()->previous() }}" class="button-minor">
                {{ __('forms.cancel') }}
            </a>

            @can('updateStatus', $equipment)
                @if($equipment->status !== Status::ENTERED_IN_ERROR)
                    <button type="button"
                            @click.prevent="$dispatch('open-update-status-modal', {
                                uuid: '{{ $equipment->uuid }}',
                                name: '{{ $equipment->names->first()->name }}',
                                status: '{{ $equipment->status }}'
                            })"
                            class="button-primary"
                    >
                        {{ __('equipments.update_status') }}
                    </button>
                @endif
            @endcan

            @can('updateAvailabilityStatus', $equipment)
                @if($equipment->status === Status::ACTIVE)
                    <button type="button"
                            @click.prevent="$dispatch('open-update-availability-status-modal', {
                                uuid: '{{ $equipment->uuid }}',
                                name: '{{ $equipment->names->first()->name }}',
                                status: '{{ $equipment->availabilityStatus }}'
                            })"
                            class="button-primary"
                    >
                        {{ __('equipments.update_availability_status') }}
                    </button>
                @endif
            @endcan
        </div>
    </div>

    </div>

    @include('livewire.equipment.modals.update-status-modal')
    @include('livewire.equipment.modals.update-availability-modal')

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</section>
