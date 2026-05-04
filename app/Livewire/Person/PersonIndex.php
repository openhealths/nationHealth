<?php

declare(strict_types=1);

namespace App\Livewire\Person;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\Status;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Person\Forms\PersonForm as Form;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use App\Rules\InDictionary;
use App\Rules\PhoneNumber;
use App\Traits\FormTrait;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Validator;

class PersonIndex extends Component
{
    use FormTrait;
    use WithPagination;

    /**
     * List of founded person.
     *
     * @var array
     */
    public array $patients = [];

    public Form $form;

    /**
     * Active filter for patients.
     *
     * @var string
     */
    public string $activeFilter = 'all';

    public bool $showAdditionalParams;

    public function mount(LegalEntity $legalEntity): void
    {
    }

    /**
     * Reset all filters to default values.
     *
     * @return void
     */
    public function resetFilters(): void
    {
        $this->activeFilter = 'all';

        // Reset search form fields
        $this->form->firstName = '';
        $this->form->lastName = '';
        $this->form->birthDate = '';
        $this->form->secondName = '';
        $this->form->taxId = '';
        $this->form->phoneNumber = '';
        $this->form->birthCertificate = '';

        // Clear patients list
        $this->patients = [];

        // Reset pagination after clearing data
        $this->resetPage();
    }

    /**
     * Get paginated patients with filtering.
     *
     * @return LengthAwarePaginator
     */
    #[Computed]
    public function paginatedPatients(): LengthAwarePaginator
    {
        $collection = collect($this->patients);

        // Filter by active filter
        $collection = match ($this->activeFilter) {
            'all' => $collection,
            'request' => $collection->where('source', 'request'),
            'local' => $collection->where('source', 'local'),
            'ehealth' => $collection->where('source', 'ehealth'),
            default => $collection->where('status', $this->activeFilter)
        };

        return new LengthAwarePaginator(
            $collection->forPage($this->getPage(), config('pagination.per_page')),
            $collection->count(),
            config('pagination.per_page'),
            $this->getPage()
        );
    }

    /**
     * Search for person with provided filters.
     *
     * @return void
     */
    public function searchForPerson(): void
    {
        try {
            $validated = $this->form->validate($this->form->rulesForSearch());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        // Prepare filters for local DB search
        $validated['birthDate'] = convertToYmd($validated['birthDate']);
        $phoneNumber = data_get($validated, 'phoneNumber');
        $filters = Arr::except(removeEmptyKeys(Arr::toSnakeCase($validated)), ['phone_number']);

        // Search for persons in our DB
        $persons = Person::with('phones')
            ->where($filters)
            ->when($phoneNumber, static function ($query) use ($phoneNumber) {
                $query->whereHas('phones', static function (Builder $query) use ($phoneNumber) {
                    $query->whereNumber($phoneNumber);
                });
            })
            ->get([
                'id',
                'uuid',
                'gender',
                'birth_settlement',
                'first_name',
                'last_name',
                'second_name',
                'birth_date',
                'tax_id',
                'verification_status'
            ])
            ->toArray();

        $persons = $this->setPersonSource($persons, 'local');

        // Search for person_requests
        $personRequests = PersonRequest::with('phones')
            ->where($filters)
            ->whereIn('status', [Status::DRAFT, Status::NEW, Status::APPROVED, Status::REJECTED])
            ->when($phoneNumber, static function ($query) use ($phoneNumber) {
                $query->whereHas('phones', static function (Builder $query) use ($phoneNumber) {
                    $query->whereNumber($phoneNumber);
                });
            })
            ->get([
                'id',
                'status',
                'gender',
                'birth_settlement',
                'first_name',
                'last_name',
                'second_name',
                'birth_date',
                'tax_id'
            ])
            ->toArray();

        $personRequests = $this->setPersonSource($personRequests, 'request');

        // If found in our DB, show that result
        if (!empty($persons)) {
            $this->patients = array_merge($persons, $personRequests);
        } else {
            // Otherwise search in eHealth
            $buildSearchRequest = removeEmptyKeys($validated);
            try {
                $validatedEhealth = EHealth::person()->searchForPersonByParams($buildSearchRequest)->validate();
                $validatedEhealth = $this->setPersonSource($validatedEhealth, 'ehealth');
            } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
                $this->handleEHealthExceptions($exception, 'Error while submitting encounter');

                return;
            }

            $this->patients = array_merge($personRequests, Arr::toCamelCase($validatedEhealth));
        }
    }

    /**
     * Delete person request.
     *
     * @param  int  $id
     * @return void
     */
    public function deleteDraft(int $id): void
    {
        PersonRequest::destroy($id);

        // Update list
        $this->patients = collect($this->patients)
            ->reject(static fn (array $patient) => $patient['id'] === $id)
            ->all();

        Session::flash('success', 'Заявку успішно видалено.');
    }

    /**
     * Stores patient data in the DB and redirects to route by name.
     *
     * @param  string  $patientId
     * @param  string  $routeName
     * @return void
     */
    public function redirectTo(string $patientId, string $routeName): void
    {
        if (uuid_is_valid($patientId)) {
            // IF UUID is valid, then find for it in DB
            $patientData = collect($this->patients)->firstWhere('id', $patientId);
            $person = Person::firstWhere('uuid', $patientId);

            // Crete person in DB if not exist.
            if (!$person) {
                $patientData['uuid'] = $patientData['id'];
                unset($patientData['id'], $patientData['status']);

                $person = $this->storeNewPerson($patientData);

                // If validation failed, don't redirect.
                if (!$person) {
                    return;
                }
            }

            $this->redirectRoute($routeName, [legalEntity(), 'personId' => $person->id]);
        } else {
            $this->redirectRoute($routeName, [legalEntity(), 'personId' => $patientId]);
        }
    }

    /**
     * Store new person from eHealth in DB.
     *
     * @param  array  $patientData
     * @return Person|null
     */
    private function storeNewPerson(array $patientData): ?Person
    {
        // Validate incoming data
        $validator = Validator::make($patientData, [
            'uuid' => ['required', 'uuid'],
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'birthDate' => ['required', 'date'],
            'birthCountry' => ['required', 'string', 'max:255'],
            'birthSettlement' => ['required', 'string', 'max:255'],
            'gender' => ['required', new InDictionary('GENDER')],
            'second_name' => ['nullable', 'string', 'max:255'],
            'taxId' => ['nullable', 'string', 'size:10', Rule::unique('persons', 'tax_id')],
            'birth_certificate' => ['nullable', 'string', 'max:255'],
        ]);

        $phoneValidator = Validator::make($patientData['phones'] ?? [], [
            '*.type' => ['required', 'string', new InDictionary('PHONE_TYPE')],
            '*.number' => ['required', 'string', new PhoneNumber()]
        ]);

        if ($validator->fails() || $phoneValidator->fails()) {
            Session::flash('error', 'Некоректні дані пацієнта: ' . implode(', ', $validator->errors()->all()));

            return null;
        }

        $validated = $validator->validated();
        $validatedPhones = $phoneValidator->validated();

        try {
            $person = Person::firstOrCreate(['uuid' => $validated['uuid']], Arr::toSnakeCase($validated));

            if (!empty($validatedPhones)) {
                $person->phones()->createMany($validatedPhones);
            }

            return $person;
        } catch (Exception $exception) {
            Session::flash('error', 'Виникла помилка, зверніться до адміністратора.');
            $this->logDatabaseErrors($exception, 'Error while creating new person');

            return null;
        }
    }

    /**
     * Add the source where patients were founded.
     *
     * @param  array  $persons
     * @param  string  $source
     * @return array
     */
    private function setPersonSource(array $persons, string $source): array
    {
        return array_map(static function ($patient) use ($source) {
            $patient['source'] = $source;

            return $patient;
        }, $persons);
    }

    public function render(): View
    {
        return view('livewire.person.person-index', [
            'paginatedPatients' => $this->paginatedPatients,
            'activeFilter' => $this->activeFilter
        ]);
    }
}
