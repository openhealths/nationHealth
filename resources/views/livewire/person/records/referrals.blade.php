@use(App\Models\MedicalEvents\Sql\DeviceRequestRequest)
@use(App\Models\MedicalEvents\Sql\ServiceRequestRequest)

<x-layouts.patient
    :showLegacyMessages="false"
    :personId="$personId"
    :patientFullName="$patientFullName"
    activeTab="referrals"
>
    @assets
        <script src="{{ asset('js/print-sandboxed.js') }}"></script>
    @endassets
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    @if ($registryPrintUuid !== null)
        <div x-data x-init="$wire.loadReferralPrintoutForm(@js($registryPrintUuid)).then((html) => { if (html) window.printSandboxedHtml(html); })"></div>
    @endif
    @include('livewire.encounter-package.parts.referral-redeem-modal')
    @include('livewire.person.records.parts.completed-referral-resource')
    @if ($syncBatchId !== null)
        <div wire:poll.5s="checkSyncStatus" role="status">{{ __('referrals.messages.sync_running') }}</div>
    @endif

    <x-slot name="headerActions">
        @can('search', ServiceRequestRequest::class)
            <button
                type="button"
                wire:click="sync"
                wire:loading.attr="disabled"
                wire:target="sync"
                @disabled($syncBatchId !== null)
                class="button-sync"
            >
                @icon('refresh', 'w-4 h-4')
                {{ __('forms.synchronise_with_eHealth') }}
            </button>
        @endcan
        @can('viewAny', DeviceRequestRequest::class)
            <a
                href="{{ route('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $personId]) }}"
                class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap"
            >{{ __('device-requests.labels.registry') }}</a>
        @endcan
    </x-slot>

    <div
        class="breadcrumb-form shift-content p-4"
        @if ($pendingCompletions !== []) wire:poll.5s="checkPendingCompletions" @endif
    >
        <div class="mt-6 w-full">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('referrals.search') }}</p>
            </div>

            <form wire:submit="applyFilters">
                <div class="form-row-3 mb-6">
                    @foreach ([
                        ['id' => 'filterStartedAtRange', 'from' => 'filterStartedAtFrom', 'to' => 'filterStartedAtTo', 'label' => __('forms.filter_period_start_range')],
                        ['id' => 'filterEndedAtRange', 'from' => 'filterEndedAtFrom', 'to' => 'filterEndedAtTo', 'label' => __('forms.filter_period_end_range')],
                    ] as $dateRange)
                        <div class="form-group group">
                            <div
                                class="datepicker-wrapper"
                                x-data="{
                                    from: $wire.entangle('{{ $dateRange['from'] }}'),
                                    to: $wire.entangle('{{ $dateRange['to'] }}'),
                                }"
                                x-effect="if (! from && ! to) $refs.input?._flatpickr?.clear(false);"
                            >
                                <input
                                    x-ref="input"
                                    id="{{ $dateRange['id'] }}"
                                    type="text"
                                    data-referral-date-range
                                    class="daterangepicker-uk with-leading-icon input peer w-full @error($dateRange['from']) input-error @enderror @error($dateRange['to']) input-error @enderror"
                                    placeholder=" "
                                    autocomplete="off"
                                    :value="[from, to]
                                        .filter(Boolean)
                                        .map((date) =>
                                            /^\d{4}-\d{2}-\d{2}$/.test(date)
                                                ? date.split('-').reverse().join('.')
                                                : date,
                                        )
                                        .join(' — ')"
                                    @date-range-change="
                                        from = $event.detail.from;
                                        to = $event.detail.to;
                                    "
                                />
                                <label class="wrapped-label" for="{{ $dateRange['id'] }}">
                                    {{ $dateRange['label'] }}
                                </label>
                            </div>
                            @error($dateRange['from'])
                                <p class="text-error text-xs">{{ $message }}</p>
                            @enderror
                            @error($dateRange['to'])
                                <p class="text-error text-xs">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach

                    <div class="form-group group">
                        <select id="filterStatus" wire:model="filterStatus" class="input-select peer w-full">
                            <option value="">{{ __('referrals.registry.all') }}</option>
                            <option value="draft">{{ __('forms.draft') }}</option>
                            <option value="new">{{ __('referrals.registry.new_request') }}</option>
                            <option value="active">{{ __('forms.status.active') }}</option>
                            <option value="in_progress">{{ __('referrals.statuses.in_progress') }}</option>
                            <option value="completed">{{ __('referrals.registry.completed') }}</option>
                            <option value="recalled">{{ __('referrals.registry.recalled') }}</option>
                            <option value="entered-in-error">{{ __('forms.status.entered_in_error') }}</option>
                        </select>
                        <label class="label" for="filterStatus">{{ __('forms.status.label') }}</label>
                    </div>
                </div>

                <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="submit"
                            class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                        >
                            @icon('search', 'w-4 h-4')
                            <span>{{ __('forms.search') }}</span>
                        </button>
                        <button
                            wire:click.prevent="resetFilters"
                            type="button"
                            class="button-primary-outline-red px-5 py-2.5 text-sm"
                        >
                            {{ __('forms.reset_all_filters') }}
                        </button>
                    </div>
                </div>
            </form>

            <div class="space-y-4">
                @forelse ($referrals as $referral)
                    <div
                        class="record-inner-card"
                        wire:key="sr-{{ $referral['kind'] }}-{{ $referral['id'] ?? $referral['uuid'] }}"
                    >
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <label
                                    for="referralRecord-{{ $referral['kind'] }}-{{ $referral['uuid'] }}"
                                    class="sr-only"
                                >{{ __('forms.select') }}</label>
                                <input
                                    type="checkbox"
                                    id="referralRecord-{{ $referral['kind'] }}-{{ $referral['uuid'] }}"
                                    class="default-checkbox h-5 w-5"
                                />
                            </div>
                            <div class="record-inner-column min-w-0 flex-1">
                                @include('livewire.person.records.parts.referral-identifier', [
                                    'identifierLabel' => __('referrals.requisition'),
                                    'identifierValue' => $referral['requestNumber'] ?? null,
                                ])
                                <div class="record-inner-label mt-2">{{ __('referrals.period') }}</div>
                                <div class="record-inner-value text-[16px] break-words">
                                    {{ $referral['periodLabel'] ?? '-' }}
                                </div>
                            </div>
                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    <span class="badge {{ $referral['statusBadge'] ?? 'badge-dark' }}">
                                        {{ $referral['statusLabel'] ?? ($referral['status'] ?? '—') }}
                                    </span>
                                </div>
                            </div>
                            <div class="record-inner-action-col">
                                <div
                                    x-data="{
                                        open: false,
                                        toggle() {
                                            if (this.open) return this.close();
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
                                    x-id="['referral-actions']"
                                    class="relative"
                                >
                                    <button
                                        @click="toggle()"
                                        x-ref="button"
                                        :aria-expanded="open"
                                        :aria-controls="$id('referral-actions')"
                                        aria-label="{{ __('forms.actions') }}"
                                        type="button"
                                        class="record-inner-action-btn cursor-pointer rounded-lg p-2 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                    >
                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                    </button>
                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-ref="panel"
                                        x-transition.origin.top.right
                                        @click.outside="close($refs.button)"
                                        :id="$id('referral-actions')"
                                        class="dropdown-menu right-0 w-56"
                                    >
                                        <div class="py-1">
                                            <button
                                                type="button"
                                                class="dropdown-item"
                                                @click="close($refs.button)"
                                                wire:click="toggleDetails('{{ $referral['uuid'] }}')"
                                            >
                                                @icon('eye', 'w-5 h-5 text-gray-500')
                                                {{ $expandedUuid === $referral['uuid'] ? __('referrals.registry.hide') : __('forms.view_details') }}
                                            </button>
                                            @if ($referral['canViewCompletedResource'] ?? false)
                                                <button
                                                    type="button"
                                                    class="dropdown-item"
                                                    @click="close($refs.button)"
                                                    wire:click="viewCompletedResource('{{ $referral['uuid'] }}')"
                                                >
                                                    {{ __('referrals.completed_resource') }}
                                                </button>
                                            @endif
                                            @if (!empty($referral['canSign']))
                                                <button
                                                    type="button"
                                                    class="dropdown-item"
                                                    @click="close($refs.button)"
                                                    wire:click="openSign('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                >
                                                    @icon('Edit3', 'w-5 h-5 text-gray-500')
                                                    {{ __('forms.sign') }}
                                                </button>

                                                @if (!empty($referral['encounterId']) && $personId)
                                                    <a
                                                        href="{{ route('persons.encounters.edit', [legalEntity(), 'person' => $personId, 'encounter' => $referral['encounterId']]) }}"
                                                        class="dropdown-item"
                                                    >
                                                        @icon('edit', 'w-5 h-5 text-gray-500')
                                                        {{ __('forms.edit') }}
                                                    </a>

                                                @elseif (!empty($referral['carePlanId']))
                                                    <a
                                                        href="{{ route('care-plans.show', [legalEntity(), $referral['carePlanId']]) }}"
                                                        class="dropdown-item"
                                                    >
                                                        @icon('edit', 'w-5 h-5 text-gray-500')
                                                        {{ __('forms.edit') }}
                                                    </a>

                                                @endif

                                            @endif

                                            @can('complete', ServiceRequestRequest::class)
                                                @if ($referral['canComplete'] ?? false)
                                                    <button
                                                        type="button"
                                                        class="dropdown-item"
                                                        @click="close($refs.button)"
                                                        wire:click="openReferralRedeemModal('{{ $referral['uuid'] }}')"
                                                    >
                                                        {{ __('encounters.redeem_referral') }}
                                                    </button>
                                                @endif
                                                @if ($referral['hasCompletion'] ?? false)
                                                    <button
                                                        type="button"
                                                        class="dropdown-item"
                                                        @click="close($refs.button)"
                                                        wire:click="checkReferralCompletion('{{ $referral['uuid'] }}')"
                                                    >
                                                        {{ __('care-plan.submission_check') }}
                                                    </button>
                                                @endif
                                            @endcan

                                            @if (!empty($referral['canOperate']))
                                                @if (!empty($referral['canRecall']))
                                                    <button
                                                        type="button"
                                                        class="dropdown-item"
                                                        @click="close($refs.button)"
                                                        wire:click="recallReferral('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                    >
                                                        @icon('close', 'w-5 h-5 text-gray-500')
                                                        {{ __('referrals.actions.recall') }}
                                                    </button>

                                                @endif

                                                @if (!empty($referral['canCancel']))
                                                    <button
                                                        type="button"
                                                        class="dropdown-item"
                                                        @click="close($refs.button)"
                                                        wire:click="cancelReferral('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                    >
                                                        @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                                        {{ __('medical-events.mark_as_error') }}
                                                    </button>

                                                @endif

                                                <button
                                                    type="button"
                                                    class="dropdown-item"
                                                    @click="
 close($refs.button);
                                                    $wire.loadReferralPrintoutForm('{{ $referral['uuid'] }}').then((html) => {
                                                        if (! html) {
                                                            return;
                                                        }
                                                        window.printSandboxedHtml(html);
                                                    });
                                                "
                                                >
                                                    @icon('printer', 'w-5 h-5 text-gray-500')
                                                    {{ __('referrals.actions.print_memo') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="dropdown-item"
                                                    @click="close($refs.button)"
                                                    wire:click="resendSms('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                >
                                                    @icon('refresh', 'w-5 h-5 text-gray-500')
                                                    SMS
                                                </button>

                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label">
                                            {{ __('referrals.registry.service_or_device') }}
                                        </div>
                                        <div class="record-inner-value break-words">
                                            {{ $referral['itemName'] ?? '—' }}
                                        </div>
                                        <div class="record-inner-label mt-1">
                                            {{ $referral['categoryLabel'] ?? '' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('referrals.quantity') }}</div>
                                        <div class="record-inner-value">{{ $referral['quantity'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('referrals.registry.basis') }}</div>
                                        <div class="record-inner-value">
                                            @if (!empty($referral['carePlanId']) && !empty($referral['activityId']))
                                                <a
                                                    href="{{ route('care-plans.activities.show', [legalEntity(), $referral['carePlanId'], $referral['activityId']]) }}"
                                                    class="text-link"
                                                >
                                                    {{ $referral['basisLabel'] }}
                                                </a>

                                            @elseif (!empty($referral['carePlanId']))
                                                <a
                                                    href="{{ route('care-plans.show', [legalEntity(), $referral['carePlanId']]) }}"
                                                    class="text-link"
                                                >
                                                    {{ $referral['basisLabel'] }}
                                                </a>

                                            @elseif (!empty($referral['encounterId']) && $personId)
                                                <a
                                                    href="{{ route('persons.encounters.edit', [legalEntity(), 'person' => $personId, 'encounter' => $referral['encounterId']]) }}"
                                                    class="text-link"
                                                >
                                                    {{ $referral['basisLabel'] }}
                                                </a>

                                            @else
                                                {{ $referral['basisLabel'] ?? '—' }}

                                            @endif
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('care-plan.program') }}</div>
                                        <div class="record-inner-value break-words">
                                            {{ $referral['programName'] ?? '—' }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="record-inner-id-col">
                                <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                                <div class="record-inner-id-value">{{ $referral['uuid'] ?? '—' }}</div>
                            </div>
                        </div>

                        @if ($expandedUuid === $referral['uuid'])
                            <div
                                class="border-t border-gray-200 p-4 dark:border-gray-700"
                                wire:key="sr-details-{{ $referral['uuid'] }}"
                            >
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div class="sm:col-span-3">
                                        @include('livewire.person.records.parts.referral-identifier', [
                                            'identifierLabel' => __('referrals.requisition'),
                                            'identifierValue' => $referral['requestNumber'] ?? null,
                                        ])
                                        @if ($referral['kind'] === 'service_request')
                                            <p class="mt-1 text-sm text-gray-500">
                                                {{ __('referrals.registry.number_hint') }}
                                            </p>
                                        @endif
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('referrals.period') }}</div>
                                        <div class="record-inner-value">{{ $referral['periodLabel'] ?? '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                        <div class="record-inner-value">{{ $referral['statusLabel'] ?? '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('referrals.category') }}</div>
                                        <div class="record-inner-value">{{ $referral['categoryLabel'] ?? '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('encounters.priority') }}</div>
                                        <div class="record-inner-value">{{ $referral['priorityLabel'] ?? '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('care-plan.program') }}</div>
                                        <div class="record-inner-value">{{ $referral['programName'] ?? '—' }}</div>
                                    </div>
                                    <div>
                                        @include('livewire.person.records.parts.referral-identifier', [
                                            'identifierLabel' => __('forms.ehealth_id').' (UUID)',
                                            'identifierValue' => $referral['uuid'],
                                        ])
                                    </div>
                                    <div class="sm:col-span-3">
                                        <div class="record-inner-label">{{ __('referrals.registry.note') }}</div>
                                        <div class="record-inner-value">
                                            {{ $referral['note'] !== '' ? $referral['note'] : '—' }}
                                        </div>
                                    </div>
                                    <div class="sm:col-span-3">
                                        <div class="record-inner-label">
                                            {{ __('referrals.registry.patient_instruction') }}
                                        </div>
                                        <div class="record-inner-value">
                                            {{ $referral['patientInstruction'] !== '' ? $referral['patientInstruction'] : '—' }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                        @endif
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>
        </div>
    </div>

    <x-signature-modal
        method="sign"
        :only-actions="['sign_referral', 'sign_devicerequest', 'recall_referral', 'cancel_referral']"
    >
        <x-slot:customFields>
            @if (in_array($actionType, ['recall_referral', 'cancel_referral'], true))
                <x-referral-status-fields
                    reason-model="referralStatusReason"
                    letter-model="referralExplanatoryLetter"
                    :reasons="$referralStatusReasons"
                />

            @endif
        </x-slot:customFields>
    </x-signature-modal>
    <x-forms.loading />
</x-layouts.patient>
