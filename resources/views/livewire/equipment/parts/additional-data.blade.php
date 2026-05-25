@use('App\Enums\Equipment\AvailabilityStatus')

<fieldset class="fieldset form">
    <legend class="legend">
        {{ __('equipments.additional_data') }}
    </legend>

    <div class="form-row-2">
        <div class="form-group group">
            <select wire:model="form.divisionId"
                    name="divisionId"
                    id="divisionId"
                    class="peer input-select"
                    @disabled($context === 'view')
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach($divisions as $key => $division)
                    <option value="{{ $division['uuid'] }}">{{ $division['name'] }}</option>
                @endforeach
            </select>
            <label for="divisionId" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                {{ __('forms.division_name') }}
            </label>

            @error('form.divisionId')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <select name="availabilityStatus"
                    id="availabilityStatus"
                    class="peer input-select"
                    required
                    disabled
            >
                <option value="" selected>
                    {{ AvailabilityStatus::from($form->availabilityStatus)->label() }}
                </option>
            </select>
            <label for="availabilityStatus" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                {{ __('equipments.availability_status.label') }}
            </label>

            @error('form.availabilityStatus')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group group">
            <input wire:model="form.inventoryNumber"
                   type="text"
                   name="inventoryNumber"
                   id="inventoryNumber"
                   placeholder=" "
                   class="peer input"
                   @disabled($context === 'view')
            >
            <label for="inventoryNumber" class="label">
                {{ __('equipments.inventory_number') }}
            </label>

            @error('form.inventoryNumber')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input wire:model="form.manufacturer"
                   type="text"
                   name="manufacturer"
                   id="manufacturer"
                   placeholder=" "
                   class="peer input"
                   @disabled($context === 'view')
            >
            <label for="manufacturer" class="label">
                {{ __('equipments.manufacturer') }}
            </label>

            @error('form.manufacturer')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group datepicker-wrapper relative w-full">
            <input wire:model="form.manufactureDate"
                   type="text"
                   name="manufactureDate"
                   id="manufactureDate"
                   class="peer input pl-10 datepicker-input"
                   datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                   placeholder=" "
                   @disabled($context === 'view')
            >
            <label for="manufactureDate" class="wrapped-label">{{ __('equipments.manufacture_date') }}</label>

            @error('form.manufactureDate') <p class="text-error">{{ $message }}</p> @enderror
        </div>

        <div class="form-group datepicker-wrapper relative w-full">
            <input wire:model="form.expirationDate"
                   type="text"
                   name="expirationDate"
                   id="expirationDate"
                   class="peer input pl-10 datepicker-input"
                   placeholder=" "
                   @disabled($context === 'view')
            >
            <label for="expirationDate" class="wrapped-label">{{__('equipments.expiration_date')}}</label>

            @error('form.expirationDate')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group group">
            <input wire:model="form.modelNumber"
                   type="text"
                   name="modelNumber"
                   id="modelNumber"
                   placeholder=" "
                   class="peer input"
                   @disabled($context === 'view')
            >
            <label for="modelNumber" class="label">
                {{ __('equipments.model_number') }}
            </label>

            @error('form.modelNumber')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input wire:model="form.lotNumber"
                   type="text"
                   name="lotNumber"
                   id="lotNumber"
                   placeholder=" "
                   class="peer input"
                   @disabled($context === 'view')
            >
            <label for="lotNumber" class="label">
                {{ __('equipments.lot_number') }}
            </label>

            @error('form.lotNumber')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group group">
            <select wire:model="form.parentId"
                    name="parentId"
                    id="parentId"
                    class="peer input-select"
                    @disabled($context === 'view')
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach($equipments as $key => $equipment)
                    <option value="{{ $equipment['uuid'] }}">
                        {{ $equipment['name'] }} - {{ $equipment['type']->label() }}
                        - {{ $equipment['status']->label() }} - {{ $equipment['availabilityStatus']->label() }}
                    </option>
                @endforeach
            </select>
            <label for="parentId" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                {{ __('equipments.parent_id') }}
            </label>

            @error('form.parentId')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row">
        <div>
            <label for="note" class="label-modal">{{ __('forms.comment') }}</label>
            <textarea wire:model="form.note"
                      rows="4"
                      id="note"
                      name="note"
                      class="textarea"
                      placeholder="{{ __('forms.write_comment_here') }}"
                      @disabled($context === 'view')
            ></textarea>

            @error('form.note') <p class="text-error">{{ $message }}</p> @enderror
        </div>
    </div>
</fieldset>
