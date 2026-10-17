<?php

declare(strict_types=1);

namespace App\Livewire\Division;

use Exception;
use Throwable;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\Relations\Phone;
use Livewire\Attributes\Locked;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use App\Traits\WorkTimeUtilities;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Livewire\Division\Trait\HasAction;
use App\Dto\Division\Model as DivisionData;
use App\Dto\Division\Ehealth as EhealthData;
use Symfony\Component\ObjectMapper\ObjectMapper;
use App\Dto\Division\Coordinate as CoordinateData;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Symfony\Component\PropertyAccess\PropertyAccess;

class DivisionEdit extends DivisionComponent
{
    use WorkTimeUtilities;
    use HasAction;

    #[Locked]
    public ?int $divisionId = null;

    /**
     * Array containing dictionary names only used within the component.
     *
     * @var array
     */
    protected array $allowedDictionaryItems = [];

    public function mount(LegalEntity $legalEntity, Division $division)
    {
        $this->divisionId = $division->id;
        $this->setDivisionData($division);

        $this->allowedDictionaryItems = [
            'PHONE_TYPE' => Phone::getPhoneTypes(),
            'DIVISION_TYPE' => Division::getValidDivisionTypes()
        ];

        // Throw out unused dictionary items
        $this->dictionaries = $this
            ->setDictionary()
            ->filterDictionaries($this->dictionaries, $this->allowedDictionaryItems);
    }

    /**
     * Handle updates to the division location latitude field.
     *
     * This method is automatically called by Livewire when the
     * divisionForm.division.location.latitude property is updated.
     * Numeric input is stored as a float; invalid input is kept for validation.
     *
     * @param  mixed  $value  The value for latitude from input field
     * @return void
     */
    public function updatedDivisionFormDivisionLocationLatitude($value): void
    {
        $this->divisionForm->division['location']['latitude'] = CoordinateData::fromInput($value)->value;
    }

    /**
     * Handle updates to the division location longitude field.
     *
     * This method is automatically called by Livewire when the
     * divisionForm.division.location.longitude property is updated.
     * Numeric input is stored as a float; invalid input is kept for validation.
     *
     * @param  mixed  $value  The value for latitude from input field
     * @return void
     */
    public function updatedDivisionFormDivisionLocationLongitude($value): void
    {
        $this->divisionForm->division['location']['longitude'] = CoordinateData::fromInput($value)->value;
    }

    /**
     * Store data from the Division's form into the DB
     *
     * @param  bool  $justSave  Whether to show a success message after saving (true by default)
     * @return Division|null
     */
    public function store(bool $justSave = true): ?Division
    {
        if ($this->divisionId === null ||
            (string) ($this->divisionForm->division['id'] ?? '') !== (string) $this->divisionId
        ) {
            session()->flash('error', __('divisions.policy.deny.edit'));

            return null;
        }

        $division = legalEntity()->divisions()->find($this->divisionId);

        if (!$division || Auth::user()->cannot('update', $division)) {
            session()->flash('error', __('divisions.policy.deny.edit'));

            return null;
        }

        if ($this->validateDivision()) {
            try {
                $division = $this->saveToDB($division);

                session()->flash('success', $justSave ? __('forms.saved_successfully') : null);

                return $division;
            } catch (Exception $err) {
                Log::channel('db_errors')->error('Cannot save Division\'s data!', ['error' => $err->getMessage()]);

                session()->flash('error', __('errors.database.messages.save_error'));
            }
        }

        return null;
    }

    /**
     * Combined method used both preliminary saving and modification Division's data
     *
     * @return void
     */
    public function update(): void
    {
        // Preliminary store data the the DB
        $division = $this->store(false);

        if (!$division) {
            return;
        }

        // Send request to the eHealth and store reequest data
        $this->divisionUpdate($division);
    }

    /**
     * Sends the updated division data to the eHealth API and handles the response
     *
     * This method orchestrates the final step of updating a division. It prepares
     * the data, sends it to the eHealth service, and upon a successful response,
     * saves the synchronized data back to the local database. If the API call
     * is successful, it redirects the user to the division index page with a
     * success message. Otherwise, it logs the error and displays an error message.
     *
     * Keep this method protected so Livewire submissions go through authorization and validation in store().
     *
     * @param  Division  $division  The division model instance that has been pre-saved with local changes
     * @return void.
     */
    protected function divisionUpdate(Division $division): void
    {
        if (empty($division->uuid) && Auth::user()->cannot('create', Division::class)) {
            session()->flash('error', __('divisions.policy.deny.create'));

            return;
        }

        try {
            $divisionEhealthMapped = EhealthData::fromForm($this->divisionForm, $division)
                ->toArray();

            $response = EHealth::division()->update(
                uuid: $division->uuid,
                data: $divisionEhealthMapped
            )->validate();

            if ($response->isEmpty()) {
                throw new Exception('eHealth returned an empty division response!');
            }
        } catch (EHealthResponseException $err) {
            $err->handle(self::class . ':divisionUpdate', __('errors.ehealth.messages.request_error'));

            return;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':divisionUpdate', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.request_error'));

            return;
        } catch (Throwable $err) {
            Log::channel('e_health_errors')->error(self::class . ':divisionUpdate', ['error' => $err->getMessage()]);
            session()->flash('error', __('errors.ehealth.messages.request_error'));

            return;
        }

        try {
            $divisionModelMapped = new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessorBuilder()
                ->disableExceptionOnInvalidPropertyPath()
                ->getPropertyAccessor())
                ->map($response, DivisionData::class);

            Repository::division()->saveMappedDivision($divisionModelMapped, $division, legalEntity());
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(self::class . ':divisionUpdate()', ['error' => $err->getMessage()]);
            session()->flash('error', __('errors.database.messages.save_error'));

            return;
        }

        $this->redirect(route('division.index', [legalEntity()]), navigate: true);

        session()->flash('success', __('forms.success_response'));
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function render()
    {
        return view('livewire.division.division-edit');
    }
}
