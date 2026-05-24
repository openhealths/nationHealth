@use('App\Enums\Status')
@use('App\Enums\JobStatus')
@use('App\Models\{HealthcareService, Division}')

<div x-data="{
         divisionId: 0,
         textConfirmation: '',
         actionType: '',
         actionTitle: '',
         actionButtonText: ''
     }"
>
    <livewire:components.x-message :key="now()->timestamp"/>

    <x-header-navigation class="items-start" x-data="{ showFilter: false }">
        <x-slot name="title">{{ __('forms.divisions') }}</x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            @can('create', Division::class)
                <a href="{{ route('division.create', [legalEntity()]) }}"
                   type="button"
                   class="button-primary flex items-center gap-2"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('forms.add_new_division') }}
                </a>
            @endcan

            <button
                wire:click="{{ !$this->isSync ? 'sync' : '' }}"
                class="{{ $this->isSync ? 'button-sync-disabled' : 'button-sync' }} flex items-center gap-2 whitespace-nowrap"
                {{ $this->isSync ? 'disabled' : '' }}
            >
                @icon('refresh', 'w-4 h-4')
                <span>{{ ($syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value) ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
            </button>
        </div>

        <x-slot name="navigation">
            <div class="form-row-3">
                <div class="flex items-center gap-4 col-span-1">
                    <div class="form-group group relative w-full">
                        @icon('search-outline', 'svg-input')
                        <input wire:model.live.debounce.300ms="divisionForm.search"
                               type="text"
                               id="divisionSearch"
                               placeholder=" "
                               class="input peer"
                               autocomplete="off"
                        />
                        <label for="divisionSearch" class="label">
                            {{ __('forms.search_by_name') }}
                        </label>
                        <button type="button"
                                class="absolute inset-y-0 end-0 flex items-center pe-1 text-gray-400 hover:text-gray-600"
                                x-show="$wire.divisionForm.search"
                                @click="$wire.set('divisionForm.search', '')"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                    <button type="button"
                            class="p-2.5 text-sm font-medium text-white bg-primary-700 rounded-xl hover:bg-primary-800 focus:ring-4 focus:outline-none focus:ring-primary-300 dark:bg-primary-600 dark:hover:bg-primary-700 dark:focus:ring-primary-800"
                    >
                        @icon('search-outline', 'w-6 h-6')
                    </button>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5">
        <div class="max-w-screen-xl">
            @if($divisions->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[22%]">{{ __('forms.name') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('forms.type') }}</th>
                            <th class="index-table-th w-[18%]">{{ __('forms.phone') }}</th>
                            <th class="index-table-th w-[23%]">{{ __('forms.email') }}</th>
                            <th class="index-table-th w-[14%]">{{ __('forms.status.label') }}</th>
                            <th class="index-table-th w-[6%]">{{ __('forms.action') }}</th>
                        </tr>
                        </thead>

                        <tbody>
                        @foreach ($divisions as $division)
                            <tr wire:key='{{ $division->id }}'
                                x-data="{ divisionTypes: $wire.entangle('dictionaries.DIVISION_TYPE') }"
                                class="index-table-tr"
                            >
                                <td class="index-table-td-primary">
                                    {{ $division->name ?? '' }}
                                </td>
                                <td x-text="divisionTypes['{{ $division->type }}']"
                                    class="index-table-td"
                                ></td>
                                <td class="index-table-td">
                                    {{ $division->phones()->first()?->number ?? '' }}
                                </td>
                                <td class="index-table-td">
                                    {{ $division->email ?? '' }}
                                </td>

                                <td class="index-table-td">
                                    @if ($division->status === Status::INACTIVE)
                                        <span class="badge-red">{{ __('forms.status.non_active') }}</span>
                                    @elseif ($division->status === Status::DRAFT)
                                        <span class="badge-red">{{ __('forms.status.draft') }}</span>
                                    @elseif ($division->status === Status::UNSYNCED)
                                        <span class="badge-yellow">{{ __('forms.status.unsynced') }}</span>
                                    @else
                                        <span class="badge-green">{{ __('forms.status.active') }}</span>
                                    @endif
                                </td>
                                <td class="index-table-td-actions">
                                    <div class="flex justify-center relative">
                                        <div x-data="{
                                             open: false,
                                             toggle() {
                                                 if (this.open) {
                                                     return this.close();
                                                 }
                                                 this.$refs.button.focus();

                                                 this.open = true;
                                             },
                                             close(focusAfter) {
                                                 if (!this.open) return;

                                                 this.open = false;

                                                 focusAfter && focusAfter.focus()
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
                                                    class="hover:text-primary cursor-pointer"
                                                    outline="none"
                                                    id="menu-{{ $division->id }}"
                                            >
                                                <svg class="svg-hover-action w-6 h-6 text-gray-800 dark:text-gray-300"
                                                     aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="18"
                                                     height="18" fill="none" viewBox="0 0 24 24">
                                                    <path stroke="currentColor" stroke-linecap="round"
                                                          stroke-linejoin="round"
                                                          stroke-width="2"
                                                          d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"/>
                                                </svg>
                                            </button>

                                            <div
                                                x-show="open"
                                                x-cloak
                                                x-ref="panel"
                                                x-transition.origin.top.left
                                                @click.outside="close($refs.button)"
                                                :id="$id('dropdown-button')"
                                                class="absolute right-0 mt-2 w-40 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50"
                                                wire:key="menu-{{ $division->id }}-{{ is_string($division->status) ? $division->status : ($division->status?->value ?? 'unknown') }}"
                                            >
                                                @if($division->status !== Status::DRAFT)
                                                    @can('viewAny', HealthcareService::class)
                                                        <a href="{{ route('healthcare-service.index', [legalEntity(), 'division' => $division->id]) }}"
                                                           class="flex items-center gap-2 w-full first-of-type:rounded-t-md px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('settings', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                            {{ __('forms.services') }}
                                                        </a>
                                                    @endcan
                                                @endif

                                                <a href="{{ route('division.view', [legalEntity(), $division]) }}"
                                                   class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                >
                                                    @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                    {{ __('forms.view') }}
                                                </a>

                                                @can('update', $division)
                                                    <a href="{{ route('division.edit', [legalEntity(), $division]) }}"
                                                       class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                    >
                                                        @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                        {{ __('forms.edit') }}
                                                    </a>
                                                @endcan

                                                @can('activate', $division)
                                                    <a href="#"
                                                       wire:key="activate-{{ $division->id }}"
                                                       @click.prevent="
                                                       divisionId = {{ $division->id }};
                                                       textConfirmation = @js(__('divisions.modals.activate.confirmation_text'));
                                                       actionType='activate';
                                                       actionTitle = @js(__('divisions.modals.activate.title'));
                                                       actionButtonText = @js(__('forms.activate'));
                                                       open = !open;
                                                    "
                                                       class="flex items-center gap-2 w-full first-of-type:rounded-t-md last-of-type:rounded-b-md px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                    >
                                                        @icon('check-circle', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                        {{ __('forms.activate') }}
                                                    </a>
                                                @endcan

                                                @can('deactivate', $division)
                                                    <a href="#"
                                                       wire:key="deactivate-{{ $division->id }}"
                                                       @click.prevent="
                                                       divisionId= {{ $division->id }};
                                                       textConfirmation = @js(__('divisions.modals.deactivate.confirmation_text'));
                                                       actionType = 'deactivate';
                                                       actionTitle = @js(__('divisions.modals.deactivate.title'));
                                                       actionButtonText = @js(__('forms.deactivate'));
                                                       open = !open;
                                                    "
class="flex items-center gap-2 w-full last-of-type:rounded-b-md px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                    >
                                                        @icon('delete', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                            {{ __('forms.deactivate') }}
                                                    </a>
                                                @endcan

                                                @can('delete', $division)
                                                    <a href="#"
                                                       @click.prevent="
                                                       divisionId = {{ $division->id }};
                                                       textConfirmation = @js(__('divisions.modals.delete.confirmation_text'));
                                                       actionType= 'delete';
                                                       actionTitle = @js(__('divisions.modals.delete.title'));
                                                       actionButtonText = @js(__('forms.delete'));
                                                       open = !open;
                                                    "
                                                       class="flex items-center gap-2 w-full last-of-type:rounded-b-md px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                    >
                                                        <svg class="w-5 h-5 text-gray-600 dark:text-gray-300" aria-hidden="true"
                                                             xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                             fill="none" viewBox="0 0 24 24">
                                                            <path stroke="currentColor" stroke-linecap="round"
                                                                  stroke-linejoin="round" stroke-width="2"
                                                                  d="M5 7h14m-9 3v8m4-8v8M10 3h4a1 1 0 0 1 1 1v3H9V4a1 1 0 0 1 1-1ZM6 7h12v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7Z"/>
                                                        </svg>

                                                        {{ __('forms.delete') }}
                                                    </a>
                                                @endcan
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
                    {{ $divisions->links() }}
                </div>
            @else
                <fieldset class="fieldset pl-[3.5px] ml-0 mr-auto w-full max-w-full">
                    <legend class="legend relative -top-5 ml-0">
                        @icon('nothing-found', 'w-28 h-28')
                    </legend>

                    <div class="p-4 rounded-lg bg-blue-100 flex items-start mb-4 max-w-2xl">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 mt-0.5">
                                @icon('alert-circle', 'w-5 h-5 text-blue-500 mr-3 mt-1')
                            </div>
                            <div class="flex-1">
                                <p class="font-bold text-blue-800">
                                    {{ __('forms.nothing_found') }}
                                </p>
                                <p class="text-sm text-blue-600">
                                    {{ __('forms.changing_search_parameters') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </fieldset>
            @endif
        </div>

        @include('livewire.division.modal.confirmation-modal')

        <x-forms.loading/>
    </div>
</div>
