{{-- Third-party processing status (ERLN / DRACS / DIIA) from getIntegrationData. --}}
@if (!empty($items))
    <div class="mt-6 space-y-3">
        <div class="mb-1 font-semibold text-gray-900 dark:text-gray-100">
            {{ __('patients.composition.integration.title') }}
        </div>
        @foreach ($items as $item)
            @php
                $status = data_get($item, 'integrationStatus') ?: data_get($item, 'taskStatus');
            @endphp
            <div
                class="record-inner-card"
                wire:key="integration-{{ data_get($item, 'type') }}-{{ data_get($item, 'updatedAt') }}"
            >
                <div class="record-inner-header">
                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('patients.composition.integration.component') }}</div>
                        <div class="record-inner-value text-[15px] font-semibold">
                            {{ data_get($item, 'component') ?: '-' }}
                        </div>
                    </div>
                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('patients.composition.integration.type') }}</div>
                        <div class="record-inner-value text-[15px] font-semibold">
                            {{ data_get($item, 'type') ?: '-' }}
                        </div>
                    </div>
                    <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                        <div class="record-inner-label">{{ __('patients.composition.integration.status') }}</div>
                        <div class="record-inner-value text-[14px] font-semibold">{{ $status ?: '-' }}</div>
                    </div>
                </div>
                <div class="record-inner-body">
                    <div class="record-inner-grid-container">
                        <div class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-2">
                            @if (data_get($item, 'details.SL_NUM'))
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.composition.integration.erln_number') }}
                                    </div>
                                    <div class="record-inner-value text-[14px] font-semibold">
                                        {{ data_get($item, 'details.SL_NUM') }}
                                    </div>
                                </div>
                            @endif
                            @if (data_get($item, 'statusMessage'))
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.composition.erln_resend.error_message') }}
                                    </div>
                                    <div class="record-inner-value text-[14px] font-semibold text-red-600 dark:text-red-400">
                                        {{ data_get($item, 'statusMessage') }}
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
