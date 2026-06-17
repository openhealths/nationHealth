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

        <x-slot name="navigation">
            <div class="flex flex-col -my-4">
                <div class="form-row-3 items-end w-full">
                    <div class="form-group group"
                         x-data="{
                             open: false,
                             selectedTypes: $wire.entangle('typeFilter'),
                             // Dynamically map enum values to their localized labels
                             typeLabels: {
                                 @foreach(\App\Enums\Contract\Type::cases() as $typeCase)
                                     '{{ $typeCase->value }}': '{{ $typeCase->label() }}',
                                 @endforeach
                             }
                         }"
                    >
                        <label for="typeFilter" class="label">{{ __('contracts.type_label') }}</label>
                        <div class="relative">
                            <input type="text"
                                   id="typeFilter"
                                   class="peer input pr-10 cursor-pointer"
                                   :value="selectedTypes.length === 0 ? '{{ __('forms.all') }}' : selectedTypes.map(typeValue => typeLabels[typeValue] || typeValue).join(', ')"
                                   @click="open = !open"
                                   readonly
                            />
                            @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 pointer-events-none')

                            <div x-show="open"
                                 @click.away="open = false"
                                 class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg"
                            >
                                <ul class="py-2 px-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                                     @foreach(\App\Enums\Contract\Type::cases() as $typeCase)
                                         <li>
                                             <label class="flex items-center">
                                                 <input type="checkbox"
                                                        value="{{ $typeCase->value }}"
                                                        x-model="selectedTypes"
                                                        class="w-4 h-4 text-blue-600 bg-gray-100 dark:bg-gray-600 border-gray-300 rounded focus:ring-blue-500"
                                                 >
                                                 <span class="ml-2">{{ $typeCase->label() }}</span>
                                             </label>
                                         </li>
                                     @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="form-group flex items-center mb-3">
                        <button wire:click="search"
                                type="button"
                                class="p-2.5 text-sm font-medium text-white bg-primary-700 rounded-xl hover:bg-primary-800 focus:ring-4 focus:outline-none focus:ring-primary-300 dark:bg-primary-600 dark:hover:bg-primary-700 dark:focus:ring-primary-800"
                        >
                            @icon('search-outline', 'w-6 h-6')
                        </button>
                    </div>
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
                                <td class="index-table-td text-sm text-gray-500 dark:text-gray-400">
                                    {{-- Display contract_number or translated 'missing' text --}}
                                    {{ $item->contract_number ?: __('contracts.missing') }}

                                    {{-- Show status_reason if exists, as required by eHealth TZ --}}
                                    @if($item->status_reason)
                                        <div class="text-xs text-red-500 dark:text-red-400 mt-1" title="{{ __('contracts.status_reason') }}">
                                            {{ str($item->status_reason)->limit(60) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="index-table-td">
                                    <span class="badge-yellow">
                                        {{ $item->type }}
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
                                                <a href="{{ route('contract.show', [legalEntity(), $item->uuid]) }}"
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
                <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
                    {{ $contracts->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
