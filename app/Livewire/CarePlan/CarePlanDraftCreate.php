<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Dto\CarePlan\Model as CarePlanData;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Livewire\CarePlan\Forms\CarePlanDraftForm;
use App\Livewire\CarePlan\Forms\PatientSearchForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\Repository;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class CarePlanDraftCreate extends BasePatientComponent
{
    public CarePlanDraftForm $form;

    #[Locked]
    public string $draftUuid;

    #[Locked]
    public int $legalEntityId;

    #[Locked]
    public int $openedById;

    #[Locked]
    public string $conditionUuid = '';

    public string $medicalRecordType = 'CONDITION';

    public PatientSearchForm $patientSearch;

    public array $categories = [];

    #[Locked]
    public array $allowedCategoryCodes = [];
    public array $diagnoses = [];
    #[Locked]
    public array $patientSearchResults = [];
    #[Locked]
    public bool $allowsPatientChange = false;
    public array $doctors = [];

    /**
     * Dictionaries to load via FormTrait::getDictionary().
     */
    protected array $dictionaryNames = [
        'eHealth/care_plan_categories',
        'eHealth/encounter_classes',
        'PROVIDING_CONDITION',
    ];

    /**
     * Available encounters that have been confirmed by eHealth (for the encounter selector).
     */
    public array $availableEncounters = [];
    public array $availableEpisodes = [];

    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?Encounter $encounter = null
    ): void {
        $repository = Repository::carePlan();
        $person = $person?->exists ? $person : null;
        $encounter = $encounter?->exists ? $encounter : null;
        $personId = request()->route('personId');
        $encounterKey = request()->query('encounter') ?? request()->query('encounterId');
        $hasEncounterContext = $encounter !== null || $encounterKey !== null;

        if ($encounter === null && $encounterKey !== null) {
            abort_unless(is_string($encounterKey) && (ctype_digit($encounterKey) || Str::isUuid($encounterKey)), 404);
            $encounter = $repository->findEncounter($encounterKey);
            abort_if($encounter === null, 404);
        }

        $personId ??= $encounter?->personId;
        if ($person === null && $personId !== null) {
            $person = $repository->findPatient((int) $personId);
            abort_if($person === null, 404);
        }

        if ($person !== null) {
            parent::mount($legalEntity, $person);
            $this->fillPatientContext($person);
        } else {
            $this->clearPatientContext();
        }

        $this->legalEntityId = $legalEntity->id;
        $this->openedById = (int) Auth::id();
        $this->allowsPatientChange = $person === null && !$hasEncounterContext;
        $this->draftUuid = Str::uuid()->toString();
        $this->doctors = $repository->doctors($legalEntity->id);
        $this->getDictionary();
        $this->categories = $this->dictionaries['eHealth/care_plan_categories'] ?? [];
        $this->form->periodStart = now()->format(config('app.date_format'));
        $this->form->periodStartTime = now()->format('H:i');

        if ($encounter !== null) {
            abort_if($person === null || Auth::user() === null, 404);
            $eligible = $repository->draftEncounter($encounter->uuid, $person, Auth::user(), $legalEntity);
            abort_if($eligible === null, 404);
            $this->form->encounter = $eligible->uuid;
            $this->applyEncounterToForm($eligible);
            $conditionUuid = request()->query('conditionUuid');
            $this->conditionUuid = is_string($conditionUuid) && Str::isUuid($conditionUuid) ? $conditionUuid : '';
        }

        $this->refreshEncounterFields();
    }

    public function save(): void
    {
        $user = Auth::user();
        $legalEntity = legalEntity();
        if ($user === null || $legalEntity === null || (int) $legalEntity->id !== $this->legalEntityId
            || (int) $user->id !== $this->openedById || $user->cannot('create', CarePlan::class)) {
            Session::flash('error', __('care-plan.no_permission_create'));

            return;
        }

        try {
            $repository = Repository::carePlan();
            $person = $repository->findPatient((int) $this->personId);
            if ($person === null || !Str::isUuid((string) $person->uuid)) {
                throw ValidationException::withMessages(['form.patient' => __('validation.required', [
                    'attribute' => __('care-plan.patient'),
                ])]);
            }

            $encounter = $repository->draftEncounter($this->form->encounter, $person, $user, $legalEntity);
            if ($encounter === null) {
                throw ValidationException::withMessages(['form.encounter' => __('validation.exists', [
                    'attribute' => __('care-plan.encounter'),
                ])]);
            }
            $this->form->termsOfService = $repository->encounterTermsOfService($encounter->uuid, $person->id);
            $this->form->intent = 'order';
            $medicalRecords = $this->form->medicalRecords === [] ? [] : $repository->draftMedicalRecordLabels($person, $encounter, $user);
            $fields = $this->form->validateDraft(
                array_column($repository->doctors($legalEntity->id), 'uuid'),
                $repository->draftEpisodeUuids($person, $encounter, $user),
                array_keys($medicalRecords)
            );
            $repository->assertCategoryForEncounter($this->form->category, $encounter->uuid, $person->id);
            $addresses = $repository->encounterData($encounter->uuid, $person->id, $this->conditionUuid ?: null)['addresses'];
            if ($addresses === []) {
                throw ValidationException::withMessages(['form.category' => __('care-plan.category_diagnosis_unavailable')]);
            }

            $author = $user->getCarePlanWriterEmployee($this->form->termsOfService);
            if ($author === null || !Str::isUuid((string) $author->uuid)) {
                throw ValidationException::withMessages(['form.author' => __('care-plan.referral_api_no_active_employee')]);
            }

            $mapper = objectMapper();
            $data = $mapper->map($this->form, CarePlanData::class);
            $data->uuid = $this->draftUuid;
            $data->personId = $person->id;
            $data->legalEntityId = $legalEntity->id;
            $data->authorId = $author->id;
            $data->authorUuid = $author->uuid;
            $data->encounterId = $encounter->id;
            $data->addresses = $addresses;
            $data->prepareLocal(
                $mapper,
                $person->uuid,
                $legalEntity->uuid,
                $fields['coAuthors'],
                $fields['episodes'],
                array_intersect_key($medicalRecords, array_flip($fields['medicalRecords']))
            );

            $draft = $repository->createDraft($data, $mapper);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());
            Session::flash('error', $exception->validator->errors()->first());

            return;
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        Session::flash('success', __('care-plan.draft_saved'));
        $this->redirectRoute('persons.care-plans', [$legalEntity, $draft->personId], navigate: true);
    }

    public function updatedFormEncounter(): void
    {
        $this->form->episodes = [];
        $this->form->medicalRecords = [];
        $this->conditionUuid = '';
        $this->diagnoses = [];

        $user = Auth::user();
        $legalEntity = legalEntity();
        $repository = Repository::carePlan();
        $person = $this->personId ? $repository->findPatient($this->personId) : null;
        $encounter = $person && $user && $legalEntity && (int) $legalEntity->id === $this->legalEntityId
            ? $repository->draftEncounter($this->form->encounter, $person, $user, $legalEntity)
            : null;
        $this->form->encounter = $encounter?->uuid ?? '';
        $this->form->medicalNumber = $repository->nextDisplayNumber();

        if ($encounter !== null) {
            $this->applyEncounterToForm($encounter);
        }

        $this->refreshEncounterFields();
    }

    protected function refreshEncounterFields(): void
    {
        $repository = Repository::carePlan();
        $this->form->termsOfService = $this->form->encounter === ''
            ? ''
            : $repository->encounterTermsOfService($this->form->encounter, (int) $this->personId);
        $party = Auth::user()?->getCarePlanWriterEmployee($this->form->termsOfService ?: null)?->party;
        $this->form->author = implode(' ', array_filter([$party?->last_name, $party?->first_name, $party?->second_name]));
        $this->allowedCategoryCodes = $this->form->encounter === ''
            ? []
            : $repository->categoryCodesForEncounter($this->form->encounter, (int) $this->personId);
        $this->resetValidation('form.category');
        if (!in_array($this->form->category, $this->allowedCategoryCodes, true)) {
            $this->form->category = count($this->allowedCategoryCodes) === 1 ? $this->allowedCategoryCodes[0] : '';
        }
    }

    protected function applyEncounterToForm(Encounter $encounter): void
    {
        $this->form->medicalNumber = (string) $encounter->id;
        if ($this->form->title === '') {
            $date = $encounter->period?->start ? Carbon::parse($encounter->period->start)->format('d.m.Y') : now()->format('d.m.Y');
            $this->form->title = __('План лікування від ').$date;
        }

        $episodeUuid = $encounter->episode?->value;
        $episode = $episodeUuid ? Repository::carePlan()->findEpisode($episodeUuid) : null;
        if ($episode !== null) {
            $person = Repository::carePlan()->findPatient((int) $this->personId);
            $user = Auth::user();
            if ($person === null || $user === null || $user->cannot('view', [$episode, $person])) {
                $episode = null;
            }
        }
        $this->form->episodes = $episode === null ? [] : [[
            'uuid' => $episode->uuid,
            'name' => $episode->name,
            'date' => $episode->period?->start ? Carbon::parse($episode->period->start)->format('d.m.Y') : '',
        ]];

        $this->diagnoses = $this->buildDiagnosesForUi($encounter);
    }

    /**
     * Build the UI list of diagnoses for an encounter. EncounterDiagnose::condition() resolves to
     * the Identifier row (its uuid lives in ->value), not the actual Condition, so we look the
     * Condition up locally.
     *
     * @return array<int, array{date: string, name: string}>
     */
    protected function buildDiagnosesForUi(Encounter $encounter): array
    {
        return $encounter->diagnoses->map(function ($d) {
            $conditionUuid = $d->condition?->value;
            $actualCondition = null;
            if ($conditionUuid) {
                $actualCondition = Repository::carePlan()->findCondition($conditionUuid, $this->personId);
            }

            return [
                'date' => $actualCondition?->asserted_date
                    ? Carbon::parse($actualCondition->asserted_date)->format('d.m.Y')
                    : '-',
                'name' => ($actualCondition?->code?->text ?: null)
                    ?? ($actualCondition?->code?->coding?->first()?->code ?: null)
                    ?? '-',
            ];
        })->toArray();
    }

    protected function fillPatientContext(Person $person): void
    {
        $this->personId = $person->id;
        $this->uuid = $person->uuid;
        $this->patientFullName = $person->fullName;
        $birthDate = $person->birth_date ? Carbon::parse($person->birth_date)->format(config('app.date_format')) : null;
        $this->form->patient = $birthDate ? $person->fullName.' · '.$birthDate : $person->fullName;
        $this->declarationNumber = $person->declarations->first()?->declarationNumber;
        $this->form->medicalNumber = Repository::carePlan()->nextDisplayNumber();
        $this->availableEpisodes = Repository::carePlan()->activeEpisodes($person);
        $this->availableEncounters = Repository::carePlan()->encounterChoices($person->id);
    }

    protected function clearPatientContext(): void
    {
        $this->form->reset();
        $this->personId = 0;
        $this->uuid = '';
        $this->patientFullName = __('care-plan.new_care_plan');
        $this->declarationNumber = null;
        $this->allowedCategoryCodes = [];
        $this->diagnoses = [];
        $this->conditionUuid = '';
        $this->availableEpisodes = [];
        $this->availableEncounters = [];
    }

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

    public function selectPatient(int $personId): void
    {
        if (!$this->allowsPatientChange || Auth::user()?->can('create', CarePlan::class) !== true) {
            return;
        }

        if (!in_array($personId, array_column($this->patientSearchResults, 'id'), true)) {
            Session::flash('error', __('patients.nobody_found'));

            return;
        }

        $person = Repository::carePlan()->findPatient($personId);
        if ($person === null) {
            Session::flash('error', __('patients.nobody_found'));

            return;
        }

        $this->fillPatientContext($person);
        $this->patientSearchResults = [];
        $this->draftUuid = Str::uuid()->toString();
        $this->refreshEncounterFields();
    }

    public function clearSelectedPatient(): void
    {
        if (!$this->allowsPatientChange || Auth::user()?->can('create', CarePlan::class) !== true) {
            return;
        }

        $this->clearPatientContext();
        $this->form->periodStart = now()->format(config('app.date_format'));
        $this->form->periodStartTime = now()->format('H:i');
        $this->resetValidation();
    }

    public function cancel(): void
    {
        if ($this->personId > 0) {
            $this->redirectRoute('persons.care-plans', [legalEntity(), $this->personId], navigate: true);

            return;
        }

        $this->redirectRoute('care-plans.index', legalEntity(), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.care-plan.care-plan-draft-create');
    }
}
