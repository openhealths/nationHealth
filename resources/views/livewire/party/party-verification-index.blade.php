<div>
    <x-header-navigation>
        <x-slot name="title">
            {{ __('party_verification.verification_list') }}
        </x-slot>

        <x-slot name="navigation">
            <div class="flex items-center gap-4 mt-4 lg:mt-0">
                <div class="form-group mb-0 w-64">
                    <select wire:model.live="dracsDeathStatus" id="dracsDeathStatus" class="input peer">
                        <option value="">{{ __('forms.all') }}</option>
                        <option value="NOT_VERIFIED">Неуспішна верифікація (NOT_VERIFIED)</option>
                    </select>
                    <label for="dracsDeathStatus" class="label">Статус АЗ про смерть</label>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5">
        <div class="max-w-screen-xl">
            @if($verifications->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[25%]">{{ __('forms.employee') }}</th>
                            <th class="index-table-th w-[20%]">{{ __('party_verification.types.drfo') }}</th>
                            <th class="index-table-th w-[20%]">{{ __('party_verification.types.dracs_death') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('forms.action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($verifications as $item)
                            <tr class="index-table-tr" wire:key="verif-{{ $item['party_id'] }}">
                                <td class="index-table-td-primary">
                                    {{ $item['party_name'] }}
                                </td>

                                <td class="index-table-td">
                                    <x-verification-status-badge :status="$item['details']['drfo']['verification_status'] ?? '-'" />
                                </td>

                                <td class="index-table-td">
                                    <x-verification-status-badge :status="$item['details']['dracs_death']['verification_status'] ?? '-'" />
                                </td>

                                <td class="index-table-td-actions">
                                    @if($item['local_id'])
                                        <a href="{{ route('party.verification.show', ['legalEntity' => $legalEntity->id, 'party' => $item['local_id']]) }}"
                                           title="{{ __('forms.details') }}"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-600 hover:text-blue-600')
                                        </a>
                                    @else
                                        <span class="text-xs text-gray-400 italic" title="{{ __('forms.party_not_found_locally') }}">
                                            N/A
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <fieldset class="fieldset !mx-auto mt-8 shift-content">
                    <legend class="legend relative -top-5">@icon('nothing-found', 'w-28 h-28')</legend>
                    <div class="p-4 rounded-lg bg-blue-100 flex items-start mb-4">
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

            <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
                {{ $verifications->links() }}
            </div>
        </div>
    </div>
</div>
