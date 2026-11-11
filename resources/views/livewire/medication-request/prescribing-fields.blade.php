<div class="form-row-2">
    <div class="form-group">
        <label class="label">{{ __('medication-requests.dose_ingredient') }}</label>
        <select class="input-select w-full" wire:model.live="{{ $formName }}.dose_ingredient_id">
            @foreach ($drug['ingredients'] ?? [] as $ingredient)
                <option value="{{ $ingredient['id'] }}">{{ $ingredient['name'] ?? $ingredient['id'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label class="label">{{ __('medication-requests.dose_input_unit') }}</label>
        <select class="input-select w-full" wire:model.live="{{ $formName }}.dose_input_unit">
            <option value="denominator">
                {{ __('medication-requests.dose_denominator') }} ({{ $values['medication_unit'] ?? '' }})
            </option>
            <option value="numerator">{{ __('medication-requests.dose_numerator') }}</option>
        </select>
    </div>
    @foreach ($ingredientDoses as $ingredientDose)
        <p class="text-sm">
            {{ $ingredientDose['name'] }}: {{ $ingredientDose['value'] }} {{ $ingredientDose['unit'] }} / {{ __('medication-requests.per_day') }}
        </p>
    @endforeach
    <div class="form-group">
        <label class="label">{{ __('medication-requests.course_duration') }}</label>
        <input type="number" min="1" step="1" class="input w-full" wire:model.live="{{ $formName }}.duration" />
        @error($formName.'.duration')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>
    @if ($containers !== [])
        <div class="form-group">
            <label class="label">{{ __('medication-requests.primary_container') }}</label>
            <select class="input-select w-full" wire:model="{{ $formName }}.container_dosage">
                <option value="">{{ __('medication-requests.select_container') }}</option>
                @foreach ($containers as $container)
                    <option value="{{ $container['value'] }}">{{ $container['label'] }}</option>
                @endforeach
            </select>
            @error($formName.'.container_dosage')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    @endif
    <label class="flex items-center gap-2 md:col-span-2">
        <input type="checkbox" wire:model="{{ $formName }}.dose_warning_confirmed" />
        {{ __('medication-requests.dose_confirmed') }}
    </label>
    @error($formName.'.dose_warning_confirmed')
        <p class="text-error md:col-span-2">{{ $message }}</p>
    @enderror
    @if (!$notificationsDisabled)
        <label class="flex items-center gap-2 md:col-span-2">
            <input type="checkbox" wire:model="{{ $formName }}.phone_confirmed" />
            {{ __('medication-requests.phone_confirmed') }}
        </label>
        @error($formName.'.phone_confirmed')
            <p class="text-error md:col-span-2">{{ $message }}</p>
        @enderror
        <p class="text-sm md:col-span-2">{{ __('medication-requests.phone_confirmation_required') }}</p>
    @endif
</div>
