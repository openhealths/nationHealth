<div
    class="p-4 sm:p-8"
    id="device-dispenses-section"
    x-data="{
        deviceDispenses: $wire.entangle('deviceDispenseForm.deviceDispenses'),
        procedures: $wire.entangle('procedureForm.procedures'),
        deviceRequests: @js($deviceRequests),
        deviceTypesDictionary: $wire.dictionaries['device_definition_classification_type'],
        deviceDefinitions: $wire.dictionaries['custom/device_definitions'],
        employees: @js($deviceDispenseEmployees),
        divisions: @js($divisions),
        legalEntityName: @js(legalEntity()->name),
        modalDeviceDispense: new DeviceDispense(),
        newDeviceDispense: false,
        openDeviceDispenseDrawer: false,
        item: 0,

        activeDeviceRequests() {
            return this.deviceRequests.filter((deviceRequest) => String(deviceRequest.status).toLowerCase() === 'active' && !deviceRequest.programId);
        },

        carePlanId(id) {
            const deviceRequest = this.deviceRequests.find((request) => request.uuid === id);

            return deviceRequest?.carePlanUuid || deviceRequest?.carePlanId || '-';
        },

        employeeName(id) {
            return this.employees.find((employee) => employee.uuid === id)?.name || '-';
        },

        procedureLabel(procedure) {
            const service = Object.values($wire.dictionaries['custom/services'] ?? {}).find((service) => service.id === procedure.codeValue);

            return service ? `${service.code} / ${service.name}` : procedure.codeValue || procedure.uuid || '-';
        },

        deviceName(deviceDispense) {
            if (deviceDispense.deviceSelectionType === 'model') {
                return this.deviceDefinitions.find(
                    (deviceDefinition) => deviceDefinition.id === deviceDispense.deviceDefinitionId
                )?.name || '-';
            }

            return this.deviceTypesDictionary[deviceDispense.deviceCode] || '-';
        },

        deviceSelectionTypeName(deviceDispense) {
            return deviceDispense.deviceSelectionType === 'model' ? '{{ __('device-dispenses.model') }}' : '{{ __('device-dispenses.type') }}';
        },

        supportingInfoName(supporting) {
            const dictName =
                $wire.dictionaries['eHealth/LOINC/observation_codes']?.[supporting.code] ||
                $wire.dictionaries['eHealth/ICF/classifiers']?.[supporting.code] ||
                $wire.dictionaries['eHealth/ICPC2/condition_codes']?.[supporting.code];

            if (dictName) {
                return `${supporting.code} - ${dictName}`;
            }

            const service = Object.values($wire.dictionaries['custom/services'] ?? {}).find(
                (service) => service.id === supporting.code
            );

            return service ? `${service.code} / ${service.name}` : supporting.code || supporting.uuid || '-';
        },

        statusName(status) {
            const statuses = {
                completed: '{{ __('device-dispenses.status.completed') }}',
                entered_in_error: '{{ __('device-dispenses.status.entered_in_error') }}',
                in_progress: '{{ __('device-dispenses.status.in_progress') }}',
                stopped: '{{ __('device-dispenses.status.stopped') }}',
                unknown: '{{ __('device-dispenses.status.unknown') }}',
                preparation: '{{ __('device-dispenses.status.preparation') }}',
                canceled: '{{ __('device-dispenses.status.canceled') }}'
            };

            return statuses[status] || '-';
        },

        createDeviceDispense() {
            this.newDeviceDispense = true;
            this.modalDeviceDispense = new DeviceDispense();
            this.modalDeviceDispense.performerId = this.employees[0]?.uuid || '';
            this.modalDeviceDispense.locationId = $wire.form.encounter.divisionId || '';
            this.modalDeviceDispense.whenHandedOverDate = $wire.form.encounter.periodDate || '';
            this.modalDeviceDispense.whenHandedOverTime = $wire.form.encounter.periodStart || '';
            this.openDeviceDispenseDrawer = true;
        },

        editDeviceDispense(index) {
            this.item = index;
            this.modalDeviceDispense = new DeviceDispense(this.deviceDispenses[index]);
            this.newDeviceDispense = false;
            this.openDeviceDispenseDrawer = true;
        },

        saveDeviceDispense() {
            const deviceDispense = JSON.parse(JSON.stringify(this.modalDeviceDispense));

            if (this.newDeviceDispense) {
                this.deviceDispenses.push(deviceDispense);
            } else {
                this.deviceDispenses.splice(this.item, 1, deviceDispense);
            }

            this.openDeviceDispenseDrawer = false;
        },

        canSaveDeviceDispense() {
            return this.modalDeviceDispense.performerId &&
                this.modalDeviceDispense.locationId &&
                this.modalDeviceDispense.whenHandedOverDate &&
                this.modalDeviceDispense.whenHandedOverTime &&
                Number.isInteger(Number(this.modalDeviceDispense.quantity)) &&
                Number(this.modalDeviceDispense.quantity) > 0 &&
                this.modalDeviceDispense.deviceSelectionType &&
                (this.modalDeviceDispense.deviceSelectionType !== 'type' || this.modalDeviceDispense.deviceCode) &&
                (this.modalDeviceDispense.deviceSelectionType !== 'model' || this.modalDeviceDispense.deviceDefinitionId);
        }
    }"
>
    <div class="space-y-4">
        <template x-for="(deviceDispense, index) in deviceDispenses" :key="deviceDispense.uuid || index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                    <label for="deviceDispenseRecord" class="sr-only">{{ __('forms.select') }}</label>
                        <input
                            type="checkbox" id="deviceDispenseRecord"
                            class="default-checkbox h-5 w-5"
                            disabled
                        />
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('device-dispenses.product') }}</div>
                        <div class="record-inner-value text-[16px]" x-text="deviceName(deviceDispense)"></div>
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
                                    @click.prevent="editDeviceDispense(index)"
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
                                                editDeviceDispense(index);
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            type="button"
                                            class="dropdown-delete"
                                            @click.prevent="
                                                deviceDispenses.splice(index, 1);
                                                close($refs.button);
                                            "
                                        >
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
                                <div class="record-inner-label">{{ __('device-dispenses.date_and_time') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="`${deviceDispense.whenHandedOverDate || '-'} ${deviceDispense.whenHandedOverTime || ''}`"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('device-dispenses.procedure_id') }}</div>
                                <div class="record-inner-subvalue break-all" x-text="deviceDispense.partOfId || '-'"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('device-dispenses.care_plan_id') }}</div>
                                <div
                                    class="record-inner-subvalue break-all"
                                    x-text="carePlanId(deviceDispense.basedOnId)"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">
                                    {{ __('device-dispenses.related_prescription_episode_id') }}
                                </div>
                                <div
                                    class="record-inner-subvalue break-all"
                                    x-text="deviceDispense.originEpisodeId || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('device-dispenses.legal_entity') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="deviceDispense.legalEntityName || legalEntityName"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('device-dispenses.employee') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="deviceDispense.performerName || employeeName(deviceDispense.performerId)"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('device-dispenses.created_at') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="deviceDispense.createdDate || $wire.form.encounter.periodDate || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    <div class="record-inner-label">
                                        {{ __('device-dispenses.specify_type_or_model') }}
                                    </div>
                                    <div
                                        class="record-inner-subvalue"
                                        x-text="deviceSelectionTypeName(deviceDispense)"
                                    ></div>
                                </div>

                                <div>
                                    <div
                                        class="record-inner-label"
                                        x-text="
                                            deviceDispense.deviceSelectionType === 'model'
                                                ? '{{ __('device-dispenses.device_model') }}'
                                                : '{{ __('device-dispenses.device_type') }}'
                                        "
                                    ></div>
                                    <div
                                        class="record-inner-subvalue"
                                        x-text="deviceName(deviceDispense)"
                                    ></div>
                                </div>

                                <div class="col-span-2 xl:col-span-4">
                                    <div class="record-inner-label">
                                        {{ __('device-dispenses.supporting_info') }}
                                    </div>

                                    <template x-if="! deviceDispense.supportingInfo?.length">
                                        <div class="record-inner-subvalue">-</div>
                                    </template>

                                    <template x-if="deviceDispense.supportingInfo?.length">
                                        <div class="space-y-1">
                                            <template
                                                x-for="supporting in deviceDispense.supportingInfo"
                                                :key="supporting.uuid"
                                            >
                                                <div
                                                    class="record-inner-subvalue"
                                                    x-text="supportingInfoName(supporting)"
                                                ></div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="statusName(deviceDispense.status)"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    @unless ($isReadonly ?? false)
        <button
            type="button"
            @click.prevent="createDeviceDispense()"
            class="item-add my-5"
        >
            {{ __('device-dispenses.dispense') }}
        </button>
    @endunless

    <x-dialog-drawer x-model="openDeviceDispenseDrawer" maxWidth="4/5" wire:ignore>
        <x-slot name="title">{{ __('device-dispenses.new') }}</x-slot>

        <form>
            <fieldset @disabled($isReadonly ?? false) @class(['pointer-event-none' => $isReadonly ?? false])>
                <div class="form-row-2">
                    <div class="form-group group">
                        <select
                            x-model="modalDeviceDispense.basedOnId"
                            id="deviceDispenseBasedOn"
                            class="input-select peer"
                        >
                            <option value="">{{ __('forms.select') }}</option>
                            <template x-for="deviceRequest in activeDeviceRequests()" :key="deviceRequest.uuid">
                                <option
                                    :value="deviceRequest.uuid"
                                    x-text="[deviceRequest.requestNumber, deviceRequest.itemName].filter(Boolean).join(' — ')"
                                ></option>
                            </template>
                        </select>
                        <label for="deviceDispenseBasedOn" class="label">
                            {{ __('device-dispenses.prescription_erequest') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select
                            x-model="modalDeviceDispense.partOfId"
                            id="deviceDispenseProcedure"
                            class="input-select peer"
                        >
                            <option value="">{{ __('forms.select') }}</option>
                            <template x-for="procedure in procedures" :key="procedure.uuid">
                                <option :value="procedure.uuid" x-text="procedureLabel(procedure)"></option>
                            </template>
                        </select>
                        <label for="deviceDispenseProcedure" class="label">
                            {{ __('procedures.link') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-2 mt-6">
                    <div class="form-group group">
                        <select
                            x-model="modalDeviceDispense.performerId"
                            id="deviceDispensePerformer"
                            class="input-select peer"
                            required
                        >
                            <option value="">{{ __('forms.select') }}</option>
                            @foreach ($deviceDispenseEmployees as $employee)
                                <option value="{{ $employee['uuid'] }}">{{ $employee['name'] }}</option>
                            @endforeach
                        </select>
                        <label for="deviceDispensePerformer" class="label">
                            {{ __('device-dispenses.employee') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select
                            x-model="modalDeviceDispense.locationId"
                            id="deviceDispenseLocation"
                            class="input-select peer"
                            required
                        >
                            <option value="">{{ __('forms.select') }}</option>
                            @foreach ($divisions as $division)
                                <option value="{{ $division['uuid'] }}">{{ $division['name'] }}</option>
                            @endforeach
                        </select>
                        <label for="deviceDispenseLocation" class="label">
                            {{ __('device-dispenses.division') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-2 mt-6">
                    <div class="form-group group relative flex justify-between">
                        <div class="datepicker-wrapper flex-1">
                            <input
                                x-model="modalDeviceDispense.whenHandedOverDate"
                                :datepicker-max-date="$wire.form.encounter.periodDate"
                                type="text"
                                id="deviceDispenseWhenHandedOverDate"
                                autocomplete="off"
                                class="datepicker-input with-leading-icon input peer rounded-r-none border-r-0"
                                placeholder=" "
                                required
                            />
                            <label for="deviceDispenseWhenHandedOverDate" class="wrapped-label">
                                {{ __('device-dispenses.date_and_time') }}
                            </label>
                        </div>

                        <div class="relative -ml-px w-32">
                            <input
                                x-model="modalDeviceDispense.whenHandedOverTime"
                                type="time"
                                id="deviceDispenseWhenHandedOverTime"
                                class="input peer rounded-l-none pl-10"
                                placeholder=" "
                                required
                            />
                            @icon('clock', 'svg-input left-2.5 text-gray-400')
                        </div>
                    </div>

                    <div class="form-group group relative">
                        <input
                            x-model.number="modalDeviceDispense.quantity"
                            type="number"
                            min="1"
                            step="1"
                            id="deviceDispenseQuantity"
                            class="input peer"
                            placeholder=" "
                            required
                        />
                        <label for="deviceDispenseQuantity" class="label">
                            {{ __('device-dispenses.quantity_integer') }}
                        </label>
                        <button
                            type="button"
                            @click="modalDeviceDispense.quantity = null"
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400"
                            tabindex="-1"
                        >
                            @icon('close', 'h-4 w-4')
                        </button>
                    </div>
                </div>

                <div class="form-row-2 mt-6">
                    <div class="form-group group">
                        <select
                            x-model="modalDeviceDispense.deviceSelectionType"
                            @change="
                                modalDeviceDispense.deviceCode = '';
                                modalDeviceDispense.deviceDefinitionId = '';
                            "
                            id="deviceDispenseSelectionType"
                            class="input-select peer"
                            required
                        >
                            <option value="">{{ __('forms.select') }}</option>
                            <option value="type">{{ __('device-dispenses.type') }}</option>
                            <option value="model">{{ __('device-dispenses.model') }}</option>
                        </select>
                        <label for="deviceDispenseSelectionType" class="label">
                            {{ __('device-dispenses.specify_type_or_model') }}
                        </label>
                    </div>

                    <div>
                        <template x-if="modalDeviceDispense.deviceSelectionType === 'type'">
                            <div class="form-group group">
                                <select
                                    x-model="modalDeviceDispense.deviceCode"
                                    id="deviceDispenseDeviceType"
                                    class="input-select peer"
                                    required
                                >
                                    <option value="">{{ __('forms.select') }}</option>
                                    @foreach ($this->dictionaries['device_definition_classification_type'] as $code => $classificationType)
                                        <option value="{{ $code }}">{{ $classificationType }}</option>
                                    @endforeach
                                </select>
                                <label for="deviceDispenseDeviceType" class="label">
                                    {{ __('device-dispenses.device_type') }}
                                </label>
                            </div>
                        </template>

                        <template x-if="modalDeviceDispense.deviceSelectionType === 'model'">
                            <div class="form-group group">
                                <select
                                    x-model="modalDeviceDispense.deviceDefinitionId"
                                    id="deviceDispenseDeviceModel"
                                    class="input-select peer"
                                    required
                                >
                                    <option value="">{{ __('forms.select') }}</option>
                                    <template x-for="deviceDefinition in deviceDefinitions" :key="deviceDefinition.id">
                                        <option :value="deviceDefinition.id" x-text="deviceDefinition.name"></option>
                                    </template>
                                </select>
                                <label for="deviceDispenseDeviceModel" class="label">
                                    {{ __('device-dispenses.device_model') }}
                                </label>
                            </div>
                        </template>

                        <div class="form-group group" x-show="! modalDeviceDispense.deviceSelectionType" x-cloak>
                            <select class="input-select peer" disabled>
                                <option value="" selected>{{ __('forms.select') }}</option>
                            </select>
                            <label class="label">{{ __('device-dispenses.device_type') }}</label>
                        </div>
                    </div>
                </div>

                <div class="form-row-1 mt-6">
                    <div>
                        <div class="mt-6">
                            @include('livewire.encounter.device-dispense-parts.supporting-info')
                        </div>
                        <label for="deviceDispenseNote" class="label-modal mb-2 block">
                            {{ __('device-dispenses.note') }}
                        </label>
                        <div>
                            <textarea
                                x-model="modalDeviceDispense.note"
                                id="deviceDispenseNote"
                                class="textarea"
                                rows="4"
                                maxlength="3000"
                                placeholder="{{ __('encounters.text_for_input') }}"
                            ></textarea>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex w-full justify-start space-x-4">
                    <button type="button" @click="openDeviceDispenseDrawer = false" class="button-minor">
                        {{ __('forms.cancel') }}
                    </button>
                    @unless ($isReadonly ?? false)
                        <button
                            type="button"
                            @click="saveDeviceDispense()"
                            class="button-primary"
                            :disabled="! canSaveDeviceDispense()"
                        >
                            {{ __('forms.add') }}
                        </button>
                    @endunless
                </div>
            </fieldset>
        </form>
    </x-dialog-drawer>
</div>

<script>
    class DeviceDispense {
        constructor(obj = null) {
            this.uuid = crypto.randomUUID();
            this.basedOnId = '';
            this.partOfId = '';
            this.performerId = '';
            this.locationId = '';
            this.whenHandedOverDate = '';
            this.whenHandedOverTime = '';
            this.quantity = 1;
            this.deviceSelectionType = '';
            this.deviceCode = '';
            this.deviceDefinitionId = '';
            this.note = '';
            this.supportingInfo = [];
            this.status = 'completed';
            this.originEpisodeId = '';
            this.contextEpisodeId = '';
            this.legalEntityName = '';
            this.performerName = '';
            this.createdDate = '';

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>