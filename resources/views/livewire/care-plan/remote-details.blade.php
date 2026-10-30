<fieldset class="fieldset !mb-6 !max-w-full">
    <legend class="legend">{{ __('device-requests.plan.remote_details') }}</legend>
    <button type="button" wire:click="sync" wire:loading.attr="disabled" class="button-primary-outline">
        {{ __('device-requests.plan.refresh') }}
    </button>
    <dl class="mt-4 grid gap-4 md:grid-cols-2">
        @foreach (['requisition', 'status', 'category', 'title', 'period', 'encounter', 'supporting_info', 'addresses', 'description', 'status_reason', 'note'] as $field)
            <div>
                <dt class="font-semibold">{{ __('device-requests.plan.'.$field) }}</dt>
                <dd class="break-words">
                    @foreach (is_array($record[$field] ?? null) ? $record[$field] : [$record[$field] ?? '—'] as $label)
                        <div>{{ $label }}</div>
                    @endforeach
                </dd>
            </div>
        @endforeach
    </dl>
</fieldset>
