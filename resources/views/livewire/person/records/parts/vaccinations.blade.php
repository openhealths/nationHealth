<div class="record-inner-card">
    <div class="record-inner-header">
        <div class="record-inner-checkbox-col">
            <input type="checkbox" class="default-checkbox w-5 h-5">
        </div>

        <div class="record-inner-column flex-1">
            <div class="record-inner-label">{{ __('patients.vaccine') }}</div>
            <div class="record-inner-value text-[16px]">SarsCov2_Pr</div>
        </div>

        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
            <div class="record-inner-label">{{ __('forms.status.label') }}</div>
            <div>
                <span class="badge-green">
                    {{ __('patients.status_done') }}
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
                    <div class="record-inner-label">{{ __('patients.dosage') }}</div>
                    <div class="record-inner-subvalue">3 ML</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.route') }}</div>
                    <div class="record-inner-subvalue">Внутрішньом'язево</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.reason') }}</div>
                    <div class="record-inner-subvalue">Згідно календаря щеплень</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.reactions') }}</div>
                    <div class="record-inner-subvalue">-</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.performer') }}</div>
                    <div class="record-inner-subvalue">Шевченко Т.Г.</div>
                </div>

                <div>
                    <div class="record-inner-label">{{ __('patients.manufacturer_and_batch') }}</div>
                    <div class="record-inner-subvalue">Данія (55998)</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                    <div class="record-inner-subvalue">Праве плече</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.was_performed') }}</div>
                    <div class="record-inner-subvalue">Так</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.date_time_performed') }}</div>
                    <div class="record-inner-subvalue">10:00 02.04.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.date_time_entered') }}</div>
                    <div class="record-inner-subvalue">12:00 03.04.2025</div>
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
