<fieldset class="fieldset">
    <legend class="legend">{{ __('contracts.contractor_provider') }}</legend>
    <div class="form-row-2">
        <div class="form-group group">
            <label for="contractor-name" class="label">{{ __('contracts.name_label') }}</label>
            <input id="contractor-name"
                   type="text"
                   value="{{ data_get($data, 'contractor_legal_entity.name') ?: '-' }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
        <div class="form-group group">
            <label for="contractor-edrpou" class="label">{{ __('contracts.edrpou_label') }}</label>
            <input id="contractor-edrpou"
                   type="text"
                   value="{{ data_get($data, 'contractor_legal_entity.edrpou') ?: '-' }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
    </div>
    <div class="form-row-2">
        <div class="form-group group">
            @php
                $addresses = data_get($data, 'contractor_legal_entity.addresses', []);
                $address = collect($addresses)->first();
                $addressStr = $address
                    ? trim(implode(', ', array_filter([
                        $address['area'] ?? '',
                        $address['settlement'] ?? '',
                        trim(($address['street'] ?? '') . ' ' . ($address['building'] ?? '')),
                    ])))
                    : null;
            @endphp
            <label for="contractor-address" class="label">{{ __('contracts.address_label') }}</label>
            <input id="contractor-address"
                   type="text"
                   value="{{ $addressStr ?: '-' }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
        <div class="form-group group">
            <label for="contractor-signer" class="label">{{ __('contracts.signer_owner') }}</label>
            <input id="contractor-signer"
                   type="text"
                   value="{{ trim(data_get($data, 'contractor_owner.party.last_name', '') . ' ' . data_get($data, 'contractor_owner.party.first_name', '') . ' ' . data_get($data, 'contractor_owner.party.second_name', '')) ?: '-' }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
    </div>
    <div class="form-row-2">
        <div class="form-group group">
            <label for="contractor-base" class="label">{{ __('contracts.base_of_activity') }}</label>
            <input id="contractor-base"
                   type="text"
                   value="{{ data_get($data, 'contractor_base') ?: '-' }}"
                   class="input peer"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
        <div class="form-group group">
            <label for="contractor-owner-id" class="label">{{ __('contracts.contractor_owner') }} ID</label>
            <input id="contractor-owner-id"
                   type="text"
                   value="{{ data_get($data, 'contractor_owner.id') ?: data_get($data, 'contractor_owner.uuid') ?: '-' }}"
                   class="input peer font-mono text-sm"
                   placeholder=" "
                   disabled
                   readonly
            />
        </div>
    </div>
</fieldset>
