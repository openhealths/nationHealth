@use(App\Enums\LegalEntity\ConnectionStatus)

<div x-data="{
        openGrantAccessDrawer: false,
        showSignatureModal: $wire.entangle('showSignatureModal'),
        showConnectionData: $wire.entangle('showConnectionData')
    }"
>
    <livewire:components.x-message :key="time()" />

    <x-forms.loading />

    <x-header-navigation class="items-start">
        <x-slot name="title">
            {{ __('legal-entity-connection.create_title') }}
        </x-slot>
    </x-header-navigation>

    <div>
        @if(!$connection)
            <x-slot name="title">
                <span class="text-xl font-semibold">{{ __('legal-entity-connection.btn_grant_access') }}</span>
            </x-slot>

            <form
                class="space-y-6 mt-6"
                x-data="{
                    clientUuid: '',
                    redirectUri: @js($redirectUri),
                    clientName: '',
                    clientType: $wire.entangle('clientType'),
                    showSignatureModal: $wire.entangle('showSignatureModal')
                }"
            >
                <div class="flex flex-col gap-6 max-w-2xl">
                    <div class="form-group group top-3 grow">
                    <input type="text"
                            required
                            id="client_id"
                            placeholder=" "
                            class="input peer"
                            x-model="clientUuid"
                        >
                        <label for="client_id" class="label">
                            {{ __('legal-entity-connection.client_id_label') }}
                        </label>

                        @error('clientUuid')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group group top-3 grow">
                        <input type="text"
                            required
                            id="redirect_url"
                            placeholder=" "
                            class="input peer"
                            x-model="redirectUri"
                        >
                        <label for="redirect_url" class="label">
                            {{ __('legal-entity-connection.callback_url_label') }}
                        </label>

                        @error('redirectUri')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group group top-3 grow">
                        <input type="text"
                            required
                            id="client_name"
                            placeholder=" "
                            class="input peer"
                            x-model="clientName"
                        >
                        <label for="client_name" class="label">
                            {{ __('legal-entity-connection.facility_name') }}
                        </label>
                        <p class="text-xs text-blue-600 mt-1">
                            {{ __('Назва, для ідентифікації закладу в списку при лоігні') }}<BR>
                            {{ __('(зміниться на назву з реєстру ЄДР, після першого входу)') }}
                        </p>

                        @error('clientName')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group group">
                        <select
                            required
                            id="clientType"
                            x-model="clientType"
                            @error('clientType') licenseTypeErrorHelp @enderror"
                            class="input-select !cursor-default text-gray-400 border-gray-200 dark:text-gray-500 @error('clientType') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                        >
                            <option value="_placeholder_" selected hidden>-- {{ __('forms.select') }} --</option>

                            @foreach($legalEntityTypes as $k => $legalEntityType)
                                <option value="{{ $k }}" @selected($k == $legalEntityType)>
                                    {{ $legalEntityType }}
                                </option>
                            @endforeach
                        </select>

                        @error('clientType')
                            <p id="clientTypeErrorHelp" class="text-error">
                                {{ $message }}
                            </p>
                        @enderror

                        <label for="clientType" class="label z-10">
                            {{ __('legal-entity.type') }}
                        </label>
                    </div>
                </div>

                <div class="flex items-center gap-4 mt-8">
                    <a
                        href="{{ route('dashboard.index') }}"
                        class="button-minor px-6"
                    >
                        {{ __('legal-entity-connection.btn_back') }}
                    </a>

                    <button type="button"
                            @click="openGrantAccessDrawer = false; $wire.create(clientUuid, redirectUri, clientName)"
                            class="button-primary px-6"
                            x-bind:disabled="!clientUuid || !redirectUri || !clientName || !clientType"
                    >
                        {{ __('legal-entity-connection.btn_sign') }}
                    </button>
                </div>
            </form>
        </div>
    @else
        <div class="form shift-content p-6 max-w-5xl grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
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
            <a
                href="{{ route('dashboard.index') }}"
                class="button-minor px-6"
            >
                {{ __('legal-entity-connection.btn_back') }}
            </a>
        </div>
    @endif

    <x-dialog-drawer x-model="showSignatureModal" maxWidth="4/5" overlayWidth="100%">
        <x-slot name="title">
            <span class="text-xl font-semibold">{{ __('legal-entity-connection.signature_modal_title') }}</span>
        </x-slot>

        <div x-data="{
                 fileName: '{{ __('forms.no_file_chosen') }}',
                 displayFileName() {
                     const stored = $wire.form?.keyContainerFileName;
                     if (stored) {
                         return stored;
                     }
                     if (this.fileName && !String(this.fileName).startsWith('livewire-file:')) {
                         return this.fileName;
                     }
                     return '{{ __('forms.no_file_chosen') }}';
                 },
                 setFileNameFromInput(event) {
                     const file = event.target.files?.[0];
                     if (file) {
                         this.fileName = file.name;
                         $wire.set('form.keyContainerFileName', file.name);
                     } else {
                         this.fileName = '{{ __('forms.no_file_chosen') }}';
                         $wire.set('form.keyContainerFileName', '');
                     }
                 },
                 syncFileNameFromWire() {
                     const stored = $wire.form?.keyContainerFileName;
                     if (stored) {
                         this.fileName = stored;
                         return;
                     }
                     const upload = $wire.form?.keyContainerUpload;
                     if (!upload) {
                         this.fileName = '{{ __('forms.no_file_chosen') }}';
                         return;
                     }
                     if (typeof upload === 'string') {
                         if (upload.startsWith('livewire-file:')) {
                             return;
                         }
                         this.fileName = upload.split('/').pop() || this.fileName;
                         return;
                     }
                     if (upload?.name && !String(upload.name).startsWith('livewire-file:')) {
                         this.fileName = upload.name;
                     }
                 },
             }"
             x-effect="if (!showSignatureModal) { if ($refs.keyContainerUpload) $refs.keyContainerUpload.value = ''; } else { syncFileNameFromWire(); }"
             class="mt-6"
        >
            <form onsubmit="return false;">
                <div class="flex flex-col gap-6 max-w-2xl">
                    {{-- KEP Provider --}}
                    <div>
                        <label for="knedp" class="default-label">{{ __('forms.knedp') }} *</label>
                        <select class="input-modal" wire:model="form.knedp" name="knedp" id="knedp">
                            <option value="" selected>{{__('forms.select')}}</option>
                            @foreach(signatureService()->getCertificateAuthorities() as $certificateType)
                                <option value="{{ $certificateType['id'] }}"
                                        wire:key="{{ $certificateType['id'] }}"
                                >
                                    {{ $certificateType['name'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('form.knedp') <p class="text-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- Key File --}}
                    <div>
                        <label for="keyContainerUpload" class="default-label">
                            {{ __('forms.key_container_upload') }} *
                        </label>
                        <div class="file-input-wrapper">
                            <label for="keyContainerUpload" class="file-input-button">
                                {{ __('forms.choose_file') }}
                            </label>
                            <span class="file-input-text" x-text="displayFileName()"></span>
                            <input type="file"
                                   wire:model="form.keyContainerUpload"
                                   class="hidden"
                                   id="keyContainerUpload"
                                   name="keyContainerUpload"
                                   x-ref="keyContainerUpload"
                                   accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                                   @change="setFileNameFromInput($event)"
                                   x-on:livewire-upload-finish="if ($wire.form?.keyContainerFileName) { fileName = $wire.form.keyContainerFileName; }"
                            >
                        </div>

                        <div wire:loading
                             wire:target="form.keyContainerUpload"
                             class="text-sm text-gray-500 mt-2"
                        >
                            {{ __('general.loading') }}...
                        </div>
                        @error('form.keyContainerUpload') <p class="text-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- TAX ID --}}
                    <div>
                        <label for="taxId" class="default-label">{{ __('forms.tax_id') }} *</label>
                        <input
                                type="text"
                                wire:model="form.taxId"
                                class="default-input"
                                id="taxId"
                                name="taxId"
                        />
                        @error('form.taxId') <p class="text-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- Password --}}
                    <div>
                        <label for="password" class="default-label">{{ __('forms.password') }} *</label>
                        <input type="password"
                               wire:model="form.password"
                               class="default-input"
                               id="password"
                               name="password"
                               autocomplete="current-password"
                        />
                        @error('form.password') <p class="text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </form>

            <div class="mt-12 flex flex-row items-center gap-4 border-t border-gray-200 pt-6 max-w-2xl">
                <button type="button"
                        @click="$wire.showSignatureModal = false"
                        class="button-minor"
                >
                    {{ __('legal-entity-connection.btn_cancel') }}
                </button>

                <button wire:click="sign"
                        type="button"
                        class="button-primary"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50 cursor-not-allowed"
                        wire:target="sign"
                >
                    <span wire:loading.remove wire:target="sign">{{ __('legal-entity-connection.btn_sign') }}</span>
                    <span wire:loading wire:target="sign">{{ __('forms.signature') }}...</span>
                </button>
            </div>
        </div>
    </x-dialog-drawer>
</div>
