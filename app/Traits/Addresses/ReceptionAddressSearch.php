<?php

declare(strict_types=1);

namespace App\Traits\Addresses;

use App\Models\Relations\Address;
use App\View\Components\Forms\AddressesReception;
use Illuminate\Validation\ValidationException;

trait ReceptionAddressSearch
{
    use BaseAddress;

    public ?array $receptionAddress = [
        'country' => Address::DEFAULT_COUNTRY,
        'type' => Address::RECEPTION_TYPE
    ];

    public ?array $receptionDistricts = [];

    public ?array $receptionSettlements = [];

    public ?array $receptionStreets = [];

    public function receptionAddressValidation(): array
    {
        $errors = [];

        try {
            $this->validate(AddressesReception::getAddressRules($this->receptionAddress), AddressesReception::getAddressMessages());
        } catch (ValidationException $err) {
            $errors = $err->validator->errors()->toArray();
        }

        return $errors;
    }
}
