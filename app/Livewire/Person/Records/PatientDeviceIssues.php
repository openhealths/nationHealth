<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use Illuminate\View\View;

class PatientDeviceIssues extends BasePatientComponent
{
    public string $filterIssueId = '';
    public string $filterEncounterId = '';
    public string $filterStatus = '';
    public string $filterEpisodeId = '';
    public string $filterOrganization = '';
    public string $filterPractitioner = '';
    public string $filterDetectedAtFrom = '';
    public string $filterDetectedAtTo = '';
    public string $filterCreatedAt = '';

    public array $issues = [];

    protected function initializeComponent(): void
    {
        $this->issues = [
            [
                'id' => '1',
                'name' => 'Кардіостимулятор',
                'status' => 'Дійсний',
                'issue_id' => '1231-adsadas-aqeqe-casdda',
                'encounter_id' => '1231-adsadas-aqeqe-casdda',
                'episode_id' => '1231-adsadas-aqeqe-casdda',
                'device_id' => '1231-adsadas-aqeqe-casdda',
                'organization' => 'Лікарня №1',
                'practitioner' => 'Сидоренко І.В.',
                'detected_at' => '01.02.2025 11:00',
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
            'filterIssueId',
            'filterEncounterId',
            'filterStatus',
            'filterEpisodeId',
            'filterOrganization',
            'filterPractitioner',
            'filterDetectedAtFrom',
            'filterDetectedAtTo',
            'filterCreatedAt',
        ]);
    }

    public function render(): View
    {
        return view('livewire.person.records.device-issues');
    }
}
