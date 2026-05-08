<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class CarePlanIndex extends Component
{
    public $carePlans = [];
    public string $searchRequisition = '';
    
    // Filters
    public string $status = '';
    public string $startDateFrom = '';
    public string $startDateTo = '';
    public string $endDateFrom = '';
    public string $endDateTo = '';
    public string $isPartOfCarePlan = '';
    public string $includesCarePlan = '';

    public function mount(CarePlanRepository $repository): void
    {
        $legalEntity = legalEntity();

        if ($legalEntity) {
            $this->carePlans = $repository->getByLegalEntity($legalEntity->id);
        }

        // Add fake data if empty for testing styling
        if (count($this->carePlans) === 0) {
            $this->carePlans = [
                [
                    'id' => 1,
                    'title' => 'План лікування носової кровотечі',
                    'status' => 'active',
                    'status_display' => 'Активний',
                    'created_at' => '2025-04-02T10:00:00Z',
                    'period' => [
                        'start' => '2025-04-02T10:00:00Z',
                        'end' => '2025-02-02T10:00:00Z',
                    ],
                    'author' => [
                        'party' => [
                            'full_name' => 'Петров І.І.',
                        ],
                    ],
                    'requisition' => '3123213211',
                    'uuid' => '1231-adsadas-aqeqe-casdda',
                    'episode_id' => '1231-adsadas-aqeqe-casdda',
                ],
            ];
        }
    }

    /**
     * Search eHealth by public requisition number (per TZ 3.10.3.2.1).
     */
    public function searchByRequisition(): void
    {
        if (empty($this->searchRequisition)) {
            return;
        }

        try {
            $response = EHealth::carePlan()->getMany(['requisition' => $this->searchRequisition]);
            $data = $response->getData();
            // Merge eHealth results with local list
            $this->carePlans = collect($data)->toArray();
        } catch (\Throwable $e) {
            Log::error('CarePlan search error: ' . $e->getMessage());
            session()->flash('error', 'Помилка пошуку планів лікування в ЕСОЗ: ' . $e->getMessage());
        }
    }

    public function resetFilters(): void
    {
        $this->reset([
            'searchRequisition',
            'status',
            'startDateFrom',
            'startDateTo',
            'endDateFrom',
            'endDateTo',
            'isPartOfCarePlan',
            'includesCarePlan',
        ]);
    }

    public function syncWithEHealth(): void
    {
        // Placeholder for sync logic 
        // Example: app(CarePlanRepository::class)->syncCarePlans($person);
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-index');
    }
}
