<fieldset class="fieldset">
    <legend class="legend">{{ __('contracts.customer_nhs') }}</legend>
    <div class="form-row-2">
        <div class="form-group group">
            <input id="nhs-entity"
                   type="text"
                   value="{{ data_get($data, 'nhs_legal_entity.name', 'НСЗУ') }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
            <label for="nhs-entity" class="label">
                {{ __('contracts.legal_entity_label') }}
            </label>
        </div>
        <div class="form-group group">
            <input id="nhs-signer"
                   type="text"
                   value="{{ trim(data_get($data, 'nhs_signer.party.last_name', '') . ' ' . data_get($data, 'nhs_signer.party.first_name', '')) ?: '---' }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
            <label for="nhs-signer" class="label">
                {{ __('contracts.signer_nhs') }}
            </label>
        </div>
    </div>
    <div class="form-row-2">
        <div class="form-group group">
            <input id="nhs-base"
                   type="text"
                   value="{{ data_get($data, 'nhs_signer_base', '---') }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
            <label for="nhs-base" class="label">
                {{ __('contracts.base_label') }}
            </label>
        </div>
        @php
            $paymentMethod = data_get($data, 'nhs_payment_method');
            $paymentMethodTranslationKey = 'contracts.payment_methods.' . strtolower((string)$paymentMethod);
            $translatedMethod = __($paymentMethodTranslationKey);
            $translatedMethod = $translatedMethod === $paymentMethodTranslationKey ? $paymentMethod : $translatedMethod;
        @endphp
        <div class="form-group group">
            <input id="nhs-payment-method"
                   type="text"
                   value="{{ $translatedMethod ?: '---' }}"
                   class="input peer font-mono"
                   placeholder=" "
                   disabled
                   readonly
            />
            <label for="nhs-payment-method" class="label">
                {{ __('contracts.payment_method_label') }}
            </label>
        </div>
    </div>
    <div class="form-row-2">
        <div class="form-group group">
            <input id="nhs-price"
                   type="text"
                   value="{{ number_format((float)data_get($data, 'nhs_contract_price', 0), 2, '.', ' ') }} UAH"
                   class="input peer font-semibold text-green-600 dark:text-green-400"
                   placeholder=" "
                   disabled
                   readonly
            />
            <label for="nhs-price" class="label">
                {{ __('contracts.contract_amount_label') }}
            </label>
        </div>
    </div>
</fieldset>
