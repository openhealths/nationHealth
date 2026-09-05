<div>
    @use('App\Enums\Person\AuthStep')

    <legend class="legend mb-8 text-2xl font-bold">{{ __('patients.update_method_alias') }}</legend>

    @nonempty($this->uploadedDocuments)
        <div class="mb-8 flex items-start rounded-lg bg-gray-100 p-4 dark:bg-slate-800">
            @icon('alert-circle', 'w-5 h-5 text-gray-700 dark:text-gray-300 mr-3 mt-0.5')
            <p class="text-sm text-gray-800 dark:text-gray-200">{{ __('patients.load_person_documents') }}</p>
        </div>

        @foreach ($this->uploadedDocuments as $key => $document)
            <div class="flex pb-4" wire:key="{{ $key }}">
                <div class="flex-grow">
                    <label
                        class="mb-3 block text-sm font-medium text-gray-900 dark:text-white"
                        for="fileInput-{{ $key }}"
                    >
                        {{ __('patients.documents.' . Str::afterLast($document['type'], '.')) }}
                    </label>
                    <div class="flex items-center gap-4">
                        <input
                            type="file"
                            class="block cursor-pointer rounded-lg border border-gray-300 bg-gray-50 text-sm text-gray-900 focus:outline-none xl:w-1/2 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400 dark:placeholder-gray-400"
                            id="fileInput-{{ $key }}"
                            wire:model.live="form.uploadedDocuments.{{ $key }}"
                            accept=".jpeg,.jpg"
                        />
                    </div>

                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                        {{ __('forms.max_file_size_and_format') }}
                    </p>

                    @error("form.uploadedDocuments.$key")
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        @endforeach
    @endnonempty

    @empty($this->uploadedDocuments)
        <div
            class="form-row-3 mt-4"
            x-data="{
                timer: 60,
                init() {
                    setInterval(() => {
                        if (this.timer > 0) this.timer--;
                    }, 1000);
                },
                resetTimer() {
                    if (this.timer === 0) {
                        this.timer = 60;
                        $wire.resendCode();
                    }
                },
            }"
        >
            <div class="form-group group">
                <input
                    type="text"
                    wire:model="verificationCode"
                    inputmode="numeric"
                    name="verificationCode"
                    id="verificationCode"
                    class="peer input"
                    placeholder=" "
                    autocomplete="off"
                />
                <label for="verificationCode" class="label"> {{ __('patients.code_sms') }} </label>
            </div>

            <button type="button" @click="resetTimer()" :disabled="timer > 0" class="button-minor">
                @icon('mail', 'w-4 h-4 mr-2')
                <span>{{ __('forms.send_again') }}</span>
                <template x-if="timer > 0">
                    <span x-text="`(${timer}c)`"></span>
                </template>
            </button>
        </div>
    @endempty

    <div class="mt-8 flex gap-3">
        <button type="button" @click="localStep = {{ AuthStep::CHANGE_ALIAS }}" class="button-minor">
            {{ __('forms.back') }}
        </button>

        <button type="button" wire:click="approveUpdatingAlias" class="button-primary">{{ __('forms.update') }}</button>
    </div>
</div>
