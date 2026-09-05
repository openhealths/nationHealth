<?php

namespace App\Livewire\LegalEntity\Connections;

use Livewire\Component;
use Livewire\Attributes\Title;

class LegalEntityConnectionShow extends Component
{
    public string $connectionId;
    public array $connection;
    public $legalEntity;

    public function mount($legalEntity, $id)
    {
        $this->legalEntity = $legalEntity;
        $this->connectionId = $id;
        
        // Mock data based on the screenshot
        $this->connection = [
            'name' => 'КНП "Лікарня №5"',
            'callback' => 'https://mis.example.com/',
            'client_id' => '1331qwee13-1312qe11',
            'status' => 'Активний',
            'consumer_id' => 'MIS-12334145',
            'created_at' => '02.04.2025',
            'conn_id' => $id,
            'updated_at' => '02.04.2025',
        ];
    }

    #[Title('Деталі зв\'язку')]
    public function render()
    {
        return view('livewire.legal-entity.connection.connection-show');
    }
}
