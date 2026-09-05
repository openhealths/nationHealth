<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use Illuminate\View\View;

class PatientDeviceAssociations extends BasePatientComponent
{
    public string $filterDeviceId = '';
    public string $filterEncounterId = '';
    public string $filterStatus = '';
    public string $filterEpisodeId = '';
    public string $filterOrganization = '';
    public string $filterAnatomicalSite = '';
    public string $filterPractitioner = '';
    public string $filterDate = '';
    public string $filterCreatedAt = '';

    public array $devices = [];

    protected function initializeComponent(): void
    {
        $this->devices = [
            [
                'id' => '1',
                'name' => 'Кардіостимулятор',
                'status' => 'Дійсний',
                'anatomical_site' => 'Голова',
                'device_id' => '1231-adsadas-aqeqe-casdda',
                'date' => '01.02.2025',
                'organization' => 'Лікарня №1',
                'practitioner' => 'Сидоренко І.В.',
                'created_at' => '01.02.2025',
                'association_id' => '1231-adsadas-aqeqe-casdda',
                'encounter_id' => '1231-adsadas-aqeqe-casdda',
                'episode_id' => '1231-adsadas-aqeqe-casdda',
            ]
        ];
    }

    public function search(): void
    {
        // search logic
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterDeviceId',
            'filterEncounterId',
            'filterStatus',
            'filterEpisodeId',
            'filterOrganization',
            'filterAnatomicalSite',
            'filterPractitioner',
            'filterDate',
            'filterCreatedAt',
        ]);
    }

    public function render(): View
    {
        return view('livewire.person.records.device-associations');
    }
}
