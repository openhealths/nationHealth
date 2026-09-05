<div>
    <livewire:components.x-message :listen-async="true" :key="time()" />

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
                    @forelse ($approvals as $approval)
                        <tr class="index-table-tr">
                            <td class="index-table-td">
                                <div class="flex flex-col">
                                    <span class="font-medium text-gray-900 dark:text-white">
                                        {{ $approval['grantedToDetails']['name'] ?? $approval['granted_to_details']['name'] ?? '-' }}
                                    </span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $approval['grantedToDetails']['description'] ?? $approval['granted_to_details']['description'] ?? '' }}
                                    </span>
                                </div>
                            </td>
                            <td class="index-table-td">
                                @php
                                    $approvalStatus = \App\Enums\Person\ApprovalStatus::resolve($approval['status'] ?? null);
                                @endphp
                                <span class="badge {{ \App\Enums\Person\ApprovalStatus::colorFor($approval['status'] ?? null) }}">
                                    {{ \App\Enums\Person\ApprovalStatus::labelFor($approval['status'] ?? null) }}
                                </span>
                            </td>
                            <td class="index-table-td">
                                {{ isset($approval['createdAt']) || isset($approval['created_at']) ? \Carbon\Carbon::parse($approval['createdAt'] ?? $approval['created_at'])->format('d.m.Y H:i') : '-' }}
                            </td>
                            <td class="index-table-td-actions text-right">
                                @if ($approvalStatus?->isGranted() && !$isReadOnly)
                                    <button
                                        type="button"
                                        wire:click="cancelApproval('{{ $approval['uuid'] }}')"
                                        wire:confirm="{{ __('care-plan.confirm_cancel_approval') }}"
                                        class="p-1 text-red-500 hover:text-red-700"
                                    >
                                        @icon('close-outline', 'w-4 h-4')
                                    </button>
                                @elseif ($approvalStatus?->isAwaitingPatient() && !$isReadOnly)
                                    <div class="flex items-center justify-end gap-2">
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

                        @if (!empty($authMethods))
                            <div class="form-group group">
                                <select
                                    class="input-select peer"
                                    wire:model="selectedAuthMethodUuid"
                                    id="selectedAuthMethodUuid"
                                >
                                    <option value="">{{ __('care-plan.choose_auth_method') }}</option>
                                    @foreach ($authMethods as $method)
                                        <option value="{{ $method['id'] ?? $method['uuid'] }}">
                                            @if (($method['type'] ?? '') === 'OTP')
                                                SMS ({{ $method['phone_number'] ?? '' }})
                                            @elseif (($method['type'] ?? '') === 'OFFLINE')
                                                {{ __('care-plan.offline_paper') }}
                                            @else
                                                {{ $method['type'] ?? __('care-plan.other') }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                <label for="selectedAuthMethodUuid" class="label">
                                    {{ __('care-plan.auth_method') }} *
                                </label>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 flex justify-end">
                        <button type="submit" class="button-primary" wire:loading.attr="disabled">
                            <span wire:loading.remove>{{ __('care-plan.grant_access_btn') }}</span>
                            <span wire:loading>{{ __('forms.loading') }}</span>
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </fieldset>

    @include('livewire.care-plan.modals.authentication')
</div>
