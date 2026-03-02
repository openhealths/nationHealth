{{-- Auth Drawer Overlay --}}
<div x-show="showAuthDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showAuthDrawer = false"
     class="fixed inset-0 bg-gray-900/50"
     style="z-index: 35;"
></div>

{{-- Auth Drawer --}}
<div x-show="showAuthDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     class="fixed top-0 right-0 h-screen pt-16 bg-white dark:bg-gray-800 shadow-2xl"
     style="z-index: 40; width: calc(80% - 65px);"
     id="auth-drawer"
     tabindex="-1"
     x-data="{
         localTimer: 60,
         timerInterval: null,
         startTimer() {
             this.localTimer = 60;
             if(this.timerInterval) clearInterval(this.timerInterval);
             this.timerInterval = setInterval(() => { if(this.localTimer > 0) this.localTimer-- }, 1000)
         },
         resetTimer() {
             if(this.localTimer === 0) {
                 this.localTimer = 60;
                 this.startTimer();
             }
         }
     }"
     x-init="$watch('showAuthDrawer', value => { if(value) startTimer() })"
>
    <div class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-6 py-4">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">
            @if($authDrawerMode === 'create')
                {{ __('patients.authentication_SMS') }}
            @elseif($authDrawerMode === 'deactivate')
                {{ __('patients.deactivate_relationship') }}
            @else
                {{ __('patients.authentication_SMS') }}
            @endif
        </h2>
    </div>

    <div class="overflow-y-auto p-6 bg-white dark:bg-gray-800" style="height: calc(100% - 70px);">
        <legend class="legend">{{ __('patients.code_sms') }}</legend>

        @foreach($this->uploadedDocuments as $key => $document)
            <div class="pb-4 flex" wire:key="{{ $key }}">
                <div class="flex-grow">
                    <label class="block mb-3 text-sm font-medium text-gray-900 dark:text-white"
                           for="fileInput-{{ $key }}">
                        {{ __('patients.documents.' . Str::afterLast($document['type'], '.')) }}
                    </label>
                    <div class="flex items-center gap-4">
                        <input type="file"
                               class="xl:w-1/2 block text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 dark:text-gray-400 focus:outline-none dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400"
                               id="fileInput-{{ $key }}"
                               wire:model.live="form.uploadedDocuments.{{ $key }}"
                               accept=".jpeg,.jpg"
                        >
                    </div>

                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                        {{ __('forms.max_file_size_and_format') }}
                    </p>

                    @error("form.uploadedDocuments.$key")
                    <p class="text-error">
                        {{ $message }}
                    </p>
                    @enderror
                </div>
            </div>
        @endforeach

        <div class="form-row-3 mt-8">
            <div class="form-group group">
                <input type="text"
                       wire:model="form.verificationCode"
                       inputmode="numeric"
                       name="authVerificationCode"
                       id="authVerificationCode"
                       class="peer input"
                       placeholder=" "
                       autocomplete="off"
                       maxlength="4"
                />
                <label for="authVerificationCode" class="label">
                    {{ __('forms.confirmation_code_from_SMS') }}
                </label>
            </div>

            <button type="button"
                    wire:click.prevent="resendCodeOnConfidantPersonRelationship"
                    :disabled="localTimer > 0"
                    class="button-minor flex items-end gap-4 mt-4 mb-8 flex-1 max-w-xs"
            >
                @icon('mail', 'w-4 h-4')
                <span>{{ __('forms.send_again') }}</span>
                <template x-if="localTimer > 0">
                    <span x-text="`(через ${localTimer} c)`"></span>
                </template>
            </button>
        </div>

        <div class="flex gap-3">
            <button type="button"
                    @click="showAuthDrawer = false"
                    class="button-minor"
            >
                {{ __('forms.back') }}
            </button>

            <div>
                <button type="button"
                        @click="showDocumentDrawer = false; showConfidantPersonDrawer = false; showSignatureDrawer = true"
                        class="button-outline-primary"
                >
                    {{ __('patients.to_authentication_methods') }}
                </button>

                <button type="button"
                        wire:click.prevent="approveConfidantPersonRelationshipRequest"
                        class="button-primary"
                >
                    {{ __('forms.confirm') }}
                </button>
            </div>
        </div>
    </div>
</div>

@include('livewire.person.parts.drawers.add-signature')
