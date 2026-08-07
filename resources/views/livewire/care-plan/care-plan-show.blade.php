@use('App\Livewire\CarePlan\CarePlanShow')
@use('App\Enums\CarePlanStatus')
@use('App\Enums\Status')

<x-layouts.patient
    :personId="$carePlan->person_id"
    :uuid="$carePlan->person?->uuid ?? null"
    :patientFullName="$carePlan->person?->full_name ?? ''"
    :hideNavigation="true"
    :breadcrumbs="[
        ['label' => __('general.home') ?? 'Головна', 'url' => route('dashboard', [legalEntity()])],
        ['label' => $carePlan->person?->full_name ?? __('care-plan.patient') ?? 'Пацієнт', 'url' => route('persons.care-plans', [legalEntity(), $carePlan->person_id])],
        ['label' => __('care-plan.care_plan') . ' №' . ($carePlan->requisition ?? $carePlan->id)]
    ]"
>
    <x-slot name="headerActions"></x-slot>

    <div
        class="shift-content mt-6 pl-4"
        x-data="{
            activeTab: 'info',
            openDropdown: false,
            showServiceDrawer: @entangle('showServiceDrawer'),
            showServiceSearchDrawer: @entangle('showServiceSearchDrawer'),
            showMedicationDrawer: @entangle('showMedicationDrawer'),
            showMedicationSearchDrawer: @entangle('showMedicationSearchDrawer'),
            showMedicationFormDrawer: @entangle('showMedicationFormDrawer'),
            showMedicalDeviceDrawer: @entangle('showMedicalDeviceDrawer'),
            showMedicalDeviceSearchDrawer: @entangle('showMedicalDeviceSearchDrawer'),
            showMedicalDeviceFormDrawer: @entangle('showMedicalDeviceFormDrawer')
         }"
        @close-drawers.window="
            showServiceDrawer = false;
            showServiceSearchDrawer = false;
            showMedicationDrawer = false;
            showMedicationSearchDrawer = false;
            showMedicationFormDrawer = false;
            showMedicalDeviceDrawer = false;
            showMedicalDeviceSearchDrawer = false;
            showMedicalDeviceFormDrawer = false;
        "
        wire:key="care-plan-show-container"
    >
        <div class="w-full max-w-screen-xl">
            @php
                $status = is_array($carePlan->status) ? ($carePlan->status['coding'][0]['code'] ?? ($carePlan->status['text'] ?? '')) : $carePlan->status;
                $statusEnum = CarePlanStatus::tryFrom(strtolower(str_replace('_', '-', (string) $status))) ?? CarePlanStatus::UNKNOWN;

                $categoryLabel = $carePlan->categoryConcept?->text ?? $carePlan->categoryConcept?->coding?->first()?->display;
                if (!$categoryLabel) {
                    $categoryCode = is_array($carePlan->category) ? ($carePlan->category['coding'][0]['code'] ?? ($carePlan->category['text'] ?? '')) : $carePlan->category;
                    $categoryLabel = $dictionaries['care_plan_categories'][$categoryCode] ?? $categoryCode;
                }

                $intent = 'order'; // In eHealth plans always have intent 'order'
                $tos = is_array($carePlan->terms_of_service) ? ($carePlan->terms_of_service['coding'][0]['code'] ?? ($carePlan->terms_of_service['text'] ?? '')) : $carePlan->terms_of_service;
            @endphp

            <!-- Tabs Navigation -->
            <div class="mb-6 flex items-center justify-between">
                <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                    <button
                        @click="activeTab = 'info'"
                        type="button"
                        :class="activeTab === 'info'
                            ? 'border-blue-500 text-blue-600 dark:text-blue-500 font-bold'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 font-medium'"
                        class="cursor-pointer border-b-2 px-1 pb-4 text-sm whitespace-nowrap transition-all"
                    >
                        {{ __('care-plan.plan_info') ?? 'Інформація про план' }}
                    </button>
                    <button
                        @click="activeTab = 'activities'"
                        type="button"
                        :class="activeTab === 'activities'
                            ? 'border-blue-500 text-blue-600 dark:text-blue-500 font-bold'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 font-medium'"
                        class="cursor-pointer border-b-2 px-1 pb-4 text-sm whitespace-nowrap transition-all"
                    >
                        {{ __('care-plan.activities') ?? 'Призначення' }} ({{ $carePlan->activities->count() }})
                    </button>
                </nav>

                @if (in_array(strtolower((string)$status), [CarePlanStatus::ACTIVE->value, CarePlanStatus::DRAFT->value, 'new', 'pending']))
                    <div class="relative pb-2">
                        <button
                            type="button"
                            @click="openDropdown = ! openDropdown"
                            @click.away="openDropdown = false"
                            class="button-primary flex items-center gap-2"
                        >
                            <span>+ {{ __('care-plan.new_prescription') }}</span>
                            @icon('chevron-down', 'w-4 h-4')
                        </button>

                        <div
                            x-show="openDropdown"
                            x-transition
                            style="display: none"
                            class="ring-opacity-5 absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-md border border-gray-100 bg-white shadow-lg ring-1 ring-black focus:outline-none dark:border-gray-600 dark:bg-gray-700"
                        >
                            <div class="py-1" role="none">
                                <button
                                    type="button"
                                    @click="
                                        openDropdown = false;
                                        showServiceDrawer = true;
                                    "
                                    wire:click="initActivityForm('service_request')"
                                    class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                                >
                                    {{ __('care-plan.service_prescription') }}
                                </button>
                                <button
                                    type="button"
                                    @click="
                                        openDropdown = false;
                                        showMedicationDrawer = true;
                                    "
                                    wire:click="initActivityForm('medication_request')"
                                    class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                                >
                                    {{ __('care-plan.medication_prescription') }}
                                </button>
                                <button
                                    type="button"
                                    @click="
                                        openDropdown = false;
                                        showMedicalDeviceDrawer = true;
                                    "
                                    wire:click="initActivityForm('device_request')"
                                    class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                                >
                                    {{ __('care-plan.medical_device_prescription') }}
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Info Tab Content -->
            <div x-show="activeTab === 'info'">
                {{-- Doctors --}}
                <fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
                    <legend class="legend">{{ __('care-plan.doctors') ?? 'Лікарі' }}</legend>

                    <div class="form">
                        <div class="form-row-2">
                            <div class="form-group group">
                                <input
                                    type="text"
                                    class="input peer"
                                    value="{{ $carePlan->author?->party?->full_name ?? '-' }}"
                                    readonly
                                />
                                <label class="label"> {{ __('care-plan.author') ?? 'Автор' }} </label>
                            </div>
                            <div class="form-group group">
                                <input
                                    type="text"
                                    class="input peer"
                                    value="{{ $carePlan->author?->party?->full_name ?? '-' }}"
                                    readonly
                                />
                                <label class="label"> {{ __('care-plan.managing_doctor') ?? 'Керуючий лікар' }} </label>
                            </div>
                        </div>
                    </div>
                </fieldset>

                {{-- Patient Data --}}
                <fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
                    <legend class="legend">{{ __('care-plan.patient_data') ?? 'Дані пацієнта' }}</legend>

                    <div class="form">
                        <div class="form-row-2">
                            <div class="form-group group">
                                <input
                                    type="text"
                                    class="input peer"
                                    value="{{ $carePlan->person?->full_name ?? '-' }}"
                                    readonly
                                />
                                <label class="label"> {{ __('care-plan.patient') ?? 'Пацієнт' }} </label>
                            </div>

                            <div class="form-group group">
                                <input
                                    type="text"
                                    class="input peer"
                                    value="{{ $carePlan->medical_number ?? ($carePlan->encounter_id ? (string)$carePlan->encounter_id : '-') }}"
                                    readonly
                                />
                                <label class="label">
                                    {{ __('care-plan.medical_number') ?? 'Медичний запис №' }}
                                </label>
                            </div>
                        </div>
                    </div>
                </fieldset>

                {{-- Care Plan Data --}}
                <fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
                    <legend class="legend">{{ __('care-plan.care_plan_data') ?? 'Дані плану лікування' }}</legend>

                    <div class="form">
                        <div class="form-row-2">
                            <div class="form-group group">
                                <input
                                    type="text"
                                    class="input peer font-mono text-sm"
                                    value="{{ $carePlan->uuid ?? '-' }}"
                                    readonly
                                />
                                <label class="label"> {{ __('care-plan.ehealth_id') }} </label>
                            </div>

                            <div class="form-group group flex items-center">
                                <div class="mt-2.5">
                                    <span class="{{ $statusEnum->color() }}"> {{ $statusEnum->label() }} </span>
                                </div>
                                <label class="label"> {{ __('care-plan.status_in_ehealth') }} </label>
                            </div>
                        </div>

                        <div class="form-row-2 mt-5">
                            <div class="form-group group">
                                <input type="text" class="input peer" value="{{ $categoryLabel ?: '-' }}" readonly />
                                <label class="label"> {{ __('care-plan.category') }} </label>
                            </div>

                            <div class="form-group group">
                                <input type="text" class="input peer" value="{{ $carePlan->title }}" readonly />
                                <label class="label"> {{ __('care-plan.name_care_plan') }} </label>
                            </div>
                        </div>

                        <div class="form-row-2 mt-5">
                            <div class="form-group group">
                                <input
                                    type="text"
                                    class="input peer"
                                    value="{{ __('care-plan.assignment') ?? $intent }}"
                                    readonly
                                />
                                <label class="label"> {{ __('care-plan.intention') }} </label>
                            </div>

                            <div class="form-group group">
                                @php
                                    $tosLabel = $carePlan->care_provision_conditions ?? $tos ?? '-';
                                @endphp
                                <input type="text" class="input peer" value="{{ $tosLabel }}" readonly />
                                <label class="label">
                                    {{ __('forms.providing_condition') ?? __('care-plan.terms_of_service') }}
                                </label>
                            </div>
                        </div>

                        <div class="form-row-2 mt-5">
                            <div class="form-group group">
                                <div class="datepicker-wrapper">
                                    <input
                                        type="text"
                                        class="datepicker-input with-leading-icon input peer"
                                        value="{{ $carePlan->period_start?->format(config('app.date_format') ?? 'd.m.Y') ?? '-' }}"
                                        readonly
                                    />
                                    <label class="wrapped-label"> {{ __('care-plan.period_start_date') }} </label>
                                </div>
                            </div>

                            <div class="form-group group">
                                <div class="datepicker-wrapper">
                                    <input
                                        type="text"
                                        class="datepicker-input with-leading-icon input peer"
                                        value="{{ $carePlan->period_end ? $carePlan->period_end->format(config('app.date_format') ?? 'd.m.Y') : __('care-plan.no_end_date') }}"
                                        readonly
                                    />
                                    <label class="wrapped-label"> {{ __('care-plan.period_end_date') }} </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>

                {{-- Condition/Diagnosis --}}
                <fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
                    <legend class="legend">{{ __('care-plan.condition_diagnosis') ?? 'Стан/діагноз' }}</legend>

                    <div class="index-table-wrapper mt-4">
                        <table class="index-table w-full">
                            <thead class="index-table-thead">
                                <tr>
                                    <th class="index-table-th w-40">{{ __('care-plan.date') }}</th>
                                    <th class="index-table-th">{{ __('care-plan.name') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($carePlan->addresses ?? [] as $address)
                                    @php
                                        $condId = is_array($address['reference'] ?? null) ? ($address['reference']['identifier']['value'] ?? null) : ($address['reference'] ?? null);
                                        if (str_contains($condId ?? '', '/')) {
                                            $condId = last(explode('/', $condId));
                                        }
                                        $condition = null;
                                        if ($condId) {
                                            $condition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $condId)->first();
                                        }
                                    @endphp
                                    <tr class="index-table-tr">
                                        <td class="index-table-td">
                                            {{ $condition?->onset_date?->format('d.m.Y') ?? '-' }}
                                        </td>
                                        <td class="index-table-td-primary">
                                            {{ $condition ? ($condition->typeConcept?->text ?? $condition->typeConcept?->coding->first()?->display ?? '-') : '-' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="index-table-td !py-3 text-center text-gray-400">
                                            {{ __('care-plan.no_diagnoses') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </fieldset>

                {{-- Supporting Information --}}
                <fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
                    <legend class="legend">{{ __('care-plan.supporting_information') }}</legend>

                    <div class="mt-4 space-y-8">
                        @php
                            $episodes = $carePlan->supporting_info['episodes'] ?? [];
                            $medical_records = $carePlan->supporting_info['medical_records'] ?? [];
                        @endphp

                        <div class="space-y-3">
                            <div class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                {{ __('care-plan.episodes') ?? 'Епізоди' }}
                            </div>
                            <div class="index-table-wrapper overflow-x-auto">
                                <table class="index-table w-full">
                                    <thead class="index-table-thead">
                                        <tr>
                                            <th class="index-table-th w-32">{{ __('care-plan.date') }}</th>
                                            <th class="index-table-th">{{ __('care-plan.name_episode') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($episodes as $item)
                                            @php
                                                $ref = $item['name'] ?? ($item['reference'] ?? ($item['uuid'] ?? '-'));
                                                $date = $item['date'] ?? \Carbon\CarbonImmutable::now()->format('d.m.Y');
                                            @endphp
                                            <tr class="index-table-tr">
                                                <td class="index-table-td">{{ $date }}</td>
                                                <td class="index-table-td-primary">{{ $ref }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="2" class="index-table-td !py-3 text-center text-gray-400">
                                                    {{ __('care-plan.no_episodes') ?? 'Немає пов\'язаних епізодів' }}
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                {{ __('care-plan.medical_records') ?? 'Медичні записи' }}
                            </div>
                            <div class="index-table-wrapper overflow-x-auto">
                                <table class="index-table w-full">
                                    <thead class="index-table-thead">
                                        <tr>
                                            <th class="index-table-th w-32">{{ __('care-plan.date') }}</th>
                                            <th class="index-table-th">{{ __('care-plan.medical_record') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($medical_records as $item)
                                            @php
                                                $ref = $item['name'] ?? ($item['reference'] ?? ($item['uuid'] ?? '-'));
                                                $date = $item['date'] ?? \Carbon\CarbonImmutable::now()->format('d.m.Y');
                                            @endphp
                                            <tr class="index-table-tr">
                                                <td class="index-table-td">{{ $date }}</td>
                                                <td class="index-table-td-primary">{{ $ref }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="2" class="index-table-td !py-3 text-center text-gray-400">
                                                    {{ __('care-plan.no_records') ?? 'Немає пов\'язаних медичних записів' }}
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </fieldset>

                {{-- Additional Information --}}
                <fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
                    <legend class="legend">{{ __('forms.additional_info') }}</legend>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-900 dark:text-white">
                            {{ __('care-plan.extended_description') }}
                        </label>
                        <div class="min-h-[90px] rounded-lg border border-gray-100 bg-gray-50 p-4 text-sm whitespace-pre-line text-gray-700 dark:border-gray-600 dark:bg-gray-700/50 dark:text-gray-300">
                            {{ $carePlan->description ?: __('care-plan.no_description') }}
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="mb-2 block text-sm font-medium text-gray-900 dark:text-white">
                            {{ __('care-plan.notes') }}
                        </label>
                        <div class="min-h-[90px] rounded-lg border border-gray-100 bg-gray-50 p-4 text-sm whitespace-pre-line text-gray-700 dark:border-gray-600 dark:bg-gray-700/50 dark:text-gray-300">
                            {{ $carePlan->note ?: __('care-plan.no_notes') }}
                        </div>
                    </div>
                </fieldset>

                {{-- Approvals --}}
                <div class="mb-6">
                    @livewire('care-plan.care-plan-approvals', ['carePlan' => $carePlan])
                </div>

                {{-- Bottom Actions --}}
                <div class="mt-8 flex items-center gap-4 pt-4 pb-12">
                    <a
                        href="{{ route('persons.care-plans', [legalEntity(), $carePlan->person_id]) }}"
                        class="button-minor flex items-center gap-2"
                        wire:navigate
                    >
                        @icon('arrow-left', 'w-4 h-4')
                        <span>{{ __('forms.back') }}</span>
                    </a>

                    @if (!$carePlan->uuid && in_array(strtoupper($status), [Status::NEW->value, 'DRAFT', 'PENDING']))
                        <button
                            type="button"
                            class="button-primary-outline"
                            @click="$wire.openSignatureModal('sign_plan')"
                        >
                            {{ __('care-plan.sign_and_send_plan') }}
                        </button>
                    @elseif ($carePlan->uuid && strtoupper($status) === 'NEW')
                        <button type="button" class="button-primary" wire:click="openMethodSelectionModal">
                            {{ __('care-plan.activate_plan_patient_approval') }}
                        </button>
                    @elseif ($carePlan->uuid && in_array(strtoupper($status), [Status::ACTIVE->value]))
                        <button
                            type="button"
                            class="button-primary-outline"
                            @click="$wire.openSignatureModal('cancel')"
                        >
                            {{ __('care-plan.cancel_care_plan') }}
                        </button>
                        <button type="button" class="button-primary" @click="$wire.openSignatureModal('complete')">
                            {{ __('care-plan.complete_care_plan') }}
                        </button>
                    @endif
                </div>
            </div>

            <!-- Activities Tab Content -->
            <div x-show="activeTab === 'activities'" style="display: none">
                <fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
                    <legend class="legend">{{ __('care-plan.activities') }}</legend>

                    <div class="index-table-wrapper mt-4">
                        <table class="index-table w-full">
                            <thead class="index-table-thead">
                                <tr>
                                    <th class="index-table-th w-[35%]">{{ __('care-plan.kind') }}</th>
                                    <th class="index-table-th w-[15%]">{{ __('care-plan.quantity') }}</th>
                                    <th class="index-table-th w-[20%]">{{ __('forms.start_date') }}</th>
                                    <th class="index-table-th w-[15%]">{{ __('forms.status.label') }}</th>
                                    <th class="index-table-th w-[15%] text-right">{{ __('forms.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($carePlan->activities ?? [] as $activity)
                                    <tr class="index-table-tr">
                                        <td class="index-table-td">
                                            @php
                                                $resolvedKind = $activity->resolvedKind();
                                                $kindTranslationKey = 'care-plan.activity_kind.' . $resolvedKind;
                                                $translatedKind = \Illuminate\Support\Facades\Lang::has($kindTranslationKey) ? __($kindTranslationKey) : $resolvedKind;
                                            @endphp
                                            <div class="font-medium text-gray-900 dark:text-white">
                                                {{ $translatedKind ?: '-' }}
                                            </div>
                                            <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                                @if ($activity->uuid)
                                                    ID:
                                                    <span class="font-mono">{{ $activity->uuid }}</span>
                                                @else
                                                    ID:
                                                    <span class="font-mono">{{ $activity->id }} ({{ __('care-plan.status.draft') }})</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="index-table-td">
                                            @if (is_array($activity->quantity))
                                                {{ $activity->quantity['value'] ?? '-' }} {{ $activity->quantity['unit'] ?? '' }}
                                            @else
                                                {{ $activity->quantity ?? '-' }}
                                            @endif
                                        </td>
                                        <td class="index-table-td">
                                            {{ $activity->scheduled_period_start?->format('d.m.Y') }}
                                        </td>
                                        <td class="index-table-td">
                                            @php
                                                $activityStatus = is_array($activity->status) ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? '')) : $activity->status;
                                                $activityStatusEnum = CarePlanStatus::tryFrom(strtolower(str_replace('_', '-', (string) $activityStatus)));
                                                $statusKey = 'care-plan.status.' . strtolower($activityStatus);
                                                $activityStatusDisplay = $activityStatusEnum?->label()
                                                    ?? (\Illuminate\Support\Facades\Lang::has($statusKey) ? __($statusKey) : (is_array($activity->status) ? ($activity->status['text'] ?? ($activity->status['coding'][0]['display'] ?? $activityStatus)) : $activityStatus));
                                                $activityBadgeColor = $activityStatusEnum?->color()
                                                    ?? (in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']) ? 'badge-yellow' : 'badge-green');
                                            @endphp
                                            <span class="{{ $activityBadgeColor }}">
                                                {{ $activityStatusDisplay }}
                                            </span>
                                        </td>
                                        <td class="index-table-td text-right">
                                            <div
                                                x-data="{
                                                    open: false,
                                                    toggle() {
                                                        if (this.open) {
                                                            return this.close();
                                                        }
                                                        this.$refs.button.focus();
                                                        this.open = true;
                                                    },
                                                    close(focusAfter) {
                                                        if (! this.open) return;
                                                        this.open = false;
                                                        focusAfter && focusAfter.focus();
                                                    },
                                                }"
                                                @keydown.escape.prevent.stop="close($refs.button)"
                                                @focusin.window="! $refs.panel.contains($event.target) && close()"
                                                x-id="['dropdown-button']"
                                                class="relative inline-block text-left"
                                            >
                                                <button
                                                    @click="toggle()"
                                                    x-ref="button"
                                                    :aria-expanded="open"
                                                    :aria-controls="$id('dropdown-button')"
                                                    type="button"
                                                    class="record-inner-action-btn inline-flex cursor-pointer items-center justify-center rounded-lg p-2 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                                >
                                                    @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                                </button>

                                                <div
                                                    x-show="open"
                                                    x-cloak
                                                    x-ref="panel"
                                                    x-transition.origin.top.right
                                                    @click.outside="close($refs.button)"
                                                    :id="$id('dropdown-button')"
                                                    class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-600 dark:bg-gray-700"
                                                >
                                                    <a
                                                        href="{{ route('care-plans.activities.show', [legalEntity(), $carePlan->id, $activity->id]) }}"
                                                        class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        wire:navigate
                                                    >
                                                        @icon('eye', 'w-5 h-5 text-gray-500')
                                                        {{ __('patients.view_details') }}
                                                    </a>

                                                    @if (in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']))
                                                        <button
                                                            type="button"
                                                            @click="close()"
                                                            wire:click="editActivity({{ $activity->id }})"
                                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('edit', 'w-5 h-5 text-gray-500')
                                                            {{ __('forms.edit') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            @click="close()"
                                                            wire:click="openSignatureModal('sign_activity', {{ $activity->id }})"
                                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('check', 'w-5 h-5 text-gray-500')
                                                            {{ __('care-plan.sign_activity') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            @click="close()"
                                                            wire:click="confirmDeleteActivity({{ $activity->id }})"
                                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('trash', 'w-5 h-5 text-gray-500')
                                                            {{ __('forms.delete') }}
                                                        </button>
                                                    @elseif (in_array(strtoupper($activityStatus), ['ACTIVE', 'SCHEDULED', 'IN-PROGRESS', 'IN_PROGRESS', 'ON-HOLD', 'PROCESSED']))
                                                        <button
                                                            type="button"
                                                            @click="close()"
                                                            wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})"
                                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('close', 'w-5 h-5 text-gray-500')
                                                            {{ __('care-plan.cancel_activity') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            @click="close()"
                                                            wire:click="openSignatureModal('complete_activity', {{ $activity->id }})"
                                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('check-circle', 'w-5 h-5 text-gray-500')
                                                            {{ __('care-plan.complete_activity') }}
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="index-table-td !py-8 text-center text-gray-400 italic">
                                            {{ __('care-plan.no_activities') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </fieldset>

                <div class="mt-8 flex items-center justify-between pt-4 pb-12">
                    <a
                        href="{{ route('persons.care-plans', [legalEntity(), $carePlan->person_id]) }}"
                        class="button-minor flex items-center gap-2"
                        wire:navigate
                    >
                        @icon('arrow-left', 'w-4 h-4')
                        <span>{{ __('forms.back') }}</span>
                    </a>
                </div>
            </div>
        </div>

        @if ($actionType === 'cancel')
            @include('livewire.care-plan.parts.modals.cancel-care-plan-modal', ['method' => 'sign'])
        @elseif ($actionType === 'complete')
            @include('livewire.care-plan.parts.modals.complete-care-plan-modal', ['method' => 'sign'])
        @elseif ($actionType === 'cancel_activity')
            @include('livewire.care-plan.parts.modals.cancel-activity-modal', ['method' => 'sign'])
        @elseif ($actionType === 'complete_activity')
            @include('livewire.care-plan.parts.modals.complete-activity-modal', ['method' => 'sign'])
        @else
            @include('components.signature-modal', ['method' => 'sign'])
        @endif

        @if ($isPolling)
            <div wire:poll.3s.keep-alive="checkApprovalJobStatus" class="hidden"></div>
        @endif

        @if ($showAuthModal)
            @include('livewire.care-plan.modals.authentication')
        @endif

        @if ($showMethodSelectionModal)
            @include('livewire.care-plan.modals.method-selection')
        @endif

        {{-- Drawers --}}
        @include('livewire.care-plan.parts.modals.services-drawer')
        @include('livewire.care-plan.parts.modals.service-search-drawer')
        @include('livewire.care-plan.parts.modals.medications-drawer')
        @include('livewire.care-plan.parts.modals.medication-search-drawer')
        @include('livewire.care-plan.parts.modals.medication-form-drawer')
        @include('livewire.care-plan.parts.modals.medical-devices-drawer')
        @include('livewire.care-plan.parts.modals.medical-device-search-drawer')
        @include('livewire.care-plan.parts.modals.medical-device-form-drawer')

        <x-confirmation-modal wire:model.live="confirmingActivityDeletion">
            <x-slot name="title">{{ __('care-plan.confirm_delete_activity_title') }}</x-slot>

            <x-slot name="content">{{ __('care-plan.confirm_delete_activity') }}</x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="cancelDeleteActivity" wire:loading.attr="disabled">
                    {{ __('forms.cancel') }}
                </x-secondary-button>

                @if ($activityToDelete)
                    <x-danger-button
                        class="ms-3"
                        wire:click="deleteActivity({{ $activityToDelete }})"
                        wire:loading.attr="disabled"
                    >
                        {{ __('forms.delete') }}
                    </x-danger-button>
                @endif
            </x-slot>
        </x-confirmation-modal>

        <x-forms.loading />
    </div>
</x-layouts.patient>
