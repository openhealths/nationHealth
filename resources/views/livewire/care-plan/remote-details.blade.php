<fieldset class="fieldset !mb-6 !max-w-full">
    <legend class="legend">{{ __('device-requests.plan.remote_details') }}</legend>
    <button type="button" wire:click="refreshDisplayedDetails" class="button-minor">
        {{ __('device-requests.plan.refresh') }}
    </button>
    <dl class="mt-4 grid gap-4 md:grid-cols-2">
        @foreach (['requisition', 'status', 'category', 'title', 'period', 'encounter', 'supporting_info', 'addresses', 'description', 'status_reason', 'note'] as $field)
            <div>
                <dt class="font-semibold">{{ __('device-requests.plan.'.$field) }}</dt>
                <dd class="break-words">
                    @include('livewire.device-request.record-value', ['value' => $record[$field] ?? '—'])
                </dd>
            </div>
        @endforeach
    </dl>
</fieldset>
