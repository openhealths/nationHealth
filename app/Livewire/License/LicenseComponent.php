<?php

declare(strict_types=1);

namespace App\Livewire\License;

use App\Traits\FormTrait;
use Livewire\Attributes\Locked;
use Livewire\Component;
use App\Livewire\License\Forms\LicenseForm as Form;

abstract class LicenseComponent extends Component
{
    use FormTrait;

    #[Locked]
    public string $uuid = '';

    public Form $form;

    public array $licenseTypes = [];

    public function boot(): void
    {
        $this->licenseTypes = dictionary()->basics()->byName('LICENSE_TYPE')->asCodeDescription()->toArray();

        if (legalEntity()->type->name === 'OUTPATIENT' || legalEntity()->type->name === 'PHARMACY') {
            $this->licenseTypes = ['PHARMACY_DRUGS' => $this->licenseTypes['PHARMACY_DRUGS']];
        }
    }
}
