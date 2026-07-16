<?php

declare(strict_types=1);

namespace App\Livewire\Declaration;

use App\Models\Declaration;
use App\Models\LegalEntity;
use Illuminate\View\View;
use Livewire\Component;

class DeclarationView extends Component
{
    /**
     * Declaration data with the needed relation data.
     *
     * @var Declaration
     */
    protected Declaration $declaration;

    public array $dictionary;

    /**
     * DECLARATION_REASONS dictionary (status change reasons) keyed by code.
     *
     * @var array
     */
    public array $declarationReasons;

    /**
     * Declaration content.
     *
     * @var string
     */
    public string $printableContent;

    public function mount(LegalEntity $legalEntity, Declaration $declaration): void
    {
        $this->dictionary = dictionary()->basics()->byName('POSITION')->asCodeDescription()->toArray();
        $this->declarationReasons = dictionary()->basics()->byName('DECLARATION_REASONS')->asCodeDescription()->toArray();

        $this->declaration = $declaration->load([
            'declarationRequest:id,data_to_be_signed,parent_declaration_uuid',
            'employee',
            'person:id,birth_date',
            'person.names',
            'employee.party:id,last_name,first_name,second_name',
            'division:id,name'
        ]);

        $this->printableContent = $this->declaration->declarationRequest->dataToBeSigned['content'] ?? '';
    }

    public function render(): View
    {
        return view('livewire.declaration.declaration-view')->with('declaration', $this->declaration);
    }
}
