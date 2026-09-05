<div x-data="{ showUpdateModal: false }" x-effect="showUpdateModal = $wire.showUpdateModal">
    {{-- Breadcrumb Navigation --}}
    <x-header-navigation>
        <x-slot name="title">{{ __('party_verification.label') }} {{ $party->fullName ?? '' }}</x-slot>
    </x-header-navigation>

    {{-- Main Content Section --}}
    <x-section class="form shift-content mt-6">
        {{-- 1. Verification Details Table --}}
        <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
            <table class="w-full min-w-full text-left text-sm text-gray-500 rtl:text-right dark:text-gray-400">
                <thead class="bg-gray-50 text-xs text-gray-700 uppercase dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="w-1/5 px-6 py-3">{{ __('party_verification.label') }}</th>
                        <th scope="col" class="px-6 py-3">{{ __('party_verification.status') }}</th>
                        <th scope="col" class="px-6 py-3">{{ __('forms.reason_code') }}</th>
                        <th scope="col" class="w-2/5 px-6 py-3">{{ __('forms.ehealth_comment_recommendation') }}</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- String filtering occurs at the PHP level (drfo/dracs_death), so here we simply output --}}
                    @forelse ($verificationDetails['details'] ?? [] as $key => $details)
                        @php
                            $status = data_get($details, 'verification_status');
                            $reason = data_get($details, 'verification_reason');
                            $comment = data_get($details, 'verification_comment'); // Або 'reason' з API
                            $result = data_get($details, 'result');
                        @endphp
                        <tr
                            class="border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800"
                            wire:key="details-{{ $key }}"
                        >
                            {{-- Column 1: Type --}}
                            <td class="px-6 py-4 align-top text-sm font-medium whitespace-normal text-gray-900 dark:text-white">
                                {{ __('party_verification.types.' . $key) }}
                            </td>

                            {{-- Column 2: Status --}}
                            <td class="px-6 py-4 align-top text-sm whitespace-normal">
                                @if ($status === 'VERIFIED')
                                    <span class="badge-green">{{ __('party_verification.statuses.VERIFIED') }}</span>
                                @elseif ($status === 'NOT_VERIFIED')
                                    <span class="badge-red">{{ __('party_verification.statuses.NOT_VERIFIED') }}</span>
                                @elseif ($status === 'VERIFICATION_NEEDED')
                                    <span class="badge-yellow">{{ __('party_verification.statuses.VERIFICATION_NEEDED') }}</span>
                                @elseif ($status === 'VERIFICATION_NOT_NEEDED')
                                    <span class="badge-gray">{{ __('party_verification.statuses.VERIFICATION_NOT_NEEDED') }}</span>
                                @elseif ($status)
                                    <span class="badge-red">{{ $status }}</span>
                                @else
                                    <span>-</span>
                                @endif
                            </td>

                            {{-- Column 3: Reason --}}
                            <td class="px-6 py-4 align-top text-sm whitespace-normal text-gray-500 dark:text-gray-400">
                                <div>
                                    {{ $reason ? (__('party_verification.reasons.' . $reason) ?? $reason) : '-' }}
                                </div>
                                @if ($result)
                                    <div class="text-xs text-gray-400">({{ __('forms.code') }}: {{ $result }})</div>
                                @endif
                            </td>

                            {{-- Column 4: Comment/Recommendation --}}
                            <td class="px-6 py-4 align-top text-sm whitespace-normal text-gray-500 dark:text-gray-400">
                                @if (!empty($comment))
                                    <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $comment }}</span>
                                @elseif ($status !== 'VERIFIED')
                                    {{ __('party_verification.recommendations.' . $key, ['result' => $result]) }}
                                @else
                                    <span>-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr class="border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                            <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                                {{ __('forms.verification_details_not_loaded') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 2. Warning Block (3.23 п.3.2.2: drfo / dracs_death / dms_passport) --}}
        @php
            $warningStreams = collect(['drfo', 'dracs_death', 'dms_passport'])
                ->filter(fn (string $stream) => data_get($verificationDetails, "details.{$stream}.verification_status") === 'NOT_VERIFIED')
                ->values();
        @endphp
        @if ($warningStreams->isNotEmpty())
            <div
                class="mt-6 mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-gray-800 dark:text-red-400"
                role="alert"
            >
                <h4 class="font-bold">{{ __('party_verification.warning.header') }}</h4>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    @foreach ($warningStreams as $stream)
                        <li>{{ __('party_verification.warning.' . $stream) }}</li>
                    @endforeach
                </ul>
                <p class="mt-3">{{ __('party_verification.warning.footer') }}</p>
            </div>
        @endif

        {{-- 3. Action Buttons --}}
        <div class="mt-8 flex items-center justify-start gap-4">
            <a href="{{ $backUrl }}" class="button-minor"> {{ __('forms.back') }} </a>

            @can('syncVerification', \App\Models\Relations\Party::class)
                <button
                    type="button"
                    wire:click="{{ !$isSyncing ? 'syncOne' : '' }}"
                    wire:loading.attr="disabled"
                    @disabled($isSyncing)
                    class="{{ $isSyncing ? 'button-sync-disabled' : 'button-sync' }} flex items-center gap-2 whitespace-nowrap"
                >
                    @icon('refresh', 'w-4 h-4')
                    {{ __('forms.synchronise_with_eHealth') }}
                </button>
            @endcan

            <button type="button" wire:click="checkAndOpenModal" class="button-primary-outline">
                {{ __('forms.update_data') }}
            </button>
        </div>
    </x-section>

    {{-- 4. Update Status Modal --}}
    <div
        x-show="showUpdateModal"
        class="fixed inset-0 z-50 flex items-center justify-center"
        style="display: none"
        x-cloak
    >
        {{-- Backdrop --}}
        <div
            x-show="showUpdateModal"
            x-transition.opacity
            class="fixed inset-0 bg-black/75"
            @click="$wire.closeUpdateModal()"
        ></div>

        {{-- Modal Body --}}
        <div
            x-show="showUpdateModal"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            class="relative z-50 m-4 w-full max-w-2xl rounded-lg bg-white shadow dark:bg-gray-800"
        >
            <form wire:submit.prevent="updateStatus">
                {{-- Modal Header --}}
                <div class="flex items-center justify-between rounded-t border-b border-gray-200 p-4 dark:border-gray-600">
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('forms.update_data') }}</h3>

                    <button
                        type="button"
                        @click="$wire.closeUpdateModal()"
                        class="ml-auto inline-flex items-center rounded-lg bg-transparent p-1.5 text-sm text-gray-400 hover:bg-gray-200 hover:text-gray-900 dark:hover:bg-gray-600 dark:hover:text-white"
                    >
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                    </button>
                </div>

                <div class="space-y-6 p-6">
                    {{-- 1. Subject of verification --}}
                    <div class="form-group group">
                        <select
                            wire:model.live="verificationStream"
                            id="verificationStream"
                            class="input-select peer px-4 py-2"
                        >
                            <option value="dracs_death">{{ __('party_verification.types.dracs_death') }}</option>
                        </select>
                        <label
                            for="verificationStream"
                            class="label"
                        >{{ __('party_verification.subject_verification') }}</label>
                    </div>

                    {{-- 2. Status (read-only; API allows VERIFIED only — never bind disabled wire:model) --}}
                    <div class="form-group group">
                        <input
                            type="text"
                            id="status"
                            class="input peer px-4 py-2"
                            value="{{ __('party_verification.statuses.VERIFIED') }}"
                            readonly
                            tabindex="-1"
                        />
                        <label for="status" class="label">{{ __('party_verification.status') }}</label>
                    </div>

                    {{-- 3. Reason (live IL enum: MANUAL_DECEASED / MANUAL_NO_DEATH_RECORD) --}}
                    <div class="form-group group">
                        <select wire:model="reason" id="reason" class="input-select peer px-4 py-2">
                            <option value="">{{ __('forms.choose_reason') }}</option>
                            <option value="{{ \App\Enums\Party\DracsDeathVerificationReason::MANUAL_DECEASED->value }}">
                                {{ __('party_verification.reasons.MANUAL_DECEASED') }}
                            </option>
                            <option value="{{ \App\Enums\Party\DracsDeathVerificationReason::MANUAL_NO_DEATH_RECORD->value }}">
                                {{ __('party_verification.reasons.MANUAL_NO_DEATH_RECORD') }}
                            </option>
                        </select>
                        <label for="reason" class="label">{{ __('forms.reason_code') }}</label>
                        @error('reason')
                            <span class="mt-1 text-sm text-red-500">{{ $message }}</span>
                        @enderror
                    </div>

                    {{-- 4. Comment (required by product / Apiary sample) --}}
                    <div class="form-group">
                        <label for="comment" class="mb-1 block text-sm font-medium text-gray-500 dark:text-gray-400">
                            {{ __('forms.comment') }}
                        </label>
                        <textarea
                            id="comment"
                            wire:model="comment"
                            class="textarea mt-1 px-4 !text-gray-500 dark:!text-gray-400"
                            placeholder=" "
                        ></textarea>
                        @error('comment')
                            <span class="mt-1 text-sm text-red-500">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                {{-- Modal Footer --}}
                <div class="flex items-center justify-start gap-4 border-t border-gray-200 p-6 dark:border-gray-600">
                    <button type="button" @click="$wire.closeUpdateModal()" class="button-minor">
                        {{ __('forms.cancel') }}
                    </button>

                    <button type="submit" class="button-primary-outline" wire:loading.attr="disabled">
                        {{ __('forms.update_data') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
