<x-layouts.patient
    :showLegacyMessages="false"
    :personId="$personId"
    :patientFullName="$patientFullName"
    activeTab="referrals"
>
    @assets
        <script src="{{ asset('js/print-sandboxed.js') }}"></script>
    @endassets
    <livewire:components.x-message
        :consume-messages="true"
        :key="(string) str()->uuid()"
    />

    <x-slot name="headerActions">
        @can('viewAny', [\App\Models\MedicalEvents\Sql\DeviceRequestRequest::class, legalEntity()])
            <a
                href="{{ route('device-requests.index', ['legalEntity' => legalEntity(), 'person' => $personId]) }}"
                class="button-primary-outline"
            >{{ __('device-requests.labels.registry') }}</a>
        @endcan
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full">
            <div
                class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100"
            >
                @icon ('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('referrals.search') }}</p>
            </div>

            <form wire:submit="applyFilters">
                <div class="form-row-3 mb-6">
                    @foreach ([
                    ['from' => 'filterStartedAtFrom', 'to' => 'filterStartedAtTo', 'label' => __('forms.filter_period_start_range')],
                    ['from' => 'filterEndedAtFrom', 'to' => 'filterEndedAtTo', 'label' => __('forms.filter_period_end_range')],
                ] as $dateRange)
                        <fieldset class="min-w-0">
                            <legend
                                class="mb-2 text-sm text-gray-900 dark:text-gray-100"
                            >
                                {{ $dateRange['label'] }}
                            </legend>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach (['from' => __('referrals.registry.from'), 'to' => __('referrals.registry.to')] as $boundary => $boundaryLabel)
                                    <div class="form-group group">
                                        <input
                                            id="{{ $dateRange[$boundary] }}"
                                            type="date"
                                            class="input peer w-full"
                                            wire:model="{{ $dateRange[$boundary] }}"
                                            placeholder=" "
                                        />
                                        <label
                                            class="wrapped-label"
                                            for="{{ $dateRange[$boundary] }}"
                                        >
                                            {{ $boundaryLabel }}
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                    <div class="form-group group">
                        <select
                            id="filterStatus"
                            wire:model="filterStatus"
                            class="input-select peer w-full"
                        >
                            <option value="">{{ __('referrals.registry.all') }}</option>
                            <option value="draft">{{ __('forms.draft') }}</option>
                            <option value="new">
                                {{ __('referrals.registry.new_request') }}
                            </option>
                            <option value="active">{{ __('forms.status.active') }}</option>
                            <option value="in_progress">
                                {{ __('referrals.statuses.in_progress') }}
                            </option>
                            <option value="completed">
                                {{ __('referrals.registry.completed') }}
                            </option>
                            <option value="recalled">
                                {{ __('referrals.registry.recalled') }}
                            </option>
                            <option value="entered-in-error">
                                {{ __('forms.status.entered_in_error') }}
                            </option>
                        </select>
                        <label
                            class="label"
                            for="filterStatus"
                            >{{ __('forms.status.label') }}</label
                        >
                    </div>
                </div>

                <div class="mb-9 flex flex-wrap gap-2">
                    <button
                        type="submit"
                        class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon ('search', 'w-4 h-4')
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
            </form>

            <div class="space-y-4">
                @forelse ($referrals as $referral)
                    <div
                        class="record-inner-card"
                        wire:key="sr-{{ $referral['kind'] }}-{{ $referral['id'] ?? $referral['uuid'] }}"
                    >
                        <div class="record-inner-header">
                            <div class="record-inner-column min-w-0 flex-1">
                                <div class="record-inner-label">
                                    {{ __('referrals.requisition') }}
                                </div>
                                <div
                                    class="record-inner-value break-words text-[16px]"
                                >
                                    {{ filled($referral['requestNumber'] ?? null) ? $referral['requestNumber'] : '—' }}
                                </div>
                                <div class="record-inner-label mt-1">
                                    {{ $referral['categoryLabel'] ?? '' }}
                                </div>
                            </div>
                            <div
                                class="record-inner-column-bordered w-full shrink-0 md:w-64"
                            >
                                <div class="record-inner-label">
                                    {{ __('referrals.period') }}
                                </div>
                                <div class="record-inner-value">
                                    {{ $referral['periodLabel'] ?? '—' }}
                                </div>
                            </div>
                            <div
                                class="record-inner-column-bordered w-full shrink-0 md:w-36"
                            >
                                <div class="record-inner-label">
                                    {{ __('forms.status.label') }}
                                </div>
                                <div>
                                    <span
                                        class="badge {{ $referral['statusBadge'] ?? 'badge-dark' }}"
                                    >
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
                                            if (!this.open) return;
                                            this.open = false;
                                            focusAfter && focusAfter.focus();
                                        },
                                    }"
                                    @keydown.escape.prevent.stop="
                                        close($refs.button)
                                    "
                                    @focusin.window="
                                        !$refs.panel.contains($event.target) &&
                                            close()
                                    "
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
                                        @icon ('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                    </button>
                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-ref="panel"
                                        x-transition.origin.top.right
                                        @click.outside="close($refs.button)"
                                        :id="$id('referral-actions')"
                                        class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-600 dark:bg-gray-700"
                                    >
                                        <div class="py-1">
                                            <button
                                                type="button"
                                                class="dropdown-button !flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm"
                                                @click="close($refs.button)"
                                                wire:click="toggleDetails('{{ $referral['uuid'] }}')"
                                            >
                                                @icon ('eye', 'w-5 h-5 text-gray-500')
                                                {{ $expandedUuid === $referral['uuid'] ? __('referrals.registry.hide') : __('forms.view_details') }}
                                            </button>
                                            @if (!empty($referral['encounterId']) && $personId)
                                                <a
                                                    href="{{ route('encounter.view', [legalEntity(), 'person' => $personId, 'encounter' => $referral['encounterId']]) }}"
                                                    class="text-link text-xs"
                                                >
                                                    {{ __('Редагувати') }}
                                                </a>
                                            @elseif (!empty($referral['carePlanId']))
                                                <a
                                                    href="{{ route('care-plans.show', [legalEntity(), $referral['carePlanId']]) }}"
                                                    class="text-link text-xs"
                                                >
                                                    {{ __('Редагувати') }}
                                                </a>
                                            @endif

                                            @if (!empty($referral['canSign']))
                                                <button
                                                    type="button"
                                                    class="dropdown-button !flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm"
                                                    @click="close($refs.button)"
                                                    wire:click="openSign('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                >
                                                    @icon ('Edit3', 'w-5 h-5 text-gray-500')
                                                    {{ __('forms.sign') }}
                                                </button>
                                                @if (!empty($referral['encounterId']) && $personId)
                                                    <a
                                                        href="{{ route('encounter.edit', [legalEntity(), 'person' => $personId, 'encounter' => $referral['encounterId']]) }}"
                                                        class="dropdown-button !flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm"
                                                    >
                                                        @icon ('edit', 'w-5 h-5 text-gray-500')
                                                        {{ __('forms.edit') }}
                                                    </a>
                                                @elseif (!empty($referral['carePlanId']))
                                                    <a
                                                        href="{{ route('care-plans.show', [legalEntity(), $referral['carePlanId']]) }}"
                                                        class="dropdown-button !flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm"
                                                    >
                                                        @icon ('edit', 'w-5 h-5 text-gray-500')
                                                        {{ __('forms.edit') }}
                                                    </a>
                                                @endif
                                            @endif

                                            @if (!empty($referral['canOperate']))
                                                @if (!empty($referral['canRecall']))
                                                    <button
                                                        type="button"
                                                        class="dropdown-button !flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm"
                                                        @click="
                                                            close($refs.button)
                                                        "
                                                        wire:click="recallReferral('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                    >
                                                        @icon ('close', 'w-5 h-5 text-gray-500')
                                                        {{ __('referrals.actions.recall') }}
                                                    </button>
                                                @endif
                                                @if (!empty($referral['canCancel']))
                                                    <button
                                                        type="button"
                                                        class="dropdown-button !flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm"
                                                        @click="
                                                            close($refs.button)
                                                        "
                                                        wire:click="cancelReferral('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                    >
                                                        @icon ('alert-circle', 'w-5 h-5 text-gray-500')
                                                        {{ __('medical-events.mark_as_error') }}
                                                    </button>
                                                @endif
                                                <button
                                                    type="button"
                                                    class="dropdown-button !flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm"
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
                                                    @icon ('printer', 'w-5 h-5 text-gray-500')
                                                    {{ __('referrals.actions.print_memo') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="dropdown-button !flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm"
                                                    @click="close($refs.button)"
                                                    wire:click="resendSms('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                >
                                                    @icon ('refresh', 'w-5 h-5 text-gray-500')
                                                    SMS
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container md:!pl-4">
                                <div
                                    class="grid grid-cols-1 gap-4 sm:grid-cols-3"
                                >
                                    <div class="min-w-0">
                                        <div class="record-inner-label">
                                            {{ __('referrals.registry.service_or_device') }}
                                        </div>
                                        <div
                                            class="record-inner-value break-words"
                                        >
                                            {{ $referral['itemName'] ?? '—' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">
                                            {{ __('referrals.quantity') }}
                                        </div>
                                        <div class="record-inner-value">
                                            {{ $referral['quantity'] ?? '—' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">
                                            {{ __('referrals.registry.basis') }}
                                        </div>
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
                                                    href="{{ route('encounter.edit', [legalEntity(), 'person' => $personId, 'encounter' => $referral['encounterId']]) }}"
                                                    class="text-link"
                                                >
                                                    {{ $referral['basisLabel'] }}
                                                </a>
                                            @else
                                                {{ $referral['basisLabel'] ?? '—' }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="record-inner-id-col">
                                <div class="record-inner-label">
                                    {{ __('forms.ehealth_id') }}
                                </div>
                                <div class="record-inner-id-value">
                                    {{ $referral['uuid'] ?? '—' }}
                                </div>
                            </div>
                        </div>

                        @if ($expandedUuid === $referral['uuid'])
                            <div
                                class="border-t border-gray-200 p-4 dark:border-gray-700"
                                wire:key="sr-details-{{ $referral['uuid'] }}"
                            >
                                <div
                                    class="grid grid-cols-1 gap-4 sm:grid-cols-3"
                                >
                                    <div>
                                        <div class="record-inner-label">
                                            {{ __('encounters.priority') }}
                                        </div>
                                        <div class="record-inner-value">
                                            {{ $referral['priorityLabel'] ?? '—' }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">
                                            {{ __('care-plan.program') }}
                                        </div>
                                        <div class="record-inner-value">
                                            {{ $referral['programName'] ?? '—' }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">
                                            {{ __('forms.ehealth_id') }}
                                        </div>
                                        <div class="record-inner-id-value">
                                            {{ $referral['uuid'] }}
                                        </div>
                                    </div>
                                    <div class="sm:col-span-3">
                                        <div class="record-inner-label">
                                            {{ __('referrals.registry.note') }}
                                        </div>
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
    />
    <x-forms.loading />
</x-layouts.patient>
