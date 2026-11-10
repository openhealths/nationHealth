<?php

declare(strict_types=1);

namespace App\Livewire\Division;

use Livewire\Component;
use App\Models\Division;
use App\Traits\FormTrait;
use App\Traits\WorkTimeUtilities;
use App\Livewire\Division\Forms\DivisionForm;
use App\Dto\Division\Ehealth as EhealthData;
use App\Repositories\Repository;
use App\Dto\Division\Model as DivisionData;
use App\Dto\Division\Form as DivisionFormData;
use App\Dto\Division\Location as DivisionLocationData;
use App\Models\Relations\Address;
use App\Traits\Addresses\AddressSearch;
use App\Traits\Addresses\ReceptionAddressSearch;
use Illuminate\Support\Collection;

class DivisionComponent extends Component
{
    use FormTrait;
    use WorkTimeUtilities;
    use AddressSearch;
    use ReceptionAddressSearch;

    /**
     * Array containing dictionary names only used within the component.
     *
     * @var array
     */
    public array $dictionaryNames = [
        'DIVISION_TYPE',
        'SETTLEMENT_TYPE',
        'PHONE_TYPE'
    ];

    /**
     * Maps restored location coordinates before Livewire applies incoming property updates.
     */
    public function hydrate(): void
    {
        $this->normalizeFormLocation();
    }

    protected function normalizeFormLocation(): void
    {
        $location = $this->divisionForm->division['location'] ?? [];

        if (!\is_array($location)) {
            return;
        }

        $normalized = objectMapper()->map($this->divisionForm, DivisionLocationData::class);
        $this->divisionForm->division['location'] = array_replace($location, $normalized->toArray());
    }

    protected function updateLocationCoordinate(string $axis, mixed $value): void
    {
        $location = objectMapper()->map(new Collection([$axis => $value]), DivisionLocationData::class);

        $this->divisionForm->division['location'][$axis] = $location->{$axis};
    }

    /**
     * The form model instance for handling division data.
     *
     * @var DivisionForm
     */
    public DivisionForm $divisionForm;

    protected function setDivisionData(Division $division): void
    {
        $data = objectMapper()->map($division, DivisionFormData::class);

        $this->divisionForm->setDivision($data->toArray());
        $this->address = $data->address(Address::DEFAULT_TYPE);
        $this->receptionAddress = $data->address(Address::RECEPTION_TYPE);
        $this->divisionForm->showReceptionAddress = $data->hasReceptionAddress();
    }

    /**
     * Sets the dictionary for this component.
     *
     * @return static Returns the current instance for method chaining
     */
    protected function setDictionary(): static
    {
        $this->getDictionary();

        return $this;
    }

    /**
     * Validate the data coming from the form(s)
     *
     * @return bool
     */
    public function validateDivision(): bool
    {
        // Livewire can update the entire location without calling the individual coordinate hooks.
        $this->normalizeFormLocation();
        $error = $this->divisionForm->doValidation();

        if ($error) {
            session()->flash('error', $error);

            return false;
        }

        return true;

    }

    /**
     * Map form data and normalize the payload before calling the HTTP client.
     *
     * @return array The normalized and cleaned division data ready for API request
     */
    protected function prepareRequestData(?Division $division = null): array
    {
        $data = objectMapper()
            ->map($this->divisionForm, new EhealthData(registered: !empty($division?->uuid)));

        return $data->toArray();
    }

    /**
     * Store division data to the database
     *
     * @param  Division|null  $division  Existing authorized division, if already loaded
     * @return null|Division
     */
    protected function saveToDB(?Division $division = null): ?Division
    {
        // The authorized model owns the eHealth UUID; the form must not replace it.
        if ($division !== null) {
            $this->divisionForm->division['uuid'] = $division->uuid;
        }

        $divisionData = propertyObjectMapper()
            ->map($this->divisionForm, DivisionData::class);

        if ($division === null) {
            $divisionId = $this->divisionForm->division['id'] ?? null;
            if ($divisionId) {
                $division = legalEntity()->divisions()->findOrFail($divisionId);
            } elseif ($divisionData->uuid) {
                $division = legalEntity()->divisions()->where('uuid', $divisionData->uuid)->firstOrFail();
            }

            $division ??= new Division();
        }

        $division = Repository::division()->saveMappedDivision($divisionData, $division, legalEntity());
        $this->divisionForm->division['id'] = $division->id;

        return $division;
    }

    /**
     * Filters an array of dictionaries based on allowed items.
     *
     * @param  array  $source  The source array of dictionaries to filter
     * @param  array  $allowedItems  Array of allowed items to filter by
     * @return array The filtered array containing only allowed items
     */
    protected function filterDictionaries(array $source, array $allowedItems): array
    {
        $arr = [];

        foreach ($source as $key => $dictionary) {

            if (\in_array($key, array_keys($allowedItems))) {
                $arr[$key] = array_filter($dictionary, fn ($item) => \in_array($item, $allowedItems[$key]), ARRAY_FILTER_USE_KEY);

                continue;
            }

            $arr[$key] = $dictionary;
        }

        return $arr;
    }
}
