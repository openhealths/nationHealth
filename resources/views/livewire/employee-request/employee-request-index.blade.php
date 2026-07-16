@use('App\Enums\JobStatus')

<div>
    {{-- 1. DEFINE PERMISSIONS --}}
    @php
        $currentUser = auth()->user();

       $permissions = [
        'request_view'   => $currentUser->can('employee_request:read') || $currentUser->hasAllowedRole([\App\Enums\User\Role::ADMIN, \App\Enums\User\Role::HR, \App\Enums\User\Role::OWNER, \App\Enums\User\Role::PHARMACY_OWNER]),
        'request_write'  => $currentUser->can('employee_request:write') || $currentUser->hasAllowedRole([\App\Enums\User\Role::ADMIN, \App\Enums\User\Role::HR, \App\Enums\User\Role::OWNER, \App\Enums\User\Role::PHARMACY_OWNER]),
        'request_delete' => $currentUser->can('employee_request:write') || $currentUser->hasAllowedRole([\App\Enums\User\Role::ADMIN, \App\Enums\User\Role::HR, \App\Enums\User\Role::OWNER, \App\Enums\User\Role::PHARMACY_OWNER]),

        'employee_view' => false, 'employee_write' => false, 'employee_deactivate' => false
    ];
    @endphp

    <x-header-navigation class="items-start">
        <x-slot name="title">
            {{ __('forms.application_register') }}
        </x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            <button
                wire:click="{{ !$this->isSync ? 'sync' : '' }}"
                wire:loading.attr="disabled"
                class="{{ $this->isSync ? 'button-sync-disabled' : 'button-sync' }} flex items-center gap-2 whitespace-nowrap"
                {{ $this->isSync ? 'disabled' : '' }}
            >
                <span wire:loading.remove wire:target="sync">@icon('refresh', 'w-4 h-4')</span>
                <span wire:loading wire:target="sync" class="animate-spin">@icon('refresh', 'w-4 h-4')</span>
                <span>{{ ($syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value) ? __('forms.sync_retry') : __('forms.sync_all') }}</span>
            </button>
        </div>

        <x-slot name="navigation">
            <div class="flex flex-col -my-4">
                <div class="form-row-4">
                    <div class="form-group group">
                        <input type="text"
                               wire:model.live.debounce.500ms="search"
                               class="input peer"
                               placeholder=" " />
                        <label class="label">{{ __('forms.search_name') }}</label>
                    </div>

                    <div class="form-group group">
                        <select wire:model.live="status" class="input-select peer">
                            <option value="">Всі статуси</option>
                            @foreach($statuses as $st)
                                <option value="{{ $st->value }}">{{ $st->label() }}</option>
                            @endforeach
                        </select>
                        <label class="label">Статус</label>
                    </div>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5">
        <div class="max-w-screen-xl">
            @if($requests->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[12%]">{{ __('forms.request_id') }}</th>
                            <th class="index-table-th w-[22%]">{{ __('forms.full_name') }}</th>
                            <th class="index-table-th w-[16%]">{{ __('forms.role') }}</th>
                            <th class="index-table-th w-[16%]">{{ __('forms.division') }}</th>
                            <th class="index-table-th w-[14%]">{{ __('forms.created_at') }}</th>
                            <th class="index-table-th w-[12%]">{{ __('forms.status.label') }}</th>
                            <th class="index-table-th w-[8%]">{{ __('forms.action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($requests as $request)
                            <tr class="index-table-tr">
                                <td class="index-table-td">
                                    <span class="font-mono text-xs" title="{{ $request->uuid ?? $request->id }}">
                                        {{ $request->uuid ?? $request->id }}
                                    </span>
                                </td>
                                <td class="index-table-td-primary">
                                    @php
                                        $data = $request->revision->data ?? [];
                                        $partyData = $data['party'] ?? [];
                                        $fullName = trim(($partyData['last_name'] ?? '') . ' ' . ($partyData['first_name'] ?? '') . ' ' . ($partyData['second_name'] ?? ''));
                                    @endphp
                                    <span title="{{ $fullName }}">{{ $fullName ?: 'N/A' }}</span>
                                </td>

                                <td class="index-table-td">
                                    @php
                                        $posCode = $data['employee_request_data']['position'] ?? ($data['position'] ?? null);
                                        $posName = $dictionaries['POSITION'][$posCode] ?? $posCode;
                                    @endphp
                                    <span title="{{ $posName }}">{{ $posName ?: 'N/A' }}</span>
                                </td>

                                <td class="index-table-td">
                                    <span title="{{ $request->division->name ?? '' }}">
                                        {{ $request->division->name ?? 'N/A' }}
                                    </span>
                                </td>

                                <td class="index-table-td">
                                    {{ $request->created_at ? $request->created_at->format('d.m.Y H:i') : '-' }}
                                </td>

                                <td class="index-table-td">
                                    @if($request->isLocalDraft())
                                        <span class="badge-red">{{ __('forms.status.draft') }}</span>
                                    @elseif($request->isPendingEhealth())
                                        <span class="badge-yellow">{{ __('forms.status.new') }}</span>
                                    @elseif($request->status == \App\Enums\Employee\RequestStatus::APPROVED)
                                        <span class="badge-green">{{ $request->status->label() }}</span>
                                    @else
                                        <span class="badge-gray">{{ $request->status->label() }}</span>
                                    @endif
                                </td>

                                <td class="index-table-td-actions">
                                    @include('livewire.employee.parts.actions-dropdown', [
                                        'position' => $request,
                                        'permissions' => $permissions
                                    ])
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-nothing-found />
            @endif

            @if($requests->isNotEmpty())
                <div class="pagination">
                    {{ $requests->links() }}
                </div>
            @endif
        </div>
    </div>

    <x-forms.loading wire:target="sync"/>
</div>
