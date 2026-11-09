<x-modal wire:model="showReferralStatusModal" maxWidth="xl">
    <div class="p-6 text-left md:p-8">
        <h2 class="mb-6 text-xl font-bold text-gray-900 dark:text-white">
            {{ __('referrals.messages.completion_job_details') }}
        </h2>

        <dl class="space-y-4 text-sm">
            @foreach ([
                ['label' => __('referrals.requisition'), 'value' => $incomingReferral['requestNumber'] ?? '—'],
                ['label' => __('forms.ehealth_id').' (UUID)', 'value' => $incomingReferral['uuid'] ?? '—'],
                ['label' => __('referrals.status'), 'value' => $incomingReferral['statusLabel'] ?? '—'],
                ['label' => __('encounters.submission.job_id'), 'value' => $referralCompletionJob['id'] ?? '—'],
                ['label' => __('encounters.submission.label'), 'value' => $referralCompletionJob['status'] ?? '—'],
            ] as $detail)
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ $detail['label'] }}</dt>
                    <dd class="break-all font-medium text-gray-900 dark:text-gray-100">{{ $detail['value'] }}</dd>
                </div>
            @endforeach
            @if (!empty($referralCompletionJob['error']))
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('encounters.submission.error') }}</dt>
                    <dd class="whitespace-pre-line font-medium text-red-600 dark:text-red-400">{{ $referralCompletionJob['error'] }}</dd>
                </div>
            @endif
        </dl>

        @if (!empty($referralCompletionJob['refreshError']))
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ $referralCompletionJob['refreshError'] }}</p>
        @endif

        <div class="mt-8 flex gap-4">
            <button type="button" wire:click="openReferralStatusModal" wire:loading.attr="disabled" wire:target="openReferralStatusModal" class="button-primary">
                {{ __('care-plan.submission_check') }}
            </button>
            <button type="button" @click="show = false" class="button-minor">
                {{ __('forms.close') }}
            </button>
        </div>
    </div>
</x-modal>
