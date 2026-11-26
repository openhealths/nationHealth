<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Enums\Episode\Status as EpisodeStatus;
use App\Enums\Person\ProcedureStatus;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\Repository;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class CarePlanCreate extends CarePlanComponent
{
    #[Locked]
    public ?string $draftUuid = null;

    #[Locked]
    public ?int $draftLegalEntityId = null;

    #[Locked]
    public ?int $draftOpenedById = null;

    public function mount(LegalEntity $legalEntity, $personId = null, $encounter = null, ?Person $person = null): void
    {
        $this->draftUuid = Str::uuid()->toString();
        $this->draftLegalEntityId = $legalEntity->id;
        $this->draftOpenedById = Auth::id();

        $routePersonId = $person?->id ?? request()->route('personId');
        $personId ??= $routePersonId;
        $encounterRouteParam = request()->route('encounter') ?? (request()->query('encounter') ?? request()->query('encounterId'));
        $this->allowsPatientChange = empty($routePersonId) && empty($encounterRouteParam);

        $resolvedPersonId = null;
        $resolvedEncounter = null;

        // Try to resolve encounter from route parameters, query string or sequence-passed personId

        if ($encounterRouteParam) {
            if (Str::isUuid((string) $encounterRouteParam)) {
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
        $this->loadPatientForm($legalEntity, $person);

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

    protected function afterDraftSaved(int $draftId): void
    {
        Session::flash('success', __('care-plan.draft_saved'));
        $this->redirectRoute('care-plans.edit', [legalEntity(), $draftId], navigate: true);
    }

    protected function persistDraft(): ?int
    {
        $user = Auth::user();
        $legalEntity = legalEntity();
        if ($user === null || $legalEntity === null || $this->draftUuid === null
            || !$this->draftContextMatches($user) || $user->cannot('create', CarePlan::class)) {
            Session::flash('error', __('care-plan.no_permission_create'));

            return null;
        }

        $repository = Repository::carePlan();

        $person = $repository->findPatient((int) $this->personId);
        if ($person === null || !Str::isUuid((string) $person->uuid)) {
            throw ValidationException::withMessages(['form.patient' => __('validation.required', [
                'attribute' => __('care-plan.patient'),
            ])]);
        }
        $encounter = $this->prepareDraftEncounter($person);

        $coAuthors = array_column($repository->doctors($legalEntity->id), 'uuid');
        $episodes = $this->draftEpisodes($person, $encounter, $user);
        $medicalRecords = $this->form->medicalRecords === [] ? collect() : $this->draftMedicalRecords($person, $encounter, $user);
        $validated = $this->form->validateDraft($coAuthors, $episodes->keys()->all(), $medicalRecords->keys()->all());

        $mapper = objectMapper();
        $draft = $this->mapDraftForm($mapper, $user, $person, $encounter);
        $draft->uuid = $this->draftUuid;
        $draft->personId = $person->id;
        $draft->legalEntityId = $legalEntity->id;
        $draft->prepareLocal(
            $mapper,
            $person->uuid,
            $legalEntity->uuid,
            $validated['coAuthors'],
            $validated['episodes'],
            array_intersect_key($medicalRecords->all(), array_flip($validated['medicalRecords'])),
        );

        return $repository->saveLocally(collect([$draft]), $mapper)->first()?->id;
    }

    protected function draftContextMatches(User $user): bool
    {
        return $this->draftOpenedById === $user->id
            && $this->draftLegalEntityId !== null
            && $this->draftLegalEntityId === legalEntity()?->id;
    }

    protected function draftEpisodes(Person $person, Encounter $encounter, User $user): Collection
    {
        return $person->filterRecordsWithMerged(Episode::query())
            ->where(fn ($query) => $query->whereStatus(EpisodeStatus::ACTIVE)->orWhere('uuid', $encounter->episode?->value))
            ->get()
            ->toBase()
            ->filter(fn (Episode $episode): bool => $user->can('view', [$episode, $person]))
            ->mapWithKeys(fn (Episode $episode): array => [$episode->uuid => $episode->name]);
    }

    protected function draftMedicalRecords(Person $person, Encounter $encounter, User $user): Collection
    {
        $procedures = $person->filterRecordsWithMerged(Procedure::forEncounter($encounter->uuid))
            ->whereStatus(ProcedureStatus::COMPLETED)
            ->with('code')->get()->toBase()
            ->filter(fn (Procedure $record): bool => $user->can('view', [$record, $person]));
        $reports = $person->filterRecordsWithMerged(DiagnosticReport::forEncounter($encounter->uuid))
            ->final()
            ->with('code')->get()->toBase()
            ->filter(fn (DiagnosticReport $record): bool => $user->can('view', [$record, $person]));

        return $procedures->concat($reports)->mapWithKeys(function (Procedure|DiagnosticReport $record): array {
            $type = $record instanceof Procedure ? 'procedure' : 'diagnostic_report';
            $label = $record->code?->displayValue ?: ($record->code?->value ?: $record->uuid);

            return [$type.':'.$record->uuid => __('care-plan.emz_type.'.$type).' — '.$label];
        });
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
        if (!$this->allowsPatientChange || !Auth::user()?->can('create', CarePlan::class)) {
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
        if (!$this->allowsPatientChange || !Auth::user()?->can('create', CarePlan::class)) {
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
        if (!$this->allowsPatientChange || !Auth::user()?->can('create', CarePlan::class)) {
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

    protected function renderCarePlan(): View
    {
        return view('livewire.care-plan.care-plan-create');
    }
}
