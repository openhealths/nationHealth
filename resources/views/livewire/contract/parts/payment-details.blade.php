@if(isset($contract) && isset($data))
    @php
        $localPaymentDetails = is_array($contract->contractor_payment_details ?? null)
            ? $contract->contractor_payment_details
            : [];
        $remotePaymentDetails = is_array(data_get($data, 'contractor_payment_details'))
            ? data_get($data, 'contractor_payment_details')
            : [];
        $paymentDetails = array_merge($localPaymentDetails, $remotePaymentDetails);
        $mfo = $paymentDetails['MFO'] ?? $paymentDetails['mfo'] ?? '---';
    @endphp
    <fieldset class="fieldset">
        <legend class="legend">{{ __('contracts.payment_details_contractor') }}</legend>
        <div class="form-row-3">
            <div class="form-group group">
                <input id="bank-name"
                       type="text"
                       value="{{ $paymentDetails['bank_name'] ?? '---' }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
                <label for="bank-name" class="label">
                    {{ __('contracts.bank_label') }}
                </label>
            </div>
            <div class="form-group group">
                <input id="bank-mfo"
                       type="text"
                       value="{{ $mfo }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
                <label for="bank-mfo" class="label">
                    {{ __('contracts.mfo_label') }}
                </label>
            </div>
            <div class="form-group group">
                <input id="bank-iban"
                       type="text"
                       value="{{ $paymentDetails['payer_account'] ?? '---' }}"
                       class="input peer font-mono"
                       placeholder=" "
                       disabled
                       readonly
                />
                <label for="bank-iban" class="label">
                    {{ __('contracts.payer_account_label') }}
                </label>
            </div>
        </div>
    </fieldset>
@else
    <fieldset class="fieldset">
        <legend class="legend">
            <h2>{{ __('contracts.payment_details') }}</h2>
        </legend>
        <p class="default-p mb-6">{{ __('contracts.payment_details_info') }}</p>
        <div class="form-row-2">
            <div class="form-group group">
                <input wire:model="form.contractorPaymentDetails.bankName"
                       type="text"
                       name="bankName"
                       id="bankName"
                       class="peer input @error('form.contractorPaymentDetails.bankName') input-error @enderror"
                       placeholder=" "
                       required
                />
                <label for="bankName" class="label">
                    {{ __('contracts.bank_name') }}
                </label>
                @error('form.contractorPaymentDetails.bankName')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
            <div class="form-group group">
                <input wire:model="form.contractorPaymentDetails.MFO"
                       type="text"
                       name="MFO"
                       id="MFO"
                       inputmode="numeric"
                       maxlength="6"
                       pattern="[0-9]{6}"
                       class="peer input @error('form.contractorPaymentDetails.MFO') input-error @enderror"
                       placeholder=" "
                       required
                />
                <label for="MFO" class="label">
                    {{ __('contracts.mfo') }}
                </label>
                @error('form.contractorPaymentDetails.MFO')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
        <div class="form-row-2">
            <div class="form-group group">
                <input wire:model="form.contractorPaymentDetails.payerAccount"
                       type="text"
                       name="payerAccount"
                       id="payerAccount"
                       x-mask="UA99 9999999 999999999999999999"
                       class="peer input @error('form.contractorPaymentDetails.payerAccount') input-error @enderror"
                       placeholder=" "
                       required
                />
                <label for="payerAccount" class="label">
                    {{ __('contracts.payer_account') }}
                </label>
                @error('form.contractorPaymentDetails.payerAccount')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>
@endif
