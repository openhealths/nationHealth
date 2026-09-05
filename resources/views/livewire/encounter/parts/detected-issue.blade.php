<div
    class="p-4 sm:p-8"
    id="detected-issue-section"
    x-data="{
        detectedIssues: $wire.entangle('detectedIssueForm.detectedIssues'),
        devices: $wire.entangle('deviceForm.devices'),
        patientDevices: $wire.patientDevices,
        previousDetectedIssues: $wire.entangle('previousDetectedIssues'),
        issueStatuses: @js($this->dictionaries['detected_issue_statuses'] ?? []),
        issueCodes: @js($this->dictionaries['detected_issue_codes'] ?? []),
        reportOrigins: @js($this->dictionaries['eHealth/report_origins'] ?? []),
        modalDetectedIssue: new DetectedIssue(),
        openProblemDrawer: false,
        newDetectedIssue: false,
        item: null,
        isReadonly: @js($isReadonly ?? false),

        availableDevices() {
            const packageDevices = (this.devices || [])
                .filter((device) => device.uuid)
                .map((device) => ({
                    uuid: device.uuid,
                    name: device.names?.[0]?.value || device.modelNumber || device.uuid,
                }));

            const packageDeviceIds = packageDevices.map((device) => device.uuid);

            const existingDevices = (this.patientDevices || [])
                .filter((device) => !packageDeviceIds.includes(device.uuid))
                .map((device) => ({
                    uuid: device.uuid,
                    name: device.name || device.uuid,
                }));

            return [
                ...packageDevices,
                ...existingDevices,
            ];
        },

        deviceName(uuid) {
            if (!uuid) {
                return '-';
            }

            return (
                this.availableDevices().find((device) => device.uuid === uuid)?.name || uuid
            );
        },

        issueStatusName(status) {
            return this.issueStatuses?.[status] || status || '-';
        },

        issueCodeName(code) {
            return this.issueCodes?.[code] || code || '-';
        },

        previousIssueOptions() {
            return (this.previousDetectedIssues || []).filter(
                (issue) => issue.uuid && issue.uuid !== this.modalDetectedIssue.uuid
            );
        },

        previousIssueLabel(issue) {
            const type = this.issueCodeName(issue.code);

            return issue.identifiedDate ? `${type} ${issue.identifiedDate}` : type;
        },

        async loadPreviousIssues(resetSelection = true) {
            if (resetSelection) {
                this.modalDetectedIssue.basedOnId = '';
            }

            this.previousDetectedIssues = [];

            if (!this.modalDetectedIssue.subjectId) {
                return;
            }

            await $wire.loadPreviousDetectedIssues(this.modalDetectedIssue.subjectId);
        },

        openNewDetectedIssue() {
            this.newDetectedIssue = true;
            this.item = null;
            this.previousDetectedIssues = [];

            this.modalDetectedIssue = new DetectedIssue();
            this.modalDetectedIssue.identifiedDate = $wire.form.encounter.periodDate || '';
            this.modalDetectedIssue.identifiedTime = $wire.form.encounter.periodStart || '';

            this.openProblemDrawer = true;
        },

        async openExistingDetectedIssue(index) {
            this.item = index;
            this.newDetectedIssue = false;
            this.modalDetectedIssue = new DetectedIssue(this.detectedIssues[index]);

            if (!this.isReadonly) {
                this.previousDetectedIssues = [];

                if (this.modalDetectedIssue.subjectId) {
                    await this.loadPreviousIssues(false);
                }
            }

            this.openProblemDrawer = true;
        },

        saveDetectedIssue() {
            const issue = new DetectedIssue(this.modalDetectedIssue);

            if (this.newDetectedIssue) {
                this.detectedIssues.push(issue);
            } else {
                this.detectedIssues[this.item] = issue;
            }

            this.openProblemDrawer = false;
        },

        canSaveDetectedIssue() {
            if (!this.modalDetectedIssue.subjectId || !this.modalDetectedIssue.status || !this.modalDetectedIssue.code) {
                return false;
            }

            if (this.modalDetectedIssue.primarySource === false && !this.modalDetectedIssue.reportOriginCode) {
                return false;
            }

            const hasDate = !!this.modalDetectedIssue.identifiedDate;

            const hasTime = !!this.modalDetectedIssue.identifiedTime;

            if (hasDate !== hasTime) {
                return false;
            }

            return true;
        },
    }"
>
    <div class="space-y-4">
        <template
            x-for="(detectedIssue, index) in detectedIssues"
            :key="detectedIssue.uuid || index"
        >
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input
                            type="checkbox"
                            class="default-checkbox h-5 w-5"
                        />
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">
                            {{ __('detected-issues.device_name') }}
                        </div>

                        <div
                            class="record-inner-value text-[16px]"
                            x-text="deviceName(detectedIssue.subjectId)"
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
                                    if (!this.openDropdown) {
                                        return;
                                    }

                                    this.openDropdown = false;

                                    if (focusAfter) {
                                        focusAfter.focus();
                                    }
                                },
                            }"
                            @keydown.escape.prevent.stop="close($refs.button)"
                            @focusin.window="
                                $refs.panel &&
                                !$refs.panel.contains($event.target) &&
                                close()
                            "
                            x-id="['dropdown-button']"
                            class="relative"
                        >
                            @if ($isReadonly ?? false)
                                <button
                                    type="button"
                                    @click.prevent="openExistingDetectedIssue(index)"
                                    class="record-inner-action-btn cursor-pointer"
                                    title="{{ __('forms.view') }}"
                                >
                                    @icon('eye', 'w-6 h-6')

                                    <span class="sr-only">
                                        {{ __('forms.view') }}
                                    </span>
                                </button>
                            @else
                                <button
                                    x-ref="button"
                                    @click="toggle()"
                                    :aria-expanded="openDropdown"
                                    :aria-controls="$id('dropdown-button')"
                                    type="button"
                                    class="record-inner-action-btn cursor-pointer"
                                >
                                    @icon(
                                        'edit-user-outline',
                                        'w-6 h-6 text-gray-800 dark:text-gray-200'
                                    )
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
                                                openExistingDetectedIssue(index);
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            type="button"
                                            class="dropdown-delete"
                                            @click.prevent="
                                                detectedIssues.splice(index, 1);
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
                        <div
                            class="grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-3"
                        >
                            <div>
                                <div class="record-inner-label">
                                    {{ __('detected-issues.device_id') }}
                                </div>

                                <div
                                    class="record-inner-subvalue"
                                    x-text="
                                        detectedIssue.subjectId || '-'
                                    "
                                ></div>
                            </div>

                            <div>
                                <div class="record-inner-label">
                                    {{ __('forms.status.label') }}
                                </div>

                                <div
                                    class="record-inner-subvalue"
                                    x-text="
                                        issueStatusName(detectedIssue.status)
                                    "
                                ></div>
                            </div>

                            <div>
                                <div class="record-inner-label">
                                    {{ __('detected-issues.identified_at_short') }}
                                </div>

                                <div
                                    class="record-inner-subvalue"
                                    x-text="
                                        [
                                            detectedIssue.identifiedDate,
                                            detectedIssue.identifiedTime
                                        ]
                                            .filter(Boolean)
                                            .join(' ') || '-'
                                    "
                                ></div>
                            </div>

                            <div>
                                <div class="record-inner-label">
                                    {{ __('detected-issues.sgusoz') }}
                                </div>

                                <div class="record-inner-subvalue">
                                    {{ legalEntity()->name }}
                                </div>
                            </div>

                            <div>
                                <div class="record-inner-label">
                                    {{ __('forms.employee') }}
                                </div>

                                <div class="record-inner-subvalue">
                                    {{ $employeeFullName }}
                                </div>
                            </div>

                            <div>
                                <div class="record-inner-label">
                                    {{ __('detected-issues.record_created_at') }}
                                </div>

                                <div
                                    class="record-inner-subvalue"
                                    x-text="
                                        $wire.form.encounter.periodDate || '-'
                                    "
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
            @click.prevent="openNewDetectedIssue()"
            class="item-add my-5 mt-5 flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
        >
            {{ __('detected-issues.add') }}
        </button>
    @endunless

    <x-dialog-drawer
        x-model="openProblemDrawer"
        maxWidth="4/5"
        wire:ignore
    >
        <x-slot name="title">
            {{ __('detected-issues.new') }}
        </x-slot>

        <form>
            <fieldset
                @disabled($isReadonly ?? false)
                @class([
                    'pointer-event-none' => $isReadonly ?? false,
                ])
            >
                {{-- Main information --}}
                <fieldset class="fieldset">
                    <legend class="legend">
                        {{ __('forms.main_information') }}
                    </legend>

                    <div class="form-row-2">
                        {{-- Subject device --}}
                        <div class="form-group group">
                            <select
                                x-model="modalDetectedIssue.subjectId"
                                @change="loadPreviousIssues()"
                                id="detectedIssueSubject"
                                class="input-select peer"
                                required
                            >
                                <option value="">
                                    {{ __('forms.select') }}
                                </option>

                                <template
                                    x-for="
                                        device in availableDevices()
                                    "
                                    :key="device.uuid"
                                >
                                    <option
                                        :value="device.uuid"
                                        x-text="device.name"
                                    ></option>
                                </template>
                            </select>

                            <label
                                for="detectedIssueSubject"
                                class="label"
                            >
                                {{ __('detected-issues.device') }}
                            </label>
                        </div>

                        {{-- Status --}}
                        <div class="form-group group">
                            <select
                                x-model="
                                    modalDetectedIssue.status
                                "
                                id="detectedIssueStatus"
                                class="input-select peer"
                                required
                            >
                                <option value="">
                                    {{ __('forms.select') }}
                                </option>

                                @foreach ($this->dictionaries['detected_issue_statuses'] ?? [] as $code => $status)
                                    @if (($isReadonly ?? false) || $code !== \App\Enums\DetectedIssue\Status::ENTERED_IN_ERROR->value)
                                        <option value="{{ $code }}">{{ $status }}</option>
                                    @endif
                                @endforeach
                            </select>

                            <label
                                for="detectedIssueStatus"
                                class="label"
                            >
                                {{ __('detected-issues.status') }}
                            </label>
                        </div>
                    </div>
                </fieldset>

                {{-- Additional information --}}
                <fieldset class="fieldset">
                    <legend class="legend">
                        {{ __('forms.additional_information') }}
                    </legend>

                    {{-- Date + time --}}
                    <div class="form-row-2">
                        <div
                            class="form-group group relative flex justify-between"
                        >
                            <div class="datepicker-wrapper flex-1">
                                <input
                                    x-model="
                                        modalDetectedIssue.identifiedDate
                                    "
                                    type="text"
                                    id="detectedIssueDate"
                                    autocomplete="off"
                                    datepicker-max-date="{{
                                        now()->format(
                                            config('app.date_format')
                                        )
                                    }}"
                                    class="datepicker-input with-leading-icon input peer rounded-r-none border-r-0"
                                    placeholder=" "
                                />

                                <label
                                    for="detectedIssueDate"
                                    class="wrapped-label"
                                >
                                    {{ __('detected-issues.identified_at') }}
                                </label>
                            </div>

                            <div class="relative -ml-px w-32">
                                <input
                                    x-model="
                                        modalDetectedIssue.identifiedTime
                                    "
                                    type="time"
                                    id="detectedIssueTime"
                                    class="input peer rounded-l-none pl-10"
                                    placeholder=" "
                                />

                                @icon(
                                    'clock',
                                    'svg-input left-2.5 text-gray-400'
                                )
                            </div>
                        </div>
                    </div>

                    {{-- Issue type --}}
                    <div class="form-row-2 mt-4">
                        <div class="form-group group">
                            <select
                                x-model="modalDetectedIssue.code"
                                id="detectedIssueCode"
                                class="input-select peer"
                                required
                            >
                                <option value="">
                                    {{ __('forms.select') }}
                                </option>

                                @foreach (
                                    $this->dictionaries['detected_issue_codes'] ?? []
                                    as $code => $issueCode
                                )
                                    <option value="{{ $code }}">
                                        {{ $issueCode }}
                                    </option>
                                @endforeach
                            </select>

                            <label
                                for="detectedIssueCode"
                                class="label"
                            >
                                {{ __('detected-issues.type') }}
                            </label>
                        </div>
                    </div>

                    {{-- Description --}}
                    <div class="form-row-1 mt-4">
                        <div>
                            <label
                                for="detectedIssueDetail"
                                class="label-modal mb-2 block text-sm text-gray-500"
                            >
                                {{ __('detected-issues.detail') }}
                            </label>

                            <textarea
                                x-model="
                                    modalDetectedIssue.detail
                                "
                                id="detectedIssueDetail"
                                class="textarea"
                                rows="4"
                                maxlength="3000"
                                placeholder="{{ __('detected-issues.text_for_input') }}"
                            ></textarea>
                        </div>
                    </div>

                    {{-- Implicated + Based on --}}
                    <div class="form-row-2 mt-4">
                        <div class="form-group group">
                            <select
                                x-model="modalDetectedIssue.implicatedId"
                                id="detectedIssueImplicated"
                                class="input-select peer"
                            >
                                <option value="">
                                    {{ __('forms.select') }}
                                </option>

                                <template
                                    x-for="
                                        device in availableDevices()
                                    "
                                    :key="device.uuid"
                                >
                                    <option
                                        :value="device.uuid"
                                        x-text="device.name"
                                    ></option>
                                </template>
                            </select>

                            <label
                                for="detectedIssueImplicated"
                                class="label"
                            >
                                {{ __('detected-issues.implicated_device')}}
                            </label>
                        </div>

                        <div class="form-group group">
                            <select
                                x-model="modalDetectedIssue.basedOnId"
                                id="detectedIssueBasedOn"
                                class="input-select peer"
                                :class="!modalDetectedIssue.subjectId ? 'cursor-not-allowed text-gray-400 dark:text-gray-500' : ''"
                                :disabled="!modalDetectedIssue.subjectId"
                            >
                                <option value="">{{ __('forms.select') }}</option>

                                <template x-for="issue in previousIssueOptions()" :key="issue.uuid">
                                    <option :value="issue.uuid" x-text="previousIssueLabel(issue)"></option>
                                </template>
                            </select>

                            <label
                                for="detectedIssueBasedOn"
                                class="label"
                            >
                                {{ __('detected-issues.based_on') }}
                            </label>
                        </div>
                    </div>

                    {{-- Source --}}
                    <div
                        class="mt-6 border-t border-gray-100 pt-6 dark:border-gray-700"
                    >
                        <div
                            class="mb-6 flex items-center gap-6"
                        >
                            <span
                                class="text-sm font-semibold text-gray-900 dark:text-gray-100"
                            >
                                {{ __('medical-events.information_source') }}
                            </span>

                            <div
                                class="flex items-center gap-4"
                            >
                                <label
                                    class="flex cursor-pointer items-center gap-2"
                                >
                                    <input
                                        type="radio"
                                        name="detectedIssuePrimarySource"
                                        x-model.boolean="
                                            modalDetectedIssue.primarySource
                                        "
                                        @change="
                                            modalDetectedIssue.reportOriginCode = ''
                                        "
                                        value="true"
                                        class="default-radio"
                                    />
                                    <span class="text-sm">{{ __('medical-events.performer') }}</span>
                                </label>

                                <label
                                    class="flex cursor-pointer items-center gap-2"
                                >
                                    <input
                                        type="radio"
                                        name="detectedIssuePrimarySource"
                                        x-model.boolean="
                                            modalDetectedIssue.primarySource
                                        "
                                        value="false"
                                        class="default-radio"
                                    />
                                    <span class="text-sm">{{ __('medical-events.other_source') }}</span>
                                </label>
                            </div>
                        </div>

                        <div
                            class="form-row-2"
                            x-show="
                                modalDetectedIssue.primarySource === false
                            "
                            x-cloak
                        >
                            <div class="form-group group">
                                <select
                                    x-model="
                                        modalDetectedIssue.reportOriginCode
                                    "
                                    id="detectedIssueReportOrigin"
                                    class="input-select peer"
                                    :required="
                                        modalDetectedIssue.primarySource === false
                                    "
                                >
                                    <option value="">
                                        {{ __('forms.select') }}
                                    </option>

                                    @foreach (
                                        $this->dictionaries['eHealth/report_origins'] ?? []
                                        as $code => $reportOrigin
                                    )
                                        <option value="{{ $code }}">
                                            {{ $reportOrigin }}
                                        </option>
                                    @endforeach
                                </select>
                                <label class="label">{{ __('medical-events.source_link') }}</label>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <div
                    class="mt-6 flex w-full justify-start space-x-4"
                >
                    <button
                        type="button"
                        @click="
                            openProblemDrawer = false
                        "
                        class="button-minor"
                    >
                        {{ ($isReadonly ?? false) ? __('forms.close') : __('forms.cancel') }}
                    </button>

                    @unless ($isReadonly ?? false)
                        <button
                            type="button"
                            @click.prevent="
                                saveDetectedIssue()
                            "
                            :disabled="
                                !canSaveDetectedIssue()
                            "
                            class="button-primary"
                        >
                            <span
                                x-text="newDetectedIssue ? @js(__('forms.add')) : @js(__('forms.save'))"
                            ></span>
                        </button>
                    @endunless
                </div>
            </fieldset>
        </form>
    </x-dialog-drawer>
</div>

<script>
    /**
     * Representation of a Detected Issue
     * associated with a patient's medical device.
     */
    class DetectedIssue {
        constructor(obj = null) {
            this.uuid = obj?.uuid || crypto.randomUUID();
            this.subjectId = '';
            this.status = 'preliminary';
            this.identifiedDate = '';
            this.identifiedTime = '';
            this.code = '';
            this.detail = '';
            this.implicatedId = '';
            this.basedOnId = '';
            this.primarySource = true;
            this.reportOriginCode = '';

            if (obj) {
                Object.assign(
                    this,
                    JSON.parse(JSON.stringify(obj))
                );
            }
        }
    }
</script>