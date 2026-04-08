<fieldset class="fieldset"
          x-data="{ coAuthors: $wire.entangle('form.coAuthors') }"
          x-init="if (!Array.isArray(coAuthors)) { coAuthors = [] }">
    <legend class="legend">
        <h2>{{ __('Лікарі') }}</h2>
    </legend>

    <div class="form">
        <div class="form-row-2">
            <div class="form-group">
                <input type="text"
                       wire:model="form.author"
                       name="author"
                       id="author"
                       class="peer input text-gray-500"
                       placeholder=" "
                       required>
                <label for="author" class="label">
                    {{ __('care-plan.author') }}
                </label>
                @error('form.author') <p class="text-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="space-y-4">
            <template x-for="(coAuthor, index) in coAuthors" :key="index">
                <div class="form-row-2 flex items-center gap-4">
                    <div class="form-group flex-1">
                        <select x-model="coAuthors[index]"
                                class="input-select peer"
                                :id="'coAuthor_' + index">
                            <option value="">{{ __('care-plan.find_doctor') }}</option>
                            @foreach($doctors as $doctor)
                                <option value="{{ $doctor['uuid'] }}">{{ $doctor['name'] }}</option>
                            @endforeach
                        </select>
                        <label :for="'coAuthor_' + index" class="label">
                            {{ __('care-plan.co-author') }}
                        </label>

                        <button type="button"
                                @click="coAuthors.splice(index, 1)"
                                class="absolute -right-8 top-3 text-red-500 hover:text-red-700">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <div class="mt-4">
            <button type="button"
                    @click="coAuthors.push('')"
                    class="flex items-center text-blue-600 hover:text-blue-800 font-medium transition-colors">
                <span class="text-xl mr-2">+</span>
                <span>{{ __('Додати співавтора') }}</span>
            </button>
        </div>
    </div>
</fieldset>
