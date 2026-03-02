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
use Illuminate\Http\RedirectResponse;
use App\Traits\Addresses\AddressSearch;
use Illuminate\Support\Facades\Redirect;
use App\Traits\Addresses\ReceptionAddressSearch;
use Livewire\Features\SupportRedirects\Redirector;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class DivisionCreate extends DivisionComponent
{
    use WorkTimeUtilities;
    use AddressSearch;
    use ReceptionAddressSearch;

    /**
     * Array containing dictionary names only used within the component.
     *
     * @var array
     */
    protected array $allowedDictionaryItems = [];

    public function mount(LegalEntity $legalEntity)
    {
        $this->allowedDictionaryItems = [
            'PHONE_TYPE' => Phone::getPhoneTypes(),
            'DIVISION_TYPE' => Division::getValidDivisionTypes()
        ];

        // Get rid of unused dictionary items
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

        $this->divisionForm->division['location']['latitude'] = (float) number_format($latitude, 6, '.', '') ;
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

        $this->divisionForm->division['location']['longitude'] = (float) number_format($longitude, 6, '.', '');
    }

    /**
     * Store data from the Division's form into the DB
     *
     * @param  bool  $justSave  Whether to show a success message after saving (true by default)
     * @return Division|RedirectResponse|Redirector|null
     */
    public function store(bool $justSave = true): Division|RedirectResponse|Redirector|null
    {
        if (Auth::user()->cannot('create', Division::class)) {
            session()->flash('error', __('divisions.policy.deny.create'));

            return null;
        }

        $this->divisionForm->division['addresses'] = $this->divisionForm->showReceptionAddress
            ? ['residence' => $this->address, 'reception' => $this->receptionAddress]
            : ['residence' => $this->address];

        if ($this->validateDivision()) {
            try {
                $division = $this->saveToDB();

                return $justSave
                    ? Redirect::route('division.edit', [legalEntity(), $division])->with('success', __('forms.saved_successfully'))
                    : $division;
            } catch (Throwable $err) {
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
    public function create(): void
    {
        // Preliminary store data the the DB
        $division = $this->store(false);

        if (!$division || !$division instanceof Division) {
            return;
        }

        // Send request to the eHealth and store reequest data
        $this->divisionCreate($division);
    }

    /**
     * Sends the prepared division data to the eHealth API and handles the response
     *
     * This method orchestrates the final step of creating a division. It takes a
     * pre-saved local division record, sends its data to the eHealth service for
     * creation, and upon a successful response, updates the local record with the
     * synchronized data (e.g., the eHealth UUID). If the API call is successful,
     * it redirects the user to the division index page with a success message.
     * Otherwise, it logs the error and displays an error message to the user.
     *
     * @param  Division  $division  The pre-saved division model instance to be created in eHealth
     * @return void This method does not return a value but performs a redirect on success
     */
    protected function divisionCreate(Division $division): void
    {
        try {
            $response = $this->createDivision();

            // If the response is empty, it means the create failed and uncatched
            if (empty($response)) {
                throw new Exception(static::class . 'createDivision() return empty response!');
            }

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
            Log::channel('e_health_errors')->error(self::class . ':divisionCreate', ['error' => $err->getMessage()]);
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':divisionCreate', ['error' => $err->getDetails()]);
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(self::class . ':divisionCreate', ['error' => $err->getMessage()]);
        }

        session()->flash('error', __('errors.ehealth.messages.request_error'));

        return;
    }

    /**
     * Prepares and sends the division data to the eHealth API for creation
     *
     * This method is responsible for the direct interaction with the
     * eHealth service. It prepares the request data, ensuring that location
     * and working hours are correctly formatted and included, and then calls
     * the eHealth API's create endpoint.
     *
     * @return array The validated response data from the eHealth API
     */
    protected function createDivision(): array
    {
        $division = $this->prepareRequestData();

        // If location is not set, then use the original location cause the 0 value has been removed by removeEmptyKeys method
        $division['location'] ??= $this->divisionForm->division['location'];

        // If working_hours is not set, then use the original working_hours value cause the '[]' value has been removed by removeEmptyKeys method
        $division['working_hours'] = $this->prepareTimeToRequest($this->divisionForm->division['workingHours'], false);

        return EHealth::division()->create(data: $division)->validate();
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function render()
    {
        return view('livewire.division.division-create');
    }
}
