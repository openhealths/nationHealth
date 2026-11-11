@use('App\Enums\JobStatus')
@if ($submissionStates !== [])
    <div @if ($hasPendingSubmission) wire:poll.3s="checkSubmissionStatus" @endif class="space-y-3">
        @foreach ($submissionStates as $documentUuid => $submission)
            <div
                class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                wire:key="submission-{{ $submission['id'] }}"
            >
                <p class="font-semibold">
                    {{ $submission['document'] }}
                    @if (filled($submission['number'])) №{{ $submission['number'] }}@endif
                </p>
                <p>
                    {{ JobStatus::tryFrom($submission['status'] ?? '')?->label() ?? __('care-plan.submission_pending') }}
                </p>
                @if ($submission['status'] === JobStatus::FAILED->value)
                    <p class="text-red-600 dark:text-red-400">
                        {{ $submission['error'] ?: __('care-plan.submission_failed_edit_draft') }}
                    </p>
                @endif
            </div>
        @endforeach
    </div>
@endif
