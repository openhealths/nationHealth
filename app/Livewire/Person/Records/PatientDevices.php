<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use Illuminate\View\View;

class PatientDevices extends BasePatientComponent
{
    public string $filterName = '';
    public string $filterEncounterId = '';
    public string $filterStatus = '';
    public string $filterEpisodeId = '';
    public string $filterOrganization = '';
    public string $filterType = '';
    public string $filterModel = '';
    public string $filterManufacturer = '';
    public string $filterPractitioner = '';
    public string $filterCreatedAt = '';

    public array $devices = [];

    protected function initializeComponent(): void
    {
        $this->devices = [
            [
                'id' => '1',
                'name' => 'Кардіостимулятор Medtronic Azure XT',
                'status' => 'Дійсний',
                'encounter_id' => '1231-adsadas-aqeqe-casdda',
                'episode_id' => '1231-adsadas-aqeqe-casdda',
                'device_id' => '1231-adsadas-aqeqe-casdda',
                'organization' => 'Лікарня №1',
                'type' => 'Гістероскоп',
                'model' => 'Nimbus2000',
                'manufacturer' => 'GlobalMeD',
                'practitioner' => 'Сидоренко І.В.',
                'serial_number' => 'IncNSPX30',
                'created_at' => '01.02.2025'
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
            'filterName',
            'filterEncounterId',
            'filterStatus',
            'filterEpisodeId',
            'filterOrganization',
            'filterType',
            'filterModel',
            'filterManufacturer',
            'filterPractitioner',
            'filterCreatedAt',
        ]);
    }

    public function render(): View
    {
        return view('livewire.person.records.devices');
    }
}
