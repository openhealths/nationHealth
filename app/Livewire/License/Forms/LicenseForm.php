<?php

declare(strict_types=1);

namespace App\Livewire\License\Forms;

use App\Core\Arr;
use App\Enums\License\Type;
use App\Models\LegalEntity;
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

    public string $licenseNumber = '';

    public string $activeFromDate = '';

    public string $expiryDate = '';

    /**
     * Set validation rules for the form.
     */
    protected function rules(): array
    {
        $allowedTypes = array_keys($this->getAllowedLicenseTypes());

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
            'issuedDate' => ['required', 'date_format:d.m.Y', 'before_or_equal:activeFromDate'],
            'expiryDate' => [
                'required_if:type,' . Type::PHARMACY_DRUGS->value,
                'date_format:d.m.Y',
                'after_or_equal:today',
                'after_or_equal:activeFromDate'
            ],
            'activeFromDate' => ['required', 'date_format:d.m.Y', 'before_or_equal:expiryDate'],
            'whatLicensed' => ['required', 'string', 'max:255'],
            'orderNo' => ['required', 'string', 'max:255'],
            'isPrimary' => ['required', Rule::in([false])]
        ];
    }

    /**
     * Convert date to ISO 8601 and format to snake case.
     */
    public function formatForApi(array $data): array
    {
        collect($data)
            ->only(['issuedDate', 'expiryDate', 'activeFromDate'])
            ->filter()
            ->each(static function (string $value, string $key) use (&$data) {
                $data[$key] = convertToYmd($value);
            });

        return removeEmptyKeys(Arr::toSnakeCase($data));
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

    /**
     * Get allowed types based on LEGAL_ENTITY_<LEGAL_ENTITY_TYPE>_ADDITIONAL_LICENSE_TYPES.
     * https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17092870145/Legal+Entities+configurable+parameters#Configurable-parameters
     *
     * @return array
     */
    private function getAllowedLicenseTypes(): array
    {
        $licenseTypes = dictionary()->basics()->byName('LICENSE_TYPE')->asCodeDescription()->toArray();

        if (
            legalEntity()->type->name === LegalEntity::TYPE_OUTPATIENT ||
            legalEntity()->type->name === LegalEntity::TYPE_PHARMACY
        ) {
            return ['PHARMACY_DRUGS' => $licenseTypes['PHARMACY_DRUGS']];
        }

        return $licenseTypes;
    }
}
