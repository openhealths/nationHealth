<div
    x-show="isEditModalOpen"
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    style="display: none"
    x-cloak
>
    <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity" @click="cancelEdit()"></div>

    <div class="relative z-10 max-h-[90vh] w-full max-w-4xl overflow-hidden overflow-y-auto rounded-lg border border-gray-200 bg-white p-8 shadow-xl dark:border-gray-700 dark:bg-gray-800">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('patients.edit_data') }}</h2>
            <p class="mt-1 text-sm font-semibold text-gray-500 dark:text-gray-400">ID {{ $form->person['uuid'] }}</p>
        </div>

        <div class="space-y-6">
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="border-b border-gray-200 bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-700/50 dark:text-gray-200">
                    {{ __('patients.main_info') }}
                </div>
                <div class="grid grid-cols-1 gap-6 bg-white p-6 md:grid-cols-3 dark:bg-gray-800">
                    <div class="relative border-b border-gray-300 pb-1 dark:border-gray-600">
                        <label for="editFirstName" class="block text-xs font-medium text-gray-400">
                            {{ __('forms.first_name') }}
                        </label>
                        <input
                            type="text"
                            id="editFirstName"
                            wire:model="form.person.firstName"
                            class="w-full border-0 bg-transparent p-0 text-gray-900 placeholder-gray-300 focus:ring-0 focus:outline-none dark:text-white"
                            placeholder="-"
                        />
                    </div>
                    <div class="relative border-b border-gray-300 pb-1 dark:border-gray-600">
                        <label for="editLastName" class="block text-xs font-medium text-gray-400">
                            {{ __('forms.last_name') }}
                        </label>
                        <input
                            type="text"
                            id="editLastName"
                            wire:model="form.person.lastName"
                            class="w-full border-0 bg-transparent p-0 text-gray-900 placeholder-gray-300 focus:ring-0 focus:outline-none dark:text-white"
                            placeholder="-"
                        />
                    </div>
                    <div class="relative border-b border-gray-300 pb-1 dark:border-gray-600">
                        <label for="editSecondName" class="block text-xs font-medium text-gray-400">
                            {{ __('forms.second_name') }}
                        </label>
                        <input
                            type="text"
                            id="editSecondName"
                            wire:model="form.person.secondName"
                            class="w-full border-0 bg-transparent p-0 text-gray-900 placeholder-gray-300 focus:ring-0 focus:outline-none dark:text-white"
                            placeholder="-"
                        />
                    </div>
                    <div class="relative border-b border-gray-300 pb-1 dark:border-gray-600">
                        <label for="editGender" class="block text-xs font-medium text-gray-400">
                            {{ __('forms.gender') }}
                        </label>
                        <select
                            id="editGender"
                            wire:model="form.person.gender"
                            class="w-full border-0 bg-transparent p-0 text-gray-900 focus:ring-0 focus:outline-none dark:text-white"
                        >
                            <option value="">{{ __('forms.select') }}</option>
                            @foreach (dictionary()->basics()->byName('GENDER')->asCodeDescription()->toArray() as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="relative flex items-end gap-2 border-b border-gray-300 pb-1 dark:border-gray-600">
                        <div class="grow">
                            <label for="editBirthDate" class="block text-xs font-medium text-gray-400">
                                {{ __('forms.birth_date') }}
                            </label>
                            <div class="datepicker-wrapper text-gray-900 dark:text-white">
                                <input
                                    type="text"
                                    id="editBirthDate"
                                    wire:model="form.person.birthDate"
                                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                    class="datepicker-input w-full border-0 bg-transparent p-0 pl-7 placeholder-gray-300 focus:ring-0 focus:outline-none"
                                    placeholder="-"
                                    autocomplete="off"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="border-b border-gray-200 bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-700/50 dark:text-gray-200">
                    {{ __('preperson.contact_person') }}
                </div>
                <div class="grid grid-cols-1 gap-6 bg-white p-6 md:grid-cols-3 dark:bg-gray-800">
                    <div class="relative border-b border-gray-300 pb-1 dark:border-gray-600">
                        <label for="editContactFirstName" class="block text-xs font-medium text-gray-400">
                            {{ __('forms.first_name') }}
                        </label>
                        <input
                            type="text"
                            id="editContactFirstName"
                            wire:model="form.person.emergencyContact.firstName"
                            class="w-full border-0 bg-transparent p-0 text-gray-900 placeholder-gray-300 focus:ring-0 focus:outline-none dark:text-white"
                            placeholder="-"
                        />
                    </div>
                    <div class="relative border-b border-gray-300 pb-1 dark:border-gray-600">
                        <label for="editContactLastName" class="block text-xs font-medium text-gray-400">
                            {{ __('forms.last_name') }}
                        </label>
                        <input
                            type="text"
                            id="editContactLastName"
                            wire:model="form.person.emergencyContact.lastName"
                            class="w-full border-0 bg-transparent p-0 text-gray-900 placeholder-gray-300 focus:ring-0 focus:outline-none dark:text-white"
                            placeholder="-"
                        />
                    </div>
                    <div class="relative border-b border-gray-300 pb-1 dark:border-gray-600">
                        <label for="editContactSecondName" class="block text-xs font-medium text-gray-400">
                            {{ __('forms.second_name') }}
                        </label>
                        <input
                            type="text"
                            id="editContactSecondName"
                            wire:model="form.person.emergencyContact.secondName"
                            class="w-full border-0 bg-transparent p-0 text-gray-900 placeholder-gray-300 focus:ring-0 focus:outline-none dark:text-white"
                            placeholder="-"
                        />
                    </div>
                    <div class="relative border-b border-gray-300 pb-1 dark:border-gray-600">
                        <label for="editContactPhoneType" class="block text-xs font-medium text-gray-400">
                            {{ __('forms.phone_type') }}
                        </label>
                        <select
                            id="editContactPhoneType"
                            wire:model="form.person.emergencyContact.phones.0.type"
                            class="w-full border-0 bg-transparent p-0 text-gray-900 focus:ring-0 focus:outline-none dark:text-white"
                        >
                            <option value="">{{ __('forms.select') }}</option>
                            @foreach ((dictionary()->basics()->getMultipleFormatted(['PHONE_TYPE'])->toArray()['PHONE_TYPE'] ?? []) as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="relative flex items-end gap-2 border-b border-gray-300 pb-1 dark:border-gray-600">
                        <div class="grow">
                            <label for="editContactPhone" class="block text-xs font-medium text-gray-400">
                                {{ __('forms.phone') }}
                            </label>
                            <div class="flex items-center gap-1.5 text-gray-900 dark:text-white">
                                @icon('tabler-phone', 'w-4 h-4 text-gray-400')
                                <input
                                    type="tel"
                                    id="editContactPhone"
                                    wire:model="form.person.emergencyContact.phones.0.number"
                                    x-mask="+380999999999"
                                    class="w-full border-0 bg-transparent p-0 placeholder-gray-300 focus:ring-0 focus:outline-none"
                                    placeholder="-"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 flex gap-4">
            <button type="button" @click="cancelEdit()" class="button-minor">{{ __('forms.back') }}</button>
            <button type="button" @click="confirmEdit()" class="button-primary min-w-37.5">
                {{ __('forms.save') }}
            </button>
        </div>
    </div>
</div>
