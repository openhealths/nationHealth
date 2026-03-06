@use('App\Enums\Equipment\Status')

<div x-data="{ show: false, equipmentUuid: null, equipmentName: '', currentStatus: '' }"
     @open-update-status-modal.window="
         equipmentUuid = $event.detail.uuid;
         equipmentName = $event.detail.name;
         currentStatus = $event.detail.status;
         show = true;
    "
     @close-update-status-modal.window="show = false"
>
    <template x-teleport="body">
        <div x-show="show"
             style="display: none"
             @keydown.escape.prevent.stop="show = false"
             role="dialog"
             aria-modal="true"
             class="fixed inset-0 z-50 overflow-y-auto"
        >
            <div x-show="show" x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-show="show"
                 x-transition
                 @click="show = false"
                 class="relative flex min-h-screen items-center justify-center p-4"
            >
                <div @click.stop
                     x-trap.noscroll.inert="show"
                     class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white p-6 text-center shadow-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800"
                >
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6 text-left">
                        {{ __('equipments.update_equipment_status') }} "<span x-text="equipmentName"></span>"
                    </h2>

                    <form @submit.prevent="$wire.updateStatus(equipmentUuid)" wire:key="{{ time() }}">
                        <div class="mb-4 text-left">
                            <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('forms.status.label') }}
                            </label>
                            <select id="status"
                                    wire:model="status"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                    required
                            >
                                <option value="">{{ __('forms.select') }}</option>

                                <template x-if="currentStatus !== '{{ Status::INACTIVE->value }}'">
                                    <option value="{{ Status::INACTIVE->value }}">
                                        {{ __('equipments.status.inactive') }}
                                    </option>
                                </template>

                                <option value="{{ Status::ENTERED_IN_ERROR->value }}">
                                    {{ __('equipments.status.entered_in_error') }}
                                </option>
                            </select>

                            @error('status') <p class="text-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="mb-6 text-left" x-show="$wire.status === '{{ Status::ENTERED_IN_ERROR->value }}'">
                            <label for="errorReason" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('equipments.reason_for_status_change') }}
                            </label>
                            <select wire:model="errorReason"
                                    id="errorReason"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach($this->dictionaries['equipment_status_reasons'] as $key => $reason)
                                    <option value="{{ $key }}">{{ $reason }}</option>
                                @endforeach
                            </select>

                            @error('errorReason') <p class="text-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="mt-6 flex justify-end gap-3">
                            <button type="button"
                                    @click="show = false"
                                    class="button-minor"
                            >
                                {{ __('forms.cancel') }}
                            </button>
                            <button type="submit"
                                    wire:loading.attr="disabled"
                                    class="button-primary"
                            >
                                {{ __('forms.update_data') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
