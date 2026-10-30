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

        // On update the license type is immutable, so it must equal the original type.
        // On create the legal entity must not already have a license with the same type.
        $typeRules = $this->component->uuid === ''
            ? [
                'required',
                Rule::in($allowedTypes),
                Rule::unique('licenses', 'type')->where('legal_entity_id', legalEntity()->id)
            ]
            : ['required', Rule::in([$this->component->originalType])];

        return [
            'type' => $typeRules,
            'licenseNumber' => ['nullable', 'string', 'max:255'],
            'issuedBy' => ['required', 'string', 'max:255'],
            'issuedDate' => ['required', 'date_format:' . config('app.date_format'), 'before_or_equal:activeFromDate'],
            'expiryDate' => [
                'required_if:type,' . Type::PHARMACY_DRUGS->value,
                'date_format:' . config('app.date_format'),
                'after_or_equal:today',
                'after_or_equal:activeFromDate'
            ],
            'activeFromDate' => ['required', 'date_format:' . config('app.date_format')],
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
