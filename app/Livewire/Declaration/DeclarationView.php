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
    public string $printableContent = '';

    public function mount(LegalEntity $legalEntity, Declaration $declaration): void
    {
        $this->dictionary = dictionary()->basics()->byName('POSITION')->asCodeDescription()->toArray();
        $this->declarationReasons = dictionary()->basics()->byName('DECLARATION_REASONS')->asCodeDescription()->toArray();

        $this->declaration = $declaration->load([
            'employee',
            'employee.party:id,last_name,first_name,second_name',
            'division:id,name'
        ]);

        if (!auth()->user()->usesFacilityDeclarationRegister()) {
            $declaration->load(['person:id,birth_date', 'person.names', 'declarationRequest:id,data_to_be_signed,parent_declaration_uuid']);
            $this->printableContent = $declaration->declarationRequest?->dataToBeSigned['content'] ?? '';
        } else {
            $declaration->load('declarationRequest:id,parent_declaration_uuid');
        }
    }

    public function render(): View
    {
        return view('livewire.declaration.declaration-view')->with('declaration', $this->declaration);
    }
}
