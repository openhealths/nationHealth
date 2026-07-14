<?php

declare(strict_types=1);

namespace App\View\Components\Forms;

use App\Rules\Zip;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\View\View;

class AddressesSearch extends Addresses
{
    /**
     * Create a new component instance.
     */
    public function __construct($address, $districts, $settlements, $streets, $class, $readonly = false)
    {
        parent::__construct($address, $districts, $settlements, $streets, $class, $readonly);
    }

    public static function getAddressRules(array $address): array
    {
        return [
            'address.area' => ['required', 'string'],
            'address.region' => ['nullable', 'string'],
            'address.settlementType' => ['required', 'string'],
            'address.settlement' => ['required', 'string'],
            'address.settlementId' => ['required', 'string'],
            'address.streetType' => ['nullable', 'string'],
            'address.street' => ['required_with:address.building', 'nullable', 'string'],
            'address.building' => ['required_with:address.apartment', 'nullable', 'string'],
            'address.apartment' => ['nullable', 'string'],
            'address.zip' => ['nullable', 'string', new Zip()],
        ];
    }

    public static function getAddressMessages(): array
    {
        return [
            'address.area' => __('forms.addresses.error.area'),
            'address.settlementType' => __('forms.addresses.error.settlementType'),
            'address.settlement' => __('forms.addresses.error.settlement'),
            'address.street.required_with' => __('forms.addresses.error.street_required_with'),
            'address.building.required_with' => __('forms.addresses.error.building_required_with'),
            'address.building' => __('forms.addresses.error.building'),
            'address.apartment' => __('forms.addresses.error.apartment'),
            'address.zip' => __('forms.addresses.error.zip'),
        ];
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        return view('components.forms.addresses-search');
    }
}
