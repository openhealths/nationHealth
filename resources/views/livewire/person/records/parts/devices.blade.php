<div class="record-inner-card">
    <div class="record-inner-header">
        <div class="record-inner-checkbox-col">
            <input type="checkbox" class="default-checkbox w-5 h-5">
        </div>

        <div class="record-inner-column flex-1">
            <div class="record-inner-label">{{ __('forms.name') }}</div>
            <div class="record-inner-value text-[16px]">Тест-смужки Accu-Chek Active для глюкометра</div>
        </div>

        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
            <div class="record-inner-label">{{ __('forms.status.label') }}</div>
            <div>
                <span class="badge-green">
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
                    <div class="record-inner-label">{{ __('patients.model_number') }}</div>
                    <div class="record-inner-subvalue">1231FDSE</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('forms.type') }}</div>
                    <div class="record-inner-subvalue">Гістероскоп</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.lot_number') }}</div>
                    <div class="record-inner-subvalue">1231FDSE</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.manufacture_date') }}</div>
                    <div class="record-inner-subvalue">01.02.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.comment') }}</div>
                    <div class="record-inner-subvalue">Імплант був вилучений по причині заміни на новий</div>
                </div>

                <div>
                    <div class="record-inner-label">{{ __('patients.properties') }}</div>
                    <div class="record-inner-subvalue">10 шт</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.manufacturer_and_serial') }}</div>
                    <div class="record-inner-subvalue">GlobalMed, Inc <br> NSPX30</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                    <div class="record-inner-subvalue">Сидоренко І.В.</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.expiration_date') }}</div>
                    <div class="record-inner-subvalue">02.02.2027</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.status_change_reason') }}</div>
                    <div class="record-inner-subvalue">-</div>
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
