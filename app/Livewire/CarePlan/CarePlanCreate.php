<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Dto\CarePlan\Model as CarePlanData;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\CarePlanRepository;
use App\Repositories\Repository as AppRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CarePlanCreate extends BaseCarePlanComponent
{
    /**
     * The encounter and the person are typed, so Livewire binds them from the route before the route's `can` checks run.
     * The first three parameters keep the parent signature: the person id from the route or the person is accepted
     * as the second one, and the preperson is not used here.
     *
     * @param  LegalEntity  $legalEntity
     * @param  mixed  $personId
     * @param  Preperson|null  $preperson
     * @param  Encounter|null  $encounter
     * @param  Person|null  $person
     * @return void
     */
    public function mount(LegalEntity $legalEntity, $personId = null, ?Preperson $preperson = null, ?Encounter $encounter = null, ?Person $person = null): void
    {
        $routePersonId = $person?->id ?? request()->route('personId');
        $personId ??= $routePersonId;
        $encounterRouteParam = $encounter?->id ?? request()->query('encounter') ?? request()->query('encounterId');
        $this->allowsPatientChange = empty($routePersonId) && empty($encounterRouteParam);

        $resolvedPersonId = null;
        $resolvedEncounter = null;

        // Try to resolve encounter from route parameters, query string or sequence-passed personId

        if ($encounterRouteParam) {
            if (Str::isUuid((string) $encounterRouteParam)) {
                $resolvedEncounter = app(CarePlanRepository::class)->findEncounter($encounterRouteParam);
            } elseif (is_numeric($encounterRouteParam)) {
                $resolvedEncounter = app(CarePlanRepository::class)->findEncounter((int) $encounterRouteParam);
            }
            if ($resolvedEncounter) {
                $resolvedPersonId = $resolvedEncounter->person_id;
            }
        }

        if ($person !== null && $encounterRouteParam) {
            abort_unless($resolvedEncounter !== null && (int) $resolvedEncounter->person_id === $person->id, 404);
            $this->authorize('view', [$resolvedEncounter, $person]);
        }

        // If personId was passed, use it, unless it was sequential mapping of encounter
        if (!$resolvedPersonId && $personId && (!ctype_digit((string) $personId) || !app(CarePlanRepository::class)->findPatient((int) $personId))) {
            // Check if $personId is actually an encounter ID or UUID
            $possibleEncounter = null;
            if (Str::isUuid((string) $personId)) {
                $possibleEncounter = app(CarePlanRepository::class)->findEncounter($personId);
            } elseif (is_numeric($personId)) {
                $possibleEncounter = app(CarePlanRepository::class)->findEncounter((int) $personId);
            }

            if ($possibleEncounter) {
                $resolvedEncounter = $possibleEncounter;
                $resolvedPersonId = $possibleEncounter->person_id;
            } else {
                $resolvedPersonId = ctype_digit((string) $personId) ? (int) $personId : null;
            }
        }

        $patientId = $resolvedPersonId ?? $personId;
        $this->personId = ctype_digit((string) $patientId) ? (int) $patientId : 0;

        if ($this->personId > 0) {
            parent::mount($legalEntity);
        } else {
            $this->patientFullName = __('care-plan.new_care_plan');
            $this->verificationStatus = '';
            $this->uuid = '';
            $this->declarationNumber = null;
        }

        $person = app(CarePlanRepository::class)->findPatient($this->personId);
        if ($person) {
            $name = $person->primary_name;
            $this->form->patient = $name ? trim($name->last_name . ' ' . $name->first_name . ' ' . ($name->second_name ?? '')) : '';
            $this->form->medical_number = app(CarePlanRepository::class)->nextDisplayNumber();

            $this->availableEpisodes = app(CarePlanRepository::class)->activeEpisodes($person);

            $this->loadPatientAuthMethods($this->uuid);
        } else {
            $this->form->medical_number = app(CarePlanRepository::class)->nextDisplayNumber();
        }

        $this->loadAvailableEncounters();

        $this->conditionUuid = request()->query('conditionUuid', '');

        if ($resolvedEncounter) {
            $this->form->encounter = $resolvedEncounter->uuid;
            $this->form->medical_number = (string) $resolvedEncounter->id;

            // Pre-fill title if empty
            if (empty($this->form->title)) {
                $date = $resolvedEncounter->period?->start ? Carbon::parse($resolvedEncounter->period->start)->format('d.m.Y') : now()->format('d.m.Y');
                $this->form->title = __('План лікування від ') . $date;
            }

            app(CarePlanRepository::class)->loadEncounter($resolvedEncounter);
            if ($resolvedEncounter->episode?->value) {
                $episode = app(CarePlanRepository::class)->findEpisode($resolvedEncounter->episode->value);
                if ($episode) {
                    $this->form->episodes = [
                        [
                            'uuid' => $episode->uuid,
                            'name' => $episode->name,
                            'date' => $episode->period?->start ? Carbon::parse($episode->period->start)->format('d.m.Y') : '',
                        ]
                    ];
                }
            }

            $this->diagnoses = $this->buildDiagnosesForUi($resolvedEncounter);
        }

        $this->form->periodStart = now()->format(config('app.date_format'));
        $this->form->periodStartTime = now()->format('H:i');

        $this->synchronizeSystemFields();
        $this->loadDoctorsAndDictionaries();
        $this->refreshCategoryOptions();
        $this->form->uuid = Str::uuid()->toString();
    }

    /**
     * Search for a patient in the local registry (same criteria as the patients page).
     */
    public function searchForPatient(): void
    {
        try {
            $validated = $this->patientSearch->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $firstName = $validated['firstName'];
        $lastName = $validated['lastName'];
        $birthDate = convertToYmd($validated['birthDate']);

        $this->patientSearchResults = app(CarePlanRepository::class)->searchPatients($firstName, $lastName, $birthDate);

        if ($this->patientSearchResults === []) {
            Session::flash('error', __('patients.nobody_found') . '. ' . __('patients.try_change_search_parameters'));
        }
    }

    /**
     * Select a patient from the search results.
     */
    public function selectPatient(int $personId): void
    {
        $person = app(CarePlanRepository::class)->findPatient($personId) ?? abort(404);

        $this->personId = $person->id;
        $this->uuid = $person->uuid;
        $this->patientUuid = $person->uuid;
        $this->patientFullName = $person->fullName;
        $this->verificationStatus = $person->verificationStatus;
        $this->declarationNumber = $person->declarations->first()?->declarationNumber ?? null;

        $birthDate = $person->birth_date
            ? Carbon::parse($person->birth_date)->format(config('app.date_format'))
            : null;
        $this->form->patient = trim($person->fullName . ($birthDate ? ' · ' . $birthDate : ''));
        $this->form->medical_number = app(CarePlanRepository::class)->nextDisplayNumber();
        $this->form->encounter = '';
        $this->form->category = '';
        $this->allowedCategoryCodes = [];
        $this->diagnoses = [];
        $this->patientSearchResults = [];
        $this->loadAvailableEncounters();

        $this->loadPatientAuthMethods((string) $person->uuid);
    }

    /**
     * Reset patient selection and return to search mode.
     */
    public function clearSelectedPatient(): void
    {
        $this->personId = 0;
        $this->patientUuid = '';
        $this->uuid = '';
        $this->patientFullName = __('care-plan.new_care_plan');
        $this->verificationStatus = '';
        $this->declarationNumber = null;
        $this->form->patient = '';
        $this->form->encounter = '';
        $this->form->category = '';
        $this->allowedCategoryCodes = [];
        $this->availableEncounters = [];
        $this->authMethods = [];
        $this->diagnoses = [];
        $this->patientSearchResults = [];
        $this->form->medical_number = app(CarePlanRepository::class)->nextDisplayNumber();
    }

    public function cancel(): void
    {
        $encounterUuid = $this->form->encounter;
        if ($encounterUuid) {
            $encounter = app(CarePlanRepository::class)->findEncounter($encounterUuid, $this->personId);
            if ($encounter) {
                $this->redirectRoute('persons.encounters.package.show', [legalEntity(), $this->personId, $encounter->id], navigate: true);

                return;
            }
        }
        if ($this->personId > 0) {
            $this->redirectRoute('persons.care-plans', [legalEntity(), $this->personId], navigate: true);

            return;
        }

        $this->redirectRoute('care-plans.index', legalEntity(), navigate: true);
    }

    /**
     * Validate the form and keep the care plan as a local draft.
     *
     * @return void
     */
    public function save(): void
    {
        if (!$this->guardPermission()) {
            return;
        }

        try {
            $carePlan = $this->storeLocally($this->prepareForm());
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        Session::flash('success', __('care-plan.draft_saved'));
        $this->redirectRoute('care-plans.edit', [legalEntity(), $carePlan->id], navigate: true);
    }

    /**
     * Keep the care plan the form describes as a new local record.
     *
     * @param  array{personId: int, authorId: int, encounterId: int}  $ids
     * @param  bool  $forSubmission
     * @return CarePlan
     * @throws ClinicalStorageException
     */
    protected function storeLocally(array $ids, bool $forSubmission = false): CarePlan
    {
        AppRepository::carePlan()->assertFhirStorageReady(beforeSubmission: $forSubmission);

        return AppRepository::carePlan()->save(
            objectMapper()->map($this->form, CarePlanData::class),
            new CarePlan([
                'uuid' => $this->form->uuid,
                'person_id' => $ids['personId'],
                'legal_entity_id' => legalEntity()->id,
                'author_id' => $ids['authorId'],
                'encounter_id' => $ids['encounterId'],
            ])
        );
    }

    protected function permissionError(): ?string
    {
        return Auth::user()?->can('create', CarePlan::class) ? null : __('care-plan.no_permission_create');
    }


    public function render()
    {
        return view('livewire.care-plan.care-plan-create');
    }
}
