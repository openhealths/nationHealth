{{--
    Marking a conclusion as entered in error (TV 3.8.1.10, 3.8.2.15).

    The action is inseparable from signing it, so it reuses the shared KEP modal and adds
    the reason as a custom field rather than presenting two dialogs in sequence.
--}}
<x-signature-modal method="cancelComposition" :agreementText="__('patients.composition.cancel.warning_message')">
    <x-slot name="customFields">
        <div>
            <label for="cancel-reason" class="default-label">
                {{ __('patients.composition.cancel.reason_label') }} *
            </label>
            <select class="input-modal" wire:model="form.reason" name="cancel-reason" id="cancel-reason">
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->cancellationReasons as $code => $description)
                    <option value="{{ $code }}" wire:key="cancel-reason-{{ $code }}">{{ $description }}</option>
                @endforeach
            </select>

            @error('form.reason')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="cancel-reason-text" class="default-label">
                {{ __('patients.composition.cancel.justification_label') }} *
            </label>
            <textarea
                wire:model="form.reasonText"
                id="cancel-reason-text"
                name="cancel-reason-text"
                rows="3"
                class="default-input"
                placeholder="{{ __('patients.composition.cancel.justification_placeholder') }}"
            ></textarea>

            @error('form.reasonText')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </x-slot>
</x-signature-modal>
