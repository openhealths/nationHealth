<?php

declare(strict_types=1);

namespace App\Livewire\License\Forms;

use App\Enums\License\Type;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Form;

class LicenseForm extends Form
{
    #[Locked]
    public bool $isPrimary = false;

    public string $type = '';

    public string $orderNo = '';

    public string $issuedBy = '';

    public string $issuedDate = '';

    public string $whatLicensed = '';

    public ?string $licenseNumber = '';

    public string $activeFromDate = '';

    public string $expiryDate = '';

    /**
     * Set validation rules for the form.
     */
    protected function rules(): array
    {
        $allowedTypes = array_keys($this->component->licenseTypes);

        return [
            'type' => [
                'required',
                Rule::in($allowedTypes),
                // Check that legal entity does not have license with type same as in request.
                Rule::unique('licenses', 'type')
                    ->where('legal_entity_id', legalEntity()->id)
                    ->ignore($this->component->uuid, 'uuid')
            ],
            'licenseNumber' => ['nullable', 'string', 'max:255'],
            'issuedBy' => ['required', 'string', 'max:255'],
            'issuedDate' => ['required', 'date_format:' . config('app.date_format'), 'before_or_equal:activeFromDate'],
            'expiryDate' => [
                'required_if:type,' . Type::PHARMACY_DRUGS->value,
                'date_format:' . config('app.date_format'),
                'after_or_equal:today',
                'after_or_equal:activeFromDate'
            ],
            'activeFromDate' => ['required', 'date_format:' . config('app.date_format'), 'before_or_equal:expiryDate'],
            'whatLicensed' => ['required', 'string', 'max:255'],
            'orderNo' => ['required', 'string', 'max:255'],
            'isPrimary' => ['required', Rule::in([false])]
        ];
    }

    /**
     * Redefine field names for error messages.
     *
     * @return array
     */
    protected function validationAttributes(): array
    {
        return ['type' => __('licenses.type.label')];
    }
}
