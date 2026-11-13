<?php

declare(strict_types=1);

namespace App\Livewire\LegalEntity\Connections\Form;

use App\Core\BaseForm;
use App\Models\LegalEntityType;
use Illuminate\Validation\Rule;

class ConnectionForm extends BaseForm
{
    public string $clientUuid = '';

    public ?string $taxId = null;

    /**
     * Validation rules required to sign data with a KEP key.
     *
     * @return array
     */
    public function rulesForSign(bool $skipTaxIdValidation = false): array
    {
        $rules = $this->signingRules();

        if (!$skipTaxIdValidation) {
            $rules['taxId'] = ['required', 'string', 'max:10'];
        }

        return $rules;
    }

    public function rulesForClientId(): array
    {
        return [
            'clientUuid' => ['required', 'string'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'clientUuid' => __('legal-entity-connection.client_id_label_lower'),
        ];
    }

    public function rulesForCreate(array $allowedLegalEntityTypes): array
    {
        return [
            'clientUuid' => ['required', 'string'],
            'redirectUri' => ['required', 'string'],
            'clientName' => ['required', 'string'],
            'clientType' => [
                'required',
                'integer',
                Rule::exists(LegalEntityType::class, 'id')->whereIn('name', $allowedLegalEntityTypes)
            ]
        ];
    }

    public function rulesForCredentials(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'secret' => ['required', 'string']
        ];
    }
}
