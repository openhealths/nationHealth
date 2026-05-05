<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Repositories\CarePlanRepository;
use Illuminate\Contracts\View\View;

class PersonCarePlans extends BasePatientComponent
{
    public $carePlans = [];

    public string $filterName = '';
    public string $filterEncounterId = '';
    public string $filterStatus = '';
    public string $filterStartDateRange = '';
    public string $filterEndDateRange = '';
    public string $filterIsPartOf = '';
    public string $filterIncludes = '';
    public bool $showAdditionalParams = false;

    /**
     * Initialize component with care plans for the specific patient.
     */
    protected function initializeComponent(): void
    {
        $this->loadCarePlans();

        try {
            $basics = app(\App\Services\Dictionary\DictionaryManager::class)->basics();
            $this->dictionaries['care_plan_categories'] = $basics->byName('eHealth/care_plan_categories')
                ?->asCodeDescription()
                ?->toArray() ?? [];
        } catch (\Exception $exception) {
            \Illuminate\Support\Facades\Log::warning('PersonCarePlans: failed to load dictionaries: ' . $exception->getMessage());
        }
    }

    /**
     * Load care plans from the database.
     */
    public function loadCarePlans(): void
    {
        $this->carePlans = app(CarePlanRepository::class)->getByPersonId($this->personId);
    }

    /**
     * Synchronize care plans with eHealth.
     */
    public function sync(): void
    {
        try {
            $person = \App\Models\Person\Person::findOrFail($this->personId);
            app(CarePlanRepository::class)->syncCarePlans($person);
            $this->loadCarePlans();
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => __('patients.sync_success') ?? 'Синхронізація успішна']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('PersonCarePlans sync failed: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => __('patients.sync_error') ?? 'Помилка синхронізації']);
        }
    }

    public function search(): void
    {
        $this->loadCarePlans();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterName',
            'filterEncounterId',
            'filterStatus',
            'filterStartDateRange',
            'filterEndDateRange',
            'filterIsPartOf',
            'filterIncludes',
        ]);
    }

    public function render(): View
    {
        return view('livewire.person.records.care-plans');
    }
}
