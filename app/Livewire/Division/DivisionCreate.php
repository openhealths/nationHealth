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
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use App\Dto\Division\Model as DivisionData;
use App\Dto\Division\Ehealth as EhealthData;
use Symfony\Component\ObjectMapper\ObjectMapper;
use App\Dto\Division\Coordinate as CoordinateData;
use Livewire\Features\SupportRedirects\Redirector;
use Symfony\Component\PropertyAccess\PropertyAccess;

class DivisionCreate extends DivisionComponent
{
    use WorkTimeUtilities;

    #[Locked]
    public ?int $divisionId = null;

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
     * @param  mixed  $value  The value for longitude from input field
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
     * @return Division|RedirectResponse|Redirector|null
     */
    public function store(bool $justSave = true): Division|RedirectResponse|Redirector|null
    {
        if (Auth::user()->cannot('create', Division::class)) {
            session()->flash('error', __('divisions.policy.deny.create'));

            return null;
        }

        if ((string) ($this->divisionForm->division['id'] ?? '') !== (string) $this->divisionId) {
            session()->flash('error', __('divisions.policy.deny.edit'));

            return null;
        }

        // A draft already saved by this component must be continued on the edit page.
        if ($this->divisionId !== null) {
            $division = legalEntity()->divisions()->find($this->divisionId);

            if (!$division) {
                session()->flash('error', __('errors.database.messages.load_error'));

                return null;
            }

            if (Auth::user()->cannot('update', $division)) {
                session()->flash('error', __('divisions.policy.deny.edit'));

                return null;
            }

            return Redirect::route('division.edit', [legalEntity(), $division]);
        }

        if ($this->validateDivision()) {
            try {
                $division = $this->saveToDB(new Division());
                $this->divisionId = $division->id;

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
     * Saves a new local draft and submits it for registration in eHealth.
     *
     * @return void
     */
    public function create(): void
    {
        // Preliminary store data in the DB
        $division = $this->store(false);

        if (!$division || !$division instanceof Division) {
            return;
        }

        // Send request to the eHealth and store request data
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
     * If the request fails, it redirects to the saved draft with an error message.
     *
     * @param  Division  $division  The pre-saved division model instance to be created in eHealth
     * @return void This method does not return a value but performs a redirect on success
     */
    protected function divisionCreate(Division $division): void
    {
        try {
            $divisionEhealthMapped = new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())
                ->map($this->divisionForm, EhealthData::class)
                ->toArray();

            $response = EHealth::division()->create(data: $divisionEhealthMapped)->validate();
            // If the response is empty, it means the create failed and uncatched
            if ($response->isEmpty()) {
                throw new Exception('eHealth return empty response!');
            }

        } catch (Throwable $err) {
            Log::channel('e_health_errors')->error(self::class . '::divisionCreate()', ['error' => $err->getMessage()]);
            session()->flash('error', __('errors.ehealth.messages.request_error'));
            $this->redirect(route('division.edit', [legalEntity(), $division]), navigate: true);

            return;
        }

        try {
            $divisionModelMapped = new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessorBuilder()
                ->disableExceptionOnInvalidPropertyPath()
                ->getPropertyAccessor())
                ->map($response, DivisionData::class);

            Repository::division()->saveMappedDivision($divisionModelMapped, $division, legalEntity());
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(self::class . '::divisionCreate()', ['error' => $err->getMessage()]);
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
        return view('livewire.division.division-create');
    }
}
