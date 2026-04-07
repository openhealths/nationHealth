<div class="record-inner-card">
    <div class="record-inner-header">
        <div class="record-inner-checkbox-col">
            <input type="checkbox" class="default-checkbox w-5 h-5">
        </div>

        <div class="record-inner-column flex-1">
            <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
            <div class="record-inner-value text-[16px]">56001-00 | Комп'ютерна томографія головного мозку</div>
        </div>

        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
            <div class="record-inner-label">{{ __('forms.status.label') }}</div>
            <div>
                <span class="badge-yellow">
                    Підписано
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
                class="grid grid-cols-2 xl:grid-cols-3 gap-y-4 gap-x-4 w-full [&>div]:min-w-0 [&_.record-inner-subvalue]:break-words">
                <div>
                    <div class="record-inner-label">{{ __('forms.category') }}</div>
                    <div class="record-inner-subvalue">Візуальні дослідження</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.performer') }}</div>
                    <div class="record-inner-subvalue">Сидоренко О.В.</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.created') }}</div>
                    <div class="record-inner-subvalue">02.02.2025</div>
                </div>

                <div>
                    <div class="record-inner-label">{{ __('patients.referrals') }}</div>
                    <div class="record-inner-subvalue">1232132131123</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.conclusion') }}</div>
                    <div class="record-inner-subvalue">Виконано</div>
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
