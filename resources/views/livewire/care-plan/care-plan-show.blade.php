@use('App\Enums\JobStatus')
@use('App\Models\CarePlan')
@use('Carbon\CarbonImmutable')

@use('App\Livewire\CarePlan\CarePlanShow')
@use('App\Enums\CarePlanStatus')

@assets
    <script src="{{ asset('js/print-sandboxed.js') }}"></script>
@endassets

<x-layouts.patient
    :showLegacyMessages="false"
    :personId="$carePlan->person_id"
    :uuid="$carePlan->person?->uuid ?? null"
    :patientFullName="$carePlan->person?->full_name ?? ''"
    :hideNavigation="true"
    :title="__('care-plan.care_plan') . ' №' . ($carePlan->requisition ?? $carePlan->id)"
    :breadcrumbs="[
        ['label' => __('general.home') ?? 'Головна', 'url' => route('dashboard', [legalEntity()])],
        ['label' => $carePlan->person?->full_name ?? __('care-plan.patient') ?? 'Пацієнт', 'url' => route('persons.care-plans', [legalEntity(), $carePlan->person_id])],
        ['label' => __('care-plan.care_plan') . ' №' . ($carePlan->requisition ?? $carePlan->id)]
    ]"
>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />

    <x-slot name="headerActions">
        @if (filled($carePlan->uuid))
            <button type="button" class="button-sync flex items-center gap-2" wire:click="sync" wire:loading.attr="disabled" wire:target="sync">
                @icon('refresh', 'w-4 h-4')
                {{ __('forms.synchronise_with_eHealth') }}
            </button>
        @endif
    </x-slot>

    <div
        class="shift-content mt-6 pl-4"
        data-livewire-background-actions="checkPlanSubmissionStatus checkApprovalJobStatus checkPlanSyncStatus checkSmsResendStatus event:care-plan-approvals-changed event:submission-processed event:submission-rejected event:care-plan-submission-status-updated"
        x-data="{
            activeTab: 'info',
            openDropdown: false,
            showServiceDrawer: @entangle('showServiceDrawer'),
            showServiceSearchDrawer: @entangle('showServiceSearchDrawer'),
            showMedicalRecordsSearchDrawer: false,
            showMedicationDrawer: @entangle('showMedicationDrawer'),
            showMedicationSearchDrawer: @entangle('showMedicationSearchDrawer'),
            showMedicationFormDrawer: @entangle('showMedicationFormDrawer'),
            showMedicalDeviceDrawer: @entangle('showMedicalDeviceDrawer'),
            showMedicalDeviceSearchDrawer: @entangle('showMedicalDeviceSearchDrawer'),
            showMedicalDeviceFormDrawer: @entangle('showMedicalDeviceFormDrawer'),
            showReferralDrawer: @entangle('showReferralDrawer').live,
            showEPrescriptionDrawer: @entangle('showEPrescriptionDrawer').live
         }"
        @close-drawers.window="
            showServiceDrawer = false;
            showServiceSearchDrawer = false;
            showMedicalRecordsSearchDrawer = false;
            showMedicationDrawer = false;
            showMedicationSearchDrawer = false;
            showMedicationFormDrawer = false;
            showMedicalDeviceDrawer = false;
            showMedicalDeviceSearchDrawer = false;
            showMedicalDeviceFormDrawer = false;
            showReferralDrawer = false;
            showEPrescriptionDrawer = false;
        "
        wire:key="care-plan-show-container"
        @care-plan-activity-added.window="activeTab = 'activities'"
    >
        <div class="w-full max-w-screen-xl">
            @if (!$this->canManageActivities && !$this->isTerminalCarePlan && $carePlan->status !== CarePlanStatus::DRAFT->value && !$carePlan->requiresFhirImport())
                <p
                    class="mb-6 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-900 dark:bg-gray-800 dark:text-yellow-300"
                    role="status"
                >
                    {{ __('care-plan.prescribing_patient_access_required') }}
                </p>
            @endif
            @if ($carePlan->requiresFhirImport())
                <p
                    class="mb-6 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-900 dark:bg-gray-800 dark:text-yellow-300"
                    role="status"
                >
                    {{ $carePlan->status === CarePlanStatus::DRAFT->value ? __('care-plan.draft_backfill_required') : __('care-plan.full_sync_required') }}
                </p>
            @endif

            @php
                $status = is_array($carePlan->status) ? ($carePlan->status['coding'][0]['code'] ?? ($carePlan->status['text'] ?? '')) : $carePlan->status;
                $statusEnum = CarePlanStatus::fromStored($status);
                $activityCreationBlockReason = $activityCreationBlockReason ?? match (true) {
                    $carePlan->requiresFhirImport() => __($statusEnum === CarePlanStatus::DRAFT ? 'care-plan.draft_backfill_required' : 'care-plan.full_sync_required'),
                    !$this->canManageActivities && !$this->isTerminalCarePlan => __('care-plan.prescribing_patient_access_required'),
                    default => null,
                };

                $categoryLabel = $carePlan->categoryConcept?->text;
                if (!$categoryLabel) {
                    $categoryCode = is_array($carePlan->category) ? ($carePlan->category['coding'][0]['code'] ?? ($carePlan->category['text'] ?? '')) : $carePlan->category;
                    $categoryLabel = $dictionaries['care_plan_categories'][$categoryCode] ?? $categoryCode;
                }

                $intent = 'order'; // In eHealth plans always have intent 'order'
                $tos = is_array($carePlan->terms_of_service) ? ($carePlan->terms_of_service['coding'][0]['code'] ?? ($carePlan->terms_of_service['text'] ?? '')) : $carePlan->terms_of_service;
            @endphp

            @include('livewire.care-plan.parts.tabs-navigation')

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
                                    <span class="{{ $statusEnum === CarePlanStatus::DRAFT ? 'badge-red' : $statusEnum->color() }}">
                                        {{ CarePlanStatus::labelFor($status) }}
                                    </span>
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

                            @php
                                $tosUpper = strtoupper((string) ($tos ?? ''));
                                $tosMap = [
                                    'OUTPATIENT' => 'Амбулаторно',
                                    'INPATIENT' => 'Стаціонарно',
                                    'FIELD' => 'За місцем перебування пацієнта',
                                    'COMMUNITY' => 'За місцем проживання/перебування',
                                ];
                                $tosLabel = $tosMap[$tosUpper] ?? ($carePlan->care_provision_conditions ?? $tos ?? '-');
                            @endphp

                            <div class="form-group group">
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

                @if ($remotePlanDetails)
                    @include('livewire.care-plan.remote-details', ['record' => $remotePlanDetails])
                @endif

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
                                        $condition = $addressConditions->get($condId);
                                    @endphp
                                    <tr class="index-table-tr">
                                        <td class="index-table-td">
                                            {{ $condition?->onsetDate ? CarbonImmutable::parse($condition->onsetDate)->format('d.m.Y') : '-' }}
                                        </td>
                                        <td class="index-table-td-primary">
                                            {{ $condition?->code?->text ?: ($condition?->code?->coding->first()?->code ?? ($address['text'] ?? data_get($address, 'coding.0.code', '-'))) }}
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
                                                $date = $item['date'] ?? '—';
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
                                                $date = $item['date'] ?? CarbonImmutable::now()->format('d.m.Y');
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

                <div
                    class="mb-6"
                    @if (in_array($planSubmission['status'] ?? null, [JobStatus::PENDING->value, JobStatus::PROCESSING->value], true) || in_array($inpatientApprovalStatus, ['queued', 'pending'], true)) wire:poll.3s="checkPlanSubmissionStatus" @endif
                >
                    <livewire:components.submission-status
                        :record-type="CarePlan::class"
                        :record-id="$carePlan->id"
                        :record-status-label="CarePlanStatus::labelFor($carePlan->status)"
                        :edit-url="$this->canSignPlan && auth()->user()->can('update', $carePlan) ? route('care-plans.edit', [legalEntity(), $carePlan->id]) : null"
                        :key="'plan-submission-'.$carePlan->id.'-'.($planSubmission['status'] ?? 'draft')"
                    />
                </div>

                {{-- Approvals --}}
                @if (in_array($inpatientApprovalStatus, ['queued', 'pending'], true))
                    <p class="mb-4 text-sm text-gray-600 dark:text-gray-300" role="status">
                        {{ __('care-plan.inpatient_access_processing') }}
                    </p>
                @elseif ($inpatientApprovalStatus === 'failed')
                    <p class="mb-4 text-sm text-red-600 dark:text-red-400" role="alert">
                        {{ __('care-plan.inpatient_access_failed') }}
                    </p>
                @endif
                @if ($this->isInpatientPlanOfCurrentOrganization && $carePlan->status === CarePlanStatus::PENDING->value)
                    <p class="mb-4 text-sm text-gray-600 dark:text-gray-300" role="status">
                        {{ __('care-plan.inpatient_registered') }}
                    </p>
                @endif
                @if ($carePlan->status !== CarePlanStatus::DRAFT->value && ($planSubmission['status'] ?? null) !== JobStatus::PENDING->value)
                    <div class="mb-6">
                        @livewire('care-plan.care-plan-approvals', ['carePlan' => $carePlan], key('care-plan-approvals-'.$carePlan->id.'-'.$carePlan->status))
                    </div>
                @endif

                {{-- Bottom Actions --}}
                <div class="mt-8 flex flex-wrap items-center justify-start gap-4 border-t border-gray-100 pt-6 pb-12 dark:border-gray-700">
                    <a
                        href="{{ route('persons.care-plans', [legalEntity(), $carePlan->person_id]) }}"
                        class="button-minor flex items-center gap-2"
                        wire:navigate
                    >
                        @icon('arrow-left', 'w-4 h-4')
                        <span>{{ __('forms.back') }}</span>
                    </a>

                    <div class="flex flex-wrap items-center gap-4">
                        @if ($this->canSignPlan)
                            @can('update', $carePlan)
                                <button
                                    type="button"
                                    class="button-primary-outline flex items-center gap-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    disabled
                                >
                                    @icon('archive', 'w-4 h-4')
                                    <span>{{ __('forms.save') }}</span>
                                </button>
                            @endcan

                            <button
                                type="button"
                                class="button-primary-outline"
                                @click="$wire.openSignatureModal('sign_plan')"
                            >
                                {{ __('care-plan.sign_and_send_plan') }}
                            </button>
                        @elseif ($this->canRequestInpatientAccess)
                            <button
                                type="button"
                                class="button-primary"
                                wire:click="openMethodSelectionModal"
                                wire:loading.attr="disabled"
                                wire:target="openMethodSelectionModal"
                            >
                                {{ __($inpatientApprovalStatus === 'failed' ? 'care-plan.inpatient_retry_access' : 'care-plan.inpatient_get_access') }}
                            </button>
                        @elseif ($this->canRequestPatientApproval)
                            <button type="button" class="button-primary" wire:click="openMethodSelectionModal">
                                {{ __('care-plan.activate_plan_patient_approval') }}
                            </button>
                        @endif
                        @if ($this->canCancelPlan)
                            <button
                                type="button"
                                class="button-primary-outline-red"
                                @click="$wire.openSignatureModal('cancel')"
                            >
                                {{ __('care-plan.cancel_care_plan') }}
                            </button>
                        @endif
                        @if ($this->canCompletePlan)
                            <button type="button" class="button-primary" @click="$wire.openSignatureModal('complete')">
                                {{ __('care-plan.complete_care_plan') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            @include('livewire.care-plan.parts.activities-tab')
        </div>

        @if ($actionType === 'cancel')
            @include('livewire.care-plan.parts.modals.cancel-care-plan-modal', ['method' => 'sign'])
        @elseif ($actionType === 'complete')
            @include('livewire.care-plan.parts.modals.complete-care-plan-modal', ['method' => 'sign'])
        @elseif ($actionType === 'cancel_activity')
            @include('livewire.care-plan.parts.modals.cancel-activity-modal', ['method' => 'sign'])
        @elseif ($actionType === 'complete_activity')
            @include('livewire.care-plan.parts.modals.complete-activity-modal', ['method' => 'sign'])
        @elseif (in_array($actionType, ['cancel_referral', 'recall_referral'], true))
            <x-signature-modal method="sign">
                <x-slot:customFields>
                    <x-referral-status-fields
                        reason-model="statusReason"
                        letter-model="referralExplanatoryLetter"
                        :reasons="$this->statusReasons"
                    />
                </x-slot:customFields>
            </x-signature-modal>
        @else
            @include('components.signature-modal', ['method' => 'sign'])
        @endif

        @if ($isPolling)
            <div wire:poll.3s.keep-alive="checkApprovalJobStatus" class="hidden"></div>
        @endif

        @if ($planSyncBatchId !== null)
            <div wire:poll.3s="checkPlanSyncStatus" role="status" class="text-sm">
                {{ __('care-plan.sync_processing') }}
            </div>
        @endif

        @if ($showAuthModal)
            @include('livewire.care-plan.modals.authentication')
        @endif

        @if ($showMethodSelectionModal)
            @include('livewire.care-plan.modals.method-selection')
        @endif

        <x-confirmation-modal wire:model.live="showActivationPrompt">
            <x-slot name="title">{{ __('care-plan.activation_prompt_title') }}</x-slot>
            <x-slot name="content">{{ __('care-plan.activation_prompt_content') }}</x-slot>
            <x-slot name="footer">
                <x-secondary-button wire:click="dismissPlanActivation">
                    {{ __('care-plan.activation_later') }}
                </x-secondary-button>
                <x-button wire:click="acceptPlanActivation" wire:loading.attr="disabled" class="ml-3">
                    {{ __('care-plan.activation_accept') }}
                </x-button>
            </x-slot>
        </x-confirmation-modal>

        {{-- Drawers --}}
        @include('livewire.care-plan.parts.modals.services-drawer')
        @include('livewire.care-plan.parts.modals.service-search-drawer')
        @include('livewire.care-plan.parts.modals.medical-records-search-drawer')
        @include('livewire.care-plan.parts.modals.medications-drawer')
        @include('livewire.care-plan.parts.modals.medication-search-drawer')
        @include('livewire.care-plan.parts.modals.medication-form-drawer')
        @include('livewire.care-plan.parts.modals.medical-devices-drawer')
        @include('livewire.care-plan.parts.modals.medical-device-search-drawer')
        @include('livewire.care-plan.parts.modals.medical-device-form-drawer')
        @include('livewire.care-plan.parts.modals.referral-form-drawer')
        @include('livewire.care-plan.parts.modals.eprescription-form-drawer')

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
