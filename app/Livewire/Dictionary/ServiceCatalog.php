<?php

declare(strict_types=1);

namespace App\Livewire\Dictionary;

use App\Models\LegalEntity;
use App\Traits\FormTrait;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class ServiceCatalog extends Component
{
    use WithPagination;
    use FormTrait;

    public string $searchBy = '';
    public string $serviceCategory = '';
    public string $serviceActive = '';
    public string $serviceGroupActive = '';
    public string $allowedForEn = '';

    public array $dictionaryNames = ['SERVICE_CATEGORY'];

    public function mount(LegalEntity $legalEntity): void
    {
        $this->getDictionary();
    }

    #[Computed]
    public function services(): LengthAwarePaginator
    {
        // Get full collection from dictionary service
        $allServices = dictionary()->services();

        // Apply filters
        $filteredServices = $allServices->filter(function ($item) {
            // Search filter
            if (!empty($this->searchBy)) {
                $searchTerm = trim($this->searchBy);
                if (!$this->searchInItem($item, $searchTerm)) {
                    return false;
                }
            }

            // Category filter
            if (!empty($this->serviceCategory)) {
                if (!$this->itemHasCategory($item, $this->serviceCategory)) {
                    return false;
                }
            }

            // Group active filter (is_active parameter for groups)
            if ($this->serviceGroupActive !== '') {
                $isActiveRequired = (bool) $this->serviceGroupActive;
                if (!$this->itemMatchesActiveStatus($item, $isActiveRequired)) {
                    return false;
                }
            }

            // Request allowed filter for groups (request_allowed parameter)
            if ($this->allowedForEn !== '') {
                $requestAllowedRequired = (bool) $this->allowedForEn;
                if (!$this->itemMatchesRequestAllowed($item, $requestAllowedRequired)) {
                    return false;
                }
            }

            // Service active filter (is_active parameter for services)
            if ($this->serviceActive !== '') {
                $serviceActiveRequired = (bool) $this->serviceActive;
                if (!$this->itemHasActiveServices($item, $serviceActiveRequired)) {
                    return false;
                }
            }

            return true;
        });

        // Apply pagination
        $perPage = config('pagination.per_page');
        $currentPage = Paginator::resolveCurrentPage();
        $currentPageItems = $filteredServices->forPage($currentPage, $perPage);

        return new LengthAwarePaginator(
            $currentPageItems->values(),
            $filteredServices->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url()]
        );
    }

    public function search(): void
    {
        // Reset to first page when searching
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'searchBy',
            'serviceCategory',
            'serviceActive',
            'serviceGroupActive',
            'allowedForEn',
        ]);
        $this->resetPage();
    }

    public function selectSearchSuggestion(int $index): void
    {
        $suggestions = $this->searchSuggestions;

        if (isset($suggestions[$index])) {
            $this->searchBy = $suggestions[$index];
        }
    }

    public function getSearchSuggestionsProperty(): array
    {
        return [];
    }

    /**
     * Search in the entire item structure (recursive search)
     */
    private function searchInItem(array $item, string $searchTerm): bool
    {
        // Search in main item
        if ($this->itemMatches($item, $searchTerm)) {
            return true;
        }

        // Search in direct services
        if (!empty($item['services'])) {
            foreach ($item['services'] as $service) {
                if ($this->itemMatches($service, $searchTerm)) {
                    return true;
                }
            }
        }

        // Search in groups
        if (!empty($item['groups'])) {
            foreach ($item['groups'] as $group) {
                if ($this->itemMatches($group, $searchTerm)) {
                    return true;
                }

                // Search in subgroups
                if (!empty($group['groups'])) {
                    foreach ($group['groups'] as $subgroup) {
                        if ($this->itemMatches($subgroup, $searchTerm)) {
                            return true;
                        }

                        // Search in subgroup services
                        if (!empty($subgroup['services'])) {
                            foreach ($subgroup['services'] as $service) {
                                if ($this->itemMatches($service, $searchTerm)) {
                                    return true;
                                }
                            }
                        }
                    }
                }

                // Search in group services (if no subgroups)
                if (!empty($group['services'])) {
                    foreach ($group['services'] as $service) {
                        if ($this->itemMatches($service, $searchTerm)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if item matches search term by code and name
     */
    private function itemMatches(array $item, string $searchTerm): bool
    {
        $searchTerm = mb_strtolower($searchTerm);

        // Search by code
        if (!empty($item['code']) && mb_stripos($item['code'], $searchTerm) !== false) {
            return true;
        }

        // Search by name
        if (!empty($item['name']) && mb_stripos($item['name'], $searchTerm) !== false) {
            return true;
        }

        return false;
    }

    /**
     * Check if item has services with the specified category
     */
    private function itemHasCategory(array $item, string $category): bool
    {
        // Check direct services
        if (!empty($item['services'])) {
            foreach ($item['services'] as $service) {
                if (!empty($service['category']) && $service['category'] === $category) {
                    return true;
                }
            }
        }

        // Check services in groups
        if (!empty($item['groups'])) {
            foreach ($item['groups'] as $group) {
                // Check group services
                if (!empty($group['services'])) {
                    foreach ($group['services'] as $service) {
                        if (!empty($service['category']) && $service['category'] === $category) {
                            return true;
                        }
                    }
                }

                // Check subgroups
                if (!empty($group['groups'])) {
                    foreach ($group['groups'] as $subgroup) {
                        if (!empty($subgroup['services'])) {
                            foreach ($subgroup['services'] as $service) {
                                if (!empty($service['category']) && $service['category'] === $category) {
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if item matches the specified active status
     */
    private function itemMatchesActiveStatus(array $item, bool $isActiveRequired): bool
    {
        // Check main item active status
        if (isset($item['is_active']) && (bool) $item['is_active'] === $isActiveRequired) {
            return true;
        }

        // Check services in groups
        if (!empty($item['groups'])) {
            foreach ($item['groups'] as $group) {
                // Check group active status
                if (isset($group['is_active']) && (bool) $group['is_active'] === $isActiveRequired) {
                    return true;
                }

                // Check subgroups
                if (!empty($group['groups'])) {
                    foreach ($group['groups'] as $subgroup) {
                        if (isset($subgroup['is_active']) && (bool) $subgroup['is_active'] === $isActiveRequired) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if item matches the specified request_allowed status for groups
     */
    private function itemMatchesRequestAllowed(array $item, bool $requestAllowedRequired): bool
    {
        // Check main item request_allowed status
        if (isset($item['request_allowed']) && (bool) $item['request_allowed'] === $requestAllowedRequired) {
            return true;
        }

        // Check groups
        if (!empty($item['groups'])) {
            foreach ($item['groups'] as $group) {
                // Check group request_allowed status
                if (isset($group['request_allowed']) && (bool) $group['request_allowed'] === $requestAllowedRequired) {
                    return true;
                }

                // Check subgroups
                if (!empty($group['groups'])) {
                    foreach ($group['groups'] as $subgroup) {
                        if (isset($subgroup['request_allowed']) && (bool) $subgroup['request_allowed'] === $requestAllowedRequired) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if item has services with the specified is_active status
     */
    private function itemHasActiveServices(array $item, bool $serviceActiveRequired): bool
    {
        // Check direct services
        if (!empty($item['services'])) {
            foreach ($item['services'] as $service) {
                if (isset($service['is_active']) && (bool) $service['is_active'] === $serviceActiveRequired) {
                    return true;
                }
            }
        }

        // Check services in groups
        if (!empty($item['groups'])) {
            foreach ($item['groups'] as $group) {
                // Check group services
                if (!empty($group['services'])) {
                    foreach ($group['services'] as $service) {
                        if (isset($service['is_active']) && (bool) $service['is_active'] === $serviceActiveRequired) {
                            return true;
                        }
                    }
                }

                // Check subgroups
                if (!empty($group['groups'])) {
                    foreach ($group['groups'] as $subgroup) {
                        if (!empty($subgroup['services'])) {
                            foreach ($subgroup['services'] as $service) {
                                if (isset($service['is_active']) && (bool) $service['is_active'] === $serviceActiveRequired) {
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    public function render(): View
    {
        return view('livewire.dictionary.service-catalog', [
            'services' => $this->services
        ]);
    }
}
