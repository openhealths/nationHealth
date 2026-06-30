<fieldset class="fieldset">
    <legend class="legend">
        {{ __('patients.patient_type') }}
    </legend>

    <div class="form-row-2">
        <div class="form-group">
            <select
                wire:model="form.patientType"
                name="patientType"
                id="patientType"
                class="input-select peer @error('form.patientType') input-error @enderror"
                required
            >
                <option value="person">{{ __('patients.identified') }}</option>
                <option value="preperson">{{ __('patients.unidentified') }}</option>
            </select>
            <label for="patientType" class="label">
                {{ __('patients.patient_type') }}
            </label>
        </div>
    </div>
</fieldset>
