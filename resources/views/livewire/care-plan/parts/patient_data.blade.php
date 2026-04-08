<fieldset class="fieldset">
    <legend class="legend">
        {{ __('care-plan.patient_data') }}
    </legend>

    <div class="form-row-2">
        <div class="form-group group relative" x-data="{ open: true }">
            <input type="text"
                   name="patient"
                   id="patient"
                   class="input-select peer"
                   placeholder=" "
                   autocomplete="off"
                   wire:model.live.debounce.300ms="form.patient"
                   @focus="open = true"
                   required
            >

            <label for="patient" class="label">
                {{ __('care-plan.patient') }}
            </label>

            @if(!empty($patientSuggestions))
                <div x-show="open"
                     @click.away="open = false"
                     class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg max-h-60 overflow-auto"
                >
                    @foreach($patientSuggestions as $suggestion)
                        <div wire:click="selectPatient('{{ $suggestion['uuid'] }}', '{{ $suggestion['name'] }}')"
                             @click="open = false"
                             class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer border-b border-gray-50 dark:border-gray-700 last:border-0"
                        >
                            <div class="font-medium text-gray-900 dark:text-white">{{ $suggestion['name'] }}</div>
                            <div class="text-xs text-gray-500">{{ __('forms.rnokpp') }}: {{ $suggestion['tax_id'] ?? '-' }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            @error('form.patient')
            <p class="text-error" id="error-form-patient">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input type="text"
                   name="medical_number"
                   id="medical_number"
                   class="input-select peer"
                   placeholder=" "
                   autocomplete="off"
                   wire:model="form.medical_number"
                   required
            >

            <label for="medical_number" class="label">
                {{ __('care-plan.medical_number') }}
            </label>
            @error('form.medical_number')
            <p class="text-error" id="error-form-medical_number">{{ $message }}</p>
            @enderror
        </div>
    </div>
</fieldset>
