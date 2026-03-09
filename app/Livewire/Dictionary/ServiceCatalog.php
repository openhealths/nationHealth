<?php

namespace App\Livewire\Dictionary;

use Illuminate\Support\Collection;
use Livewire\Component;

class ServiceCatalog extends Component
{
    public string $search = '';
    public string $serviceCategory = '';
    public string $serviceActive = '';
    public string $serviceGroupActive = '';
    public string $allowedForEn = '';

    public Collection $services;

    public function mount(): void
    {
        $this->services = $this->fakeServices();
    }

    public function search(): void
    {

    }

    public function resetFilters(): void
    {
        $this->reset([
            'search',
            'serviceCategory',
            'serviceActive',
            'serviceGroupActive',
            'allowedForEn',
        ]);
    }

    public function selectSearchSuggestion(int $index): void
    {
        $suggestions = $this->searchSuggestions;

        if (isset($suggestions[$index])) {
            $this->search = $suggestions[$index];
        }
    }

    public function getServiceCategoriesProperty(): array
    {
        return [];
    }

    public function getSearchSuggestionsProperty(): array
    {
        return [];
    }

    public function render()
    {
        return view('livewire.dictionary.service-catalog', [
            'serviceCategories' => $this->serviceCategories,
            'searchSuggestions' => $this->searchSuggestions,
            'services' => $this->services,
        ]);
    }

    private function fakeServices(): Collection
    {
        return collect([
            [
                'id' => '132313-1313',
                'name' => 'Направлення до спеціаліста',
                'allowed_for_en' => true,
                'code' => null,
                'status' => 'active',
                'children' => [
                    [
                        'id' => '132313-1313',
                        'name' => 'Електрокардіографія',
                        'code' => '13121-123',
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'id' => '132314-1314',
                'name' => 'Лікувально-діагностичні процедури',
                'allowed_for_en' => true,
                'code' => null,
                'status' => 'inactive',
                'children' => [
                    [
                        'name' => 'Електрокардіографія',
                        'code' => '31221-123',
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'id' => '132315-1315',
                'name' => 'Діагностичні процедури',
                'allowed_for_en' => true,
                'code' => null,
                'status' => 'active',
                'children' => [
                    [
                        'id' => '132313-1313',
                        'name' => 'Електрокардіографія',
                        'code' => '13121-123',
                        'status' => 'active',
                    ],
                    [
                        'name' => 'Ультразвукове дослідження',
                        'code' => '31221-123',
                        'status' => 'inactive',
                    ],
                    [
                        'name' => 'Киснева терапія',
                        'code' => '5435-123',
                        'status' => 'active',
                    ],
                ],
            ],
        ]);
    }
}
