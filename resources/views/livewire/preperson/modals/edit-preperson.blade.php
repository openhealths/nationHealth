<div
    x-show="isEditModalOpen"
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    style="display: none;"
    x-cloak
>
    <div
        class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity"
        @click="isEditModalOpen = false"
    ></div>

    <div
        class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl overflow-hidden max-w-4xl w-full p-8 max-h-[90vh] overflow-y-auto z-10 border border-gray-200 dark:border-gray-700">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ __('patients.edit_data') }}
            </h2>
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400 mt-1">
                ID {{ $form->person['uuid'] }}
            </p>
        </div>

        <div class="space-y-6">
            <div
                class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                <div
                    class="bg-gray-50 dark:bg-gray-700/50 px-4 py-3 border-b border-gray-200 dark:border-gray-700 font-semibold text-gray-700 dark:text-gray-200 text-sm">
                    {{ __('patients.main_info') }}
                </div>
                <div
                    class="p-6 bg-white dark:bg-gray-800 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div
                        class="relative border-b border-gray-300 dark:border-gray-600 pb-1">
                        <label class="block text-xs font-medium text-gray-400">
                            {{ __('forms.first_name') }}
                        </label>
                        <input
                            type="text"
                            wire:model="form.person.firstName"
                            class="w-full bg-transparent border-0 p-0 text-gray-900 dark:text-white focus:ring-0 focus:outline-none placeholder-gray-300"
                            placeholder="-"
                        >
                    </div>
                    <div
                        class="relative border-b border-gray-300 dark:border-gray-600 pb-1">
                        <label class="block text-xs font-medium text-gray-400">
                            {{ __('forms.last_name') }}
                        </label>
                        <input
                            type="text"
                            wire:model="form.person.lastName"
                            class="w-full bg-transparent border-0 p-0 text-gray-900 dark:text-white focus:ring-0 focus:outline-none placeholder-gray-300"
                            placeholder="-"
                        >
                    </div>
                    <div
                        class="relative border-b border-gray-300 dark:border-gray-600 pb-1">
                        <label class="block text-xs font-medium text-gray-400">
                            {{ __('forms.second_name') }}
                        </label>
                        <input
                            type="text"
                            wire:model="form.person.secondName"
                            class="w-full bg-transparent border-0 p-0 text-gray-900 dark:text-white focus:ring-0 focus:outline-none placeholder-gray-300"
                            placeholder="-"
                        >
                    </div>
                    <div
                        class="relative border-b border-gray-300 dark:border-gray-600 pb-1">
                        <label class="block text-xs font-medium text-gray-400">
                            {{ __('forms.gender') }}
                        </label>
                        <select
                            wire:model="form.person.gender"
                            class="w-full bg-transparent border-0 p-0 text-gray-900 dark:text-white focus:ring-0 focus:outline-none"
                        >
                            <option value="">
                                {{ __('forms.select') }}
                            </option>
                            @foreach((dictionary()->basics()->getMultipleFormatted(['GENDER'])->toArray()['GENDER'] ?? []) as $key => $label)
                                <option value="{{ $key }}">
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div
                        class="relative border-b border-gray-300 dark:border-gray-600 pb-1 flex items-end gap-2">
                        <div class="grow">
                            <label class="block text-xs font-medium text-gray-400">
                                {{ __('forms.birth_date') }}
                            </label>
                            <div
                                class="flex items-center gap-1.5 text-gray-900 dark:text-white">
                                @icon('calendar', 'w-4 h-4 text-gray-400')
                                <input
                                    type="text"
                                    wire:model="form.person.birthDate"
                                    class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:outline-none placeholder-gray-300"
                                    placeholder="-"
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                <div
                    class="bg-gray-50 dark:bg-gray-700/50 px-4 py-3 border-b border-gray-200 dark:border-gray-700 font-semibold text-gray-700 dark:text-gray-200 text-sm">
                    {{ __('preperson.contact_person') }}
                </div>
                <div
                    class="p-6 bg-white dark:bg-gray-800 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div
                        class="relative border-b border-gray-300 dark:border-gray-600 pb-1">
                        <label class="block text-xs font-medium text-gray-400">
                            {{ __('forms.first_name') }}
                        </label>
                        <input
                            type="text"
                            wire:model="form.person.emergencyContact.firstName"
                            class="w-full bg-transparent border-0 p-0 text-gray-900 dark:text-white focus:ring-0 focus:outline-none placeholder-gray-300"
                            placeholder="-"
                        >
                    </div>
                    <div
                        class="relative border-b border-gray-300 dark:border-gray-600 pb-1">
                        <label class="block text-xs font-medium text-gray-400">
                            {{ __('forms.last_name') }}
                        </label>
                        <input
                            type="text"
                            wire:model="form.person.emergencyContact.lastName"
                            class="w-full bg-transparent border-0 p-0 text-gray-900 dark:text-white focus:ring-0 focus:outline-none placeholder-gray-300"
                            placeholder="-"
                        >
                    </div>
                    <div
                        class="relative border-b border-gray-300 dark:border-gray-600 pb-1">
                        <label class="block text-xs font-medium text-gray-400">
                            {{ __('forms.second_name') }}
                        </label>
                        <input
                            type="text"
                            wire:model="form.person.emergencyContact.secondName"
                            class="w-full bg-transparent border-0 p-0 text-gray-900 dark:text-white focus:ring-0 focus:outline-none placeholder-gray-300"
                            placeholder="-"
                        >
                    </div>
                    <div
                        class="relative border-b border-gray-300 dark:border-gray-600 pb-1">
                        <label class="block text-xs font-medium text-gray-400">
                            {{ __('forms.phone_type') }}
                        </label>
                        <select
                            wire:model="form.person.emergencyContact.phones.0.type"
                            class="w-full bg-transparent border-0 p-0 text-gray-900 dark:text-white focus:ring-0 focus:outline-none"
                        >
                            <option value="">
                                {{ __('forms.select') }}
                            </option>
                            @foreach((dictionary()->basics()->getMultipleFormatted(['PHONE_TYPE'])->toArray()['PHONE_TYPE'] ?? []) as $key => $label)
                                <option value="{{ $key }}">
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div
                        class="relative border-b border-gray-300 dark:border-gray-600 pb-1 flex items-end gap-2">
                        <div class="grow">
                            <label class="block text-xs font-medium text-gray-400">
                                {{ __('forms.phone') }}
                            </label>
                            <div
                                class="flex items-center gap-1.5 text-gray-900 dark:text-white">
                                @icon('tabler-phone', 'w-4 h-4 text-gray-400')
                                <input
                                    type="text"
                                    wire:model="form.person.emergencyContact.phones.0.number"
                                    class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:outline-none placeholder-gray-300"
                                    placeholder="-"
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 flex gap-4">
            <button
                type="button"
                @click="isEditModalOpen = false"
                class="button-minor"
            >
                {{ __('forms.back') }}
            </button>
            <button
                type="button"
                @click="$wire.saveEdit($wire.get('editingId')).then(() => { if (! $wire.get('editingId')) isEditModalOpen = false })"
                class="button-primary min-w-37.5"
            >
                {{ __('forms.save') }}
            </button>
        </div>
    </div>
</div>
