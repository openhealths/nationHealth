@use('App\Enums\Person\AuthenticationMethod')

<fieldset class="fieldset">
    <legend class="legend">{{ __('forms.authentication') }}</legend>

    {{-- Patient authentication methods --}}
    <div class="form-row-2">
        <div class="form-group group">
            <label class="label" for="authorizeWith">{{ __('forms.auth_method') }}</label>
            <select
                wire:model="form.authorizeWith"
                id="authorizeWith"
                name="authorizeWith"
                class="input-select peer"
                type="text"
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($authMethods as $key => $authMethod)
                    <option value="{{ $authMethod['uuid'] }}">
                        {{ AuthenticationMethod::tryFrom($authMethod['type'])->label() }}
                        @if (!empty($authMethod['phone_number']))
                            ({{ $authMethod['phone_number'] }})
                        @endif
                    </option>
                @endforeach
            </select>

            @error('form.authorizeWith')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @empty($authMethods)
    <div class="mb-10 rounded-lg bg-red-100">
        <div class="flex items-center gap-2 p-4">
            @icon('alert-circle', 'w-5 h-5 text-red-700')
            <p class="font-semibold text-red-700">{{ __('forms.patient_has_no_auth_methods') }}</p>
        </div>
    </div>

    <a href="{{ route('persons.patient-data', [legalEntity(), $personId]) }}" class="button-primary gap-2">
        @icon('plus', 'w-4 h-4')
        {{ __('forms.new_auth_method') }}
    </a>
    @endif
</fieldset>
