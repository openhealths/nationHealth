@use(App\Enums\LegalEntity\States)

<div x-data="{ openGrantAccessDrawer: false, showSignatureModal: $wire.entangle('showSignatureModal') }">
    <livewire:components.x-message :key="time()" />
    <x-forms.loading />

    <x-header-navigation class="items-start">
        <x-slot name="title">
            {{ __('legal-entity-connection.index_title') }}
        </x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            <button type="button"
                @click="openGrantAccessDrawer = true"
                class="button-primary flex items-center gap-2"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('legal-entity-connection.btn_grant_access') }}
            </button>

            <button type="button"
                class="button-sync flex items-center gap-2 whitespace-nowrap"
            >
                @icon('refresh', 'w-4 h-4')
                <span>{{ __('legal-entity-connection.sync_data') }}</span>
            </button>
        </div>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5">
        <div class="max-w-7xl">
            @if($connections->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[20%]">{{ __('legal-entity.name') }},<br>{{ __('forms.uuid') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('legal-entity-connection.table_mis_id') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('legal-entity-connection.table_conn_id') }}</th>
                            <th class="index-table-th w-[20%]">{{ __('legal-entity-connection.table_callback_url') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('legal-entity-connection.table_status') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('legal-entity-connection.table_created') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('legal-entity-connection.table_action') }}</th>
                        </tr>
                        </thead>

                        <tbody>
                        @foreach ($connections as $connection)
                            <tr wire:key="connection-{{ $connection->id }}" class="index-table-tr">
                                <td class="index-table-td-primary">
                                    <span class="font-bold">{{ $connection->client->name }}</span><br>
                                    <span class="text-gray-500 text-sm">{{ $connection->legalEntity->uuid }}</span>
                                </td>
                                <td class="index-table-td">
                                    {{ $connection->consumer_uuid }}
                                </td>
                                <td class="index-table-td">
                                    {{ $connection->uuid }}
                                </td>
                                <td class="index-table-td">
                                    {{ $connection->redirect_uri }}
                                </td>
                                <td class="index-table-td !whitespace-nowrap">
                                    {{-- status-alert-* classes are full-width alert blocks, use their badge-* (pill) equivalent here --}}
                                    @php
                                        $legalEntityState = States::tryFrom($connection->legalEntity->status);
                                        $badgeClass = str_replace('status-alert-', 'badge-', $legalEntityState?->cssClass() ?? 'status-alert-default');
                                    @endphp
                                    <span class="{{ $badgeClass }} whitespace-nowrap">
                                        {{ $legalEntityState?->label() ?? __('forms.unknown') }}
                                    </span>
                                </td>
                                <td class="index-table-td">
                                    {{ $connection->ehealthInsertedAt }}
                                </td>
                                <td class="index-table-td-actions">
                                    <div class="flex justify-center relative">
                                        <div x-data="{
                                                open: false,
                                                toggle() {
                                                    if (this.open) {
                                                        return this.close();
                                                    }
                                                    this.$refs.button.focus();
                                                    this.open = true;
                                                },
                                                close(focusAfter) {
                                                    if (!this.open) return;
                                                    this.open = false;
                                                    focusAfter && focusAfter.focus()
                                                }
                                             }"
                                             @keydown.escape.prevent.stop="close($refs.button)"
                                             @focusin.window="!$refs.panel.contains($event.target) && close()"
                                             x-id="['dropdown-button']"
                                             class="relative"
                                        >
                                            <button @click="toggle()"
                                                    x-ref="button"
                                                    :aria-expanded="open"
                                                    :aria-controls="$id('dropdown-button')"
                                                    type="button"
                                                    class="hover:text-primary cursor-pointer"
                                                    outline="none"
                                            >
                                                <svg class="svg-hover-action w-6 h-6 text-gray-800 dark:text-gray-300"
                                                     aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="18"
                                                     height="18" fill="none" viewBox="0 0 24 24">
                                                    <path stroke="currentColor" stroke-linecap="round"
                                                          stroke-linejoin="round"
                                                          stroke-width="2"
                                                          d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"/>
                                                </svg>
                                            </button>

                                            <div
                                                x-show="open"
                                                x-cloak
                                                x-ref="panel"
                                                x-transition.origin.top.left
                                                @click.outside="close($refs.button)"
                                                :id="$id('dropdown-button')"
                                                class="absolute right-0 mt-2 w-48 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50"
                                            >
                                                <a href="{{ route('connection.show', [legalEntity(), $connection]) }}" class="flex items-center gap-2 w-full first-of-type:rounded-t-md px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                    @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300') {{ __('legal-entity-connection.btn_view_details') }}
                                                </a>
                                                @can('updateSecret', $connection)
                                                <a href="#" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                    @icon('refresh', 'w-5 h-5 text-gray-600 dark:text-gray-300') {{ __('legal-entity-connection.btn_update_secret_short') }}
                                                </a>
                                                @endcan

                                                @can('updateConnection', $connection)
                                                <a href="#" class="flex items-center gap-2 w-full last-of-type:rounded-b-md px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                    @icon('refresh', 'w-5 h-5 text-gray-600 dark:text-gray-300') {{ __('legal-entity-connection.btn_update_callback_short') }}
                                                </a>
                                                @endcan
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    {{ $connections->links() }}
                </div>
            @else
                <x-nothing-found />
            @endif
        </div>
    </div>

    <x-dialog-drawer x-model="openGrantAccessDrawer" maxWidth="4/5" overlayWidth="100%">
        <x-slot name="title">
            <span class="text-xl font-semibold">{{ __('legal-entity-connection.btn_grant_access') }}</span>
        </x-slot>

        <form class="space-y-6 mt-6">
            <div class="flex flex-col gap-6 max-w-2xl">
                <div class="form-group group top-3 grow">
                    <input type="text"
                        id="client_id"
                        placeholder=" "
                        class="input peer"
                    >
                    <label for="client_id" class="label">
                        {{ __('legal-entity-connection.client_id_label_lower') }}
                    </label>
                </div>
            </div>

            <div class="flex items-center gap-4 mt-8">
                <button type="button"
                    @click="openGrantAccessDrawer = false"
                    class="button-minor px-6"
                >
                    {{ __('legal-entity-connection.btn_back') }}
                </button>
                <button type="button"
                        @click="openGrantAccessDrawer = false; showSignatureModal = true"
                        class="button-primary px-6"
                >
                    {{ __('legal-entity-connection.btn_sign') }}
                </button>
            </div>
        </form>
    </x-dialog-drawer>

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
