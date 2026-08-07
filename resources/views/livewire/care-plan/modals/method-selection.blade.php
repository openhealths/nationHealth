@use('App\Enums\Person\AuthenticationMethod')

<div
    x-data="{
        showMethodSelectionModal: $wire.entangle('showMethodSelectionModal'),
        authenticationMethods: $wire.entangle('authMethods'),
    }"
>
    <template x-teleport="body">
        <div
            x-show="showMethodSelectionModal"
            style="display: none"
            @keydown.escape.prevent.stop="showMethodSelectionModal = false"
            role="dialog"
            aria-modal="true"
            class="modal"
        >
            <div
                x-transition.opacity
                class="fixed inset-0 bg-black/30 backdrop-blur-sm"
                @click="showMethodSelectionModal = false"
            ></div>
            <div class="modal-wrapper">
                <div
                    @click.stop
                    x-trap.noscroll.inert="showMethodSelectionModal"
                    class="modal-content mx-auto w-full max-w-4xl rounded-2xl bg-white p-6 shadow-xl sm:p-8 dark:bg-gray-800"
                >
                    <div>
                        <div class="mb-8 flex items-center justify-between">
                            <legend class="legend !mb-0 text-2xl font-bold text-gray-900 dark:text-white">
                                {{ __('patients.authentication_methods') }}
                            </legend>
                        </div>

                        <template x-if="! authenticationMethods || authenticationMethods.length === 0">
                            <div class="mb-8 rounded-xl border border-red-200 bg-red-100 p-4 dark:border-red-800 dark:bg-red-900/20">
                                <div class="flex items-center gap-2">
                                    @icon('alert-circle', 'w-5 h-5 text-red-700 dark:text-red-400')
                                    <p class="font-semibold text-red-700 dark:text-red-300">
                                        {{ __('forms.patient_has_no_auth_methods') }}
                                    </p>
                                </div>
                            </div>
                        </template>

                        <template x-if="authenticationMethods && authenticationMethods.length > 0">
                            <div class="space-y-4">
                                <template x-for="(method, methodIndex) in authenticationMethods" :key="methodIndex">
                                    <div class="fieldset mb-4 space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-none dark:border-gray-700 dark:bg-gray-800">
                                        <div class="flex items-start justify-between gap-4">
                                            <div
                                                class="shrink"
                                                x-data="{
                                                    labels: @js(AuthenticationMethod::options()),
                                                    prefix: '{{ __('forms.authentication') }}'
                                                }"
                                            >
                                                <h3
                                                    class="text-lg font-bold text-gray-900 dark:text-white"
                                                    x-text="`${prefix} ${labels[method.type] ?? method.type}`"
                                                ></h3>
                                            </div>

                                            <div class="flex items-center gap-4">
                                                <div x-data="{ open: false }" class="relative">
                                                    <button
                                                        @click="open = ! open"
                                                        type="button"
                                                        class="cursor-pointer text-sm font-medium whitespace-nowrap text-blue-600 hover:underline"
                                                    >
                                                        {{ __('patients.change') }}
                                                    </button>

                                                    <div
                                                        x-show="open"
                                                        @click.away="open = false"
                                                        x-transition
                                                        style="display: none"
                                                        class="absolute right-0 z-50 mt-2 w-64 rounded-lg border border-gray-100 bg-white p-2 shadow-xl dark:border-gray-700 dark:bg-gray-800"
                                                    >
                                                        <template x-if="method.type === '{{ AuthenticationMethod::OTP->value }}'">
                                                            <button
                                                                type="button"
                                                                @click="open = false"
                                                                class="w-full cursor-pointer rounded px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                                                            >
                                                                {{ __('patients.change_phone_number') }}
                                                            </button>
                                                        </template>

                                                        <template x-if="method.type === '{{ AuthenticationMethod::OFFLINE->value }}'">
                                                            <button
                                                                type="button"
                                                                @click="open = false"
                                                                class="w-full cursor-pointer rounded px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                                                            >
                                                                {{ __('patients.change_method_to_sms') }}
                                                            </button>
                                                        </template>

                                                        <button
                                                            type="button"
                                                            @click="open = false"
                                                            class="w-full cursor-pointer rounded px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                                                        >
                                                            {{ __('patients.change_method_alias') }}
                                                        </button>

                                                        <template x-if="method.type === '{{ AuthenticationMethod::THIRD_PERSON->value }}'">
                                                            <button
                                                                type="button"
                                                                @click="open = false"
                                                                class="w-full cursor-pointer rounded px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                                                            >
                                                                {{ __('patients.deactivate_method') }}
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>

                                                <button
                                                    type="button"
                                                    class="button-primary px-6 py-2 whitespace-nowrap"
                                                    @click="$wire.selectAuthMethod(method.id || method.uuid)"
                                                >
                                                    {{ __('forms.select') }}
                                                </button>
                                            </div>
                                        </div>

                                        <template x-if="method.type !== '{{ AuthenticationMethod::THIRD_PERSON->value }}'">
                                            <div>
                                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                                    {{ __('patients.authentication_method_name') }}:
                                                    <span
                                                        class="text-gray-900 dark:text-white"
                                                        x-text="method.alias || '-'"
                                                    ></span>
                                                </p>
                                            </div>
                                        </template>

                                        <div class="space-y-2">
                                            <template x-if="method.type === '{{ AuthenticationMethod::OTP->value }}'">
                                                <div class="space-y-1.5 pt-1">
                                                    <label class="label-modal !mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                                                        {{ __('forms.phone_number') }}
                                                    </label>
                                                    <div class="max-w-[285px]">
                                                        <input
                                                            type="tel"
                                                            class="input-modal w-full"
                                                            :value="method.phoneNumber || method.phone_number"
                                                            readonly
                                                        />
                                                    </div>
                                                </div>
                                            </template>

                                            <template x-if="method.type === '{{ AuthenticationMethod::OFFLINE->value }}'">
                                                <div class="pt-1 text-sm text-gray-500 dark:text-gray-400">
                                                    {{ __('patients.offline_auth_method_description') }}
                                                </div>
                                            </template>

                                            <template x-if="method.type === '{{ AuthenticationMethod::THIRD_PERSON->value }}'">
                                                <div class="space-y-4 pt-1">
                                                    <div class="form-row-2">
                                                        <div class="form-group">
                                                            <label class="label-modal !mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                                                                {{ __('patients.alias') }}
                                                            </label>
                                                            <input
                                                                type="text"
                                                                :value="method.alias"
                                                                class="input-modal"
                                                                readonly
                                                            />
                                                        </div>

                                                        <div class="form-group">
                                                            <label class="label-modal !mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                                                                {{ __('patients.ended_at') }}
                                                            </label>
                                                            <input
                                                                :value="method.endedAt || method.ended_at || '-'"
                                                                type="text"
                                                                class="input-modal"
                                                                readonly
                                                            />
                                                        </div>
                                                    </div>

                                                    <template x-if="method.confidantPerson || method.confidant_person">
                                                        <div class="form-row-2">
                                                            <div class="form-group">
                                                                <label class="label-modal !mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                                                                    {{ __('patients.confidant_full_name') }}
                                                                </label>
                                                                <input
                                                                    type="text"
                                                                    :value="(
                                                                        method.confidantPerson ||
                                                                        method.confidant_person
                                                                    )?.name"
                                                                    class="input-modal"
                                                                    readonly
                                                                />
                                                            </div>

                                                            <div class="form-group">
                                                                <label class="label-modal !mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                                                                    {{ __('forms.rnokpp') }}
                                                                </label>
                                                                <input
                                                                    :value="(
                                                                        method.confidantPerson ||
                                                                        method.confidant_person
                                                                    )?.taxId ||
                                                                    (method.confidantPerson || method.confidant_person)
                                                                        ?.tax_id"
                                                                    type="text"
                                                                    class="input-modal"
                                                                    readonly
                                                                />
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div class="mt-8 flex justify-start">
                            <button
                                @click="showMethodSelectionModal = false"
                                type="button"
                                class="button-minor px-6 py-2.5"
                            >
                                {{ __('forms.cancel') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
