<fieldset class="fieldset bg-white dark:bg-gray-800 !rounded-xl !shadow-none !border-gray-100 dark:!border-gray-700 !max-w-full !p-6 !mb-6">
    <legend class="legend">
        {{ __('forms.additional_info') }}
    </legend>

    <div class="form-row-2">
        <div class="form-group group">
            <label for="based_on" class="label">
                {{ __('care-plan.based_care_plan') }}
            </label>
            <select id="based_on" name="based_on" class="input-select peer" wire:model="form.based_on" :disabled="$isReadOnly ?? false">
                <option value="">{{ __('care-plan.choose_care_plan') }}</option>
            </select>
        </div>

        <div class="form-group group">
            <label for="part_of" class="label">
                {{ __('care-plan.part_care_plan') }}
            </label>
            <select id="part_of" name="part_of" class="input-select peer" wire:model="form.part_of" :disabled="$isReadOnly ?? false">
                <option value="">{{ __('care-plan.choose_care_plan') }}</option>
            </select>
        </div>
    </div>

    <div class="form-row mt-5">
        <div class="form-group">
            <label for="description" class="label mb-2 block">
                {{ __('care-plan.extended_description') }}
            </label>
            <textarea
                id="description"
                class="textarea !text-gray-500 dark:!text-gray-400 min-h-[100px]"
                placeholder="{{ __('forms.write_comment_here') }}"
                wire:model="form.description"
                :disabled="$isReadOnly ?? false">
            </textarea>
            @error('form.description') <p class="text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="form-row mt-5">
        <div class="form-group">
            <label for="note" class="label mb-2 block">
                {{ __('care-plan.notes') }}
            </label>
            <textarea
                id="note"
                class="textarea !text-gray-500 dark:!text-gray-400 min-h-[100px]"
                placeholder="{{ __('forms.write_comment_here') }}"
                wire:model="form.note"
                :disabled="$isReadOnly ?? false">
            </textarea>
            @error('form.note') <p class="text-error">{{ $message }}</p> @enderror
        </div>
    </div>
</fieldset>
