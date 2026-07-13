@use('App\Enums\Person\AuthenticationMethod')

<x-dialog-drawer
    x-model="showMergeConfirmationDrawer"
    onCloseClick="showMergeConfirmationDrawer = false"
    maxWidth="4/5"
>
    <x-slot name="title">
        {{ __('preperson.merge.confirm_title', ['uuid' => $prepersonUuid]) }}
    </x-slot>

    <div x-data="{ consent: false }">
        <div class="mt-8 space-y-6">
            <div
                class="p-6 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-700/50 flex gap-3.5">
                @icon('alert-circle', 'w-5 h-5 text-gray-500 dark:text-gray-400 shrink-0 mt-0.5')
                <div class="space-y-3.5 text-sm text-gray-755 dark:text-gray-300">
                    <p class="font-bold text-gray-900 dark:text-white flex items-center">
                        {{ __('declarations.medical_worker_confirmation') }}
                    </p>
                    <div class="space-y-1">
                        <p class="leading-relaxed">- {{ __('declarations.patient_identified') }}</p>
                        <p class="leading-relaxed">- {{ __('preperson.merge.confirm_selected_correctly') }}</p>
                        <p class="leading-relaxed">- {{ __('preperson.merge.confirm_representative') }}</p>
                    </div>
                </div>
            </div>

            <div
                class="p-6 rounded-xl bg-blue-50 dark:bg-blue-950/20 border border-blue-100 dark:border-blue-900/30 flex flex-col gap-4">
                <div class="flex items-center gap-2">
                    @icon('alert-circle', 'w-5 h-5 text-blue-600 dark:text-blue-400')
                    <h4 class="font-bold text-blue-800 dark:text-blue-300 text-sm tracking-wider">
                        {{ __('declarations.patient_memo') }}
                    </h4>
                </div>
                <div class="space-y-3 text-blue-700 dark:text-blue-300 text-sm leading-relaxed">
                    <p class="font-semibold">
                        {{ __('declarations.sms_or_documents_note') }}
                    </p>
                    <div class="space-y-1">
                        <p>- {{ __('preperson.merge.memo_point_1') }}</p>
                        <p>- {{ __('preperson.merge.memo_point_2') }}</p>
                    </div>
                </div>
            </div>

            @if(data_get($this->selectedAuthMethod, 'type') === AuthenticationMethod::OFFLINE->value)
                <div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer transition-colors"
                        @click="window.open('https://ehealth.gov.ua/privacy_merge.html', '_blank')"
                    >
                        @icon('printer', 'w-4 h-4 text-gray-500 dark:text-gray-400')
                        <span>{{ __('preperson.merge.print_memo') }}</span>
                    </button>
                </div>
            @endif

            <div class="flex items-center gap-3 py-2">
                <input type="checkbox" x-model="consent" id="patientConsent" class="default-checkbox">
                <label
                    for="patientConsent"
                    class="text-sm font-medium text-gray-900 dark:text-gray-100 select-none cursor-pointer"
                >
                    {{ __('patients.informed') }}
                </label>
            </div>
        </div>

        <div class="flex gap-3 mt-10">
            <button
                class="button-minor"
                type="button"
                @click="showMergeConfirmationDrawer = false; showMergeAuthDrawer = true"
            >
                {{ __('forms.back') }}
            </button>

            <button
                class="button-primary"
                type="button"
                :disabled="!consent"
                @click="
                    showMergeConfirmationDrawer = false;
                    if (currentMethod === '{{ AuthenticationMethod::OTP->value }}') {
                        showMergeSmsDrawer = true;
                    } else {
                        showMergeDocumentsDrawer = true;
                    }
                "
            >
                {{ __('forms.confirm') }}
            </button>
        </div>
    </div>
</x-dialog-drawer>
