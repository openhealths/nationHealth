<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Models\MedicalEvents\Sql\Episode;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

class PatientEpisodeEdit extends BasePatientComponent
{
    public string $episodeUuid = '';

    public string $name = '';

    public string $careManagerUuid = '';

    public string $typeCode = 'treatment';

    public string $statusCode = 'active';

    public string $startDate = '';

    public string $startTime = '';

    public array $employees = [];

    public array $episodeTypes = [];

    public array $episodeStatuses = [];

    public function save(): void {}

    public function cancel(): void {}

    public function render(): View
    {
        return view('livewire.person.records.episode-edit');
    }
}
