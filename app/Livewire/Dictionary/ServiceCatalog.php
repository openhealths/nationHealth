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

    public string $filterCodeOrName = '';
    public string $filterCategory = '';
    public string $filterServiceIsActive = '';
    public string $filterGroupIsActive = '';
    public string $filterRequestAllowed = '';
    public bool $selectionMode = false;
    #[\Livewire\Attributes\Locked]
    public bool $allowGroupSelection = false;
    public string $selectionEvent = 'service-selected';
    public array $allowedCategories = [];
    public bool $requestAllowedOnly = false;

    public array $dictionaryNames = ['SERVICE_CATEGORY'];

    public function mount(
        LegalEntity $legalEntity,
        bool $selectionMode = false,
        string $selectionEvent = 'service-selected',
        array $allowedCategories = [],
        bool $requestAllowedOnly = false,
        bool $allowGroupSelection = false
    ): void {
        $this->selectionMode = $selectionMode;
        $this->allowGroupSelection = $allowGroupSelection;
        $this->selectionEvent = $selectionEvent;
        $this->allowedCategories = $allowedCategories;
        $this->requestAllowedOnly = $requestAllowedOnly;

        $this->getDictionary();
    }

    #[Computed]
    public function services(): LengthAwarePaginator
    {
        // Get full collection from dictionary service
        $allServices = dictionary()->services();

        if ($this->allowedCategories !== []) {
            $allServices = $allServices
                ->map(fn (array $item) => $this->filterItemByAllowedCategories($item))
                ->filter()
                ->values();
        }

        if ($this->requestAllowedOnly) {
            $allServices = $allServices
                ->map(fn (array $item) => $this->filterItemByRequestAllowed($item))
                ->filter()
                ->values();
        }

        $filteredServices = $this->hasActiveFilters()
            ? $allServices->map(fn (array $item): ?array => $this->pruneItem($item))->filter()->values()
            : $allServices;

        // Apply pagination
        $perPage = max(1, (int) (config('pagination.per_page') ?: 10));
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
            'filterCodeOrName',
            'filterCategory',
            'filterServiceIsActive',
            'filterGroupIsActive',
            'filterRequestAllowed'
        ]);

        $this->resetPage();
    }

    /**
     * Whether a search or a filter narrows the catalog, so the found branches are shown expanded.
     *
     * @return bool
     */
    public function hasActiveFilters(): bool
    {
        return trim($this->filterCodeOrName) !== ''
            || $this->filterCategory !== ''
            || $this->filterServiceIsActive !== ''
            || $this->filterGroupIsActive !== ''
            || $this->filterRequestAllowed !== '';
    }

    /**
     * Keep only the part of a catalog node that matches the search and the filters, along with the path to it.
     * A group found by code or name keeps all of its content, a group without matches inside is dropped.
     *
     * @param  array  $item
     * @param  bool  $isFoundByAncestor  Whether a group above was found by code or name
     * @return array|null
     */
    private function pruneItem(array $item, bool $isFoundByAncestor = false): ?array
    {
        // Only a service carries a category, and one outside any group can't match a group filter
        if (array_key_exists('category', $item)) {
            $hasGroupFilters = $this->filterGroupIsActive !== '' || $this->filterRequestAllowed !== '';

            return !$hasGroupFilters && $this->serviceMatches($item, $isFoundByAncestor) ? $item : null;
        }

        $isFound = $isFoundByAncestor || $this->matchesCodeOrName($item);
        $isGroupMatched = $this->groupMatches($item);

        $item['services'] = $isGroupMatched
            ? collect($item['services'] ?? [])
                ->filter(fn (array $service): bool => $this->serviceMatches($service, $isFound))
                ->values()
                ->all()
            : [];

        $item['groups'] = collect($item['groups'] ?? [])
            ->map(fn (array $group): ?array => $this->pruneItem($group, $isFound))
            ->filter()
            ->values()
            ->all();

        if ($item['services'] !== [] || $item['groups'] !== []) {
            return $item;
        }

        // A group without content of its own stays when it matches and no service filter is set
        $hasServiceFilters = $this->filterCategory !== '' || $this->filterServiceIsActive !== '';
        $isSearchMatched = trim($this->filterCodeOrName) === '' || $isFound;

        return $isGroupMatched && $isSearchMatched && !$hasServiceFilters ? $item : null;
    }

    /**
     * Check a service against the search and the service filters.
     *
     * @param  array  $service
     * @param  bool  $isFoundByAncestor  Whether a group above was found by code or name
     * @return bool
     */
    private function serviceMatches(array $service, bool $isFoundByAncestor): bool
    {
        if (!$isFoundByAncestor && trim($this->filterCodeOrName) !== '' && !$this->matchesCodeOrName($service)) {
            return false;
        }

        if ($this->filterCategory !== '' && ($service['category'] ?? null) !== $this->filterCategory) {
            return false;
        }

        return $this->filterServiceIsActive === ''
            || (bool) ($service['is_active'] ?? false) === (bool) $this->filterServiceIsActive;
    }

    /**
     * Check a group against the group filters.
     *
     * @param  array  $group
     * @return bool
     */
    private function groupMatches(array $group): bool
    {
        if ($this->filterGroupIsActive !== '' && (bool) ($group['is_active'] ?? false) !== (bool) $this->filterGroupIsActive) {
            return false;
        }

        return $this->filterRequestAllowed === ''
            || (bool) ($group['request_allowed'] ?? false) === (bool) $this->filterRequestAllowed;
    }

    /**
     * Whether the code or the name of a catalog node contains the searched text.
     *
     * @param  array  $item
     * @return bool
     */
    private function matchesCodeOrName(array $item): bool
    {
        $searchTerm = trim($this->filterCodeOrName);

        if ($searchTerm === '') {
            return false;
        }

        $terms = preg_split('/[\s;,]+/u', $searchTerm, flags: PREG_SPLIT_NO_EMPTY) ?: [$searchTerm];
        foreach ($terms as $term) {
            if (mb_stripos((string) ($item['code'] ?? ''), $term) === false
                && mb_stripos((string) ($item['name'] ?? ''), $term) === false) {
                return false;
            }
        }

        return true;
    }

    private function filterItemByRequestAllowed(array $item): ?array
    {
        if (!empty($item['services'])) {
            $item['services'] = collect($item['services'])
                ->filter(fn (array $service) => (bool) ($service['request_allowed'] ?? false))
                ->values()
                ->all();
        }

        if (!empty($item['groups'])) {
            $item['groups'] = collect($item['groups'])
                ->map(fn (array $group) => $this->filterItemByRequestAllowed($group))
                ->filter()
                ->values()
                ->all();
        }

        if ((bool) ($item['request_allowed'] ?? false)) {
            return $item;
        }

        if (!empty($item['services']) || !empty($item['groups'])) {
            return $item;
        }

        return null;
    }

    private function filterItemByAllowedCategories(array $item): ?array
    {
        foreach (['groups', 'services'] as $key) {
            if (!empty($item[$key])) {
                $item[$key] = collect($item[$key])
                    ->map(fn (array $child) => $this->filterItemByAllowedCategories($child))
                    ->filter()
                    ->values()
                    ->all();
            }
        }

        $hasChildren = !empty($item['groups']) || !empty($item['services']);

        if ($hasChildren) {
            return $item;
        }

        return $this->isCategoryAllowed($item['category'] ?? null) ? $item : null;
    }

    private function isCategoryAllowed(?string $category): bool
    {
        return $this->allowedCategories === [] || ($category !== null && in_array($category, $this->allowedCategories, true));
    }

    public function selectService(string $serviceId): void
    {
        if (!$this->selectionMode) {
            return;
        }

        $service = dictionary()
            ->services()
            ->flattened()
            ->firstWhere('id', $serviceId);

        if ($service === null || !$this->isCategoryAllowed($service['category'] ?? null)
            || (($service['type'] ?? '') === 'service_group' && !$this->allowGroupSelection)
            || ($this->requestAllowedOnly && empty($service['request_allowed']))) {
            return;
        }

        $this->dispatch($this->selectionEvent, service: $service);
    }

    public function render(): View
    {
        return view('livewire.dictionary.service-catalog', [
            'services' => $this->services
        ]);
    }
}
