@use('App\Enums\Person\AuthenticationMethod')
@use('App\Enums\Person\AuthStep')
@use('App\Livewire\Person\Records\PatientData')

<div
    x-data="{
        showAuthMethodModal: $wire.entangle('showAuthMethodModal'),
        authenticationMethods: $wire.entangle('authenticationMethods'),
        selectedMethod: $wire.entangle('form.authorizeWith'),
        localStep: $wire.entangle('authStep'),
    }"
>
    <template x-teleport="body">
        <div
            x-show="showAuthMethodModal"
            style="display: none"
            @keydown.escape.prevent.stop="showAuthMethodModal = false"
            role="dialog"
            aria-modal="true"
            class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition @click="showAuthMethodModal = false" class="modal-wrapper">
                <div
                    @click.stop
                    x-trap.noscroll.inert="showAuthMethodModal"
                    class="modal-content mx-auto w-full max-w-4xl"
                >
                    <div x-show="localStep === {{ AuthStep::INITIAL }}" wire:key="auth-step-0">
                        <div class="mb-8 flex items-center justify-between">
                            <legend class="legend !mb-0">{{ __('patients.authentication_methods') }}</legend>

                            <div x-data="{ openAdd: false }" class="relative">
                                @if ($this->canAddSelfAuthenticationMethod() || $this->canAddThirdPersonAuthenticationMethod())
                                    <button @click="openAdd = ! openAdd" type="button" class="item-add">
                                        <span>{{ __('patients.add_authentication_method') }}</span>
                                    </button>
                                @endif

                                <button type="button" class="button-sync" wire:click.prevent="syncAuthMethods">
                                    <span>{{ __('patients.sync_auth_methods') }}</span>
                                </button>

                                <div
                                    x-show="openAdd"
                                    @click.away="openAdd = false"
                                    x-transition:enter="transition ease-out duration-100"
                                    x-transition:enter-start="opacity-0 scale-95"
                                    x-transition:enter-end="opacity-100 scale-100"
                                    style="display: none"
                                    class="absolute right-0 z-50 mt-2 w-72 rounded-lg border border-gray-100 bg-white p-1 shadow-xl"
                                >
                                    @if ($this->canAddSelfAuthenticationMethod())
                                        <button
                                            type="button"
                                            @click="localStep = {{ AuthStep::ADD_NEW_BY_SMS }}; openAdd = false"
                                            class="w-full cursor-pointer rounded px-4 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50"
                                        >
                                            {{ __('patients.authentication_SMS') }}
                                        </button>

                                        <button
                                            type="button"
                                            wire:click.prevent="createOfflineAuthMethod"
                                            @click="openAdd = false"
                                            class="w-full cursor-pointer rounded px-4 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50"
                                        >
                                            {{ __('patients.authentication_documents') }}
                                        </button>
                                    @endif

                                    @if ($this->canAddThirdPersonAuthenticationMethod())
                                        <button
                                            type="button"
                                            @click="localStep = {{ AuthStep::ADD_NEW_BY_THIRD_PERSON }}; openAdd = false"
                                            class="w-full cursor-pointer rounded px-4 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50"
                                        >
                                            {{ __('patients.authentication_third_person') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <template x-if="! authenticationMethods || authenticationMethods.length === 0">
                            <div class="mb-8 rounded-lg bg-red-100">
                                <div class="p-4">
                                    <div class="mb-2 flex items-center gap-2">
                                        @icon('alert-circle', 'w-5 h-5 text-red-700')
                                        <p class="font-semibold text-red-700">
                                            {{ __('forms.patient_has_no_auth_methods') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template x-if="authenticationMethods && authenticationMethods.length > 0">
                            <div class="space-y-4">
                                <template x-for="(method, methodIndex) in authenticationMethods" :key="methodIndex">
                                    <div class="fieldset space-y-3 rounded border p-3 dark:border-white">
                                        <div class="flex items-start justify-between">
                                            <div
                                                class="shrink"
                                                x-data="{
                                                    labels: @js(AuthenticationMethod::options()),
                                                    prefix: '{{ __('forms.authentication') }}'
                                                }"
                                            >
                                                <h3
                                                    class="font-bold text-gray-900 dark:text-white"
                                                    x-text="`${prefix} ${labels[method.type] ?? method.type}`"
                                                ></h3>
                                            </div>

                                            <div class="flex items-center gap-4">
                                                <div x-data="{ open: false }" class="relative">
                                                    <button
                                                        @click="open = ! open"
                                                        type="button"
                                                        class="cursor-pointer text-sm whitespace-nowrap text-blue-600 hover:underline"
                                                    >
                                                        {{ __('patients.change') }}
                                                    </button>

                                                    <div
                                                        x-show="open"
                                                        @click.away="open = false"
                                                        style="display: none"
                                                        class="absolute right-0 z-50 mt-2 w-64 rounded-lg border border-gray-100 bg-white p-2 shadow-xl"
                                                    >
                                                        <template x-if="method.type === '{{ AuthenticationMethod::OTP->value }}'">
                                                            <button
                                                                type="button"
                                                                @click="open = false"
                                                                wire:click.prevent="selectAuthMethod(method.uuid, method.type, {{ AuthStep::CHANGE_PHONE_INITIAL }})"
                                                                class="w-full cursor-pointer rounded px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
                                                            >
                                                                {{ __('patients.change_phone_number') }}
                                                            </button>
                                                        </template>

                                                        <template x-if="method.type === '{{ AuthenticationMethod::OFFLINE->value }}'">
                                                            <button
                                                                type="button"
                                                                @click="open = false"
                                                                wire:click.prevent="selectAuthMethod(method.uuid, method.type, {{ AuthStep::CHANGE_PHONE_INITIAL }})"
                                                                class="w-full cursor-pointer rounded px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
                                                            >
                                                                {{ __('patients.change_method_to_sms') }}
                                                            </button>
                                                        </template>

                                                        <button
                                                            type="button"
                                                            @click="open = false"
                                                            wire:click.prevent="selectAuthMethod(method.uuid, method.type, {{ AuthStep::CHANGE_ALIAS }})"
                                                            class="w-full cursor-pointer rounded px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
                                                        >
                                                            {{ __('patients.change_method_alias') }}
                                                        </button>

                                                        <template
                                                            x-if="
                                                                method.type === '{{ AuthenticationMethod::THIRD_PERSON->value }}'
                                                                    && authenticationMethods.length > 1
                                                            "
                                                        >
                                                            <button
                                                                type="button"
                                                                @click="open = false"
                                                                wire:click.prevent="deactivateAuthMethod(method.uuid)"
                                                                class="w-full cursor-pointer rounded px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
                                                            >
                                                                {{ __('patients.deactivate_method') }}
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>

                                                @unless ($this instanceof PatientData)
                                                    <template x-if="method.type === '{{ AuthenticationMethod::OTP->value }}'">
                                                        <button
                                                            class="button-primary whitespace-nowrap"
                                                            @click="selectedMethod = method.id || method.uuid; localStep = {{ AuthStep::ASK_OTP_PERMISSION }}"
                                                        >
                                                            {{ __('forms.select') }}
                                                        </button>
                                                    </template>

                                                    <template x-if="method.type !== '{{ AuthenticationMethod::OTP->value }}'">
                                                        <button
                                                            class="button-primary whitespace-nowrap"
                                                            wire:click.prevent="update(method.uuid)"
                                                            :disabled="! method.isActive"
                                                            :class="{
                                                                'cursor-not-allowed opacity-50': ! method.isActive,
                                                            }"
                                                        >
                                                            {{ __('forms.select') }}
                                                        </button>
                                                    </template>
                                                @endunless
                                            </div>
                                        </div>

                                        <template x-if="method.type !== '{{ AuthenticationMethod::THIRD_PERSON->value }}'">
                                            <div>
                                                <p class="default-p">
                                                    {{ __('patients.authentication_method_name') }}:
                                                    <span x-text="method.alias || '-'"></span>
                                                </p>
                                            </div>
                                        </template>

                                        <div class="space-y-2">
                                            <template x-if="method.type === '{{ AuthenticationMethod::OTP->value }}'">
                                                <div class="space-y-4">
                                                    <label for="phoneNumber" class="label-modal">
                                                        {{ __('forms.phone_number') }}
                                                    </label>
                                                    <div class="form-row-3">
                                                        <input
                                                            type="tel"
                                                            class="input-modal"
                                                            :value="method.phoneNumber"
                                                            id="phoneNumber"
                                                            readonly
                                                        />
                                                    </div>
                                                </div>
                                            </template>

                                            <template x-if="method.type === '{{ AuthenticationMethod::THIRD_PERSON->value }}'">
                                                <div class="space-y-4">
                                                    <div class="form-row-2">
                                                        <div class="form-group">
                                                            <label :for="'alias-' + methodIndex" class="label-modal">
                                                                {{ __('patients.alias') }}
                                                            </label>
                                                            <input
                                                                type="text"
                                                                :value="method.alias"
                                                                class="input-modal"
                                                                name="alias"
                                                                :id="'alias-' + methodIndex"
                                                                readonly
                                                            />
                                                        </div>

                                                        <div class="form-group">
                                                            @icon('calendar-month', 'w-5 h-5 svg-input absolute left-1 !top-2/3 transform -translate-y-1/2 pointer-events-none')

                                                            <label :for="'endedAt-' + methodIndex" class="label-modal">
                                                                {{ __('patients.ended_at') }}
                                                                <span class="text-red-600"></span>
                                                            </label>
                                                            <input
                                                                :value="method.ehealthEndedAt"
                                                                type="text"
                                                                name="endedAt"
                                                                :id="'endedAt-' + methodIndex"
                                                                class="input-modal"
                                                                autocomplete="off"
                                                                readonly
                                                            />
                                                        </div>
                                                    </div>

                                                    <div class="form-row-2">
                                                        <div class="form-group">
                                                            <label
                                                                :for="'confidantPersonFullName-' + methodIndex"
                                                                class="label-modal"
                                                            >
                                                                {{ __('patients.confidant_full_name') }}
                                                            </label>
                                                            <input
                                                                type="text"
                                                                :value="method.confidantPerson.name"
                                                                class="input-modal"
                                                                :id="'confidantPersonFullName-' + methodIndex"
                                                                name="confidantPersonFullName"
                                                                readonly
                                                            />
                                                        </div>

                                                        <div class="form-group">
                                                            <label :for="'taxId-' + methodIndex" class="label-modal">
                                                                {{ __('forms.rnokpp') }}
                                                                <span class="text-red-600"></span>
                                                            </label>
                                                            <input
                                                                :value="method.confidantPerson.taxId"
                                                                type="text"
                                                                name="taxId"
                                                                :id="'taxId-' + methodIndex"
                                                                class="input-modal"
                                                                autocomplete="off"
                                                                readonly
                                                            />
                                                        </div>
                                                    </div>

                                                    <div class="form-row-2">
                                                        <div class="form-group">
                                                            <label :for="'unzr-' + methodIndex" class="label-modal">
                                                                {{ __('patients.unzr') }}
                                                            </label>
                                                            <input
                                                                type="text"
                                                                :value="method.confidantPerson.unzr"
                                                                class="input-modal"
                                                                name="unzr"
                                                                :id="'unzr-' + methodIndex"
                                                                readonly
                                                            />
                                                        </div>
                                                    </div>

                                                    <template
                                                        :key="`${method.uuid}-doc-${index}`"
                                                        x-for="
                                                            (document, index) in method.confidantPerson.documentsPerson
                                                        "
                                                    >
                                                        <div class="form-row-2">
                                                            <div
                                                                class="form-group"
                                                                x-data="{
                                                                    documentLabels: @js(__('patients.documents')),
                                                                    getDocumentLabel(type) {
                                                                        return this.documentLabels[type] ?? type
                                                                    }
                                                                }"
                                                            >
                                                                <label
                                                                    :for="'documentType-' + index"
                                                                    class="label-modal"
                                                                >
                                                                    {{ __('forms.document_type') }}
                                                                    <span class="text-red-600"></span>
                                                                </label>
                                                                <input
                                                                    :value="getDocumentLabel(document.type)"
                                                                    type="text"
                                                                    name="documentType"
                                                                    :id="'documentType-' + index"
                                                                    class="input-modal"
                                                                    autocomplete="off"
                                                                    readonly
                                                                />
                                                            </div>

                                                            <div class="form-group">
                                                                <label
                                                                    :for="'documentNumber-' + index"
                                                                    class="label-modal"
                                                                >
                                                                    {{ __('forms.document_number') }}
                                                                </label>
                                                                <input
                                                                    type="text"
                                                                    :value="document.number"
                                                                    class="input-modal"
                                                                    name="documentNumber"
                                                                    :id="'documentNumber-' + index"
                                                                    readonly
                                                                />
                                                            </div>
                                                        </div>
                                                    </template>

                                                    <div class="form-row-2">
                                                        <div class="form-group">
                                                            <label
                                                                :for="'phoneNumber-' + methodIndex"
                                                                class="label-modal"
                                                            >
                                                                {{ __('forms.phone_number') }}
                                                                <span class="text-red-600"></span>
                                                            </label>
                                                            <input
                                                                :value="method.confidantPerson.phones?.number"
                                                                type="tel"
                                                                name="phoneNumber"
                                                                :id="'phoneNumber-' + methodIndex"
                                                                class="input-modal"
                                                                autocomplete="off"
                                                                readonly
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div class="mt-8 flex items-center justify-between">
                            <button
                                type="button"
                                @click="showAuthMethodModal = false; localStep = {{ AuthStep::INITIAL }}"
                                class="button-minor"
                            >
                                {{ __('forms.cancel') }}
                            </button>
                        </div>
                    </div>

                    @php
                        $modalSteps = [
                            AuthStep::CHANGE_PHONE_INITIAL->value => 'livewire.person.parts.modals.init-phone-verification',
                            AuthStep::ASK_OTP_PERMISSION->value => 'livewire.person.parts.modals.ask-otp-permission',
                            AuthStep::VERIFY_PHONE->value => 'livewire.person.parts.modals.complete-otp-verification',
                            AuthStep::NO_PHONE_ACCESS->value => 'livewire.person.parts.modals.no-phone-access',
                            AuthStep::COMPLETE_VERIFICATION->value => 'livewire.person.parts.modals.create-new-phone-number',
                            AuthStep::CHANGE_FROM_OFFLINE->value => 'livewire.person.parts.modals.confirm-by-documents',
                            AuthStep::CHANGE_PHONE->value => 'livewire.person.parts.modals.confirm-by-sms',
                            AuthStep::CHANGE_ALIAS->value => 'livewire.person.parts.modals.new-alias-name',
                            AuthStep::UPDATE_ALIAS->value => 'livewire.person.parts.modals.approve-alias-update',
                            AuthStep::ADD_NEW_BY_SMS->value => 'livewire.person.parts.modals.add-by-sms',
                            AuthStep::APPROVE_ADDING_BY_SMS->value => 'livewire.person.parts.modals.approve-adding-by-sms',
                            AuthStep::ADD_NEW_BY_DOCUMENT->value => 'livewire.person.parts.modals.authentication-from-documents',
                            AuthStep::ADD_NEW_BY_THIRD_PERSON->value => 'livewire.person.parts.modals.authentication-from-third-person',
                            AuthStep::ADD_ALIAS_FOR_THIRD_PERSON->value => 'livewire.person.parts.modals.add-alias-for-third-person',
                            AuthStep::APPROVE_ADDING_NEW_METHOD->value => 'livewire.person.parts.modals.approve-adding-new-method',
                            AuthStep::APPROVE_DEACTIVATING_METHOD->value => 'livewire.person.parts.modals.approve-deactivating-method',
                        ];
                    @endphp

                    @php
                        // These steps list the documents the System returned for the current request. Livewire's
                        // morph does not reach inside <template>, so their content would stay at whatever the page
                        // was first rendered with — they are put straight into the modal instead
                        $stepsFilledFromServer = [
                            AuthStep::CHANGE_FROM_OFFLINE->value,
                            AuthStep::UPDATE_ALIAS->value,
                            AuthStep::ADD_NEW_BY_DOCUMENT->value
                        ];
                    @endphp

                    @foreach ($modalSteps as $step => $view)
                        @if (in_array($step, $stepsFilledFromServer, true))
                            @if ($authStep->value === $step)
                                <div x-show="localStep === {{ $step }}" x-cloak>
                                    @include($view)
                                </div>
                            @endif
                        @else
                            <template x-if="localStep === {{ $step }}">
                                @include($view)
                            </template>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </template>

    <livewire:components.x-message :key="time()" />
</div>
