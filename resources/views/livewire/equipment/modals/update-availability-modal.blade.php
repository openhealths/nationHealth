@use('App\Enums\Equipment\AvailabilityStatus')

<div
    x-data="{ show: false, equipmentUuid: null, equipmentName: '', equipmentStatus: '' }"
    @open-update-availability-status-modal.window="
        equipmentUuid = $event.detail.uuid;
        equipmentName = $event.detail.name;
        equipmentStatus = $event.detail.status;
        show = true;
        $wire.loadChildEquipments(equipmentUuid);
    "
    @close-update-availability-status-modal.window="show = false"
>
    <template x-teleport="body">
        <div
            x-show="show"
            style="display: none"
            @keydown.escape.prevent.stop="show = false"
            role="dialog"
            aria-modal="true"
            class="fixed inset-0 z-50 overflow-y-auto"
        >
            <div x-show="show" x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div
                x-show="show"
                x-transition
                @click="show = false"
                class="relative flex min-h-screen items-center justify-center p-4"
            >
                <div
                    @click.stop
                    x-trap.noscroll.inert="show"
                    class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white p-6 text-center shadow-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800"
                >

                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6 text-left">
                        {{ __('equipments.update_equipment_availability') }} "<span x-text="equipmentName"></span>"
                    </h2>

                    @if (!empty($childEquipments))
                        <div
                            class="mb-6 rounded-md border border-yellow-300 bg-yellow-50 p-4 text-left dark:border-yellow-700 dark:bg-yellow-900/30">
                            <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                                {{ __('equipments.child_equipment_warning') }}
                            </p>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-yellow-800 dark:text-yellow-200">
                                @foreach ($childEquipments as $childEquipment)
                                    <li wire:key="child-equipment-{{ $loop->index }}">
                                        {{ $childEquipment['names'][0]['name'] ?? '' }}
                                        @if (!empty($childEquipment['inventoryNumber']))
                                            ({{ __('equipments.inventory_number') }}
                                            : {{ $childEquipment['inventoryNumber'] }})
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form @submit.prevent="$wire.updateAvailabilityStatus(equipmentUuid)" wire:key="{{ time() }}">
                        <div class="mb-6 text-left">
                            <label
                                for="availabilityStatus"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                {{ __('equipments.availability_status.label') }}
                            </label>

                            <select
                                id="availabilityStatus"
                                x-model="equipmentStatus"
                                wire:model="availabilityStatus"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach(AvailabilityStatus::options() as $key => $status)
                                    <option value="{{ $key }}">{{ $status }}</option>
                                @endforeach
                            </select>

                            @error('availabilityStatus') <p class="text-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="mt-6 flex justify-end gap-3">
                            <button type="button" @click="show = false" class="button-minor">
                                {{ __('forms.cancel') }}
                            </button>
                            <button type="submit" wire:loading.attr="disabled" class="button-primary">
                                {{ __('forms.update_data') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
