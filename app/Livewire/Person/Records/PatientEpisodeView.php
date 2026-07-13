<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\EpisodeCurrentDiagnosis;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

class PatientEpisodeView extends BasePatientComponent
{
    public string $episodeUuid = '';

    public ?Episode $episode = null;

    public string $statusLabel = '';

    public string $careManagerName = '';

    public string $managingOrganizationName = '';

    public ?EpisodeCurrentDiagnosis $currentMainDiagnosis = null;

    public array $icd10Descriptions = [];

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null): void
    {
        parent::mount($legalEntity, $person, $preperson);

        $this->episode = new Episode([
            'name'                => 'Тестовий епізод',
            'uuid'                => '00000000-0000-0000-0000-000000000001',
            'ehealth_inserted_at' => '2024-01-15 10:30:00',
            'ehealth_updated_at'  => '2024-06-20 14:45:00',
        ]);

        $this->statusLabel              = 'Активний';
        $this->careManagerName          = 'Іванов Іван Іванович';
        $this->managingOrganizationName = 'Клініка "Здоров\'я"';
    }

    public function back(): void {}

    public function render(): View
    {
        return view('livewire.person.records.episode-view');
    }
}
