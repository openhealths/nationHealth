<?php

declare(strict_types=1);

namespace App\Livewire\Dictionary;

use App\Enums\MedicalProgram\Type;
use App\Models\LegalEntity;
use Illuminate\View\View;
use Livewire\Component;

class ServiceProgram extends Component
{
    /**
     * Active medical programs filtered by type
     *
     * @var array
     */
    public array $activePrograms = [];

    public function mount(LegalEntity $legalEntity): void
    {
        $this->activePrograms = dictionary()->medicalPrograms()
            ->where('is_active', '=', true)
            ->where('type', '=', Type::SERVICE)
            ->values()
            ->toArray();
    }

    public function render(): View
    {
        return view('livewire.dictionary.service-program');
    }
}
