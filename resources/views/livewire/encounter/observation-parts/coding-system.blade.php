<fieldset class="fieldset">
    <legend class="legend">
        {{ __('patients.coding_system') }}
    </legend>

    <div class="form-row-3">
        <div class="flex items-center">
            <input x-model="modalObservation.codingSystem"
                   id="loincDictionary"
                   type="radio"
                   value="loinc"
                   name="loinc"
                   class="default-radio"
                   :checked="modalObservation.codingSystem === 'loinc'"
            >
            <label for="loincDictionary" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                {{ __('dictionaries.loinc_observation_dictionary') }}
            </label>
        </div>

        <div class="flex items-center">
            <input x-model="modalObservation.codingSystem"
                   id="icfDictionary"
                   type="radio"
                   value="icf"
                   name="icf"
                   class="default-radio"
                   :checked="modalObservation.codingSystem === 'icf'"
            >
            <label for="icfDictionary" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                {{ __('dictionaries.icf_dictionary_condition_patient') }}
            </label>
        </div>
    </div>
</fieldset>
