<div>
    <x-header-navigation>
        <x-slot name="title">
            {{ __('party_verification.verification_list') }}
        </x-slot>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5">
        <div class="max-w-screen-xl">
            @if($verifications->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[18%]">{{ __('forms.employee') }}</th>
                            <th class="index-table-th w-[12%]">{{ __('party_verification.status') }}</th>
                            <th class="index-table-th w-[12%]">{{ __('party_verification.types.drfo') }}</th>
                            <th class="index-table-th w-[12%]">{{ __('party_verification.types.dracs_death') }}</th>
                            <th class="index-table-th w-[18%]">{{ __('party_verification.types.mvs_passport') }} / {{ __('party_verification.types.dms_passport') }}</th>
                            <th class="index-table-th w-[14%]">{{ __('party_verification.types.dracs_name_change') }}</th>
                            <th class="index-table-th w-[8%]">{{ __('forms.action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($verifications as $item)
                            <tr class="index-table-tr" wire:key="verif-{{ $item['party_id'] }}">
                                <td class="index-table-td-primary">
                                    {{ $item['party_name'] }}
                                </td>

                                <td class="index-table-td">
                                    <x-verification-status-badge :status="$item['verification_status'] ?? '-'" />
                                </td>

                                <td class="index-table-td">
                                    <x-verification-status-badge :status="$item['details']['drfo']['verification_status'] ?? '-'" />
                                </td>

                                <td class="index-table-td">
                                    <x-verification-status-badge :status="$item['details']['dracs_death']['verification_status'] ?? '-'" />
                                </td>

                                <td class="index-table-td">
                                    <div class="flex flex-col space-y-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                {{ __('party_verification.types.mvs_passport') }}
                                            </span>
                                            <x-verification-status-badge :status="$item['details']['mvs_passport']['verification_status'] ?? '-'" />
                                        </div>
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                {{ __('party_verification.types.dms_passport') }}
                                            </span>
                                            <x-verification-status-badge :status="$item['details']['dms_passport']['verification_status'] ?? '-'" />
                                        </div>
                                    </div>
                                </td>

                                <td class="index-table-td">
                                    <x-verification-status-badge :status="$item['details']['dracs_name_change']['verification_status'] ?? '-'" />
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
                <x-nothing-found />
            @endif

            @if($verifications->isNotEmpty())
                <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
                    {{ $verifications->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
