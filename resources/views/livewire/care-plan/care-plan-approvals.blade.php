<div class="mt-8">
    @if ($isPolling)
        <div wire:poll.3s.keep-alive="checkApprovalJobStatus" class="hidden"></div>
    @endif

    <div class="mb-4 flex items-center justify-between">
        <h3 class="title-sm">{{ __('care-plan.access_management') }}</h3>

        @if ($isPolling)
            <span class="flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400">
                <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                {{ __('care-plan.approval_processing_short') }}
            </span>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- List of Approvals --}}
        <div class="lg:col-span-2">
            <div class="index-table-wrapper">
                <table class="index-table">
                    <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th">{{ __('care-plan.granted_to') }}</th>
                            <th class="index-table-th">{{ __('forms.status.label') }}</th>
                            <th class="index-table-th">{{ __('forms.date') }}</th>
                            <th class="index-table-th"></th>
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
                                    <span class="badge {{ ($approval['status'] ?? '') === 'active' ? 'badge-success' : 'badge-secondary' }}">
                                        {{ $approval['status'] ?? 'unknown' }}
                                    </span>
                                </td>
                                <td class="index-table-td">
                                    {{ isset($approval['createdAt']) || isset($approval['created_at']) ? \Carbon\Carbon::parse($approval['createdAt'] ?? $approval['created_at'])->format('d.m.Y H:i') : '-' }}
                                </td>
                                <td class="index-table-td-actions">
                                    @if (($approval['status'] ?? '') === 'active')
                                        <button
                                            type="button"
                                            wire:click="cancelApproval('{{ $approval['uuid'] }}')"
                                            wire:confirm="{{ __('care-plan.confirm_cancel_approval') }}"
                                            class="text-red-500 hover:text-red-700"
                                        >
                                            @icon('close-outline', 'w-4 h-4')
                                        </button>
                                    @elseif (in_array(($approval['status'] ?? ''), ['pending', 'NEW']))
                                        <button
                                            type="button"
                                            wire:click="verifyExistingApproval('{{ $approval['uuid'] }}')"
                                            class="btn btn-sm btn-primary rounded bg-blue-600 px-2 py-1 text-xs text-white transition hover:bg-blue-700"
                                        >
                                            Підтвердити
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-gray-400">
                                    {{ __('care-plan.no_approvals_found') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Create New Approval Form --}}
        <div>
            @if (empty($carePlanUuid))
                <div
                    class="mb-4 rounded-lg bg-yellow-50 p-4 text-sm text-yellow-800 dark:bg-gray-800 dark:text-yellow-300"
                    role="alert"
                >
                    {{ __('care-plan.cannot_grant_unregistered') }}
                </div>
            @elseif ($isPolling)
                <div
                    class="mb-4 rounded-lg bg-blue-50 p-4 text-sm text-blue-800 dark:bg-gray-800 dark:text-blue-300"
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
                <div class="card p-4">
                    @if ($errorMessage)
                        <div
                            class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-gray-800 dark:text-red-400"
                            role="alert"
                        >
                            {{ $errorMessage }}
                        </div>
                    @endif
                    @if (session()->has('error'))
                        <div
                            class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-gray-800 dark:text-red-400"
                            role="alert"
                        >
                            {{ session('error') }}
                        </div>
                    @endif
                    <h4 class="mb-4 font-medium">{{ __('care-plan.grant_access') }}</h4>
                    <form wire:submit.prevent="createApproval" class="flex flex-col gap-4">
                        <div class="flex flex-col gap-1">
                            <x-forms.label class="default-label" for="employee_uuid">
                                {{ __('care-plan.employee') }} *
                            </x-forms.label>
                            @if (empty($employees))
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('care-plan.no_employees_found') }}
                                </p>
                            @else
                                <x-forms.select
                                    class="default-input"
                                    id="employee_uuid"
                                    wire:model.live="newApproval.employee_uuid"
                                >
                                    <x-slot name="option">
                                        <option value="">{{ __('care-plan.select_employee') }}</option>
                                        @foreach ($employees as $employee)
                                            <option value="{{ $employee['uuid'] }}">{{ $employee['label'] }}</option>
                                        @endforeach
                                    </x-slot>
                                </x-forms.select>
                                @error('newApproval.employee_uuid')
                                    <span class="text-xs text-red-600">{{ $message }}</span>
                                @enderror
                            @endif
                        </div>

                        @if (!empty($authMethods))
                            <div class="flex flex-col gap-1">
                                <x-forms.label class="default-label">Метод підтвердження *</x-forms.label>
                                <x-forms.select
                                    class="default-input"
                                    wire:model="selectedAuthMethodUuid"
                                    id="selectedAuthMethodUuid"
                                >
                                    <x-slot name="option">
                                        <option value="">Оберіть метод підтвердження</option>
                                        @foreach ($authMethods as $method)
                                            <option value="{{ $method['id'] ?? $method['uuid'] }}">
                                                @if (($method['type'] ?? '') === 'OTP')
                                                    SMS ({{ $method['phone_number'] ?? '' }})
                                                @elseif (($method['type'] ?? '') === 'OFFLINE')
                                                    Офлайн (паперовий документ)
                                                @else
                                                    {{ $method['type'] ?? 'Інший' }}
                                                @endif
                                            </option>
                                        @endforeach
                                    </x-slot>
                                </x-forms.select>
                            </div>
                        @endif

                        <button type="submit" class="button-primary w-full" wire:loading.attr="disabled">
                            <span wire:loading.remove>{{ __('care-plan.grant_access_btn') }}</span>
                            <span wire:loading>{{ __('forms.loading') }}</span>
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    @include('livewire.care-plan.modals.authentication')
</div>
