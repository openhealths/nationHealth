<?php

declare(strict_types=1);

namespace App\Livewire\Declaration;

use App\Models\Declaration;
use App\Models\LegalEntity;
use Livewire\Component;

class DeclarationView extends Component
{
    /**
     * Declaration data with the needed relation data.
     *
     * @var Declaration
     */
    public Declaration $declaration;

    public array $dictionary;

    /**
     * Declaration content.
     *
     * @var string
     */
    public string $printableContent;

    public function mount(LegalEntity $legalEntity, Declaration $declaration): void
    {
        $this->dictionary = dictionary()->getDictionary('POSITION');

        $this->declaration = $declaration->load([
            'declarationRequest:id,data_to_be_signed',
            'employee',
            'person:id,first_name,last_name,second_name,birth_date',
            'division:id,name'
        ]);

        $this->printableContent = $this->declaration->declarationRequest->dataToBeSigned['content'] ?? '';
    }
}
