<?php

declare(strict_types=1);

namespace App\Livewire\Division;

use Exception;
use Throwable;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\Relations\Phone;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use App\Traits\WorkTimeUtilities;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Traits\Addresses\AddressSearch;
use App\Livewire\Division\Trait\HasAction;
use App\Traits\Addresses\ReceptionAddressSearch;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class DivisionEdit extends DivisionComponent
{
    use WorkTimeUtilities;
    use ReceptionAddressSearch;
    use AddressSearch;
    use HasAction;

    /**
     * Array containing dictionary names only used within the component.
     *
     * @var array
     */
    protected array $allowedDictionaryItems = [];

    public function mount(LegalEntity $legalEntity, Division $division)
    {
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
     * It ensures the value is always stored as a float.
     *
     * @param  mixed  $value  The value for latitude from input field
     * @return void
     */
    public function updatedDivisionFormDivisionLocationLatitude($value)
    {
        $latitude = (float) (empty($value) ? 0 : $value);

        $this->divisionForm->division['location']['latitude'] = (float) number_format($latitude, 6, '.', '');
    }

    /**
     * Handle updates to the division location longitude field.
     *
     * This method is automatically called by Livewire when the
     * divisionForm.division.location.longitude property is updated.
     * It ensures the value is always stored as a float.
     *
     * @param  mixed  $value  The value for latitude from input field
     * @return void
     */
    public function updatedDivisionFormDivisionLocationLongitude($value)
    {
        $longitude = (float) (empty($value) ? 0 : $value);

        $this->divisionForm->division['location']['longitude'] = (float) number_format($longitude, 6, '.', '') ;
        ;
    }

    /**
     * Set the division form data based on the provided Division model.
     *
     * - Sets the main division parameters from the model.
     * - Assigns the address and phones to the form.
     * - Initializes working hours if not already set.
     *
     * @param  Division  $division
     * @return void
     */
    public function setDivisionData(Division $division)
    {
        $this->divisionForm->setDivision($division->toArray());

        $this->divisionForm->division['addresses'] = $division->addresses->toArray();

        if (!empty($this->divisionForm->division['addresses'])) {
            foreach ($this->divisionForm->division['addresses'] as $address) {
                $addressType = strtolower($address['type']);

                switch ($addressType) {
                    case 'residence':
                        $this->address = $address;
                        break;
                    case 'reception':
                        $this->receptionAddress = $address;
                        $this->divisionForm->showReceptionAddress = true;
                        break;
                    default:
                        continue 2;
                }
            }
        }

        $this->divisionForm->division['phones'] = $division->phones->toArray();
    }

    /**
     * Store data from the Division's form into the DB
     *
     * @param  bool  $justSave  Whether to show a success message after saving (true by default)
     * @return Division|null
     */
    public function store($justSave = true): ?Division
    {
        if (Auth::user()->cannot('update', Division::find($this->divisionForm->division['id']))) {
            session()->flash('error', __('divisions.policy.deny.edit'));

            return null;
        }

        $this->divisionForm->division['addresses'] = $this->divisionForm->showReceptionAddress
            ? ['residence' => $this->address, 'reception' => $this->receptionAddress]
            : ['residence' => $this->address];

        if ($this->validateDivision()) {
            try {
                $division = $this->saveToDB();

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
     * @param  Division  $division  The division model instance that has been pre-saved with local changes
     * @return void.
     */
    public function divisionUpdate(Division $division): void
    {
        try {
            $response = $this->updateDivision();

            // If the response is empty, it means the update failed and uncatched
            if (empty($response)) {
                throw new Exception(static::class . 'updateDivision() return empty response!');
            }

            // This need for case if the division has DRAFT status
            $response['id'] = $division->id;

            // Repository::division()->syncDivisionData($this->divisionForm->division, legalEntity()); // TODO: realize it on the next PRs
            $division = Repository::division()->saveDivisionData($response, legalEntity()); // TODO: Remove it after the syncDivisionData() will works

            if (!$division) {
                throw new Exception('Cannot save division data after response!');
            }

            $this->redirect(route('division.index', [legalEntity()]), navigate: true);

            session()->flash('success', __('forms.success_response'));

            return;
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':divisionUpdate', ['error' => $err->getMessage()]);
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':divisionUpdate', ['error' => $err->getDetails()]);
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(self::class . ':divisionUpdate', ['error' => $err->getMessage()]);
        }

        session()->flash('error', __('errors.ehealth.messages.request_error'));

        return;
    }

    /**
     * Prepares and sends the division data to the eHealth API for an update.
     *
     * This protected method is responsible for the direct interaction with the
     * eHealth service. It prepares the request data, ensuring that location
     * and working hours are correctly formatted and included, and then calls
     * the eHealth API's update endpoint. The response is then validated.
     *
     * @return array|null The validated response data from the eHealth API on success, or null on failure.
     */
    protected function updateDivision(): array|null
    {
        $uuid = $this->divisionForm->division['uuid'];

        $division = $this->prepareRequestData();

        // If location is not set, then use the original location cause the 0 value has been removed by removeEmptyKeys method
        $division['location'] ??= $this->divisionForm->division['location'];

        // If working_hours is not set, then use the original working_hours value cause the '[]' value has been removed by removeEmptyKeys method
        $division['working_hours'] = $this->prepareTimeToRequest($this->divisionForm->division['workingHours'], false);

        return EHealth::division()->update(uuid: $uuid, data: $division)->validate();
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function render()
    {
        return view('livewire.division.division-edit');
    }
}
