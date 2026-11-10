@use(App\Models\Person\Person)
@use(Carbon\Carbon)

<div>
    <livewire:components.x-message :key="time()" />
    <x-header-navigation class="items-start" x-data="{ showFilter: false }"
        :breadcrumbs="[
            ['label' => __('forms.home'), 'url' => route('dashboard', [legalEntity()])],
            ['label' => __('patients.data_access')]
        ]"
    >

        <x-slot name="title">{{ __('approval.data_access_list') }}</x-slot>

        <div class="mt-3 ml-0 flex flex-col gap-2 self-start sm:flex-row sm:flex-wrap" x-data="{ patientId: $wire.entangle('filterPatientId') }">
            <button type="button" wire:click="sync" wire:loading.attr="disabled" wire:target="sync" :disabled="!patientId"
                class="flex items-center gap-2 whitespace-nowrap rounded-lg px-5 py-2.5 text-sm font-medium text-white"
                :class="patientId ? 'button-sync' : 'bg-gray-500 cursor-not-allowed'">
                @icon('refresh', 'w-4 h-4')
                <span x-text="patientId ? @js(__('forms.synchronise_with_eHealth')) : @js(__('approval.select_patient_to_sync'))"></span>
            </button>
        </div>

        <x-slot name="navigation">
            <div class="-my-4 flex flex-col">
                <form wire:submit.prevent="search">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div class="flex w-full flex-col items-stretch gap-2 lg:flex-row lg:items-end lg:gap-4">
                            <div class="w-full lg:w-96">
                                <x-forms.combobox
                                    :options="$patients"
                                    bind="filterPatientId"
                                    bindValue="uuid"
                                    bindParam="name"
                                    :label="__('forms.patient')"
                                    x-on:input="value = ''"
                                />
                            </div>
                            <button
                                type="button"
                                class="button-minor flex w-full items-center justify-center gap-2 self-stretch lg:w-auto lg:self-auto"
                                @click="showFilter = !showFilter"
                            >
                                @icon('adjustments', 'w-4 h-4')
                                <span>{{ __('forms.additional_search_parameters') }}</span>
                            </button>
                        </div>

                        <div x-cloak x-show="showFilter" x-transition class="mt-1 w-full pt-0">
                            <div class="form-row-3">
                                <x-forms.combobox
                                    :options="$employees"
                                    bind="filterGrantedTo"
                                    bindValue="uuid"
                                    bindParam="name"
                                    :label="__('approval.granted_to')"
                                />
                                <x-forms.combobox
                                    :options="$resources"
                                    bind="filterGrantedResources"
                                    bindValue="uuid"
                                    bindParam="name"
                                    :label="__('approval.data_id')"
                                />
                                <x-forms.combobox
                                    :options="$resourceTypes"
                                    bind="filterGrantedResourceType"
                                    bindValue="code"
                                    bindParam="name"
                                    :label="__('approval.data_type')"
                                />
                            </div>

                            <div class="form-row-3 mt-4">
                                
                                <x-forms.combobox
                                    :options="$booleanOptions"
                                    bind="filterIsVerified"
                                    bindValue="code"
                                    bindParam="name"
                                    :label="__('approval.is_verified')"
                                />
                                <x-forms.combobox
                                    :options="$booleanOptions"
                                    bind="filterIsExpired"
                                    bindValue="code"
                                    bindParam="name"
                                    :label="__('approval.is_expired')"
                                />
                            </div>
                            <div class="form-row-3 mt-4">
                                <x-forms.combobox
                                    :options="$reasons"
                                    bind="filterReason"
                                    bindValue="uuid"
                                    bindParam="name"
                                    :label="__('approval.based_on')"
                                />
                                <x-forms.combobox
                                    :options="$accessLevels"
                                    bind="filterAccessLevel"
                                    bindValue="code"
                                    bindParam="name"
                                    :label="__('approval.access_level')"
                                />
                            </div>
                        </div>

                        <div class="mt-6 mb-9 flex w-full flex-col gap-2 sm:flex-row">
                            <button type="submit" class="button-primary flex items-center gap-2">
                                @icon('search', 'w-4 h-4')
                                <span>{{ __('forms.search') }}</span>
                            </button>
                            <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                                {{ __('forms.reset_all_filters') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5">
        <div class="w-full">
            @if($this->paginatedApprovals->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table w-full">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[13%]">{{ __('forms.patient') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('approval.granted_to') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('approval.data_type') }}</th>
                            <th class="index-table-th w-[19%]">{{ __('approval.data') }}</th>
                            <th class="index-table-th w-[12%]">{{ __('approval.based_on') }}</th>
                            <th class="index-table-th w-[9%]">{{ __('approval.expiration_date') }}</th>
                            <th class="index-table-th w-[9%]">{{ __('approval.access_level') }}</th>
                            <th class="index-table-th w-[8%]">{{ __('approval.confirmed_column') }}</th>
                            <th class="index-table-th w-[5%]">{{ __('forms.action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($this->paginatedApprovals as $approval)
                            @php
                                $remote = is_array($approval);
                                $local = $remote ? $this->localApprovalsByUuid->get($approval['id']) : $approval;
                                $patient = $remote ? $this->searchPatient : ($approval->approvable instanceof Person ? $approval->approvable : $approval->approvable->person);
                                $type = $remote ? (data_get($approval, 'grantedResources.0.identifier.type.coding.0.code') ?? data_get($approval, 'grantedResourceTypes.0.coding.0.code')) : (data_get($approval, 'grantedResources.0.grantedTo.identifier.type.coding.0.code') ?? data_get($approval, 'grantedResourceTypes.0.codeableConcept.coding.0.code'));
                                $type ??= $remote ? null : ($approval->approvable instanceof Person ? 'person' : 'care_plan');
                                $names = $this->resourceNamesByApproval[$remote ? $approval['id'] : $approval->uuid] ?? [];
                                $recipientType = data_get($approval, 'grantedTo.identifier.type.coding.0.code');
                                $recipientLabel = match ($recipientType) {
                                    'employee' => __('approval.employee'),
                                    'legal_entity' => __('patients.organization'),
                                    default => __('approval.recipient_types.unknown'),
                                };
                                $isVerified = $remote ? data_get($approval, 'isVerified') : $approval->getAttribute('is_verified');
                                $expiresAt = data_get($approval, 'expiresAt');
                                $isExpired = $expiresAt !== null && !Carbon::parse($expiresAt)->isFuture();
                            @endphp
                            <tr class="index-table-tr" wire:key="approval-row-{{ $remote ? $approval['id'] : $approval->uuid }}">
                                <td class="index-table-td-primary">
                                    {{ $patient->fullName }}<br>
                                    <span class="text-xs text-gray-500">{{ $patient->birthDate }} р.н.</span>
                                </td>
                                <td class="index-table-td">
                                    {{ $recipientLabel }}<br>
                                    {{ data_get($approval, 'grantedTo.displayValue') ?? '-' }}
                                </td>
                                <td class="index-table-td">{{ $type ? __('approval.resource_types.'.$type) : '-' }}</td>
                                <td class="index-table-td">
                                    @if (count($names) > 1)
                                        <ul class="list-disc pl-4 space-y-1">
                                            @foreach ($names as $name)
                                                <li class="min-w-0">
                                                    <span class="line-clamp-2 break-words" title="{{ $name }}">
                                                        {{ $name }}
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <span class="line-clamp-2 break-words" title="{{ $names[0] ?? '' }}">
                                            {{ $names[0] ?? '-' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="index-table-td break-all">
                                    {{ data_get($approval, 'reason.displayValue') ?: (data_get($approval, 'reason.identifier.value') ?: '-') }}
                                </td>

                                <td class="index-table-td whitespace-nowrap">
                                    @if ($expiresAt === null)
                                        -
                                    @elseif ($isExpired)
                                        <span class="badge-red rounded-lg">
                                            {{ __('approval.expired_short') }}
                                        </span>
                                    @else
                                        {{ Carbon::parse($expiresAt)->setTimezone(config('app.timezone'))->format('d.m.Y') }}
                                    @endif
                                </td>

                                <td class="index-table-td">
                                    {{ __('patients.'.data_get($approval, 'accessLevel')) }}
                                </td>

                                <td class="index-table-td whitespace-nowrap">
                                    @if ($isVerified === true)
                                        <span class="badge-green whitespace-nowrap">{{ __('forms.yes') }}</span>
                                    @elseif ($isVerified === false)
                                        <span class="badge-yellow whitespace-nowrap">{{ __('forms.no') }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="index-table-td-actions relative">
                                    <div class="flex justify-center relative">
                                        <div x-data="{
                                                open: false,
                                                toggle() {
                                                    if (this.open) { return this.close(); }
                                                    this.$refs.button.focus();
                                                    this.open = true;
                                                },
                                                close(focusAfter) {
                                                    if (!this.open) return;
                                                    this.open = false;
                                                    focusAfter && focusAfter.focus();
                                                }
                                            }"
                                            @keydown.escape.prevent.stop="close($refs.button)"
                                            @focusin.window="!$refs.panel.contains($event.target) && close()"
                                            class="relative"
                                        >
                                            <button @click="toggle()" x-ref="button" type="button" class="hover:text-primary cursor-pointer outline-none">
                                                <svg class="svg-hover-action w-6 h-6 text-gray-800 dark:text-gray-300" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24">
                                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"/>
                                                </svg>
                                            </button>

                                            <div x-show="open" x-cloak x-ref="panel" x-transition.origin.top.left @click.outside="close($refs.button)" class="absolute right-0 mt-2 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50">
                                                @if ($local)
                                                    <a href="{{ route('data-access.view', [legalEntity(), $local->id]) }}" class="flex whitespace-nowrap items-center gap-2 w-full first-of-type:rounded-t-md px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                        @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                        {{ __('forms.view_details') }}
                                                    </a>
                                                @else
                                                    <button type="button" wire:click="view('{{ $approval['id'] }}')" class="flex whitespace-nowrap items-center gap-2 w-full first-of-type:rounded-t-md px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                        @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                        {{ __('forms.view_details') }}
                                                    </button>
                                                @endif
                                                @if (!$isExpired && ($remote ? ($local ? Auth::user()->can('cancel', [$local, legalEntity()]) : $this->canCancelSearchedApproval()) : Auth::user()->can('cancel', [$approval, legalEntity()])))
                                                    <button
                                                        type="button"
                                                        wire:click="{{ $remote ? 'confirmCancelSearchedApproval(\''.$approval['id'].'\')' : 'confirmCancelApproval('.$approval->id.')' }}"
                                                        class="flex whitespace-nowrap items-center gap-2 w-full last-of-type:rounded-b-md px-4 py-2.5 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-gray-600"
                                                    >
                                                        @icon('cancel', 'w-5 h-5 text-red-600')
                                                        {{ __('approval.revoke_access') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    {{ $this->paginatedApprovals->links() }}
                </div>
            @else
                <x-nothing-found />
            @endif
        </div>
    </div>

    <x-confirmation-modal wire:model.live="showCancelApprovalModal" maxWidth="md">
        <x-slot name="title">
            {{ __('approval.messages.cancel_confirm') }}
        </x-slot>

        <x-slot name="content">
            {{ __('approval.messages.cancel_irreversible') }}
        </x-slot>

        <x-slot name="footer">
            <div class="flex w-full items-center justify-between">
                <x-secondary-button
                    wire:click="$set('showCancelApprovalModal', false)"
                >
                    {{ __('forms.cancel') }}
                </x-secondary-button>

                @if ($approvalToCancel !== null)
                    <x-danger-button
                        wire:click="cancelApproval({{ $approvalToCancel }})"
                        wire:loading.attr="disabled"
                        wire:target="cancelApproval"
                    >
                        {{ __('approval.revoke_access') }}
                    </x-danger-button>
                @elseif ($remoteApprovalToCancel !== null)
                    <x-danger-button
                        wire:click="cancelSearchedApproval"
                        wire:loading.attr="disabled"
                        wire:target="cancelSearchedApproval"
                    >
                        {{ __('approval.revoke_access') }}
                    </x-danger-button>
                @endif
            </div>
        </x-slot>
    </x-confirmation-modal>
</div>
