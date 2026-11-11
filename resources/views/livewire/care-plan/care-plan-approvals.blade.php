@use('Carbon\Carbon')
@use('Carbon\CarbonImmutable')
@use('App\Enums\Person\AuthenticationMethod')

<div
    @if (!$isReadOnly && !$skipsPatientOtp) wire:init="loadAuthMethods" @endif
    data-livewire-background-actions="checkApprovalJobStatus checkSmsResendStatus event:refreshApprovals"
>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />

    @if ($isPolling)
        <div wire:poll.2s="checkApprovalJobStatus" class="hidden"></div>
    @endif

    {{-- Fieldset 1: List of Approvals --}}
    <fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
        <legend class="legend flex items-center justify-between">
            <span>{{ __('care-plan.access_management') }}</span>
            @if ($isPolling)
                <span class="flex items-center gap-2 text-xs font-normal text-blue-600 dark:text-blue-400">
                    <svg class="h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    {{ __('care-plan.approval_processing') }}
                </span>
            @endif
        </legend>

        <div class="index-table-wrapper mt-4">
            <table class="index-table w-full">
                <thead class="index-table-thead">
                    <tr>
                        <th class="index-table-th">{{ __('care-plan.granted_to') }}</th>
                        <th class="index-table-th">{{ __('forms.status.label') }}</th>
                        <th class="index-table-th">{{ __('forms.date') }}</th>
                        <th class="index-table-th text-right">{{ __('forms.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        // Recheck expiry on render: Livewire can retain rows loaded before their access expired.
                        $visibleApprovals = collect($approvals)->filter(function (array $approval): bool {
                            $expiresAt = $approval['expiresAt'] ?? ($approval['expires_at'] ?? null);

                            return $expiresAt !== null
                                ? CarbonImmutable::parse($expiresAt)->isFuture()
                                : (($approval['requestPending'] ?? false) && !($approval['isVerified'] ?? ($approval['is_verified'] ?? false)));
                        });
                    @endphp
                    @forelse ($visibleApprovals as $approval)
                        <tr class="index-table-tr">
                            <td class="index-table-td">
                                <div class="flex flex-col">
                                    <span class="font-medium text-gray-900 dark:text-white">
                                        {{ $approval['grantedToDetails']['name'] ?? ($approval['granted_to_details']['name'] ?? '-') }}
                                    </span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $approval['grantedToDetails']['description'] ?? ($approval['granted_to_details']['description'] ?? '') }}
                                        @if ($approval['sharedCarePlanApproval'] ?? false)
                                            {{ __('care-plan.shared_approval') }}
                                        @endif
                                    </span>
                                </div>
                            </td>
                            <td class="index-table-td">
                                @php
                                    $isVerified = $approval['isVerified'] ?? ($approval['is_verified'] ?? false);
                                @endphp
                                <span class="badge {{ $isVerified ? 'badge-green' : 'badge-yellow' }}">
                                    {{ __($isVerified ? 'care-plan.approval_status.active' : 'care-plan.approval_status.pending') }}
                                </span>
                            </td>
                            <td class="index-table-td">
                                {{ isset($approval['createdAt']) || isset($approval['created_at']) ? Carbon::parse($approval['createdAt'] ?? $approval['created_at'])->format('d.m.Y H:i') : '-' }}
                            </td>
                            <td class="index-table-td-actions text-right">
                                @if ($approval['requestPending'] ?? false)
                                    <button
                                        type="button"
                                        wire:click="resumeApprovalRequest('{{ $approval['uuid'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="resumeApprovalRequest"
                                        class="button-minor"
                                    >
                                        {{ __('care-plan.submission_check') }}
                                    </button>
                                @elseif ($isVerified)
                                    <button
                                        type="button"
                                        wire:click="cancelApproval('{{ $approval['uuid'] }}')"
                                        wire:confirm="{{ __(($approval['sharedCarePlanApproval'] ?? false) ? 'care-plan.confirm_cancel_shared_approval' : 'care-plan.confirm_cancel_approval') }}"
                                        wire:loading.attr="disabled"
                                        wire:target="cancelApproval"
                                        class="button-secondary px-3 py-1 text-xs text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-200"
                                    >
                                        {{ __('care-plan.revoke_approval') }}
                                    </button>
                                @elseif (!$isVerified)
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="cancelApproval('{{ $approval['uuid'] }}')"
                                            wire:confirm="{{ __(($approval['sharedCarePlanApproval'] ?? false) ? 'care-plan.confirm_cancel_shared_approval' : 'care-plan.confirm_cancel_approval') }}"
                                            wire:loading.attr="disabled"
                                            wire:target="cancelApproval"
                                            class="button-secondary px-3 py-1 text-xs text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-200"
                                        >
                                            {{ __('care-plan.revoke_approval') }}
                                        </button>
                                        @if (!$isReadOnly)
                                            <button
                                                type="button"
                                                wire:click="recreateApproval('{{ $approval['uuid'] }}')"
                                                class="button-secondary px-3 py-1 text-xs text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white"
                                                title="Перестворити запит, якщо старий завис або СМС не приходить"
                                            >
                                                {{ __('care-plan.recreate_approval') ?? 'Запросити новий' }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="verifyExistingApproval('{{ $approval['uuid'] }}')"
                                                class="button-primary px-3 py-1 text-xs"
                                            >
                                                {{ __('forms.confirm') }}
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="index-table-td !py-6 text-center text-gray-400">
                                {{ __('care-plan.no_approvals_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </fieldset>

    {{-- Fieldset 2: Create New Approval Form --}}
    <fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
        <legend class="legend">{{ __('care-plan.grant_access') }}</legend>

        <div class="mt-4">
            @if (empty($carePlanUuid))
                <div
                    class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-900/30 dark:bg-gray-700/50 dark:text-yellow-300"
                    role="alert"
                >
                    {{ __('care-plan.cannot_grant_unregistered') }}
                </div>
            @elseif ($isReadOnly)
                <div
                    class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-700/50 dark:text-gray-300"
                    role="alert"
                >
                    {{ __('care-plan.cannot_grant_terminal', ['status' => $statusLabel]) }}
                </div>
            @elseif ($isPolling)
                <div
                    class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 dark:border-blue-900/30 dark:bg-gray-700/50 dark:text-blue-300"
                    role="alert"
                >
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 flex-shrink-0 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        {{ __('care-plan.approval_processing') }}
                    </div>
                </div>
            @else
                @if ($errorMessage)
                    <div
                        class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/30 dark:bg-gray-700/50 dark:text-red-400"
                        role="alert"
                    >
                        {{ $errorMessage }}
                    </div>
                @endif
                <form wire:submit.prevent="createApproval" class="form">
                    <div class="form-row-2">
                        <div class="form-group group">
                            @if (empty($employees))
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('care-plan.no_employees_found') }}
                                </p>
                            @else
                                <select
                                    class="input-select peer"
                                    id="employee_uuid"
                                    wire:model.live="newApproval.employee_uuid"
                                >
                                    <option value="">{{ __('care-plan.select_employee') }}</option>
                                    @foreach ($employees as $employee)
                                        <option value="{{ $employee['uuid'] }}">{{ $employee['label'] }}</option>
                                    @endforeach
                                </select>
                                <label for="employee_uuid" class="label"> {{ __('care-plan.employee') }} * </label>
                                @error('newApproval.employee_uuid')
                                    <p class="text-error mt-1 text-xs">{{ $message }}</p>
                                @enderror
                            @endif
                        </div>

                        @if ($skipsPatientOtp)
                            <div
                                class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-900/30 dark:bg-gray-700/50 dark:text-blue-300"
                                role="status"
                            >
                                {{ __('care-plan.approval_inpatient_no_sms') }}
                            </div>
                        @elseif (!empty($authMethods))
                            <div class="form-group group">
                                <select
                                    class="input-select peer"
                                    wire:model.live="selectedAuthMethodUuid"
                                    id="selectedAuthMethodUuid"
                                    x-effect="
                                        if ($wire.selectedAuthMethodUuid && $el.value !== $wire.selectedAuthMethodUuid)
                                            $nextTick(() => ($el.value = $wire.selectedAuthMethodUuid));
                                    "
                                >
                                    <option value="">{{ __('care-plan.choose_auth_method') }}</option>
                                    @foreach ($authMethods as $method)
                                        @continue(!in_array($method['type'] ?? '', ['OTP', 'OFFLINE', 'THIRD_PERSON'], true))
                                        @php
                                            $methodUuid = $method['id'] ?? $method['uuid'];
                                            $methodDetails = array_filter([
                                                $method['alias'] ?? null,
                                                data_get($method, 'confidantPerson.name', data_get($method, 'confidant_person.name')),
                                                AuthenticationMethod::confirmationPhone($method),
                                            ]);
                                        @endphp
                                        <option
                                            value="{{ $methodUuid }}"
                                            wire:key="care-plan-auth-method-{{ $methodUuid }}"
                                            @selected($methodUuid === $selectedAuthMethodUuid)
                                        >
                                            {{ __('care-plan.approval_method_' . strtolower($method['type'])) }}{{ $methodDetails !== [] ? ' — ' . implode(' · ', $methodDetails) : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('selectedAuthMethodUuid')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                                <label for="selectedAuthMethodUuid" class="label">
                                    {{ __('care-plan.auth_method') }} *
                                </label>
                            </div>
                        @endif
                    </div>

                    @unless ($skipsPatientOtp)
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ __('care-plan.approval_method_selection_help') }}
                        </p>
                    @endunless
                    <div class="mt-4 flex justify-end">
                        <button
                            type="submit"
                            class="button-primary"
                            wire:loading.attr="disabled"
                            wire:target="createApproval"
                            @disabled($this->selectedEmployeeHasAccess || $isPolling)
                        >
                            <span
                                wire:loading.remove
                                wire:target="createApproval"
                            >{{ __($this->selectedEmployeeHasAccess ? 'care-plan.access_already_granted' : 'care-plan.grant_access_btn') }}</span>
                            <span wire:loading wire:target="createApproval">{{ __('forms.loading') }}</span>
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </fieldset>

    @include('livewire.care-plan.modals.authentication')
</div>
