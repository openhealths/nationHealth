{{-- Expects: $connection (App\Models\Connection) --}}
{{-- Requires host x-data with: openUpdateSecretDrawer, isSecretUpdated, openUpdateCallbackDrawer, isCallbackUpdated --}}
<x-dialog-drawer x-model="openUpdateSecretDrawer" maxWidth="4/5" overlayWidth="100%">
    <x-slot name="title">
        <span class="text-xl font-semibold">
            <span x-show="isSecretUpdated" x-cloak>{{ __('legal-entity-connection.secret_updated_title') }}</span>
            <span x-show="!isSecretUpdated">{{ __('legal-entity-connection.update_secret_title') }}</span>
        </span>
    </x-slot>

    <div class="mt-6 max-w-5xl">
        <div x-show="isSecretUpdated" x-cloak>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                <div class="form-group group">
                    <input
                        type="text"
                        id="updated_secret_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="1331qwee13-1312qe11"
                        disabled
                    >
                    <label for="updated_secret_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.secret_string_label') }}</label>
                </div>

                <div class="hidden md:block"></div>

                <div class="form-group group">
                    <input
                        type="text"
                        id="updated_client_id_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->legalEntity->uuid }}"
                        disabled
                    >
                    <label for="updated_client_id_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.client_id_label') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        type="text"
                        id="updated_callback_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->redirectUri }}"
                        disabled
                    >
                    <label for="updated_callback_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.callback_url_label') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        type="text"
                        id="updated_consumer_id_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->consumerUuid }}"
                        disabled
                    >
                    <label for="updated_consumer_id_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.consumer_id_label') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        type="text"
                        id="updated_conn_id_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->uuid }}"
                        disabled
                    >
                    <label for="updated_conn_id_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.conn_id_label') }}</label>
                </div>
            </div>

            <div class="flex flex-row items-center gap-4 mt-8">
                <button
                    type="button"
                    @click="openUpdateSecretDrawer = false; isSecretUpdated = false"
                    class="button-minor px-6"
                >
                    {{ __('legal-entity-connection.btn_close') }}
                </button>
            </div>
        </div>

        <div x-show="!isSecretUpdated">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                <div class="form-group group">
                    <input
                        type="text"
                        id="secret_client_id_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->legalEntity->uuid }}"
                        disabled
                    >
                    <label for="secret_client_id_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.client_id_label_lower') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        type="text"
                        id="secret_conn_id_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->uuid }}"
                        disabled
                    >
                    <label for="secret_conn_id_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.conn_id_label_lower') }}</label>
                </div>
            </div>

            <div class="flex flex-row items-center gap-4 mt-8">
                <button
                    type="button"
                    @click="openUpdateSecretDrawer = false"
                    class="button-minor px-6"
                >
                    {{ __('legal-entity-connection.btn_back') }}
                </button>
                <button
                    type="button"
                    @click="isSecretUpdated = true"
                    class="button-primary px-6"
                >
                    {{ __('legal-entity-connection.btn_update') }}
                </button>
            </div>
        </div>
    </div>
</x-dialog-drawer>
<x-dialog-drawer x-model="openUpdateCallbackDrawer" maxWidth="4/5" overlayWidth="100%">
    <x-slot name="title">
        <span class="text-xl font-semibold">
            <span x-show="isCallbackUpdated" x-cloak>{{ __('legal-entity-connection.callback_updated_title') }}</span>
            <span x-show="!isCallbackUpdated">{{ __('legal-entity-connection.update_callback_title') }}</span>
        </span>
    </x-slot>

    <div class="mt-6 max-w-5xl">
        <div x-show="isCallbackUpdated" x-cloak>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                <div class="form-group group">
                    <input
                        type="text"
                        id="updated_callback_url_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->redirectUri }}"
                        disabled
                    >
                    <label for="updated_callback_url_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.callback_url_label') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        type="text"
                        id="updated_callback_consumer_id_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->consumerUuid }}"
                        disabled
                    >
                    <label for="updated_callback_consumer_id_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.consumer_id_label') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        type="text"
                        id="updated_callback_client_id_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->legalEntity->uuid }}"
                        disabled
                    >
                    <label for="updated_callback_client_id_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.client_id_label') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        type="text"
                        id="updated_callback_conn_id_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->uuid }}"
                        disabled
                    >
                    <label for="updated_callback_conn_id_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.conn_id_label') }}</label>
                </div>
            </div>

            <div class="flex flex-row items-center gap-4 mt-8">
                <button
                    type="button"
                    @click="openUpdateCallbackDrawer = false; isCallbackUpdated = false"
                    class="button-minor px-6"
                >
                    {{ __('legal-entity-connection.btn_close') }}
                </button>
            </div>
        </div>
        <div x-show="!isCallbackUpdated">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                <div class="form-group group">
                    <input
                        type="text"
                        id="callback_client_id_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->legalEntity->uuid }}"
                        disabled
                    >
                    <label for="callback_client_id_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.client_id_label_lower') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        type="text"
                        id="callback_conn_id_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->uuid }}"
                        disabled
                    >
                    <label for="callback_conn_id_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.conn_id_label_lower') }}</label>
                </div>

                <div class="form-group group">
                    <input
                        type="text"
                        id="callback_url_input_{{ $connection->id }}"
                        class="input peer"
                        placeholder=" "
                        value="{{ $connection->redirectUri }}"
                    >
                    <label for="callback_url_input_{{ $connection->id }}" class="label">{{ __('legal-entity-connection.callback_url_label') }}</label>
                </div>
            </div>

            <div class="flex flex-row items-center gap-4 mt-8">
                <button
                    type="button"
                    @click="openUpdateCallbackDrawer = false"
                    class="button-minor px-6"
                >
                    {{ __('legal-entity-connection.btn_back') }}
                </button>
                <button
                    type="button"
                    @click="isCallbackUpdated = true"
                    class="button-primary px-6"
                >
                    {{ __('legal-entity-connection.btn_update') }}
                </button>
            </div>
        </div>
    </div>
</x-dialog-drawer>
