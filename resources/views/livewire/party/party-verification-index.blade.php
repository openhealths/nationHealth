<div>
    <x-header-navigation class="items-start">
        <x-slot name="title">
            {{ __('party_verification.verification_list') }}
        </x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            @can('syncVerification', \App\Models\Relations\Party::class)
                <button
                    type="button"
                    wire:click="{{ !$isSyncing ? 'sync' : '' }}"
                    @disabled($isSyncing)
                    class="{{ $isSyncing ? 'button-sync-disabled' : 'button-sync' }} flex items-center gap-2 whitespace-nowrap"
                >
                    @icon('refresh', 'w-4 h-4')
                    {{ __('forms.synchronise_with_eHealth') }}
                </button>
            @endcan
        </div>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5">
        <div class="max-w-screen-xl">
            {{-- Filter Section --}}
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                <div class="flex items-center gap-4">
                    <div class="form-group group w-72">
                        <select wire:model.live="dracsDeathStatus" id="dracsDeathStatus" class="input-select peer px-4 py-2">
                            <option value="">{{ __('forms.all') }}</option>
                            <option value="VERIFIED">{{ __('party_verification.statuses.VERIFIED') }}</option>
                            <option value="NOT_VERIFIED">{{ __('party_verification.statuses.NOT_VERIFIED') }}</option>
                            <option value="VERIFICATION_NEEDED">{{ __('party_verification.statuses.VERIFICATION_NEEDED') }}</option>
                            <option value="VERIFICATION_NOT_NEEDED">{{ __('party_verification.statuses.VERIFICATION_NOT_NEEDED') }}</option>
                        </select>
                        <label for="dracsDeathStatus" class="label">{{ __('party_verification.types.dracs_death') }}</label>
                    </div>
                </div>
            </div>

            @if($verifications->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[45%]">{{ __('forms.employee') }}</th>
                            <th class="index-table-th w-[25%]">{{ __('party_verification.types.drfo') }}</th>
                            <th class="index-table-th w-[22%]">{{ __('party_verification.types.dracs_death') }}</th>
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
                <x-nothing-found />
            @endif

            @if($verifications->isNotEmpty())
                <div class="pagination">
                    {{ $verifications->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
