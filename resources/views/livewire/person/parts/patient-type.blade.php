@use('App\Models\Preperson')

<fieldset class="fieldset">
    <legend class="legend">
        {{ __('patients.patient_type') }}
    </legend>

    <div class="form-row-2">
        <div class="form-group">
            <select
                wire:model="patientType"
                name="patientType"
                id="patientType"
                class="input-select peer @error('patientType') input-error @enderror"
                required
            >
                <option value="person">{{ __('patients.identified') }}</option>
                @can('create', Preperson::class)
                    <option value="preperson">{{ __('patients.unidentified') }}</option>
                @endcan
            </select>
            <label for="patientType" class="label">
                {{ __('patients.patient_type') }}
            </label>
        </div>
    </div>
</fieldset>
