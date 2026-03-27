<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Repositories\CarePlanRepository;
use Illuminate\Contracts\View\View;

class PersonCarePlans extends BasePatientComponent
{
    public $carePlans = [];

    /**
     * Initialize component with care plans for the specific patient.
     */
    protected function initializeComponent(): void
    {
        /** @var CarePlanRepository $repository */
        $repository = app(CarePlanRepository::class);
        $this->carePlans = $repository->getByPersonId((int) $this->patientId);
    }

    public function render(): View
    {
        return view('livewire.person.records.care-plans');
    }
}
