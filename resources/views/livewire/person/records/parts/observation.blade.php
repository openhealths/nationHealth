<div class="record-inner-card">
    <div class="record-inner-header">
        <div class="record-inner-checkbox-col">
            <input type="checkbox" class="default-checkbox w-5 h-5">
        </div>

        <div class="record-inner-column flex-1">
            <div class="record-inner-label">{{ __('patients.category_and_code') }}</div>
            <div class="record-inner-value text-[16px]">Лабораторні дослідження | 85329-1</div>
        </div>

        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
            <div class="record-inner-label">{{ __('patients.status_label') }}</div>
            <div>
                <span class="record-inner-status-badge">
                    {{ __('patients.status_valid') }}
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
                class="grid grid-cols-2 xl:grid-cols-5 gap-y-4 gap-x-4 w-full [&>div]:min-w-0 [&_.record-inner-subvalue]:break-words">
                <div>
                    <div class="record-inner-label">{{ __('patients.information_source') }}</div>
                    <div class="record-inner-subvalue">Пацієнт</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.method') }}</div>
                    <div class="record-inner-subvalue">Інструментальне обстеження</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.value') }}</div>
                    <div class="record-inner-subvalue">5 мкмоль/л</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.getting_indicators') }}</div>
                    <div class="record-inner-subvalue">01.02.2025- 02.02.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.updated') }}</div>
                    <div class="record-inner-subvalue">05.02.2025</div>
                </div>

                <div>
                    <div class="record-inner-label">{{ __('patients.interpretation') }}</div>
                    <div class="record-inner-subvalue">Краще</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                    <div class="record-inner-subvalue">Праве плече</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                    <div class="record-inner-subvalue">Сидоренко І.В.</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.created') }}</div>
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
