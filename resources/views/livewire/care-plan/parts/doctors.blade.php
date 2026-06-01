<div x-data="{ coAuthors: $wire.entangle('form.coAuthors') }"
     x-init="if (!Array.isArray(coAuthors)) { coAuthors = [] }">
    <div class="form-row-2">
        <div class="form-group group">
            <input type="text"
                   wire:model="form.author"
                   name="author"
                   id="author"
                   class="peer input text-gray-500"
                   placeholder=" "
                   readonly
                   required>
            <label for="author" class="label">
                {{ __('care-plan.author') === 'care-plan.author' ? 'Автор' : __('care-plan.author') }}
            </label>
            @error('form.author') <p class="text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="space-y-6 mt-4">
        <template x-for="(coAuthor, index) in coAuthors" :key="index">
            <div class="form-row flex items-center gap-4">
                <div class="form-group group flex-1">
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
                            class="absolute -right-8 top-3 text-red-500 hover:text-red-700 transition-colors">
                        @icon('delete', 'w-5 h-5')
                    </button>
                </div>
            </div>
        </template>
    </div>

    <div class="mt-4">
        <button type="button"
                @click="coAuthors.push('')"
                class="flex items-center text-blue-600 hover:text-blue-800 font-semibold transition-colors group">
            <div class="w-8 h-8 rounded-full bg-blue-50 group-hover:bg-blue-100 flex items-center justify-center mr-3 transition-colors">
                <span class="text-xl">+</span>
            </div>
            <span>{{ __('Додати співавтора') }}</span>
        </button>
    </div>
</div>
