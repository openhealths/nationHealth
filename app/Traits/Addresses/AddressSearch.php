<?php

declare(strict_types=1);

namespace App\Traits\Addresses;

use App\Models\Relations\Address;
use App\View\Components\Forms\AddressesSearch;
use Illuminate\Validation\ValidationException;

trait AddressSearch
{
    use BaseAddress;

    public ?array $address = [
        'country' => Address::DEFAULT_COUNTRY,
        'type' => Address::DEFAULT_TYPE
    ];

    public ?array $districts = [];

    public ?array $settlements = [];

    public ?array $streets = [];

    public function addressValidation(): array
    {
        $errors = [];

        try {
            $this->validate(AddressesSearch::getAddressRules($this->address), AddressesSearch::getAddressMessages());
        } catch (ValidationException $err) {
            $errors = $err->validator->errors()->toArray();
        }

        return $errors;
    }
}
