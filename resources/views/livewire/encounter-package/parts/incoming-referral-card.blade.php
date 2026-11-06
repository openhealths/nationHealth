<div
    class="record-inner-card overflow-hidden !rounded-[18px] !border-gray-300 dark:!border-gray-700"
    @if ($referralSubmissionPending) wire:poll.5s="pollReferralCompletion" @endif
>
    <div class="record-inner-header">
        <div class="record-inner-column min-w-0 flex-1 !px-3 !py-3">
            <div
                class="record-inner-label !mb-1 !text-[9px] !text-gray-900 dark:!text-gray-300"
            >
                {{ $incomingReferral['requestNumber'] ?: '—' }}
            </div>
            <div class="record-inner-value break-words !font-semibold">
                {{ $incomingReferral['itemName'] ?? '—' }}
            </div>
        </div>
        <div class="record-inner-column-bordered shrink-0 !px-4 !py-3 md:w-48">
            <button
                type="button"
                wire:click="openReferralRedeemModal"
                wire:loading.attr="disabled"
                wire:target="openReferralRedeemModal,redeemReferral"
                @disabled(!($incomingReferral['canComplete'] ?? false) || ($incomingReferral['submissionPending'] ?? false))
                class="button-primary whitespace-nowrap !px-3 !py-2 !text-xs disabled:cursor-not-allowed disabled:opacity-50"
            >
                {{ __('encounters.redeem_referral') }}
            </button>
            @if ($incomingReferral['hasCompletion'] ?? false)
                <button
                    type="button"
                    wire:click="checkReferralCompletion"
                    wire:loading.attr="disabled"
                    class="text-link mt-2 text-xs"
                >
                    {{ __('care-plan.submission_check') }}
                </button>
            @endif
        </div>
    </div>
    <div class="record-inner-body">
        <div class="record-inner-grid-container !px-3 !py-4 md:!pl-3">
            <dl
                class="grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-[1fr_1.15fr_1.5fr_1.15fr]"
            >
                @foreach ([
                    ['label' => __('referrals.quantity'), 'value' => $incomingReferral['quantity'] ?? '—'],
                    ['label' => __('referrals.table.program'), 'value' => $incomingReferral['programName'] ?? '—'],
                    ['label' => __('encounters.referral_validity_period'), 'value' => $incomingReferral['periodLabel'] ?? '—'],
                    ['label' => __('referrals.doctor'), 'value' => $incomingReferral['doctorName'] ?? '—'],
                    ['label' => __('referrals.category'), 'value' => $incomingReferral['categoryLabel'] ?? '—'],
                    ['label' => __('encounters.referral_created'), 'value' => $incomingReferral['createdLabel'] ?? '—'],
                    ['label' => __('referrals.table.priority'), 'value' => $incomingReferral['priorityLabel'] ?? '—'],
                ] as $detail)
                    <div class="min-w-0">
                        <dt
                            class="record-inner-label !mb-1.5 !text-[9px] !text-gray-900 uppercase dark:!text-gray-300"
                        >
                            {{ $detail['label'] }}
                        </dt>
                        <dd
                            class="record-inner-value break-words !text-xs !font-normal"
                        >
                            {{ $detail['value'] }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>
</div>
