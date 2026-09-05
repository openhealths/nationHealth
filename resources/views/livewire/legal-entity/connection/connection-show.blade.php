@use(App\Enums\LegalEntity\States)

<section class="section-form"
         x-data="{
             openTerminateConnectionDrawer: false,
             openUpdateSecretDrawer: false,
             openUpdateCallbackDrawer: false,
             isSecretUpdated: false,
             isCallbackUpdated: false
         }"
>
    <livewire:components.x-message :key="time()" />
    <x-forms.loading />

    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            {{ __('legal-entity-connection.details_title') }} {{ $connection->uuid }}
        </x-slot>
        @can('updateConnection', $connection)
            <x-slot name="actions">
                <button
                    type="button"
                    class="button-success flex items-center gap-2 whitespace-nowrap"
                >
                    @icon('refresh', 'w-4 h-4')
                    <span>{{ __('legal-entity-connection.sync_data') }}</span>
                </button>
            </x-slot>
        @endcan
    </x-header-navigation>

    <div class="form shift-content p-6 max-w-5xl">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
            <div class="form-group group">
                <input
                    type="text"
                    id="name"
                    class="input peer"
                    placeholder=" "
                    value="{{ $connection->client->name }}"
                    disabled
                >
                <label for="name" class="label">{{ __('legal-entity-connection.facility_name') }}</label>
            </div>

            <div class="form-group group">
                <input type="text"
                    id="callback"
                    class="input peer"
                    placeholder=" "
                    value="{{ $connection->redirectUri }}"
                    disabled
                >
                <label for="callback" class="label">{{ __('legal-entity-connection.callback_url_label') }}</label>
            </div>

            <div class="form-group group">
                <input type="text"
                    id="client_id"
                    class="input peer"
                    placeholder=" "
                    value="{{ $connection->legalEntity->uuid }}"
                    disabled
                >
                <label for="client_id" class="label">{{ __('legal-entity-connection.client_id_label') }}</label>
            </div>

            <div class="form-group group">
                <input type="text"
                    id="status"
                    class="input peer"
                    placeholder=" "
                    value="{{ States::tryFrom($connection->legalEntity->status)->label() ?? __('forms.unknown') }}"
                    disabled
                >
                <label for="status" class="label">{{ __('legal-entity-connection.status') }}</label>
            </div>

            <div class="form-group group">
                <input type="text"
                    id="consumer_id"
                    class="input peer"
                    placeholder=" "
                    value="{{ $connection->consumerUuid }}"
                    disabled
                >
                <label for="consumer_id" class="label">{{ __('legal-entity-connection.consumer_id_label') }}</label>
            </div>

            <div class="form-group group">
                <input type="text"
                    id="created_at"
                    class="input peer"
                    placeholder=" "
                    value="{{ $connection->ehealthInsertedAt }}"
                    disabled
                >
                <label for="created_at" class="label">{{ __('legal-entity-connection.created_at') }}</label>
            </div>

            <div class="form-group group">
                <input type="text"
                    id="conn_id"
                    class="input peer"
                    placeholder=" "
                    value="{{ $connection->uuid }}"
                    disabled
                >
                <label for="conn_id" class="label">{{ __('legal-entity-connection.conn_id_label') }}</label>
            </div>

            <div class="form-group group">
                <input type="text"
                    id="updated_at"
                    class="input peer"
                    placeholder=" "
                    value="{{ $connection->ehealthUpdatedAt }}"
                    disabled
                >
                <label for="updated_at" class="label">{{ __('legal-entity-connection.updated_at') }}</label>
            </div>
        </div>

        <div class="mt-12 flex flex-row items-center gap-4">
            <a href="{{ route('connection.index', ['legalEntity' => $legalEntity]) }}"
               class="button-minor px-6"
            >
                {{ __('legal-entity-connection.btn_back') }}
            </a>

            {{-- TERMINATE CONNECTION --}}
            @can('updateConnection', $connection)
                <button
                    type="button"
                    @click="openTerminateConnectionDrawer = true"
                    class="button-primary-outline-red"
                >
                    {{ __('legal-entity-connection.btn_terminate_connection') }}
                </button>
            @endcan

            {{-- UPDATE SECRET --}}
            @can('updateSecret', $connection)
                <button
                    type="button"
                    @click="openUpdateSecretDrawer = true"
                    class="button-primary-outline border-blue-200 text-blue-600 hover:bg-blue-50"
                >
                    <span class="flex items-center gap-2">
                        @icon('refresh', 'w-4 h-4')
                        {{ __('legal-entity-connection.btn_update_secret') }}
                    </span>
                </button>
            @endcan

            {{-- UPDATE CALLBACK --}}
            @can('updateConnection', $connection)
                <button
                    type="button"
                    @click="openUpdateCallbackDrawer = true"
                    class="button-primary-outline border-blue-200 text-blue-600 hover:bg-blue-50"
                >
                    <span class="flex items-center gap-2">
                        @icon('refresh', 'w-4 h-4')
                        {{ __('legal-entity-connection.btn_update_callback') }}
                    </span>
                </button>
            @endcan
        </div>
    </div>
    <x-dialog-drawer x-model="openTerminateConnectionDrawer" maxWidth="4/5" overlayWidth="100%">
        <x-slot name="title">
            <span class="text-xl font-semibold">{{ __('legal-entity-connection.terminate_title') }} {{ $connection->uuid }}</span>
        </x-slot>

        <div class="mt-6 max-w-5xl">
            <div class="bg-red-50 rounded-md p-4 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0 mt-0.5">
                        @icon('alert-circle', 'h-5 w-5 text-red-600')
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-bold text-red-600 uppercase">{{ __('legal-entity-connection.warning_title') }}</h3>
                        <div class="mt-2 text-sm text-red-600 space-y-1">
                            <p>{{ __('legal-entity-connection.terminate_warning_text') }}</p>
                            <p>{{ __('legal-entity-connection.terminate_confirm_text') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-row items-center gap-4 mt-8">
                <button
                    type="button"
                    @click="openTerminateConnectionDrawer = false"
                    class="button-minor px-6"
                >
                    {{ __('legal-entity-connection.btn_back') }}
                </button>
                <button
                    type="button"
                    @click="window.location.href='{{ route('connection.index', ['legalEntity' => $legalEntity]) }}'"
                    class="bg-[#b91c1c] text-white hover:bg-red-800 font-medium rounded-md text-sm px-5 py-2.5 outline-none transition-colors"
                >
                    {{ __('legal-entity-connection.btn_terminate_connection') }}
                </button>
            </div>
        </div>
    </x-dialog-drawer>
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
                            id="updated_secret"
                            class="input peer"
                            placeholder=" "
                            value="1331qwee13-1312qe11"
                            disabled
                        >
                        <label for="updated_secret" class="label">{{ __('legal-entity-connection.secret_string_label') }}</label>
                    </div>

                    <div class="hidden md:block"></div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="updated_client_id"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->legalEntity->uuid }}"
                            disabled
                        >
                        <label for="updated_client_id" class="label">{{ __('legal-entity-connection.client_id_label') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="updated_callback"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->redirectUri }}"
                            disabled
                        >
                        <label for="updated_callback" class="label">{{ __('legal-entity-connection.callback_url_label') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="updated_consumer_id"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->consumerUuid }}"
                            disabled
                        >
                        <label for="updated_consumer_id" class="label">{{ __('legal-entity-connection.consumer_id_label') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="updated_conn_id"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->uuid }}"
                            disabled
                        >
                        <label for="updated_conn_id" class="label">{{ __('legal-entity-connection.conn_id_label') }}</label>
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
                            id="secret_client_id"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->legalEntity->uuid }}"
                            disabled
                        >
                        <label for="secret_client_id" class="label">{{ __('legal-entity-connection.client_id_label_lower') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="secret_conn_id"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->uuid }}"
                            disabled
                        >
                        <label for="secret_conn_id" class="label">{{ __('legal-entity-connection.conn_id_label_lower') }}</label>
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
                            id="updated_callback_url"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->redirectUri }}"
                            disabled
                        >
                        <label for="updated_callback_url" class="label">{{ __('legal-entity-connection.callback_url_label') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="updated_callback_consumer_id"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->consumerUuid }}"
                            disabled
                        >
                        <label for="updated_callback_consumer_id" class="label">{{ __('legal-entity-connection.consumer_id_label') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="updated_callback_client_id"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->legalEntity->uuid }}"
                            disabled
                        >
                        <label for="updated_callback_client_id" class="label">{{ __('legal-entity-connection.client_id_label') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="updated_callback_conn_id"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->uuid }}"
                            disabled
                        >
                        <label for="updated_callback_conn_id" class="label">{{ __('legal-entity-connection.conn_id_label') }}</label>
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
                            id="callback_client_id"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->legalEntity->uuid }}"
                            disabled
                        >
                        <label for="callback_client_id" class="label">{{ __('legal-entity-connection.client_id_label_lower') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="callback_conn_id"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->uuid }}"
                            disabled
                        >
                        <label for="callback_conn_id" class="label">{{ __('legal-entity-connection.conn_id_label_lower') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            id="callback_url_input"
                            class="input peer"
                            placeholder=" "
                            value="{{ $connection->redirectUri }}"
                        >
                        <label for="callback_url_input" class="label">{{ __('legal-entity-connection.callback_url_label') }}</label>
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
</section>
