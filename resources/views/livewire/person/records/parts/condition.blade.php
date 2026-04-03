<div class="record-inner-card">
    <div class="record-inner-header">
        <div class="record-inner-checkbox-col">
            <input type="checkbox" class="default-checkbox w-5 h-5">
        </div>

        <div class="record-inner-column flex-1">
            <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
            <div class="record-inner-value text-[16px]">A08 - Припухлість</div>
        </div>

        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
            <div class="record-inner-label">{{ __('patients.status_clinical') }}</div>
            <div>
                <span class="record-inner-status-badge">
                    {{ __('patients.active_status') }}
                </span>
            </div>
        </div>

        <div class="record-inner-action-col">
            <button class="record-inner-action-btn">
                @icon('edit-user-outline', 'w-5 h-5')
            </button>
        </div>
    </div>

    <div class="record-inner-body">
        <div class="record-inner-grid-container">
            <div
                class="grid grid-cols-2 xl:grid-cols-4 gap-y-4 gap-x-4 w-full [&>div]:min-w-0 [&_.record-inner-subvalue]:break-words">
                <div>
                    <div class="record-inner-label">{{ __('patients.type') }}</div>
                    <div class="record-inner-subvalue">{{ __('patients.basic') }}</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.verification_status') }}</div>
                    <div class="record-inner-subvalue">{{ __('patients.final') }}</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                    <div class="record-inner-subvalue">{{ __('patients.head') }}</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.created') }}</div>
                    <div class="record-inner-subvalue">04.02.2026</div>
                </div>

                <div>
                    <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                    <div class="record-inner-subvalue">Шевченко Т.Г.</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.state') }}</div>
                    <div class="record-inner-subvalue">{{ __('patients.moderate_severity') }}</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.start_date') }}</div>
                    <div class="record-inner-subvalue">02.02.2025</div>
                </div>
            </div>
        </div>
        <div class="record-inner-id-col">
            <div class="min-w-0">
                <div class="record-inner-label">ID ECO3</div>
                <div class="record-inner-id-value">1231-adsadas-aqeqe-casdda</div>
            </div>
            <div class="min-w-0">
                <div class="record-inner-label">{{ __('patients.medical_record_id') }}</div>
                <div class="record-inner-id-value">1231-adsadas-aqeqe-casdda</div>
            </div>
        </div>
    </div>
</div>
