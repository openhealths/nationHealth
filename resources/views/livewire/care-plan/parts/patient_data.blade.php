<div class="form-row-2">
    <div class="form-group group relative" x-data="{ open: false }">
        <input type="text"
               name="patient"
               id="patient"
               class="input-select peer bg-gray-50"
               placeholder=" "
               autocomplete="off"
               wire:model="form.patient"
               readonly
               required
        >

        <label for="patient" class="label">
            {{ __('care-plan.patient') }}
        </label>

        @error('form.patient')
        <p class="text-error" id="error-form-patient">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <input type="text"
               name="medical_number"
               id="medical_number"
               class="input-select peer bg-gray-50"
               placeholder=" "
               autocomplete="off"
               wire:model="form.medical_number"
               readonly
               required
        >

        <label for="medical_number" class="label">
            {{ __('care-plan.medical_number') ?? 'Медичний запис №' }}
        </label>
        @error('form.medical_number')
        <p class="text-error" id="error-form-medical_number">{{ $message }}</p>
        @enderror
    </div>
</div>

<div class="form-row-2 mt-5">
    <div class="form-group group">
        <label for="encounter_select" class="label">
            {{ __('care-plan.encounter') ?? 'Взаємодія' }}
        </label>
        <select id="encounter_select"
                name="encounter_select"
                class="input-select peer"
                wire:model="form.encounter"
        >
            <option value="">{{ __('forms.select') }} ...</option>
            @foreach($availableEncounters as $enc)
                <option value="{{ $enc['uuid'] }}">{{ $enc['label'] }}</option>
            @endforeach
        </select>
        @if(empty($availableEncounters))
            <p class="text-sm text-amber-600 mt-1">
                ⚠️ {{ __('care-plan.no_ehealth_encounters') ?? 'У пацієнта немає підтверджених ЕСОЗ взаємодій. Спочатку створіть та підпишіть взаємодію.' }}
            </p>
        @endif
        @error('form.encounter')
        <p class="text-error">{{ $message }}</p>
        @enderror
    </div>
</div>

