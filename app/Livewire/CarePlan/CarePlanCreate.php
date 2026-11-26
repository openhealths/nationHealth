<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Repositories\Repository;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CarePlanCreate extends CarePlanDraftComponent
{
    public function mount(LegalEntity $legalEntity, $personId = null, $encounter = null, ?Person $person = null): void
    {
        $this->draftUuid = Str::uuid()->toString();

        $routePersonId = $person?->id ?? request()->route('personId');
        $personId ??= $routePersonId;
        $encounterRouteParam = request()->route('encounter') ?? ($encounter ?? (request()->query('encounter') ?? request()->query('encounterId')));
        $this->allowsPatientChange = empty($routePersonId) && empty($encounterRouteParam);

        $resolvedPersonId = null;
        $resolvedEncounter = null;

        // Try to resolve encounter from route parameters, query string or sequence-passed personId

        if ($encounterRouteParam) {
            if ($encounterRouteParam instanceof Encounter) {
                $resolvedEncounter = $encounterRouteParam;
            } elseif (is_string($encounterRouteParam) && Str::isUuid($encounterRouteParam)) {
                $resolvedEncounter = Repository::carePlan()->findEncounter($encounterRouteParam);
            } elseif (is_numeric($encounterRouteParam)) {
                $resolvedEncounter = Repository::carePlan()->findEncounter((int) $encounterRouteParam);
            }
            if ($resolvedEncounter) {
                $resolvedPersonId = $resolvedEncounter->person_id;
            }
        }

        if ($person !== null && $encounterRouteParam) {
            abort_unless($resolvedEncounter !== null && (int) $resolvedEncounter->person_id === $person->id, 404);
            abort_unless(Auth::user()?->can('view', [$resolvedEncounter, $person]), 404);
        }

        // If personId was passed, use it, unless it was sequential mapping of encounter
        if (!$resolvedPersonId && $personId && (!ctype_digit((string) $personId) || !Repository::carePlan()->findPatient((int) $personId))) {
            // Check if $personId is actually an encounter ID or UUID
            $possibleEncounter = null;
            if (Str::isUuid((string) $personId)) {
                $possibleEncounter = Repository::carePlan()->findEncounter($personId);
            } elseif (is_numeric($personId)) {
                $possibleEncounter = Repository::carePlan()->findEncounter((int) $personId);
            }

            if ($possibleEncounter) {
                $resolvedEncounter = $possibleEncounter;
                $resolvedPersonId = $possibleEncounter->person_id;
            } else {
                $resolvedPersonId = ctype_digit((string) $personId) ? (int) $personId : null;
            }
        }

        $patientId = $resolvedPersonId ?? $personId;
        $person = ctype_digit((string) $patientId)
            ? Repository::carePlan()->findPatient((int) $patientId) ?? abort(404)
            : null;
        if ($person !== null) {
            parent::mount($legalEntity, $person);
        }
        $this->initializeDraftForm($legalEntity, $person);

        $conditionUuid = request()->query('conditionUuid');
        $this->conditionUuid = is_string($conditionUuid) && Str::isUuid($conditionUuid) ? $conditionUuid : '';

        if ($resolvedEncounter) {
            $this->form->encounter = $resolvedEncounter->uuid;
            $resolvedEncounter = $person ? $this->draftEncounter($person) : null;
            abort_if($resolvedEncounter === null, 404);

            $this->applyEncounterToForm($resolvedEncounter);
        }

        $this->form->periodStart = now()->format(config('app.date_format'));
        $this->form->periodStartTime = now()->format('H:i');
        $this->synchronizeSystemFields();
        $this->refreshCategoryOptions();
    }

    public function cancel(): void
    {
        $encounter = $this->form->encounter === ''
            ? null
            : Repository::carePlan()->findEncounter($this->form->encounter, $this->personId);
        $this->redirectToCarePlanContext($this->personId, $encounter?->id);
    }

    /**
     * Search for a patient in the local registry (same criteria as the patients page).
     */
    public function searchForPatient(): void
    {
        if ($this->draftId !== null || !$this->allowsPatientChange || !Auth::user()?->can('create', CarePlan::class)) {
            return;
        }

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

        $this->patientSearchResults = Repository::carePlan()->searchPatients($firstName, $lastName, $birthDate);

        if ($this->patientSearchResults === []) {
            Session::flash('error', __('patients.nobody_found') . '. ' . __('patients.try_change_search_parameters'));
        }
    }

    /**
     * Select a patient from the search results.
     */
    public function selectPatient(int $personId): void
    {
        if ($this->draftId !== null || !$this->allowsPatientChange || !Auth::user()?->can('create', CarePlan::class)) {
            return;
        }

        $person = Repository::carePlan()->findPatient($personId) ?? abort(404);

        $this->personId = $person->id;
        $this->uuid = $person->uuid;
        $this->patientFullName = $person->fullName;
        $this->declarationNumber = $person->declarations->first()?->declarationNumber ?? null;

        $birthDate = $person->birth_date
            ? Carbon::parse($person->birth_date)->format(config('app.date_format'))
            : null;
        $this->form->patient = trim($person->fullName . ($birthDate ? ' · ' . $birthDate : ''));
        $this->form->medicalNumber = Repository::carePlan()->nextDisplayNumber();
        $this->form->encounter = '';
        $this->form->category = '';
        $this->form->episodes = [];
        $this->form->medicalRecords = [];
        $this->conditionUuid = '';
        $this->draftUuid = Str::uuid()->toString();
        $this->allowedCategoryCodes = [];
        $this->diagnoses = [];
        $this->patientSearchResults = [];
        $this->availableEpisodes = Repository::carePlan()->activeEpisodes($person);
        $this->loadAvailableEncounters();

        $this->synchronizeSystemFields();
    }

    /**
     * Reset patient selection and return to search mode.
     */
    public function clearSelectedPatient(): void
    {
        if ($this->draftId !== null || !$this->allowsPatientChange || !Auth::user()?->can('create', CarePlan::class)) {
            return;
        }

        $this->personId = 0;
        $this->uuid = '';
        $this->patientFullName = __('care-plan.new_care_plan');
        $this->declarationNumber = null;
        $this->form->patient = '';
        $this->form->encounter = '';
        $this->form->category = '';
        $this->form->episodes = [];
        $this->form->medicalRecords = [];
        $this->conditionUuid = '';
        $this->draftUuid = Str::uuid()->toString();
        $this->allowedCategoryCodes = [];
        $this->availableEncounters = [];
        $this->availableEpisodes = [];
        $this->diagnoses = [];
        $this->patientSearchResults = [];
        $this->form->medicalNumber = Repository::carePlan()->nextDisplayNumber();
    }

    public function render(): View
    {
        return view('livewire.care-plan.care-plan-create');
    }
}
