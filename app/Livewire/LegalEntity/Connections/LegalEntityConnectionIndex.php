<?php

namespace App\Livewire\LegalEntity\Connections;

use App\Models\Client;
use Livewire\Component;
use App\Models\Connection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Illuminate\Pagination\LengthAwarePaginator;

class LegalEntityConnectionIndex extends Component
{
    public bool $showSignatureModal = false;
    public array $form = [];
    public $legalEntity;

    public function mount($legalEntity = null)
    {
        $this->legalEntity = $legalEntity ?? request()->route('legalEntity');
    }

    public function sign()
    {
        $this->showSignatureModal = false;

        session()->flash('success', 'Зв\'язок успішно встановлений!');
        return redirect()->route('legal-entity-connection.show', [
            'legalEntity' => $this->legalEntity ?? 1,
            'id' => 'conn-13-1312qe11'
        ]);
    }

    #[Computed]
    public function connections(): LengthAwarePaginator
    {
        $ownerUuid = Client::where('legal_entity_id', $this->legalEntity->id)->value('user_uuid');

        $connections = Connection::with(['legalEntity', 'client'])
            ->whereHas('client', function ($query) use ($ownerUuid) {
                $query->where('user_uuid', $ownerUuid);
            })
            ->get();

        // Pagination
        $perPage = config('pagination.per_page');
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $currentItems = $connections->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentItems,
            $connections->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url()]
        );
    }

    #[Title('Зв\'язки МІС та СГуСОЗ')]
    public function render()
    {
        return view('livewire.legal-entity.connection.connection-index', [
            'connections' => $this->connections(),
        ]);
    }
}
