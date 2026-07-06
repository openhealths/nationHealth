<x-dialog-drawer
    x-model="showMergeAuthDrawer"
    onCloseClick="showMergeAuthDrawer = false"
    maxWidth="4/5"
>
    <x-slot name="title">
        {{ __('preperson.merge.auth_methods') }}
    </x-slot>

    <div x-data="{
        openDropdown: false,
        get maskedPhone() {
            const phone = selectedMergePatient?.phones?.[0]?.number || '+38095123xxxxx';
            if (phone.includes('xxxxx')) return phone;
            if (phone.length > 5) {
                return phone.substring(0, phone.length - 5) + 'xxxxx';
            }
            return phone;
        }
    }">

    <div class="mt-8 space-y-4">
        <div x-show="currentMethod === 'SMS'" class="fieldset border dark:border-white p-6 rounded-xl space-y-4 relative bg-gray-50/50 dark:bg-gray-800/50">
            <div class="flex items-start justify-between">
                <div>
                    <h4 class="text-lg text-gray-900 dark:text-white font-bold mb-4">
                        {{ __('preperson.merge.auth_via_sms') }}
                    </h4>

                    <div class="space-y-4">
                        <div>
                            <p class="text-sm font-medium text-gray-400 dark:text-gray-500">
                                {{ __('preperson.merge.auth_method_name') }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-400 dark:text-gray-500">
                                {{ __('forms.phone_number') }}
                            </p>
                            <p class="text-base font-semibold text-gray-900 dark:text-gray-100 mt-1" x-text="maskedPhone">
                            </p>
                        </div>
                    </div>
                </div>

                <div class="relative inline-block text-left">
                    <button type="button"
                            class="button-primary"
                            @click="openDropdown = !openDropdown"
                    >
                        <span>{{ __('forms.select') }}</span>
                    </button>

                    <div x-show="openDropdown"
                         @click.away="openDropdown = false"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="transform opacity-0 scale-95"
                         x-transition:enter-end="transform opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="transform opacity-100 scale-100"
                         x-transition:leave-end="transform opacity-0 scale-95"
                         style="display: none"
                         class="absolute right-0 z-50 mt-2 w-72 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-xl p-1"
                    >
                        <button type="button"
                                class="cursor-pointer w-full text-left px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 rounded text-gray-700 dark:text-gray-200 transition-colors"
                                @click="currentMethod = 'SMS'; openDropdown = false"
                        >
                            {{ __('preperson.merge.auth_via_sms') }}
                        </button>
                        <button type="button"
                                class="cursor-pointer w-full text-left px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 rounded text-gray-700 dark:text-gray-200 transition-colors"
                                @click="currentMethod = 'documents'; openDropdown = false"
                        >
                            {{ __('preperson.merge.auth_via_documents') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="currentMethod === 'documents'" class="fieldset border dark:border-white p-6 rounded-xl space-y-4 relative bg-gray-50/50 dark:bg-gray-800/50">
            <div class="flex items-start justify-between">
                <div>
                    <h4 class="text-lg text-gray-900 dark:text-white font-bold mb-4">
                        {{ __('preperson.merge.auth_via_documents') }}
                    </h4>

                    <div class="space-y-4">
                        <div>
                            <p class="text-sm font-medium text-gray-400 dark:text-gray-500">
                                {{ __('preperson.merge.auth_method_name') }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="relative inline-block text-left">
                    <button type="button"
                            class="button-primary"
                            @click="openDropdown = !openDropdown"
                    >
                        <span>{{ __('forms.select') }}</span>
                    </button>

                    <div x-show="openDropdown"
                         @click.away="openDropdown = false"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="transform opacity-0 scale-95"
                         x-transition:enter-end="transform opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="transform opacity-100 scale-100"
                         x-transition:leave-end="transform opacity-0 scale-95"
                         style="display: none"
                         class="absolute right-0 z-50 mt-2 w-72 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-xl p-1"
                    >
                        <button type="button"
                                class="cursor-pointer w-full text-left px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 rounded text-gray-700 dark:text-gray-200 transition-colors"
                                @click="currentMethod = 'SMS'; openDropdown = false"
                        >
                            {{ __('preperson.merge.auth_via_sms') }}
                        </button>
                        <button type="button"
                                class="cursor-pointer w-full text-left px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 rounded text-gray-700 dark:text-gray-200 transition-colors"
                                @click="currentMethod = 'documents'; openDropdown = false"
                        >
                            {{ __('preperson.merge.auth_via_documents') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex gap-3 mt-8">
        <button class="button-minor"
                type="button"
                @click="showMergeAuthDrawer = false; showMergePatientDrawer = true"
        >
            {{ __('forms.back') }}
        </button>

        <button class="button-primary"
                type="button"
                @click="showMergeAuthDrawer = false; showMergeConfirmationDrawer = true"
        >
            {{ __('forms.next') }}
        </button>
    </div>
</x-dialog-drawer>
