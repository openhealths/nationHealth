<div
    class="p-4 sm:p-8"
    id="device-dispenses-section"
    x-data="{
        openDeviceDispenseDrawer: false,
    }"
>
    <div class="space-y-4">
        <div class="record-inner-card">
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('Виріб') }}</div>
                    <div class="record-inner-value text-[16px]">Кардіостимулятор</div>
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
                                @click.prevent="openDeviceDispenseDrawer = true"
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
                                            openDeviceDispenseDrawer = true;
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
                            <div class="record-inner-label">{{ __('Дата та час видачі') }}</div>
                            <div class="record-inner-subvalue">01.02.2025 11:00</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('ID процедури') }}</div>
                            <div class="record-inner-subvalue break-all">1231-adsadas-aqeqe-casdda</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('ID плану лікування') }}</div>
                            <div class="record-inner-subvalue break-all">1231-adsadas-aqeqe-casdda</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('Епізод пов\'язаного призначення') }}</div>
                            <div class="record-inner-subvalue break-all">1231-adsadas-aqeqe-casdda</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('СГУСОЗ') }}</div>
                            <div class="record-inner-subvalue">Лікарня №1</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('Працівник') }}</div>
                            <div class="record-inner-subvalue">Сидоренко І.В.</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('Дата створення запису') }}</div>
                            <div class="record-inner-subvalue">01.02.2025</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('Статус') }}</div>
                            <div class="record-inner-subvalue">Дійсний</div>
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
                @click.prevent="openDeviceDispenseDrawer = true"
                class="item-add my-5 mt-5 flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
            >
                {{ __('patients.dispense_medical_device') }}
            </button>
        @endunless
    </div>

    <x-dialog-drawer x-model="openDeviceDispenseDrawer" maxWidth="4/5" wire:ignore>
        <x-slot name="title">{{ __('patients.new_medical_device_dispense') }}</x-slot>

        <form>
            <fieldset @disabled($isReadonly ?? false) @class(['pointer-event-none' => $isReadonly ?? false])>
                <div class="form-row-2">
                    <div class="form-group group">
                        <select class="input-select peer" required>
                            <option value="" disabled selected hidden></option>
                            <option value="1" selected>12310-1332-13123-5541</option>
                        </select>
                        <label class="label">{{ __('patients.medical_device_prescription_erequest') }}</label>
                    </div>
                    <div class="form-group group">
                        <select class="input-select peer" required>
                            <option value="" disabled selected hidden></option>
                            <option value="1" selected>Процедура 12.02.2026</option>
                        </select>
                        <label class="label">{{ __('patients.procedure_link') }}</label>
                    </div>
                </div>

                <div class="form-row-2 mt-6">
                    <div class="form-group group">
                        <select class="input-select peer" required>
                            <option value="" disabled selected hidden></option>
                            <option value="1" selected>Шевченко Т.Г.</option>
                        </select>
                        <label class="label">{{ __('patients.dispensing_employee') }}</label>
                    </div>
                    <div class="form-group group">
                        <select class="input-select peer" required>
                            <option value="" disabled selected hidden></option>
                            <option value="1" selected>Амбулаторія №2</option>
                        </select>
                        <label class="label">{{ __('patients.dispensing_division') }}</label>
                    </div>
                </div>

                <div class="form-row-2 mt-6">
                    <div class="form-group group relative flex justify-between">
                        <div class="datepicker-wrapper flex-1">
                            <input
                                type="text"
                                class="datepicker-input with-leading-icon input peer rounded-r-none border-r-0"
                                placeholder=" "
                                value="02.04.2025"
                            />
                            <label class="wrapped-label">{{ __('patients.date_and_time_of_dispense') }}</label>
                        </div>
                        <div class="relative -ml-px w-32">
                            <input type="text" class="input peer rounded-l-none pl-10" placeholder=" " value="12:00" />
                            @icon('clock', 'svg-input left-2.5 text-gray-400')
                        </div>
                    </div>
                    <div class="form-group group relative">
                        <input type="text" class="input peer" placeholder=" " value="1" required />
                        <label class="label">{{ __('patients.quantity_integer') }}</label>
                        @icon('close', 'svg-input right-2.5 text-gray-400 cursor-pointer')
                    </div>
                </div>

                <div class="form-row-2 mt-6">
                    <div class="form-group group">
                        <select class="input-select peer" required>
                            <option value="" disabled selected hidden></option>
                            <option value="1" selected>Тип виробу</option>
                        </select>
                        <label class="label">{{ __('patients.specify_type_or_model_of_medical_device') }}</label>
                    </div>
                    <div class="form-group group">
                        <select class="input-select peer" required>
                            <option value="" disabled selected hidden></option>
                            <option value="1" selected>Тип виробу</option>
                        </select>
                        <label class="label">{{ __('care-plan.medical_device_type') }}</label>
                    </div>
                </div>

                <div class="form-row-1 mt-6">
                    <div>
                        <label class="label-modal mb-2 block"> {{ __('forms.additional_information') }} </label>
                        <div>
                            <textarea
                                class="textarea"
                                rows="4"
                                placeholder="{{ __('patients.text_for_input') }}"
                            ></textarea>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex w-full justify-start space-x-4">
                    <button type="button" @click="openDeviceDispenseDrawer = false" class="button-minor">
                        {{ __('forms.cancel') }}
                    </button>
                    @unless ($isReadonly ?? false)
                        <button type="button" @click="openDeviceDispenseDrawer = false" class="button-primary">
                            {{ __('forms.add') }}
                        </button>
                    @endunless
                </div>
            </fieldset>
        </form>
    </x-dialog-drawer>
</div>
