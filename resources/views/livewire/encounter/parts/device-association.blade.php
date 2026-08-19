<div
    class="p-4 sm:p-8"
    id="device-association-section"
    x-data="{
        openDeviceConnectionDrawer: false,
        source: 'other',
    }"
>
    <div class="space-y-4">
        <div class="record-inner-card">
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('patients.medical_device_name') }}</div>
                    <div class="record-inner-value text-[16px]">{{ __('patients.pacemaker') }}</div>
                </div>

                <div class="record-inner-action-col">
                    <div
                        x-data="{
                            openDropdown: false,
                            toggle() {
                                if (this.openDropdown) {
                                    return this.close();
                                }
                                this.$refs.button.focus();
                                this.openDropdown = true;
                            },
                            close(focusAfter) {
                                if (! this.openDropdown) return;
                                this.openDropdown = false;
                                focusAfter && focusAfter.focus();
                            },
                        }"
                        @keydown.escape.prevent.stop="close($refs.button)"
                        @focusin.window="$refs.panel && ! $refs.panel.contains($event.target) && close()"
                        x-id="['dropdown-button']"
                        class="relative"
                    >
                        @if ($isReadonly ?? false)
                            <a
                                href="#"
                                @click.prevent="openDeviceConnectionDrawer = true"
                                class="record-inner-action-btn cursor-pointer"
                                title="{{ __('forms.view') }}"
                            >
                                @icon('eye', 'w-6 h-6')
                                <span class="sr-only">{{ __('forms.view') }}</span>
                            </a>
                        @else
                            <button
                                x-ref="button"
                                @click="toggle()"
                                :aria-expanded="openDropdown"
                                :aria-controls="$id('dropdown-button')"
                                type="button"
                                class="record-inner-action-btn cursor-pointer"
                            >
                                <svg class="h-6 w-6 text-gray-800 dark:text-gray-200" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round" stroke-width="2" d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z" />
                                </svg>
                            </button>

                            <div class="absolute right-0 z-50">
                                <div
                                    x-ref="panel"
                                    x-show="openDropdown"
                                    x-transition.origin.top.left
                                    @click.outside="close($refs.button)"
                                    :id="$id('dropdown-button')"
                                    x-cloak
                                    class="dropdown-panel relative"
                                >
                                    <button
                                        type="button"
                                        @click.prevent="
                                            openDeviceConnectionDrawer = true;
                                            close($refs.button);
                                        "
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button type="button" class="dropdown-delete" @click.prevent="close($refs.button)">
                                        {{ __('forms.delete') }}
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-4">
                        <div>
                            <div class="record-inner-label">{{ __('patients.anatomical_site') }}</div>
                            <div class="record-inner-subvalue">{{ __('patients.head') }}</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.medical_device_id') }}</div>
                            <div class="record-inner-subvalue">1231-adsadas-<br />aqeqe-casdda</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                            <div class="record-inner-subvalue">{{ __('patients.status_valid') }}</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.connection_date_or_break') }}</div>
                            <div class="record-inner-subvalue">01.02.2025</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.sgusoz') }}</div>
                            <div class="record-inner-subvalue">Лікарня №1</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('forms.employee') }}</div>
                            <div class="record-inner-subvalue">Сидоренко І.В.</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('forms.created_at') }}</div>
                            <div class="record-inner-subvalue">01.02.2025</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div>
        @unless ($isReadonly ?? false)
            <button
                type="button"
                @click.prevent="openDeviceConnectionDrawer = true"
                class="item-add my-5 mt-5 flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
            >
                {{ __('patients.add_medical_device_connection') }}
            </button>
        @endunless
    </div>

    <x-dialog-drawer x-model="openDeviceConnectionDrawer" maxWidth="4/5" wire:ignore>
        <x-slot name="title">{{ __('patients.new_medical_device_connection') }}</x-slot>

        <form>
            <fieldset @disabled($isReadonly ?? false) @class(['pointer-event-none' => $isReadonly ?? false ])>
                <fieldset class="fieldset">
                    <legend class="legend">{{ __('patients.main_info') }}</legend>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <select class="input-select peer" required>
                                <option value="" disabled selected hidden></option>
                                <option value="1" selected>Кардіостимулятор Medtronic Azure XT</option>
                            </select>
                            <label class="label">{{ __('care-plan.medical_device') }}</label>
                        </div>
                        <div class="form-group group">
                            <select class="input-select peer" required>
                                <option value="" disabled selected hidden></option>
                                <option value="1" selected>{{ __('patients.attached_status') }}</option>
                            </select>
                            <label class="label">{{ __('patients.connection_status') }}</label>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="fieldset">
                    <legend class="legend">{{ __('forms.additional_information') }}</legend>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <div class="datepicker-wrapper">
                                <input
                                    type="text"
                                    class="datepicker-input with-leading-icon input peer"
                                    placeholder=" "
                                    value="02.04.2025"
                                />
                                <label class="wrapped-label">{{ __('patients.connection_date') }}</label>
                            </div>
                        </div>
                        <div class="form-group group">
                            <select class="input-select peer">
                                <option value="" disabled selected hidden></option>
                                <option value="1" selected>{{ __('patients.right_hand') }}</option>
                            </select>
                            <label class="label">{{ __('patients.anatomical_site') }}</label>
                        </div>
                    </div>

                    <div class="form-row-1 mt-4">
                        <div>
                            <label class="label-modal mb-2 block">{{ __('patients.anatomical_site_comment') }}</label>
                            <div>
                                <textarea
                                    class="textarea"
                                    rows="4"
                                    placeholder="{{ __('patients.text_for_input') }}"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 border-t border-gray-100 pt-6 dark:border-gray-700">
                        <div class="mb-6 flex items-center gap-6">
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('patients.information_source') }}</span>
                            <div class="flex items-center gap-4">
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input
                                        type="radio"
                                        name="source"
                                        x-model="source"
                                        value="performer"
                                        class="default-radio"
                                    />
                                    <span class="text-sm">{{ __('patients.performer') }}</span>
                                </label>
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input
                                        type="radio"
                                        name="source"
                                        x-model="source"
                                        value="other"
                                        class="default-radio"
                                    />
                                    <span class="text-sm">{{ __('patients.other_source') }}</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-row-2" x-show="source === 'other'" x-cloak>
                            <div class="form-group group">
                                <select class="input-select peer" required>
                                    <option value="" disabled selected hidden></option>
                                    <option value="1" selected>{{ __('patients.source_link_medical_record') }}</option>
                                </select>
                                <label class="label">{{ __('patients.source_link') }}</label>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <div class="mt-6 flex w-full justify-start space-x-4">
                    <button type="button" @click="openDeviceConnectionDrawer = false" class="button-minor">
                        {{ __('forms.cancel') }}
                    </button>

                    @unless ($isReadonly ?? false)
                        <button type="button" @click="openDeviceConnectionDrawer = false" class="button-primary">
                            {{ __('forms.add') }}
                        </button>
                    @endunless
                </div>
            </fieldset>
        </form>
    </x-dialog-drawer>
</div>
