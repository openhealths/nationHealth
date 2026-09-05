@use('App\Enums\Person\AuthStep')

<div x-data="{ alias: '' }">
    <legend class="legend mt-6">{{ __('patients.authentication_method_name') }}</legend>

    <div class="form-row-3 mt-4">
        <div class="form-group group">
            <input type="text"
                   x-model="alias"
                   name="alias"
                   id="alias"
                   class="peer input"
                   placeholder=" "
                   autocomplete="off"
            />
            <label for="alias" class="label">
                {{ __('patients.alias') }}
            </label>
        </div>
    </div>

    <div class="flex gap-4">
        <button type="button" @click="localStep = {{ AuthStep::ADD_NEW_BY_THIRD_PERSON }}" class="button-minor">
            {{ __('forms.back') }}
        </button>

        <button type="button" @click="$wire.addAuthMethodFromRelation(alias)" class="button-primary">
            {{ __('forms.confirm') }}
        </button>
    </div>
</div>
