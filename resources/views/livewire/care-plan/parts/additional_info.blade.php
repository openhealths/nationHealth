<div class="form-row">
    <div class="form-group">
        <label for="description"
               class="peer appearance-none bg-white">{{ __('care-plan.extended_description') }}</label>
        <textarea
            id="description"
            class="textarea !text-gray-500 dark:!text-gray-400 mt-1"
            placeholder="{{ __('forms.comment') }}"
            wire:model="form.description">
        </textarea>
        @error('form.description') <p class="text-error">{{ $message }}</p> @enderror
    </div>
</div>

<div class="form-row">
    <div class="form-group">
        <label for="note"
               class="peer appearance-none bg-white">{{ __('care-plan.notes') }}</label>
        <textarea
            id="note"
            class="textarea !text-gray-500 dark:!text-gray-400 mt-1"
            placeholder="{{ __('forms.comment') }}"
            wire:model="form.note">
        </textarea>
        @error('form.note') <p class="text-error">{{ $message }}</p> @enderror
    </div>
</div>

