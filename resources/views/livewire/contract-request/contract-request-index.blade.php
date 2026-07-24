@use('App\Models\Contracts\ContractRequest')
@use('App\Enums\Contract\Status')
@use('App\Enums\Contract\Type')

<div>
    <livewire:components.x-message :key="time()"/>
    <x-forms.loading/>

    <x-header-navigation class="items-start">
        <x-slot name="title">{{ __('contracts.contract_requests') }}</x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            @can('createCapitation', ContractRequest::class)
                <a href="{{ route('contract-request.capitation.create', [legalEntity()]) }}"
                   wire:navigate
                   class="button-primary flex items-center gap-2 whitespace-nowrap">
                    @icon('plus', 'w-4 h-4')
                    {{ __('contracts.new') }} ({{ __('contracts.capitation') }})
                </a>
            @endcan

            @can('createReimbursement', ContractRequest::class)
                @if(legalEntity()?->type?->name === \App\Models\LegalEntity::TYPE_PHARMACY)
                    <a href="{{ route('contract-request.reimbursement.create', [legalEntity()]) }}"
                       wire:navigate
                       class="button-primary flex items-center gap-2 whitespace-nowrap">
                        @icon('plus', 'w-4 h-4')
                        {{ __('contracts.new') }} ({{ __('contracts.reimbursement') }})
                    </a>
                @endif
            @endcan

            @can('sync', ContractRequest::class)
                <button wire:click="sync" type="button" class="button-sync flex items-center gap-2 whitespace-nowrap">
                    @icon('refresh', 'w-4 h-4')
                    {{ __('forms.synchronise_with_eHealth') }}
                </button>
            @endcan
        </div>

        <x-slot name="navigation">
            <div class="flex flex-col gap-4 max-w-2xl -my-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="form-group group relative w-full col-span-1">
                        @icon('search-outline', 'svg-input')
                        <input wire:model.live.debounce.300ms="search"
                               type="text"
                               id="contractRequestSearch"
                               placeholder=" "
                               class="input peer"
                               autocomplete="off"
                        />
                        <label for="contractRequestSearch" class="label">
                            {{ __('contracts.search_contract') }}
                        </label>
                        <button type="button"
                                class="absolute inset-y-0 end-0 flex items-center pe-1 text-gray-400 hover:text-gray-600"
                                x-show="$wire.search"
                                @click="$wire.set('search', '')"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-forms.multiselect
                        bind="statusFilter"
                        :options="Status::options()"
                        label="{{ __('contracts.status_label') }}"
                        placeholder="{{ __('forms.all') }}"
                        :live="true"
                    />

                    <x-forms.multiselect
                        bind="typeFilter"
                        :options="Type::options()"
                        label="{{ __('contracts.type_label') }}"
                        placeholder="{{ __('forms.all') }}"
                        :live="true"
                    />
                </div>

                <div class="mt-2 flex flex-col sm:flex-row gap-2 w-full">
                    <button type="button" wire:click="search" class="flex items-center justify-center gap-2 button-primary w-full sm:w-auto">
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button type="button" wire:click="resetFilters" class="button-primary-outline-red w-full sm:w-auto">
                        {{ __('forms.reset_all_filters') }}
                    </button>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5">
        <div class="max-w-screen-xl">
            @if ($contracts->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th">{{ __('contracts.number_label') }}</th>
                            <th class="index-table-th">{{ __('contracts.type_label') }}</th>
                            <th class="index-table-th">{{ __('contracts.period') }}</th>
                            <th class="index-table-th">{{ __('contracts.date_added') }}</th>
                            <th class="index-table-th">{{ __('contracts.status_label') }}</th>
                            <th class="index-table-th">{{ __('contracts.status_reason_label') }}</th>
                            <th class="index-table-th w-[10%]"></th>
                        </tr>
                        </thead>
                        <tbody class="index-table-tbody">
                        @foreach($contracts as $item)
                            <tr wire:key="contract-{{ $item->uuid }}">
                                <td class="index-table-td">
                                    <div class="text-sm text-gray-900 font-medium">
                                        {{ $item->contract_number ?: __('contracts.missing') }}
                                    </div>
                                </td>
                                <td class="index-table-td">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        {{ Type::resolveLabel($item->type) }}
                                    </span>
                                </td>
                                <td class="index-table-td text-sm text-gray-500">
                                    @php
                                        $listStart = $item->start_date?->format(config('app.date_format'))
                                            ?: formatDisplayDate(data_get($item->data, 'start_date'));
                                        $listEnd = $item->end_date?->format(config('app.date_format'))
                                            ?: formatDisplayDate(data_get($item->data, 'end_date'));
                                    @endphp
                                    {{ $listStart && $listEnd ? "{$listStart} - {$listEnd}" : ($listStart ?: $listEnd ?: '-') }}
                                </td>
                                <td class="index-table-td text-sm text-gray-500">
                                    {{ $item->inserted_at?->format(config('app.date_format'))
                                        ?? formatDisplayDate(data_get($item->data, 'inserted_at'))
                                        ?: '-' }}
                                </td>
                                <td class="index-table-td">
                                    <x-status-badge :status="$item->status"/>
                                </td>
                                <td class="index-table-td text-sm text-gray-500" title="{{ $item->status_reason ?? data_get($item->data, 'status_reason') }}">
                                    {{ ($item->status_reason ?: data_get($item->data, 'status_reason')) ?: '-' }}
                                </td>

                                <td class="index-table-td-actions">
                                    <div class="flex justify-center relative">
                                        {{-- Alpine.js dropdown logic --}}
                                        <div x-data="{
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
                                                 }
                                             }"
                                             @keydown.escape.prevent.stop="close($refs.button)"
                                             @focusin.window="!$refs.panel.contains($event.target) && close()"
                                             x-id="['dropdown-button']"
                                             class="relative"
                                        >
                                            <button @click="toggle()"
                                                    x-ref="button"
                                                    :aria-expanded="open"
                                                    :aria-controls="$id('dropdown-button')"
                                                    type="button"
                                                    class="hover:text-primary cursor-pointer outline-none"
                                            >
                                                @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                            </button>

                                            <div
                                                x-show="open"
                                                x-cloak
                                                x-ref="panel"
                                                x-transition.origin.top.left
                                                @click.outside="close($refs.button)"
                                                :id="$id('dropdown-button')"
                                                class="absolute right-0 mt-2 w-44 rounded-md bg-white shadow-md z-50 border border-gray-100"
                                            >
                                                {{-- View action with fixed route parameters --}}
                                                <a href="{{ route('contract-request.show', ['legalEntity' => legalEntity(), 'contractRequest' => $item->id]) }}"
                                                   wire:navigate
                                                   class="flex items-center gap-2 w-full rounded-md px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 transition-colors"
                                                >
                                                    @icon('eye', 'w-5 h-5 text-gray-600')
                                                    {{ __('contracts.view') }}
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-nothing-found />
            @endif

            @if($contracts->isNotEmpty())
                <div class="pagination">
                    {{ $contracts->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
