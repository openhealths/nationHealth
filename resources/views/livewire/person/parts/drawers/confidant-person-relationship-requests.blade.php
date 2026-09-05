<div class="mt-8">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
            {{ __('patients.confidant_relationship_requests') }}
        </h3>
        <button wire:click.prevent="syncConfidantPersonRelationshipRequestsList" type="button" class="button-sync">
            @icon('refresh', 'w-4 h-4 mr-2')
            {{ __('patients.sync_requests') }}
        </button>
    </div>

    <table class="table-input w-full">
        <thead class="thead-input">
            <tr>
                <th scope="col" class="th-input">{{ __('ID') }}</th>
                <th scope="col" class="th-input">{{ __('forms.status.label') }}</th>
                <th scope="col" class="th-input">{{ __('forms.action') }}</th>
                <th scope="col" class="th-input">{{ __('patients.channel') }}</th>
                <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
            </tr>
        </thead>
        <tbody>
            @nonempty($this->confidantPersonRelationshipRequests)
                @foreach ($this->confidantPersonRelationshipRequests as $index => $request)
                    <tr>
                        <td class="td-input text-sm text-gray-600 dark:text-gray-400">{{ $request['uuid'] }}</td>
                        <td class="td-input">
                            <span @class([$request['status']?->color()])>
                                {{ $request['status']?->label() ?? '-' }}
                            </span>
                        </td>
                        <td class="td-input text-gray-700 dark:text-gray-300">
                            @if (empty($request['action']))
                                -
                            @else
                                {{ $request['action'] === 'INSERT' ? __('patients.activate_relationship') : __('patients.deactivate_relationship') }}
                            @endif
                        </td>
                        <td class="td-input text-gray-700 dark:text-gray-300">
                            {{ $request['channel'] === 'MIS' ? __('patients.mis_system') : $request['channel'] }}
                        </td>
                        <td class="td-input text-center">
                            <div
                                class="relative"
                                x-data="{ openRequestDropdown: false }"
                                @click.outside="openRequestDropdown = false"
                            >
                                <button
                                    @click="openRequestDropdown = ! openRequestDropdown"
                                    type="button"
                                    class="cursor-pointer rounded-full p-1 transition-colors hover:bg-gray-100 dark:hover:bg-gray-700"
                                >
                                    @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                </button>

                                <div
                                    x-show="openRequestDropdown"
                                    x-transition
                                    x-cloak
                                    class="absolute right-0 z-10 w-44 rounded border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-700"
                                >
                                    <div class="py-1">
                                        <button
                                            type="button"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            wire:click.prevent="approveFromRequest('{{ $request['uuid'] }}')"
                                        >
                                            {{ __('forms.confirm') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-gray-50 dark:text-red-400 dark:hover:bg-gray-600"
                                            wire:click.prevent="deactivateConfidantPersonRelationshipRequest('{{ $request['uuid'] }}')"
                                        >
                                            {{ __('patients.cancel_request') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            @endnonempty
        </tbody>
    </table>
</div>
