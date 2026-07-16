<?php

declare(strict_types=1);

namespace App\View\Components\Forms;

use App\Rules\Zip;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\View\View;

class AddressesReception extends Addresses
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
            'receptionAddress.area' => ['required', 'string'],
            'receptionAddress.region' => ['nullable', 'string'],
            'receptionAddress.settlementType' => ['required', 'string'],
            'receptionAddress.settlement' => ['required', 'string'],
            'receptionAddress.settlementId' => ['required', 'string'],
            'receptionAddress.streetType' => ['nullable', 'string'],
            'receptionAddress.street' => ['required_with:receptionAddress.building', 'nullable', 'string'],
            'receptionAddress.building' => ['required_with:receptionAddress.apartment', 'nullable', 'string'],
            'receptionAddress.apartment' => ['nullable', 'string'],
            'receptionAddress.zip' => ['nullable', 'string', new Zip()],
        ];
    }

    public static function getAddressMessages(): array
    {
        return [
            'receptionAddress.area' => __('forms.addresses.error.area'),
            'receptionAddress.settlementType' => __('forms.addresses.error.settlementType'),
            'receptionAddress.settlement' => __('forms.addresses.error.settlement'),
            'receptionAddress.street.required_with' => __('forms.addresses.error.street_required_with'),
            'receptionAddress.building.required_with' => __('forms.addresses.error.building_required_with'),
            'receptionAddress.building' => __('forms.addresses.error.building'),
            'receptionAddress.apartment' => __('forms.addresses.error.apartment'),
            'receptionAddress.zip' => __('forms.addresses.error.zip'),
        ];
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        return view('components.forms.addresses-reception');
    }
}
