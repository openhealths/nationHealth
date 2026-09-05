<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use Illuminate\View\View;

class DeviceDispenses extends BasePatientComponent
{
    public string $filterDeviceId = '';
    public string $filterEncounterId = '';
    public string $filterStatus = '';
    public string $filterEpisodeId = '';
    public string $filterOrganization = '';
    public string $filterPractitioner = '';
    public string $filterProcedureId = '';
    public string $filterCarePlanId = '';
    public string $filterRelatedEpisodeId = '';
    public string $filterDispenseDateFrom = '';
    public string $filterDispenseDateTo = '';
    public string $filterCreatedAtFrom = '';
    public string $filterCreatedAtTo = '';

    public array $dispenses = [];

    protected function initializeComponent(): void
    {
        $this->dispenses = [
            [
                'id' => '1',
                'name' => 'Кардіостимулятор',
                'status' => 'Дійсний',
                'dispense_date' => '01.02.2025 11:00',
                'procedure_id' => '1231-adsadas-aqeqe-casdda',
                'care_plan_id' => '1231-adsadas-aqeqe-casdda',
                'related_episode_id' => '1231-adsadas-aqeqe-casdda',
                'organization' => 'Лікарня №1',
                'practitioner' => 'Сидоренко І.В.',
                'created_at' => '01.02.2025',
                'dispense_id' => '1231-adsadas-aqeqe-casdda',
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
            'filterPractitioner',
            'filterProcedureId',
            'filterCarePlanId',
            'filterRelatedEpisodeId',
            'filterDispenseDateFrom',
            'filterDispenseDateTo',
            'filterCreatedAtFrom',
            'filterCreatedAtTo',
        ]);
    }

    public function render(): View
    {
        return view('livewire.person.records.device-dispenses');
    }
}
