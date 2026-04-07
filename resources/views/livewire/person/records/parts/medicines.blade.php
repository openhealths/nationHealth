<div class="record-inner-card">
    <div class="record-inner-header">
        <div class="record-inner-checkbox-col">
            <input type="checkbox" class="default-checkbox w-5 h-5">
        </div>

        <div class="record-inner-column flex-1">
            <div class="record-inner-label">{{ __('forms.name') }}</div>
            <div class="record-inner-value text-[16px]">Дротаверин 20 мг/мл, розчин для ін'єкцій</div>
        </div>

        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
            <div class="record-inner-label">{{ __('forms.status.label') }}</div>
            <div>
                <span class="badge-green">
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
                    <div class="record-inner-label">{{ __('patients.frequency') }}</div>
                    <div class="record-inner-subvalue">Двічі на день</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.start_of_intake') }}</div>
                    <div class="record-inner-subvalue">02.03.2026</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.source_label') }}</div>
                    <div class="record-inner-subvalue">Зі слів пацієнта</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.date_entered') }}</div>
                    <div class="record-inner-subvalue">03.03.2026</div>
                </div>

                <div>
                    <div class="record-inner-label">{{ __('patients.dosage') }}</div>
                    <div class="record-inner-subvalue">50 г/прийом</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.end_of_intake') }}</div>
                    <div class="record-inner-subvalue">02.04.2026</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                    <div class="record-inner-subvalue">Сидоренко І.В.</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.comment') }}</div>
                    <div class="record-inner-subvalue">Коментар</div>
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
