<div class="record-inner-card">
    <div class="record-inner-header">
        <div class="record-inner-checkbox-col">
            <input type="checkbox" class="default-checkbox w-5 h-5">
        </div>

        <div class="record-inner-column flex-1">
            <div class="record-inner-label">{{ __('patients.code') }}</div>
            <div class="record-inner-value text-[16px]">ЦД. Категорія 3 (студенти)</div>
        </div>

        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
            <div class="record-inner-label">{{ __('patients.status_label') }}</div>
            <div>
                <span class="record-inner-status-badge">
                    {{ __('patients.status_completed') }}
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
            <div class="flex items-start justify-between gap-2 xl:gap-4 overflow-hidden">
                <div>
                    <div class="record-inner-label">{{ __('patients.created') }}</div>
                    <div class="record-inner-value">02.04.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.start') }}</div>
                    <div class="record-inner-value">02.04.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.end') }}</div>
                    <div class="record-inner-value">02.02.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                    <div class="record-inner-value">Петров І.І.</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.clinical_impression_conclusion') }}</div>
                    <div class="record-inner-value">{{ __('patients.conducted') }}</div>
                </div>
            </div>
        </div>
        <div class="record-inner-id-col">
            <div class="min-w-0">
                <div class="record-inner-label">ID ECO3</div>
                <div class="record-inner-id-value">1231-adsadas-aqeqe-casdda</div>
            </div>
            <div class="min-w-0">
                <div class="record-inner-label">ID Епізоду</div>
                <div class="record-inner-id-value">1231-adsadas-aqeqe-casdda</div>
            </div>
        </div>
    </div>
</div>
