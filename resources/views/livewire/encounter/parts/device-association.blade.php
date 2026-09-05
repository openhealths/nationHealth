@php
    use App\Enums\DeviceAssociation\Status as DeviceAssociationStatus;
    use App\Models\MedicalEvents\Sql\DeviceAssociation;
@endphp

<div
    class="p-4 sm:p-8"
    id="device-association-section"
    x-data="{
        deviceAssociations: $wire.entangle('deviceAssociationForm.deviceAssociations'),
        patientDevices: $wire.patientDevices,
        statusesDictionary: $wire.dictionaries['device_association_statuses'],
        bodySitesDictionary: $wire.dictionaries['eHealth/body_structures'],
        modalAssociation: new DeviceAssociation(),
        newAssociation: false,
        openDeviceAssociationDrawer: false,
        item: 0,

        deviceOptions() {
            const packageDevices = (this.$wire.deviceForm.devices ?? [])
                .filter((device) => device.uuid && device.names?.[0]?.value)
                .map((device) => ({ uuid: device.uuid, name: device.names[0].value }));
            const packageDeviceIds = packageDevices.map((device) => device.uuid);

            return [
                ...packageDevices,
                ...this.patientDevices.filter((device) => ! packageDeviceIds.includes(device.uuid)),
            ];
        },

        deviceName(deviceId) {
            return this.deviceOptions().find((device) => device.uuid === deviceId)?.name || '-';
        },
    }"
>
    <div class="space-y-4">
        <template x-for="(deviceAssociation, index) in deviceAssociations" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input type="checkbox" class="default-checkbox h-5 w-5" />
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('devices.name') }}</div>
                        <div
                            class="record-inner-value text-[16px]"
                            x-text="deviceName(deviceAssociation.deviceId)"
                        ></div>
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
                                    @click.prevent="
                                        item = index;
                                        modalAssociation = new DeviceAssociation(deviceAssociations[index]);
                                        newAssociation = false;
                                        openDeviceAssociationDrawer = true;
                                    "
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
                                                item = index;
                                                modalAssociation = new DeviceAssociation(deviceAssociations[index]);
                                                newAssociation = false;
                                                openDeviceAssociationDrawer = true;
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            type="button"
                                            class="dropdown-delete"
                                            @click.prevent="
                                                deviceAssociations.splice(index, 1);
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
                                <div class="record-inner-label">{{ __('device-associations.association_status') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="statusesDictionary[deviceAssociation.status] || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">
                                    {{ __('device-associations.association_date_short') }}
                                </div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="deviceAssociation.associationDate || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('device-associations.body_site') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="bodySitesDictionary[deviceAssociation.bodySiteCode] || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('device-associations.device_id') }}</div>
                                <div class="record-inner-subvalue" x-text="deviceAssociation.deviceId || '-'"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('devices.sgusoz') }}</div>
                                <div class="record-inner-subvalue">{{ legalEntity()->name }}</div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.employee') }}</div>
                                <div class="record-inner-subvalue">{{ $employeeFullName }}</div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.created_at') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="$wire.form.encounter.periodDate || '-'"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        @unless ($isReadonly ?? false)
            @can('create', DeviceAssociation::class)
                <button
                    type="button"
                    @click.prevent="
                        newAssociation = true;
                        modalAssociation = new DeviceAssociation();
                        openDeviceAssociationDrawer = true;
                    "
                    class="item-add my-5 mt-5 flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                >
                    {{ __('device-associations.add') }}
                </button>
            @endcan
        @endunless
    </div>

    <x-dialog-drawer x-model="openDeviceAssociationDrawer" maxWidth="4/5" wire:ignore>
        <x-slot name="title">{{ __('device-associations.new') }}</x-slot>

        <form>
            <fieldset @disabled($isReadonly ?? false) @class(['pointer-event-none' => $isReadonly ?? false])>
                <fieldset class="fieldset">
                    <legend class="legend">{{ __('forms.main_information') }}</legend>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <select
                                x-model="modalAssociation.deviceId"
                                id="deviceAssociationDevice"
                                class="input-select peer"
                                required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                <template x-for="device in deviceOptions()" :key="device.uuid">
                                    <option :value="device.uuid" x-text="device.name"></option>
                                </template>
                            </select>
                            <label for="deviceAssociationDevice" class="label">
                                {{ __('device-associations.device') }}
                            </label>
                        </div>
                        <div class="form-group group">
                            <select
                                x-model="modalAssociation.status"
                                id="deviceAssociationStatus"
                                class="input-select peer"
                                required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach ($this->dictionaries['device_association_statuses'] as $code => $status)
                                    @continue($code === DeviceAssociationStatus::ENTERED_IN_ERROR->value)
                                    <option value="{{ $code }}">{{ $status }}</option>
                                @endforeach
                            </select>
                            <label for="deviceAssociationStatus" class="label">
                                {{ __('device-associations.association_status') }}
                            </label>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="fieldset">
                    <legend class="legend">{{ __('forms.additional_information') }}</legend>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <div class="datepicker-wrapper">
                                <input
                                    x-model="modalAssociation.associationDate"
                                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                    type="text"
                                    name="associationDate"
                                    id="associationDate"
                                    class="datepicker-input with-leading-icon input peer"
                                    placeholder=" "
                                    autocomplete="off"
                                />
                                <label for="associationDate" class="wrapped-label">
                                    {{ __('device-associations.association_date') }}
                                </label>
                            </div>
                        </div>
                        <div class="form-group group">
                            <select
                                x-model="modalAssociation.bodySiteCode"
                                id="deviceAssociationBodySite"
                                class="input-select peer"
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach ($this->dictionaries['eHealth/body_structures'] as $code => $bodySite)
                                    <option value="{{ $code }}">{{ $bodySite }}</option>
                                @endforeach
                            </select>
                            <label for="deviceAssociationBodySite" class="label">
                                {{ __('device-associations.body_site') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-row-1 mt-4">
                        <div>
                            <label for="deviceAssociationBodySiteText" class="label-modal mb-2 block">
                                {{ __('device-associations.body_site_comment') }}
                            </label>
                            <div>
                                <textarea
                                    x-model="modalAssociation.bodySiteText"
                                    rows="4"
                                    id="deviceAssociationBodySiteText"
                                    name="deviceAssociationBodySiteText"
                                    class="textarea"
                                    placeholder="{{ __('forms.write_comment_here') }}"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 border-t border-gray-100 pt-6 dark:border-gray-700">
                        <div class="mb-6 flex items-center gap-6">
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('medical-events.information_source') }}</span>
                            <div class="flex items-center gap-4">
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input
                                        type="radio"
                                        name="deviceAssociationPrimarySource"
                                        x-model.boolean="modalAssociation.primarySource"
                                        :checked="modalAssociation.primarySource === true"
                                        @change="
                                            modalAssociation.reportOriginCode = '';
                                            modalAssociation.reportOriginText = '';
                                        "
                                        value="true"
                                        class="default-radio"
                                    />
                                    <span class="text-sm">{{ __('medical-events.performer') }}</span>
                                </label>
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input
                                        type="radio"
                                        name="deviceAssociationPrimarySource"
                                        x-model.boolean="modalAssociation.primarySource"
                                        :checked="modalAssociation.primarySource === false"
                                        value="false"
                                        class="default-radio"
                                    />
                                    <span class="text-sm">{{ __('medical-events.other_source') }}</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-row-2" x-show="modalAssociation.primarySource === false" x-cloak>
                            <div class="form-group group">
                                <select
                                    x-model="modalAssociation.reportOriginCode"
                                    id="deviceAssociationReportOrigin"
                                    class="input-select peer"
                                    required
                                >
                                    <option value="" selected>{{ __('forms.select') }}</option>
                                    @foreach ($this->dictionaries['eHealth/report_origins'] as $code => $reportOrigin)
                                        <option value="{{ $code }}">{{ $reportOrigin }}</option>
                                    @endforeach
                                </select>
                                <label for="deviceAssociationReportOrigin" class="label">
                                    {{ __('medical-events.source_link') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <div class="mt-6 flex w-full justify-start space-x-4">
                    <button type="button" @click="openDeviceAssociationDrawer = false" class="button-minor">
                        {{ __('forms.cancel') }}
                    </button>

                    @unless ($isReadonly ?? false)
                        <button
                            type="button"
                            @click.prevent="
                                newAssociation !== false
                                    ? deviceAssociations.push(modalAssociation)
                                    : (deviceAssociations[item] = modalAssociation);
                                openDeviceAssociationDrawer = false;
                            "
                            class="button-primary"
                            :disabled="! (
                                modalAssociation.deviceId?.trim?.() &&
                                modalAssociation.status?.trim?.() &&
                                (modalAssociation.primarySource || modalAssociation.reportOriginCode?.trim?.())
                            )"
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
    /**
     * Representation of the association of a medical device with the patient
     */
    class DeviceAssociation {
        constructor(obj = null) {
            this.uuid = obj?.uuid || crypto.randomUUID();
            this.deviceId = '';
            this.status = '';
            this.associationDate = '';
            this.bodySiteCode = '';
            this.bodySiteText = '';
            this.primarySource = true;
            this.reportOriginCode = '';
            this.reportOriginText = '';
            this.recorded = '';

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
