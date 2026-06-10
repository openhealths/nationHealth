@php
    use App\Models\Contracts\ContractRequest;
@endphp

<div>
    <livewire:components.x-message :key="time()"/>
    <x-forms.loading/>

    <x-header-navigation class="items-start">
        <x-slot name="title">{{ __('forms.contracts') }}</x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            @can('sync', ContractRequest::class)
                <button wire:click="sync" type="button" class="button-sync flex items-center gap-2 whitespace-nowrap">
                    @icon('refresh', 'w-4 h-4')
                    {{ __('forms.synchronise_with_eHealth') }}
                </button>
            @endcan
        </div>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5">
        <div class="max-w-screen-xl">
            @if ($contracts->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[25%]">{{ __('contracts.number_label') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('contracts.type_label') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('contracts.status_label') }}</th>
                            <th class="index-table-th w-[20%]">{{ __('contracts.period') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('contracts.date_added') }}</th>
                            <th class="index-table-th w-[10%]"></th>
                        </tr>
                        </thead>
                        <tbody class="index-table-tbody">
                        @foreach($contracts as $item)
                            <tr wire:key="contract-{{ $item->uuid }}">
                                <td class="index-table-td">
                                    <div class="text-sm text-gray-900 font-medium">
                                        {{-- Display contract_number or translated 'missing' text --}}
                                        {{ $item->contract_number ?: __('contracts.missing') }}
                                    </div>

                                    {{-- Show status_reason if exists, as required by eHealth TZ --}}
                                    @if($item->status_reason)
                                        <div class="text-xs text-red-500 mt-1" title="{{ __('contracts.status_reason') }}">
                                            {{ str($item->status_reason)->limit(60) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="index-table-td">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        {{-- Translate the contract type dynamically --}}
                                        {{ $item->type ? __('contracts.' . strtolower($item->type)) : __('contracts.missing') }}
                                    </span>
                                </td>
                                <td class="index-table-td">
                                    <x-status-badge :status="$item->status"/>
                                </td>
                                <td class="index-table-td text-sm text-gray-500">
                                    {{ $item->start_date?->format(config('app.date_format')) }} - {{ $item->end_date?->format(config('app.date_format')) }}
                                </td>
                                <td class="index-table-td text-sm text-gray-500">
                                    {{ $item->start_date?->format(config('app.date_format')) ?? $item->created_at?->format(config('app.date_format')) }}
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
                                                <a href="{{ route('contract-request.show', ['legalEntity' => legalEntity(), 'contract' => $item]) }}"
                                                   wire:navigate
                                                   class="flex items-center gap-2 w-full rounded-md px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 transition-colors"
                                                >
                                                    @icon('eye', 'w-5 h-5 text-gray-600')
                                                    {{ __('contracts.view') }}
                                                </a>

                                                {{-- Edit action available only for NEW status --}}
                                                @if($item->status === 'NEW' || (is_object($item->status) && $item->status->value === 'NEW'))
                                                    <a href="{{ route('contract-request.show', ['legalEntity' => legalEntity(), 'contract' => $item]) }}"
                                                       wire:navigate
                                                       class="flex items-center gap-2 w-full rounded-md px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 transition-colors"
                                                    >
                                                        @icon('pencil', 'w-5 h-5 text-gray-600')
                                                        {{ __('contracts.edit') }}
                                                    </a>
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
            @else
                <x-nothing-found />
            @endif

            @if($contracts->isNotEmpty())
                <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
                    {{ $contracts->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
