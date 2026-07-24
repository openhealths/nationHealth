@use('App\Enums\Contract\IdForm')
@use('App\Enums\Contract\Type')
@if(isset($contract) && isset($data))
    @php
        $periodStart = formatDisplayDate($contract->start_date ?? data_get($data, 'start_date'));
        $periodEnd = formatDisplayDate($contract->end_date ?? data_get($data, 'end_date'));
        $statusReason = $contract->status_reason ?? data_get($data, 'status_reason');
        $idFormDisplay = $idFormName ?? IdForm::resolveLabel(
            data_get($data, 'id_form') ?? $contract->id_form,
            $contract->type
        );
        $createdAt = formatDisplayDateTime($contract->inserted_at ?? data_get($data, 'inserted_at'));
        $updatedAt = formatDisplayDateTime(data_get($data, 'updated_at') ?? $contract->updated_at);
        $contractNumber = $contract->contract_number ?? data_get($data, 'contract_number');
        $previousRequestId = data_get($data, 'previous_request_id');
        $parentContractId = data_get($data, 'parent_contract_id');
        $issueCity = data_get($data, 'issue_city');
        $rmspAmount = data_get($data, 'contractor_rmsp_amount', $contract->contractor_rmsp_amount ?? null);
    @endphp
    <fieldset class="fieldset">
        <legend class="legend">{{ __('contracts.general_data') }}</legend>

        <div class="form-row-2">
            <div class="form-group group">
                <label for="contract-type" class="label">{{ __('contracts.type') }}</label>
                <input id="contract-type"
                       type="text"
                       value="{{ Type::resolveLabel($contract->type) }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
            <div class="form-group group">
                <label for="contract-form" class="label">{{ __('contracts.id_form_label') }}</label>
                <input id="contract-form"
                       type="text"
                       value="{{ $idFormDisplay ?: '-' }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group group">
                <label for="contract-uuid" class="label">{{ __('contracts.id') }}</label>
                <input id="contract-uuid"
                       type="text"
                       value="{{ $contract->uuid ?: data_get($data, 'id') ?: '-' }}"
                       class="input peer font-mono text-sm"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
            <div class="form-group group">
                <label for="contract-number" class="label">{{ __('contracts.number_label') }}</label>
                <input id="contract-number"
                       type="text"
                       value="{{ $contractNumber ?: '-' }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group group">
                <label for="contract-period" class="label">{{ __('contracts.period_label') }}</label>
                <input id="contract-period"
                       type="text"
                       value="{{ $periodStart && $periodEnd ? "{$periodStart} – {$periodEnd}" : ($periodStart ?: $periodEnd ?: '-') }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
            <div class="form-group group">
                <label for="contract-created-at" class="label">{{ __('contracts.created_at_label') }}</label>
                <input id="contract-created-at"
                       type="text"
                       value="{{ $createdAt ?: '-' }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group group">
                <label for="contract-updated-at" class="label">{{ __('contracts.updated_at_label') }}</label>
                <input id="contract-updated-at"
                       type="text"
                       value="{{ $updatedAt ?: '-' }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
            <div class="form-group group">
                <label for="contract-status-reason" class="label">{{ __('contracts.status_reason_label') }}</label>
                <input id="contract-status-reason"
                       type="text"
                       value="{{ $statusReason ?: '-' }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group group">
                <label for="contract-parent" class="label">{{ __('contracts.parent_contract') }}</label>
                <input id="contract-parent"
                       type="text"
                       value="{{ $parentContractId ?: '-' }}"
                       class="input peer font-mono text-sm"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
            <div class="form-group group">
                <label for="contract-previous-request" class="label">{{ __('contracts.previous_request') }}</label>
                <input id="contract-previous-request"
                       type="text"
                       value="{{ $previousRequestId ?: '-' }}"
                       class="input peer font-mono text-sm"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group group">
                <label for="contract-issue-city" class="label">{{ __('contracts.issue_city') }}</label>
                <input id="contract-issue-city"
                       type="text"
                       value="{{ $issueCity ?: '-' }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
            </div>
            @if($rmspAmount !== null && $rmspAmount !== '')
                <div class="form-group group">
                    <label for="contract-rmsp" class="label">{{ __('contracts.contractor_rmsp_amount') }}</label>
                    <input id="contract-rmsp"
                           type="text"
                           value="{{ $rmspAmount }}"
                           class="input peer"
                           placeholder=" "
                           disabled
                           readonly
                    />
                </div>
            @endif
        </div>
    </fieldset>
@else
    @php
        $dictionary = $this instanceof \App\Livewire\Contract\CapitationContractCreate
            ? ($this->dictionaries['CONTRACT_TYPE'] ?? [])
            : ($this->dictionaries['REIMBURSEMENT_CONTRACT_TYPE'] ?? []);
    @endphp
    <fieldset class="fieldset">
        <legend class="legend">
            <h2>{{ __('contracts.label') }}</h2>
        </legend>
        <p class="default-p mb-6">{{ __('contracts.contract_info') }}</p>
        <div class="form-row-2">
            <div class="form-group group">
                <select wire:model="form.idForm"
                        name="idForm"
                        id="idForm"
                        class="peer input-select @error('form.idForm') input-error @enderror"
                        required
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach($dictionary as $key => $type)
                        <option value="{{ $key }}">{{ $type }}</option>
                    @endforeach
                </select>
                <label for="idForm" class="label">
                    {{ __('contracts.id_form_label') }}
                </label>
                @error('form.idForm')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
        <div class="form-row-2">
            <div class="form-group group datepicker-wrapper relative w-full">
                <input wire:model="form.startDate"
                       type="text"
                       name="startDate"
                       id="startDate"
                       class="peer input pl-10 datepicker-input @error('form.startDate') input-error @enderror"
                       placeholder=" "
                       datepicker-autohide
                       datepicker-format="{{ frontendDateFormat() }}"
                       datepicker-button="false"
                       autocomplete="off"
                       required
                />
                <label for="startDate" class="wrapped-label">
                    {{ __('contracts.start_date_label') }}
                </label>
                @error('form.startDate')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
            <div class="form-group group datepicker-wrapper relative w-full">
                <input wire:model="form.endDate"
                       type="text"
                       name="endDate"
                       id="endDate"
                       class="peer input pl-10 datepicker-input @error('form.endDate') input-error @enderror"
                       placeholder=" "
                       datepicker-autohide
                       datepicker-format="{{ frontendDateFormat() }}"
                       datepicker-button="false"
                       autocomplete="off"
                />
                <label for="endDate" class="wrapped-label">
                    {{ __('contracts.end_date_label') }}
                </label>
                @error('form.endDate')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>
@endif
