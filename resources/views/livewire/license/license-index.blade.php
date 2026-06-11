@use('App\Enums\License\Type')
@use('App\Models\License')

<div>
    <x-header-navigation x-data="{ showFilter: false }" title="{{ __('forms.licenses') }}">
        <div class="flex flex-col">
            <div class="flex flex-wrap items-end justify-between gap-4 max-w-6xl">
                <div class="flex items-end gap-4"></div>
                <div class="ml-auto flex items-center gap-6 self-start -mt-9 translate-x-4">
                    @can('create', License::class)
                        <a href="{{ route('license.create', [legalEntity()]) }}"
                           class="button-primary flex items-center gap-2"
                        >
                            @icon('plus', 'w-4 h-4')
                            {{ __('licenses.create') }}
                        </a>
                    @endcan

                    @can('sync', License::class)
                        <button wire:click="sync" class="button-sync flex items-center gap-2">
                            @icon('refresh', 'w-4 h-4')
                            {{ __('forms.synchronise_with_eHealth') }}
                        </button>
                    @endcan
                </div>
            </div>
        </div>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5" wire:key="{{ time() }}">
        <div class="max-w-7xl">
            @if($licenses->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[20%]">{{ __('licenses.type.label') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('licenses.active_from_date_label') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('licenses.expiry_date_label') }}</th>
                            <th class="index-table-th w-[25%]">{{ __('licenses.activity') }}</th>
                            <th class="index-table-th w-[14%]">{{ __('licenses.kind') }}</th>
                            <th class="index-table-th w-[6%]">{{ __('forms.action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($licenses as $license)
                            <tr class="index-table-tr">
                                <td class="index-table-td">
                                    {{ $license->type->label() }}
                                </td>
                                <td class="index-table-td">
                                    {{ $license->activeFromDate }}
                                </td>
                                <td class="index-table-td">
                                    {{ $license->expiryDate }}
                                </td>
                                <td class="index-table-td">
                                    {{ $license->whatLicensed }}
                                </td>
                                <td class="index-table-td">
                                    @if($license->isPrimary)
                                        <span class="badge-green">{{ __('licenses.primary') }}</span>
                                    @else
                                        <span class="badge-yellow">{{ __('licenses.not_primary') }}</span>
                                    @endif
                                </td>
                                <td class="index-table-td-actions">
                                    @if($license->isPrimary)
                                        @can('view', $license)
                                            <a href="{{ route('license.view', [legalEntity(), $license->id]) }}"
                                               title="{{ __('forms.view') }}"
                                            >
                                                @icon('eye', 'w-5 h-5 text-gray-600 hover:text-blue-600')
                                            </a>
                                        @endcan
                                    @else
                                        <div x-data="{ open: false }" class="relative inline-block text-left">
                                            <button @click="open = !open"
                                                    @click.outside="open = false"
                                                    class="cursor-pointer text-gray-500 hover:text-gray-800 dark:hover:text-white focus:outline-none"
                                            >
                                                @icon('edit-user-outline', 'svg-hover-action w-6 h-6 text-gray-800 dark:text-white')
                                            </button>

                                            <div x-show="open"
                                                 x-cloak
                                                 x-transition
                                                 class="absolute right-0 z-10 mt-2 w-40 origin-top-right rounded-md bg-white dark:bg-gray-700 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none"
                                            >
                                                <div class="py-1">
                                                    @can('view', $license)
                                                        <a href="{{ route('license.view', [legalEntity(), $license->id]) }}"
                                                           class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('eye', 'w-5 h-5 text-gray-600')
                                                            {{ __('forms.view') }}
                                                        </a>
                                                    @endcan

                                                    @can('update', $license)
                                                        <a href="{{ route('license.edit', [legalEntity(), $license->id]) }}"
                                                           class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('edit', 'w-5 h-5 text-gray-600')
                                                            {{ __('forms.update') }}
                                                        </a>
                                                    @endcan
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
                    {{ $licenses->links() }}
                </div>
            @else
                <x-nothing-found />
            @endif
        </div>

        <livewire:components.x-message :key="time()" />
        <x-forms.loading />
    </div>
</div>
