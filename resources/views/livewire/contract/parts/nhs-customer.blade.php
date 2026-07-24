@use('App\Enums\Contract\PaymentMethod')
@php
    $paymentMethod = data_get($data, 'nhs_payment_method')
        ?? (isset($contract) ? ($contract->nhs_payment_method ?? null) : null);
    $contractPrice = data_get($data, 'nhs_contract_price');
    if ($contractPrice === null && isset($contract)) {
        $contractPrice = $contract->nhs_contract_price ?? null;
    }
    $signerBase = data_get($data, 'nhs_signer_base');
    if ($signerBase === null && isset($contract)) {
        $signerBase = $contract->nhs_signer_base ?? null;
    }
    $nhsName = data_get($data, 'nhs_legal_entity.name') ?: 'НСЗУ';
    $nhsSigner = trim(
        (string) data_get($data, 'nhs_signer.party.last_name', '') . ' '
        . (string) data_get($data, 'nhs_signer.party.first_name', '') . ' '
        . (string) data_get($data, 'nhs_signer.party.second_name', '')
    );
@endphp
<fieldset class="fieldset">
    <legend class="legend">{{ __('contracts.customer_nhs') }}</legend>
    <div class="form-row-2">
        <div class="form-group group">
            <label for="nhs-entity" class="label">{{ __('contracts.legal_entity_label') }}</label>
            <input id="nhs-entity"
                   type="text"
                   value="{{ $nhsName }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
        <div class="form-group group">
            <label for="nhs-signer" class="label">{{ __('contracts.signer_nhs') }}</label>
            <input id="nhs-signer"
                   type="text"
                   value="{{ $nhsSigner !== '' ? $nhsSigner : '-' }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
    </div>
    <div class="form-row-2">
        <div class="form-group group">
            <label for="nhs-base" class="label">{{ __('contracts.base_label') }}</label>
            <input id="nhs-base"
                   type="text"
                   value="{{ $signerBase ?: '-' }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
        <div class="form-group group">
            <label for="nhs-payment-method" class="label">{{ __('contracts.payment_method_label') }}</label>
            <input id="nhs-payment-method"
                   type="text"
                   value="{{ PaymentMethod::resolveLabel($paymentMethod) }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
    </div>
    <div class="form-row-2">
        <div class="form-group group">
            <label for="nhs-price" class="label">{{ __('contracts.contract_amount_label') }}</label>
            <input id="nhs-price"
                   type="text"
                   value="{{ $contractPrice !== null && $contractPrice !== '' ? number_format((float) $contractPrice, 2, '.', ' ') . ' UAH' : '-' }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
    </div>
</fieldset>
