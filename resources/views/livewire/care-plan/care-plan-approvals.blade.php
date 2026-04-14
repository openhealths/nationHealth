<div class="mt-8">
    <div class="flex items-center justify-between mb-4">
        <h3 class="title-sm">{{ __('care-plan.access_management') }}</h3>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
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
                        @forelse($approvals as $approval)
                            <tr class="index-table-tr">
                                <td class="index-table-td">
                                    <div class="flex flex-col">
                                        <span class="font-medium text-gray-900 dark:text-white">
                                            {{ $approval['granted_to']['name'] ?? $approval['granted_to']['id'] }}
                                        </span>
                                        <span class="text-xs text-gray-500">
                                            {{ $approval['granted_to']['type'] ?? '' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="index-table-td">
                                    <span class="badge {{ ($approval['status'] ?? '') === 'active' ? 'badge-success' : 'badge-secondary' }}">
                                        {{ $approval['status'] ?? 'unknown' }}
                                    </span>
                                </td>
                                <td class="index-table-td">
                                    {{ isset($approval['inserted_at']) ? \Carbon\Carbon::parse($approval['inserted_at'])->format('d.m.Y H:i') : '-' }}
                                </td>
                                <td class="index-table-td-actions">
                                    @if(($approval['status'] ?? '') === 'active')
                                        <button type="button" 
                                                wire:click="cancelApproval('{{ $approval['id'] }}')"
                                                wire:confirm="{{ __('care-plan.confirm_cancel_approval') }}"
                                                class="text-red-500 hover:text-red-700">
                                            @icon('close-outline', 'w-4 h-4')
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-6 text-gray-400">
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
            <div class="card p-4">
                <h4 class="font-medium mb-4">{{ __('care-plan.grant_access') }}</h4>
                <form wire:submit.prevent="createApproval" class="flex flex-col gap-4">
                    <x-forms.input
                        id="granted_to_legal_entity_id"
                        name="newApproval.granted_to_legal_entity_id"
                        label="{{ __('care-plan.legal_entity_uuid') }}"
                        wire:model="newApproval.granted_to_legal_entity_id"
                        placeholder="00000000-0000-0000-0000-000000000000"
                    />

                    <x-forms.textarea
                        id="approval_reason"
                        name="newApproval.reason"
                        label="{{ __('care-plan.reason') }}"
                        wire:model="newApproval.reason"
                        placeholder="{{ __('care-plan.reason_placeholder') }}"
                    />

                    <button type="submit" class="button-primary w-full">
                        {{ __('care-plan.grant_access_btn') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
