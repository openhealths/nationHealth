<?php

namespace App\Livewire\LegalEntity\Connections;

use Livewire\Component;
use App\Models\Connection;
use App\Models\LegalEntity;
use Livewire\Attributes\Title;

class LegalEntityConnectionShow extends Component
{
    /**
     * Connection data with the needed relation data.
     *
     * @var Connection
     */
    protected Connection $connection;

    /**
     * LegalEntity instance.
     *
     * @var LegalEntity
     */
    protected $legalEntity;

    public function mount(LegalEntity $legalEntity, Connection $connection)
    {
        $this->legalEntity = $legalEntity;

        $this->connection = $connection->load([
            'legalEntity',
            'client',
        ]);
    }

    #[Title('Деталі зв\'язку')]
    public function render()
    {
        return view('livewire.legal-entity.connection.connection-show', [
            'connection' => $this->connection,
            'legalEntity' => $this->legalEntity,
        ]);
    }
}
