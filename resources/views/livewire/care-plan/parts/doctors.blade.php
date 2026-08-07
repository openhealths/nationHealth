<fieldset
    class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800"
    x-data="{ coAuthors: $wire.entangle('form.coAuthors') }"
    x-init="
        if (! Array.isArray(coAuthors)) {
            coAuthors = [];
        }
    "
>
    <legend class="legend">{{ __('care-plan.doctors') ?? 'Лікарі' }}</legend>

    <div class="form">
        <div class="form-row-2">
            <div class="form-group group">
                <input
                    type="text"
                    wire:model="form.author"
                    name="author"
                    id="author"
                    class="input peer"
                    placeholder=" "
                    required
                />
                <label for="author" class="label"> {{ __('care-plan.author') ?? 'Автор' }} </label>
                @error('form.author')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="mt-4 space-y-4">
            <template x-for="(coAuthor, index) in coAuthors" :key="index">
                <div class="form-row-2 flex items-center gap-4">
                    <div class="form-group group relative flex-1">
                        <select x-model="coAuthors[index]" class="input-select peer" :id="'coAuthor_' + index">
                            <option value="">{{ __('care-plan.find_doctor') ?? 'Оберіть лікаря' }}</option>
                            @foreach ($doctors as $doctor)
                                <option value="{{ $doctor['uuid'] }}">{{ $doctor['name'] }}</option>
                            @endforeach
                        </select>
                        <label :for="'coAuthor_' + index" class="label">
                            {{ __('care-plan.co-author') ?? 'Співавтор' }}
                        </label>

                        <button
                            type="button"
                            @click="coAuthors.splice(index, 1)"
                            class="absolute top-3 -right-8 text-red-500 hover:text-red-700"
                        >
                            @icon('delete', 'w-5 h-5')
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <div class="mt-4">
            <button
                type="button"
                @click="coAuthors.push('')"
                class="flex items-center font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400"
            >
                <span class="mr-2 text-xl">+</span>
                <span>{{ __('care-plan.add_coauthor') ?? 'Додати співавтора' }}</span>
            </button>
        </div>
    </div>
</fieldset>
