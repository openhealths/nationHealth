<div class="space-y-8">
    <div class="form-row-2">
        <div class="form-group group">
            <select
                wire:model="form.encounter.hospitalization.admitSource"
                id="hospitalizationAdmitSource"
                class="input-select peer @error('form.encounter.hospitalization.admitSource') input-error @enderror"
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['eHealth/encounter_admit_source'] as $key => $admitSource)
                    <option value="{{ $key }}">{{ $admitSource }}</option>
                @endforeach
            </select>
            <label
                for="hospitalizationAdmitSource"
                class="label"
                :class="$wire.form.encounter.typeCode === 'discharge' && 'required'"
            >
                {{ __('encounters.hospitalization.admit_source') }}
            </label>
            @error('form.encounter.hospitalization.admitSource')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <select
                wire:model="form.encounter.hospitalization.reAdmission"
                id="hospitalizationReAdmission"
                class="input-select peer @error('form.encounter.hospitalization.reAdmission') input-error @enderror"
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['eHealth/encounter_re_admission'] as $key => $reAdmission)
                    <option value="{{ $key }}">{{ $reAdmission }}</option>
                @endforeach
            </select>
            <label for="hospitalizationReAdmission" class="label">
                {{ __('encounters.hospitalization.re_admission') }}
            </label>
            @error('form.encounter.hospitalization.reAdmission')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group group">
            <div class="relative">
                <label
                    for="hospitalizationPreAdmissionIdentifier"
                    class="absolute inset-x-0 bottom-[calc(100%-0.1875rem)] text-[0.65625rem] leading-[0.9375rem] text-gray-500 group-focus-within:font-medium group-focus-within:text-blue-600 dark:text-gray-400 dark:group-focus-within:text-blue-500"
                >
                    {{ __('encounters.hospitalization.pre_admission_identifier') }}
                </label>
                <input
                    wire:model="form.encounter.hospitalization.preAdmissionIdentifier"
                    type="text"
                    id="hospitalizationPreAdmissionIdentifier"
                    class="input @error('form.encounter.hospitalization.preAdmissionIdentifier') input-error @enderror"
                />
            </div>
            @error('form.encounter.hospitalization.preAdmissionIdentifier')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <select
                wire:model="form.encounter.hospitalization.destination"
                id="hospitalizationDestination"
                class="input-select peer @error('form.encounter.hospitalization.destination') input-error @enderror"
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($destinationLegalEntities as $key => $destination)
                    <option value="{{ $key }}">{{ $destination }}</option>
                @endforeach
            </select>
            <label
                for="hospitalizationDestination"
                class="label"
                :class="$wire.form.encounter.hospitalization.dischargeDisposition === 'transfer_general' && 'required'"
            >
                {{ __('encounters.hospitalization.destination') }}
            </label>
            @error('form.encounter.hospitalization.destination')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group group">
            <select
                wire:model="form.encounter.hospitalization.dischargeDisposition"
                id="hospitalizationDischargeDisposition"
                class="input-select peer @error('form.encounter.hospitalization.dischargeDisposition') input-error @enderror"
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['eHealth/encounter_discharge_disposition'] as $key => $dischargeDisposition)
                    <option value="{{ $key }}">{{ $dischargeDisposition }}</option>
                @endforeach
            </select>
            <label
                for="hospitalizationDischargeDisposition"
                class="label"
                :class="$wire.form.encounter.typeCode === 'discharge' && 'required'"
            >
                {{ __('encounters.hospitalization.discharge_disposition') }}
            </label>
            @error('form.encounter.hospitalization.dischargeDisposition')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <select
                wire:model="form.encounter.hospitalization.dischargeDepartment"
                id="hospitalizationDischargeDepartment"
                class="input-select peer @error('form.encounter.hospitalization.dischargeDepartment') input-error @enderror"
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['eHealth/encounter_discharge_department'] as $key => $dischargeDepartment)
                    <option value="{{ $key }}">{{ $dischargeDepartment }}</option>
                @endforeach
            </select>
            <label
                for="hospitalizationDischargeDepartment"
                class="label"
                :class="$wire.form.encounter.typeCode === 'discharge' && 'required'"
            >
                {{ __('encounters.hospitalization.discharge_department') }}
            </label>
            @error('form.encounter.hospitalization.dischargeDepartment')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>
</div>
