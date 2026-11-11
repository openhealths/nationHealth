<?php

declare(strict_types=1);

namespace App\Livewire\Composition\Concerns;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\SchemaResponseContract;
use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionConfiguration;
use App\Enums\Composition\CompositionJobStatus;
use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Shared wizard mechanics for both conclusion types: encounter picker, authentication
 * method, async job, print form, unsigned create, and KEP sign of the PRELIMINARY draft.
 *
 * Each concrete wizard still owns its details form and payload, because those are what
 * the contract treats as two different documents.
 */
trait DrivesCompositionWizard
{
    use InteractsWithCompositions;

    public const int STEP_ENCOUNTER = 1;

    public const int STEP_AUTH_METHOD = 2;

    public const int STEP_DETAILS = 3;

    public const int STEP_AWAITING_JOB = 4;

    public const int STEP_REVIEW = 5;

    public int $step = self::STEP_ENCOUNTER;

    public bool $showEncounterPicker = false;

    /** Episode of the chosen encounter; needed to read the conclusion back. */
    #[Locked]
    public ?string $episodeUuid = null;

    /** Verified encounter context; the browser cannot change the conclusion's author. */
    #[Locked]
    public ?array $selectedEncounter = null;

    #[Locked]
    public array $authMethods = [];

    /** Shown once the user chooses to proceed without an authentication method. */
    public bool $acknowledgedMissingAuthMethod = false;

    #[Locked]
    #[Url(as: 'job')]
    public ?string $asyncJobId = null;

    #[Locked]
    public string $asyncJobStatus = '';

    public array $asyncJobErrors = [];

    #[Locked]
    public ?string $compositionUuid = null;

    public ?array $compositionDetail = null;

    public ?array $integrationData = null;

    public ?string $printFormHtml = null;

    public bool $showPrintModal = false;

    public bool $showSignatureModal = false;

    /**
     * Patient UUID whose encounters may carry this conclusion.
     *
     * A birth conclusion is filed against the newborn, which is not always the card
     * the wizard was opened from (the mother may be), so this is not simply `$this->uuid`.
     */
    abstract protected function encounterSubjectUuid(): string;

    /**
     * Person whose authentication methods inform the conclusion, or null when none apply.
     *
     * An unidentified patient cannot hold any; a birth conclusion uses the mother's.
     */
    abstract protected function authenticationSubjectUuid(): ?string;

    abstract protected function conclusionType(): CompositionType;

    /**
     * Policy ability checked before the create request is sent.
     */
    abstract protected function createAbility(): string;

    /**
     * Patient the local projection is stored against.
     *
     * A birth conclusion always belongs to the newborn preperson, even when the wizard
     * started from the mother's card.
     */
    abstract protected function storagePatient(): Person|Preperson;

    /**
     * Rules that make the details step submittable, keyed for the Livewire form.
     *
     * @return array<string, mixed>
     */
    abstract protected function detailsRules(): array;

    /**
     * Encounters the user may build a conclusion on.
     *
     * TV 3.8.1.5.1 / 3.8.2.5.1 restrict the choice to encounters the user performed
     * themselves, and eHealth additionally rejects anything that is not finished.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function availableEncounters(): Collection
    {
        if (Auth::user()->cannot($this->createAbility(), Composition::class)) {
            return collect();
        }
        $authors = Auth::user()->getCompositionAuthorEmployees($this->conclusionType())->keyBy('uuid');
        $subjectUuid = $this->encounterSubjectUuid();

        if ($authors->isEmpty() || $subjectUuid === '') {
            return collect();
        }

        try {
            $params = array_filter(['managing_organization_id' => legalEntity()?->uuid]);
            $params['page'] = 1;
            $encounters = [];
            do {
                $response = EHealth::encounter()->getBySearchParams($subjectUuid, $params);
                $page = $response->validate();
                $paging = $response->getPaging();
                if (isset($paging['page_number']) && (int) $paging['page_number'] !== $params['page']) {
                    throw ValidationException::withMessages(['encounters' => __('compositions.errors.invalid_response')]);
                }
                if ($page === [] && $response->isNotLast()) {
                    throw ValidationException::withMessages(['encounters' => __('compositions.errors.invalid_response')]);
                }
                // Read every remote page before filtering: the author's encounter may be on the last one.
                $encounters = array_merge($encounters, $page);
                $params['page']++;
            } while ($response->isNotLast());
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));

            return collect();
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Error loading encounters for a medical conclusion');

            return collect();
        }

        return collect($encounters)
            ->filter(static fn (array $encounter) => filled(data_get($encounter, 'uuid')))
            ->filter(static fn (array $encounter) => data_get($encounter, 'status') === EncounterStatus::FINISHED->value)
            ->filter(static fn (array $encounter) => $authors->has(data_get($encounter, 'performer.identifier.value')))
            ->map(static function (array $encounter) use ($authors): array {
                $author = $authors->get(data_get($encounter, 'performer.identifier.value'));
                $encounter['authorName'] = $author->party->fullName;
                $encounter['authorPosition'] = $author->position;

                return $encounter;
            })
            ->sortByDesc(static fn (array $encounter) => data_get($encounter, 'period.start'))
            ->values();
    }

    #[Computed]
    public function authenticationManagementUrl(): ?string
    {
        $uuid = $this->authenticationSubjectUuid();
        $person = $uuid === null ? null : Repository::person()->findByUuid($uuid);

        return $person === null ? null : route('persons.patient-data', [legalEntity(), 'person' => $person->id]);
    }

    public function refreshAuthenticationMethods(): void
    {
        if (Auth::user()->cannot($this->createAbility(), Composition::class)) {
            Session::flash('error', __('compositions.errors.create_not_allowed'));

            return;
        }
        $this->loadAuthMethods();
        if (filled($this->form->informWithUuid) && !$this->isKnownAuthMethod($this->form->informWithUuid)) {
            $this->form->informWithUuid = null;
        }
    }

    protected function assertConfiguredCreationAllowed(): void
    {
        $category = $this->form->category;
        $type = $this->conclusionType();
        $categories = $type === CompositionType::NEWBORN
            ? CompositionConfiguration::NEWBORN_CATEGORIES->codes()
            : CompositionConfiguration::DISABILITY_CATEGORIES->codes();
        $position = data_get($this->selectedEncounter, 'authorPosition');
        if (!in_array($category, $categories, true) || !is_string($position)
            || !in_array($category, CompositionConfiguration::AUTHOR_POSITIONS->codes($position), true)) {
            throw new CompositionGuardException(__('compositions.errors.category_not_allowed'));
        }

        $start = CarbonImmutable::parse($type === CompositionType::NEWBORN
            ? $this->form->newbornBirthDate : $this->form->eventPeriodStart)->startOfDay();
        $today = CarbonImmutable::today();
        $encounterDate = data_get($this->selectedEncounter, 'period.start');
        if (!is_string($encounterDate)) {
            throw new CompositionGuardException(__('compositions.errors.encounter_not_selectable'));
        }
        $encounterStart = CarbonImmutable::parse($encounterDate)->startOfDay();
        if ($encounterStart->isFuture()
            || $encounterStart->lt($today->subDays(CompositionConfiguration::ENCOUNTER_AGE->days($category)))) {
            throw new CompositionGuardException(__('compositions.errors.encounter_too_old'));
        }

        $startEnabled = $type === CompositionType::NEWBORN
            ? CompositionConfiguration::NEWBORN_START_ENABLED : CompositionConfiguration::DISABILITY_START_ENABLED;
        $replacement = $type === CompositionType::TEMP_DISABILITY && $this->form->relatesToCode === 'replaces' && filled($this->form->relatesToTargetUuid);
        $startLimit = $replacement
            ? ($type === CompositionType::NEWBORN ? CompositionConfiguration::NEWBORN_REPLACEMENT_START : CompositionConfiguration::DISABILITY_REPLACEMENT_START)
            : ($type === CompositionType::NEWBORN ? CompositionConfiguration::NEWBORN_START : CompositionConfiguration::DISABILITY_START);
        $foreign = $type === CompositionType::TEMP_DISABILITY && $this->form->isForeignTreatment;
        $forceRenew = $type === CompositionType::TEMP_DISABILITY && $this->form->isForceRenew;
        if (!$foreign && !$forceRenew && $startEnabled->enabled()
            && $start->lt($today->subDays($startLimit->days($category)))) {
            throw new CompositionGuardException(__('compositions.errors.period_start_too_old'));
        }
        if ($start->isFuture() && $category !== CompositionCategory::PREGNANCY->value) {
            throw new CompositionGuardException(__('compositions.errors.period_starts_in_future'));
        }

        $authorUuid = $this->authorEmployeeUuid();
        $payload = $this->form->toPayload((string) $authorUuid);
        // Public form fields must still refer to the patient and encounter selected in the locked context.
        if (data_get($payload, 'subject.value') !== data_get($this->selectedEncounter, 'subjectUuid')
            || data_get($payload, 'subject.value') !== $this->encounterSubjectUuid()) {
            throw new CompositionGuardException(__('compositions.errors.participant_not_found'));
        }
        if (data_get($payload, 'encounter.value') !== data_get($this->selectedEncounter, 'uuid')) {
            throw new CompositionGuardException(__('compositions.errors.encounter_not_selectable'));
        }
        $this->assertConfiguredParticipantsAllowed($payload);
        $this->assertConfiguredEncounterAllowed($category, $type);
        $duplicateEnabled = $type === CompositionType::NEWBORN
            ? CompositionConfiguration::NEWBORN_DUPLICATE_ENABLED : CompositionConfiguration::DISABILITY_DUPLICATE_ENABLED;
        if ($duplicateEnabled->enabled() && data_get($payload, 'subject.type.coding.0.code') === 'preperson') {
            $this->assertPrepersonConclusionUnique(data_get($payload, 'subject.value'));
        }
        $equal = data_get($payload, 'subject.value') === data_get($payload, 'section.focus.value');
        if ($equal !== CompositionConfiguration::EQUALITY->enabled($category)) {
            throw new CompositionGuardException(__('compositions.errors.care_focus_required'));
        }
        $extensions = CompositionConfiguration::EXTENSIONS->codes($category, allowEmpty: true);
        foreach ($payload['extension'] as $extension) {
            if (!in_array($extension['valueCode'], ['INFORM_WITH', 'SIGNED_BY', 'SIGNED_AT'], true)
                && !in_array($extension['valueCode'], $extensions, true)) {
                throw new CompositionGuardException(__('compositions.errors.extension_not_allowed'));
            }
        }
        if ($type === CompositionType::TEMP_DISABILITY && !$foreign && !$forceRenew
            && CompositionConfiguration::SINGLE_SPAN_ENABLED->enabled()) {
            $end = CarbonImmutable::parse($this->form->eventPeriodEnd)->startOfDay();
            $duration = (int) $start->diffInDays($end) + 1;
            if ($duration > CompositionConfiguration::SINGLE_SPAN->days($category)) {
                throw new CompositionGuardException(__('compositions.errors.period_too_long'));
            }
        }
        if ($type === CompositionType::TEMP_DISABILITY && !$forceRenew
            && CompositionConfiguration::TOTAL_SPAN_ENABLED->enabled()
            && (int) $start->diffInDays(CarbonImmutable::parse($this->form->eventPeriodEnd)->startOfDay()) + 1
                > CompositionConfiguration::TOTAL_SPAN->days($category)) {
            throw new CompositionGuardException(__('compositions.errors.period_too_long'));
        }
    }

    private function assertConfiguredParticipantsAllowed(array $payload): void
    {
        // Signing rechecks the category in the fresh native document, not the editable form.
        $category = data_get($payload, 'category.coding.0.code');
        $extensions = collect($payload['extension'])->keyBy('valueCode');
        $foreign = data_get($extensions->get('IS_FOREIGN_TREATMENT'), 'valueBoolean') === true;
        $author = Auth::user()->getCompositionAuthorEmployees($this->conclusionType())
            ->firstWhere('uuid', data_get($payload, 'author.value'))?->refresh();
        $entity = legalEntity();
        $statuses = $this->conclusionType() === CompositionType::NEWBORN
            ? CompositionConfiguration::CUSTODIAN_NEWBORN_STATUSES : CompositionConfiguration::CUSTODIAN_DISABILITY_STATUSES;
        if ($author === null || $author->status->value !== CompositionConfiguration::AUTHOR_STATUS->value()
            || !in_array($category, CompositionConfiguration::AUTHOR_TYPES->codes($author->employeeType), true)
            || !in_array($entity->status, $statuses->codes(), true)
            || !in_array($category, CompositionConfiguration::CUSTODIAN_TYPES->codes($entity->type->name), true)) {
            throw new CompositionGuardException(__('compositions.errors.author_not_found'));
        }

        $specialities = $author->specialities;
        $officio = $specialities->where('specialityOfficio', true);
        if (!$officio->contains(fn ($speciality): bool => in_array($category, CompositionConfiguration::AUTHOR_OFFICIO->optionalCodes($speciality->speciality) ?? [], true))
            || (CompositionConfiguration::AUTHOR_SPECIALITIES_ENABLED->enabled()
                && !$specialities->reject(fn ($speciality): bool => $speciality->specialityOfficio === true)
                    ->contains(fn ($speciality): bool => in_array($category, CompositionConfiguration::AUTHOR_SPECIALITIES->optionalCodes($speciality->speciality) ?? [], true)))) {
            throw new CompositionGuardException(__('compositions.errors.author_speciality_not_allowed'));
        }
        $newborn = $this->conclusionType() === CompositionType::NEWBORN;
        $specialityEnabled = $newborn ? CompositionConfiguration::NEWBORN_DOCTOR_SPECIALITIES_ENABLED : CompositionConfiguration::DISABILITY_DOCTOR_SPECIALITIES_ENABLED;
        if ($specialityEnabled->enabled()) {
            $allowedSpecialities = $newborn
                ? CompositionConfiguration::NEWBORN_DOCTOR_SPECIALITIES->codes($category)
                : CompositionConfiguration::DISABILITY_DOCTOR_SPECIALITIES->optionalCodes($category);
            // The disability filter maps only the categories it restricts; no environment defaults are inferred.
            if ($allowedSpecialities !== null && $specialities->pluck('speciality')->intersect($allowedSpecialities)->isEmpty()) {
                throw new CompositionGuardException(__('compositions.errors.author_speciality_not_allowed'));
            }
        }

        $activeParticipants = [];
        foreach (['subject', 'section.focus'] as $path) {
            $isFocus = $path === 'section.focus';
            $reference = data_get($payload, $path);
            $resource = data_get($reference, 'type.coding.0.code');
            $types = $isFocus ? CompositionConfiguration::FOCUS_TYPES : CompositionConfiguration::SUBJECT_TYPES;
            if (!in_array($category, $types->codes($resource), true)) {
                throw new CompositionGuardException(__('compositions.errors.participant_type_not_allowed'));
            }
            $participant = $resource === 'preperson'
                ? Preperson::whereUuid($reference['value'])->first()
                : Repository::person()->findByUuid($reference['value']);
            if ($participant === null) {
                throw new CompositionGuardException(__('compositions.errors.participant_not_found'));
            }
            if ($participant instanceof Preperson) {
                $statuses = $isFocus ? CompositionConfiguration::FOCUS_STATUSES : CompositionConfiguration::SUBJECT_STATUSES;
                if ($participant->status?->value !== $statuses->value($category)) {
                    throw new CompositionGuardException(__('compositions.errors.participant_status_not_allowed'));
                }
                if ($this->conclusionType() === CompositionType::TEMP_DISABILITY && $foreign) {
                    throw new CompositionGuardException(__('compositions.errors.foreign_preperson_not_allowed'));
                }
                // Identified-person age, verification and documents do not apply to a preperson.
                if ($this->conclusionType() !== CompositionType::NEWBORN) {
                    continue;
                }
                if (blank($participant->birthDate) || blank($participant->gender)
                    || blank(data_get($extensions->get('NEWBORN_BIRTH_DATE'), 'valueDate'))
                    || !CarbonImmutable::parse($participant->birthDate)->isSameDay(CarbonImmutable::parse(data_get($extensions->get('NEWBORN_BIRTH_DATE'), 'valueDate')))
                    || $participant->gender->value !== data_get($extensions->get('NEWBORN_SEX'), 'valueString')) {
                    throw new CompositionGuardException(__('compositions.errors.newborn_identity_mismatch'));
                }
            } else {
                $statuses = $isFocus ? CompositionConfiguration::FOCUS_STATUSES : CompositionConfiguration::SUBJECT_STATUSES;
                if ($statuses->value($category) !== 'active') {
                    throw new CompositionGuardException(__('compositions.errors.participant_status_not_allowed'));
                }
                if (!isset($activeParticipants[$participant->uuid])) {
                    // API-010-051-0002 checks status=active and is_active=true before returning 200.
                    // Validate that fresh response before relying on its documented eligibility rule;
                    // an empty method list still meets that precondition and never creates an authentication method.
                    try {
                        $response = EHealth::person()->getAuthMethods($participant->uuid);
                        SchemaResponseContract::validate($response, 'GET /api/persons/{id}/authentication_methods', 'composition');
                        $response->validate();
                    } catch (EHealthConnectionException | EHealthException) {
                        throw new CompositionGuardException(__('compositions.errors.participant_status_unavailable'));
                    }
                    $activeParticipants[$participant->uuid] = true;
                }
                $verification = $isFocus ? CompositionConfiguration::FOCUS_VERIFICATION : CompositionConfiguration::SUBJECT_VERIFICATION;
                if (!in_array($participant->verificationStatus, $verification->codes($category), true)) {
                    throw new CompositionGuardException(__('compositions.errors.participant_verification_not_allowed'));
                }
                if (!$participant->noTaxId && blank($participant->taxId)) {
                    throw new CompositionGuardException(__('compositions.errors.participant_tax_id_required'));
                }
                $documents = $participant->documents()->pluck('type')->all();
                $allowed = ($isFocus ? CompositionConfiguration::FOCUS_DOCUMENTS : CompositionConfiguration::SUBJECT_DOCUMENTS)->optionalCodes($category);
                if (array_intersect($documents, CompositionConfiguration::FOREIGN_DOCUMENTS->codes(allowEmpty: true)) !== []
                    || ($allowed !== null && array_intersect($documents, $allowed) === [])
                    || ($isFocus && array_intersect($documents, CompositionConfiguration::ERLN_DOCUMENTS->codes()) === [])) {
                    throw new CompositionGuardException(__('compositions.errors.participant_documents_not_allowed'));
                }
            }
            $gender = $participant instanceof Preperson ? $participant->gender?->value : $participant->gender;
            $genders = ($isFocus ? CompositionConfiguration::FOCUS_GENDERS : CompositionConfiguration::SUBJECT_GENDERS)->optionalCodes($category);
            if ($genders !== null && !in_array($gender, $genders, true)) {
                throw new CompositionGuardException(__('compositions.errors.participant_gender_not_allowed'));
            }
            if (blank($participant->birthDate)) {
                throw new CompositionGuardException(__('compositions.errors.participant_age_not_allowed'));
            }
            $birthDate = CarbonImmutable::parse($participant->birthDate)->startOfDay();
            if ($birthDate->isFuture()) {
                throw new CompositionGuardException(__('compositions.errors.participant_age_not_allowed'));
            }
            $age = $birthDate->age;
            ($isFocus ? CompositionConfiguration::FOCUS_AGES : CompositionConfiguration::SUBJECT_AGES)->assertAgeAllowed($category, $age);
            $newborn = $this->conclusionType() === CompositionType::NEWBORN;
            $ageEnabled = $newborn ? CompositionConfiguration::NEWBORN_AGE_ENABLED : CompositionConfiguration::DISABILITY_AGE_ENABLED;
            if (!$isFocus && $ageEnabled->enabled()) {
                ($newborn ? CompositionConfiguration::NEWBORN_AGE : CompositionConfiguration::DISABILITY_AGE)->assertAgeAllowed($category, $age);
            }
            $genderEnabled = $newborn ? CompositionConfiguration::NEWBORN_GENDER_ENABLED : CompositionConfiguration::DISABILITY_GENDER_ENABLED;
            if ($isFocus && $genderEnabled->enabled()) {
                $allowed = ($newborn ? CompositionConfiguration::NEWBORN_GENDER : CompositionConfiguration::DISABILITY_GENDER)->optionalCodes($category);
                if ($allowed !== null && !in_array($gender, $allowed, true)) {
                    throw new CompositionGuardException(__('compositions.errors.participant_gender_not_allowed'));
                }
            }
        }
    }

    private function assertConfiguredEncounterAllowed(string $category, CompositionType $type): void
    {
        $newborn = $type === CompositionType::NEWBORN;
        $typesEnabled = $newborn ? CompositionConfiguration::NEWBORN_ENCOUNTER_TYPES_ENABLED : CompositionConfiguration::DISABILITY_ENCOUNTER_TYPES_ENABLED;
        if ($typesEnabled->enabled() && !in_array(
            data_get($this->selectedEncounter, 'type.coding.0.code'),
            ($newborn ? CompositionConfiguration::NEWBORN_ENCOUNTER_TYPES : CompositionConfiguration::DISABILITY_ENCOUNTER_TYPES)->codes(),
            true
        )) {
            throw new CompositionGuardException(__('compositions.errors.encounter_type_not_allowed'));
        }
        $divisionEnabled = $newborn ? CompositionConfiguration::NEWBORN_DIVISION_ENABLED : CompositionConfiguration::DISABILITY_DIVISION_ENABLED;
        if ($divisionEnabled->enabled()
            && ($newborn ? CompositionConfiguration::NEWBORN_DIVISION : CompositionConfiguration::DISABILITY_DIVISION)->enabled($category)
            && blank(data_get($this->selectedEncounter, 'division.identifier.value'))) {
            throw new CompositionGuardException(__('compositions.errors.encounter_division_required'));
        }
        $diagnosesEnabled = $newborn ? CompositionConfiguration::NEWBORN_DIAGNOSES_ENABLED : CompositionConfiguration::DISABILITY_DIAGNOSES_ENABLED;
        if (!$diagnosesEnabled->enabled()) {
            return;
        }
        $codes = ($newborn ? CompositionConfiguration::NEWBORN_DIAGNOSES : CompositionConfiguration::DISABILITY_DIAGNOSES)->optionalCodes($category);
        if ($codes === null) {
            return;
        }
        // Summary rows omit diagnoses; read the full native encounter only when the enabled rule requires it.
        $response = EHealth::encounter()->getById($this->encounterSubjectUuid(), $this->form->encounterUuid);
        $native = SchemaResponseContract::validate($response, 'GET /api/patients/{patient_id}/encounters/{encounter_id}', 'composition');
        if ($native['id'] !== $this->form->encounterUuid
            || data_get($native, 'performer.identifier.value') !== $this->authorEmployeeUuid()) {
            throw new CompositionGuardException(__('compositions.errors.encounter_not_selectable'));
        }
        $primary = collect($native['diagnoses'] ?? [])->filter(static fn (array $diagnosis): bool => data_get($diagnosis, 'role.coding.0.code') === 'primary');
        if ($primary->count() !== 1 || !in_array(data_get($primary->first(), 'code.coding.0.code'), $codes, true)) {
            throw new CompositionGuardException(__('compositions.errors.encounter_diagnosis_not_allowed'));
        }
    }

    private function assertPrepersonConclusionUnique(string $uuid, ?string $except = null): void
    {
        $query = ['subject' => $uuid];
        if ($this->conclusionType() === CompositionType::NEWBORN) {
            $query['type'] = CompositionType::NEWBORN->value;
        }
        foreach (EHealth::composition()->searchAll($query) as $item) {
            if (data_get($item, 'subject.value') !== $uuid) {
                throw ValidationException::withMessages(['data' => __('compositions.errors.invalid_response')]);
            }
            if (data_get($item, 'identifier.value') !== $except
                && CompositionStatus::fromEHealth($item['status']) !== CompositionStatus::ENTERED_IN_ERROR) {
                throw new CompositionGuardException(__('compositions.errors.preperson_duplicate'));
            }
        }
    }

    private function assertConfiguredSigningAllowed(): void
    {
        $newborn = $this->conclusionType() === CompositionType::NEWBORN;
        $delayEnabled = $newborn ? CompositionConfiguration::NEWBORN_SIGN_DELAY_ENABLED : CompositionConfiguration::DISABILITY_SIGN_DELAY_ENABLED;
        $createdAt = CarbonImmutable::parse($this->compositionDetail['date']);
        if ($delayEnabled->enabled() && ($createdAt->isFuture()
            || $createdAt->addHours(($newborn ? CompositionConfiguration::NEWBORN_SIGN_DELAY : CompositionConfiguration::DISABILITY_SIGN_DELAY)->days('max-hours'))->isPast())) {
            throw new CompositionGuardException(__('compositions.errors.signing_deadline_expired'));
        }
        $duplicateEnabled = $newborn ? CompositionConfiguration::NEWBORN_SIGN_DUPLICATE_ENABLED : CompositionConfiguration::DISABILITY_SIGN_DUPLICATE_ENABLED;
        if ($duplicateEnabled->enabled() && data_get($this->compositionDetail, 'subject.type.coding.0.code') === 'preperson') {
            $this->assertPrepersonConclusionUnique(data_get($this->compositionDetail, 'subject.value'), $this->compositionUuid);
        }
        $this->assertConfiguredParticipantsAllowed($this->compositionDetail);
        $this->assertTypeSpecificSigningAllowed($this->compositionDetail);
    }

    protected function assertTypeSpecificSigningAllowed(array $document): void
    {
        // Conclusion types may recheck their own relationships before invoking KEP.
    }

    public function selectEncounter(string $encounterRef): void
    {
        if (!$this->assertCounterpartReadyForEncounter()) {
            return;
        }

        $encounterUuid = $this->resolveEncounterUuid($encounterRef);

        if ($encounterUuid === null) {
            Session::flash('error', __('compositions.errors.encounter_not_selectable'));

            return;
        }

        $encounter = $this->availableEncounters
            ->firstWhere('uuid', $encounterUuid);

        if (!$encounter) {
            Session::flash('error', __('compositions.errors.encounter_not_selectable'));

            return;
        }

        $this->form->encounterUuid = $encounterUuid;
        $this->episodeUuid = data_get($encounter, 'episode.identifier.value');
        $this->selectedEncounter = [
            'uuid' => $encounterUuid,
            'period' => $encounter['period'] ?? null,
            'class' => $encounter['class'] ?? null,
            'type' => $encounter['type'],
            'division' => $encounter['division'] ?? null,
            'subjectUuid' => $this->encounterSubjectUuid(),
            'authorUuid' => data_get($encounter, 'performer.identifier.value'),
            'authorName' => $encounter['authorName'],
            'authorPosition' => $encounter['authorPosition'],
        ];

        $this->showEncounterPicker = false;
        $this->loadAuthMethods();
        $this->step = self::STEP_AUTH_METHOD;
        $this->dispatch('scroll-to-element', selector: '#composition-auth-method');
    }

    /**
     * Deep links may pass a local encounters.id; eHealth lists use UUID.
     */
    protected function resolveEncounterUuid(?string $encounterRef): ?string
    {
        if ($encounterRef === null || trim($encounterRef) === '') {
            return null;
        }

        $encounterRef = trim($encounterRef);

        if (Str::isUuid($encounterRef)) {
            return $encounterRef;
        }

        if (ctype_digit($encounterRef)) {
            return Encounter::query()
                ->whereKey((int) $encounterRef)
                ->value('uuid');
        }

        return null;
    }

    /**
     * Birth conclusions need mother + newborn before an encounter can be chosen.
     */
    protected function assertCounterpartReadyForEncounter(): bool
    {
        return true;
    }

    public function loadAuthMethods(): void
    {
        if (Auth::user()->cannot($this->createAbility(), Composition::class)) {
            Session::flash('error', __('compositions.errors.create_not_allowed'));

            return;
        }
        $this->authMethods = [];
        $subjectUuid = $this->authenticationSubjectUuid();

        if ($subjectUuid === null || $subjectUuid === '') {
            return;
        }

        try {
            $response = EHealth::person()->getAuthMethods($subjectUuid);
            // The shared patient client must keep its native union, including required audit metadata.
            $native = SchemaResponseContract::validate($response, 'GET /api/persons/{id}/authentication_methods', 'composition');
            $response->validate();
            $this->authMethods = collect($native)->filter(static function (array $method): bool {
                $endedAt = $method['ehealth_ended_at'] ?? ($method['ended_at'] ?? null);

                return ($method['is_active'] ?? true) !== false
                    && ($endedAt === null || CarbonImmutable::parse($endedAt)->isFuture());
            })->values()->all();
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));
        } catch (EHealthConnectionException | EHealthException $exception) {
            Log::error('Failed to load authentication methods for a medical conclusion', [
                'focus' => $subjectUuid,
                'error' => $exception->getMessage(),
            ]);

            Session::flash('error', __('compositions.errors.auth_methods_failed'));
        }
    }

    /**
     * Adopt one of the authentication methods eHealth returned for this person.
     *
     * The list is reloaded rather than trusted from component state, because the action
     * is reachable with any UUID and eHealth would otherwise be handed an INFORM_WITH
     * that does not belong to the patient.
     */
    public function selectAuthMethod(string $methodUuid): void
    {
        if (Auth::user()->cannot($this->createAbility(), Composition::class)) {
            Session::flash('error', __('compositions.errors.create_not_allowed'));

            return;
        }
        $this->loadAuthMethods();

        if (!$this->isKnownAuthMethod($methodUuid)) {
            $this->addError('form.informWithUuid', __('compositions.errors.auth_method_not_offered'));

            Session::flash('error', __('compositions.errors.auth_method_not_offered'));

            return;
        }

        $this->form->informWithUuid = $methodUuid;
        $this->acknowledgedMissingAuthMethod = false;
        $this->step = self::STEP_DETAILS;

        if (method_exists($this, 'applyDefaultPeriodDates')) {
            $this->applyDefaultPeriodDates();
        }

        $this->dispatch('scroll-to-element', selector: '#composition-details');
    }

    /**
     * Whether the UUID is one of the methods eHealth listed for the relevant person.
     */
    protected function isKnownAuthMethod(string $methodUuid): bool
    {
        // The list is taken straight from the response, so an entry may still carry the
        // raw eHealth `id` rather than the renamed `uuid` — the view reads both too.
        return collect($this->authMethods)
            ->map(static fn (mixed $method): mixed => data_get($method, 'uuid') ?? data_get($method, 'id'))
            ->filter()
            ->contains($methodUuid);
    }

    /**
     * Continue without informing the patient by SMS (TV 3.8.1.4.4, 3.8.2.4.4).
     *
     * SMS with the conclusion number is sent by eHealth only after the unsigned
     * composition is created with INFORM_WITH — not when the method is chosen here.
     */
    public function skipAuthMethod(): void
    {
        $this->form->informWithUuid = null;
        $this->acknowledgedMissingAuthMethod = true;
        $this->step = self::STEP_DETAILS;

        if (method_exists($this, 'applyDefaultPeriodDates')) {
            $this->applyDefaultPeriodDates();
        }

        $this->dispatch('scroll-to-element', selector: '#composition-details');
    }

    /**
     * Validate the details step, then create the conclusion in eHealth.
     *
     * createComposition accepts the unsigned compositionRequest JSON. The author's KEP
     * is collected later on the review step via {@see sign()} (TV 3.8.1.1.2 / 3.8.2.1.2).
     */
    public function reviewDetails(): void
    {
        $this->submitComposition();
    }

    /**
     * POST createComposition with the mapped payload (TV 3.8.1.5, 3.8.2.5).
     *
     * No KEP here — Swagger/Confluence reserve `{data: PKCS#7}` for sign and cancel only.
     */
    public function submitComposition(): void
    {
        if ($this->asyncJobId !== null || $this->compositionUuid !== null) {
            return;
        }

        if (Auth::user()->cannot($this->createAbility(), Composition::class)) {
            Session::flash('error', __('compositions.errors.create_not_allowed'));

            return;
        }

        try {
            $this->form->validate($this->detailsRules());
            MedicalEventsRepository::composition()->assertFhirStorageReady();
            $this->assertSubmissionAllowed();
            $this->assertConfiguredCreationAllowed();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());
            if (collect(array_keys($exception->errors()))->contains(static fn (string $key): bool => !str_starts_with($key, 'form.'))) {
                Session::flash('error', __('compositions.errors.invalid_response'));
            }

            return;
        } catch (CompositionGuardException $exception) {
            $this->reportGuardFailure($exception);

            return;
        }

        $authorUuid = $this->authorEmployeeUuid();

        if ($authorUuid === null) {
            Session::flash('error', __('compositions.errors.author_not_found'));

            return;
        }

        try {
            // Do not log the payload: it is the patient's medical record.
            $job = EHealth::composition()->create($this->form->toPayload($authorUuid))->validate();

            MedicalEventsRepository::compositionOperation()->store($job, CompositionAsyncOperation::CREATE, $this->storagePatient(), [
                'composition_type' => $this->conclusionType(),
                'encounter_uuid' => $this->form->encounterUuid,
                'episode_uuid' => $this->episodeUuid,
                'author_uuid' => $authorUuid,
                'requestData' => [
                    'form' => Arr::except($this->form->toArray(), ['password', 'knedp', 'keyContainerUpload']),
                    'selectedEncounter' => $this->selectedEncounter,
                ],
            ]);
            $this->asyncJobId = $job['id'];
            $this->asyncJobStatus = (string) ($job['status'] ?? CompositionJobStatus::PENDING->value);
            $this->asyncJobErrors = [];
            $this->showSignatureModal = false;
            $this->step = self::STEP_AWAITING_JOB;
        } catch (EHealthResponseException $exception) {
            $details = $exception->getDetails();
            $errText = data_get($details, 'error.message')
                ?? (data_get($details, 'details.errorMessage')
                    ?? (data_get($details, 'description')
                        ?? $exception->getMessage()));

            $exception->handle(
                'Failed to submit a medical conclusion',
                EHealthException::translate((string) $errText)
            );
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Failed to submit a medical conclusion');
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', __('clinical-storage.remote_import_pending'));
        } catch (Throwable $exception) {
            Session::flash('error', __('clinical-storage.write_failed'));

            Log::error('Failed to submit a medical conclusion', ['exceptionType' => $exception::class, 'sourceFile' => $exception->getFile(), 'sourceLine' => $exception->getLine()]);
        }
    }

    /**
     * Type-specific rules that the UI also expresses but cannot enforce.
     *
     * @throws CompositionGuardException
     */
    protected function assertSubmissionAllowed(): void
    {
        // Overridden by the conclusion types that have extra rules.
    }

    /**
     * Surface a refused rule both next to the form and as a page-level message.
     *
     * The error bag keeps it visible where the user is working; the flash covers the
     * case where the step has already been left behind.
     */
    protected function reportGuardFailure(CompositionGuardException $exception): void
    {
        $this->addError('form.guard', $exception->getMessage());

        Session::flash('error', $exception->getMessage());
    }

    /**
     * Poll the async job until eHealth finishes the current request.
     *
     * The same poller covers create (TV 3.8.1.5.3 / 3.8.2.7) and the later sign, which
     * also returns a job. After a sign, the conclusion already exists locally so the
     * details are refreshed rather than resolved from scratch.
     */
    #[On('composition-submission-updated')]
    public function pollAsyncJob(bool $notify = true): void
    {
        $operation = $this->currentOperation();
        if ($operation === null) {
            return;
        }
        $previousStep = $this->step;
        $this->asyncJobStatus = $operation->status === CompositionJobStatus::PENDING->value
            ? ($operation->job->response_data['status'] ?? $operation->status)
            : $operation->status;
        $this->asyncJobErrors = $operation->job->response_data['errors'] ?? [];
        if ($this->asyncJobStatus === CompositionJobStatus::FAILED->value) {
            $this->asyncJobErrors = $this->asyncJobErrors ?: [__('compositions.errors.async_job_failed')];

            return;
        }
        if ($this->asyncJobStatus !== CompositionJobStatus::DONE->value || !$operation->composition) {
            return;
        }
        $composition = MedicalEventsRepository::composition()->findForPatient($this->storagePatient(), $operation->composition->uuid);
        if ($composition === null) {
            return;
        }
        $this->compositionUuid = $composition->uuid;
        $this->compositionDetail = $composition->toDetail();
        $this->integrationData = $composition->integrationDetails();
        $this->step = self::STEP_REVIEW;
        if ($notify && $previousStep !== self::STEP_REVIEW) {
            $message = $operation->operation === CompositionAsyncOperation::SIGN ? 'signed' : 'created';
            $messageKey = 'compositions.create_'.strtolower($this->conclusionType()->value).'.'.$message;
            if ($message === 'signed' && blank($composition->title)) {
                $messageKey = 'compositions.messages.signed_without_number';
            }
            Session::flash('success', __($messageKey, [
                'number' => $composition->title,
                'type' => $this->conclusionType()->label(),
                'identifier' => $composition->uuid,
            ]));
        }
    }

    public function retryAsyncJob(): void
    {
        if (Auth::user()->cannot($this->createAbility(), Composition::class)) {
            Session::flash('error', __('compositions.errors.create_not_allowed'));

            return;
        }
        $operation = $this->currentOperation();
        if ($operation === null || $operation->status !== CompositionJobStatus::PENDING->value) {
            return;
        }
        MedicalEventsRepository::compositionOperation()->queue($operation);
        $this->asyncJobErrors = [];
        $this->asyncJobStatus = CompositionJobStatus::PENDING->value;
    }

    protected function resumeAsyncSubmission(): bool
    {
        if ($this->asyncJobId === null) {
            return false;
        }
        $operation = MedicalEventsRepository::compositionOperation()->findForWizard($this->patient(), $this->asyncJobId, $this->conclusionType(), Auth::user());
        abort_unless($operation !== null, 404);
        $formData = $operation->job->request_data['form'] ?? [];
        $this->form->fill(Arr::except($formData, ['password', 'knedp', 'keyContainerUpload']));
        $this->form->encounterUuid = $operation->encounterUuid;
        $this->selectedEncounter = $operation->job->request_data['selectedEncounter'] ?? null;
        $this->episodeUuid = $operation->episodeUuid;
        $this->compositionUuid = $operation->composition?->uuid;
        $this->asyncJobStatus = $operation->status;
        $this->step = self::STEP_AWAITING_JOB;
        $this->pollAsyncJob(false);

        return true;
    }

    #[Computed]
    public function compositionOperationId(): ?int
    {
        return $this->currentOperation()?->id;
    }

    public function loadCompositionDetail(): bool
    {
        if (!$this->compositionUuid || !$this->episodeUuid) {
            $this->asyncJobErrors = [__('compositions.errors.missing_read_context')];

            return false;
        }

        try {
            $details = EHealth::composition()->getById(
                $this->encounterSubjectUuid(),
                $this->compositionUuid,
                $this->episodeUuid,
                $this->form->encounterUuid
            )->validate();

            MedicalEventsRepository::composition()->assertDocumentContext($details, [
                'identifier.value' => $this->compositionUuid,
                'subject.value' => $this->encounterSubjectUuid(),
                'encounter.value' => $this->form->encounterUuid,
                'author.value' => $this->authorEmployeeUuid(),
                'type.coding.0.code' => $this->conclusionType()->value,
            ]);
            $this->compositionDetail = $details;

            $composition = MedicalEventsRepository::composition()->store(
                $this->compositionDetail,
                $this->storagePatient(),
                $this->episodeUuid
            );

            if ($composition !== null) {
                try {
                    $this->integrationData = $this->syncIntegration($composition);
                } catch (EHealthConnectionException | EHealthException) {
                    $this->integrationData = $composition->integrationDetails();
                }
            }
        } catch (EHealthConnectionException | EHealthException | ValidationException $exception) {
            $this->asyncJobErrors = [__('compositions.errors.created_unreadable')];
            Log::warning('Could not read the created medical conclusion', [
                'exception' => $exception::class,
            ]);

            return false;
        }

        if ($composition === null) {
            $this->asyncJobErrors = [__('compositions.errors.created_unreadable')];
        }

        return $composition !== null;
    }

    public function loadPrintForm(): void
    {
        if (!$this->compositionUuid || !$this->episodeUuid) {
            return;
        }

        try {
            $templateId = $this->conclusionType()->printTemplateId();
            $response = EHealth::composition()->getPrintForm(
                $this->encounterSubjectUuid(),
                $this->compositionUuid,
                $this->episodeUuid,
                $this->form->encounterUuid,
                $templateId
            );

            $this->printFormHtml = $response->validate()['content'];
            $this->showPrintModal = true;
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));
        } catch (EHealthConnectionException | EHealthException $exception) {
            Session::flash('error', __('compositions.errors.print_form_failed'));

            Log::error('Failed to load the conclusion print form', [
                'composition' => $this->compositionUuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
        $this->printFormHtml = null;
    }

    public function openSigningModal(): void
    {
        $this->form->resetSigningFields();
        $this->showSignatureModal = true;
    }

    public function sign(): void
    {
        $composition = Composition::whereUuid($this->compositionUuid)->first();

        if (!$composition) {
            Session::flash('error', __('compositions.errors.not_found'));

            return;
        }

        if (Auth::user()->cannot('sign', $composition)) {
            Session::flash('error', __('compositions.errors.sign_not_allowed'));

            return;
        }

        if (MedicalEventsRepository::compositionOperation()->hasPendingForComposition($composition)) {
            $this->showSignatureModal = false;
            Session::flash('info', __('compositions.messages.signature_submitted'));

            return;
        }

        try {
            $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        if (!$this->loadCompositionDetail()) {
            return;
        }
        $composition->refresh();
        if (Auth::user()->cannot('sign', $composition)) {
            Session::flash('error', __('compositions.errors.sign_not_allowed'));

            return;
        }

        try {
            $this->assertConfiguredSigningAllowed();
            $signedContent = app(SignatureService::class)->signData(
                $this->compositionDetail,
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $job = EHealth::composition()->sign($composition->uuid, ['data' => $signedContent])->validate();

            MedicalEventsRepository::compositionOperation()->store($job, CompositionAsyncOperation::SIGN, $this->storagePatient(), [
                'composition_id' => $composition->id,
                'composition_type' => $composition->type,
                'encounter_uuid' => $composition->encounterUuid,
                'episode_uuid' => $composition->episodeOfCareUuid,
                'author_uuid' => $composition->authorUuid,
                'requestData' => [
                    'form' => Arr::except($this->form->toArray(), ['password', 'knedp', 'keyContainerUpload']),
                    'selectedEncounter' => $this->selectedEncounter,
                ],
            ]);

            $this->showSignatureModal = false;
            $this->form->resetSigningFields();
            $this->asyncJobId = $job['id'];
            $this->asyncJobStatus = (string) ($job['status'] ?? CompositionJobStatus::PENDING->value);
            $this->asyncJobErrors = [];
            $this->step = self::STEP_AWAITING_JOB;

            Session::flash('info', __('compositions.messages.signature_submitted'));
        } catch (CompositionGuardException $exception) {
            $this->reportGuardFailure($exception);
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());

            Log::error('Failed to sign a medical conclusion', [
                'composition' => $this->compositionUuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Shared wizard state that both conclusions reset. Each child then restores the
     * fields that are unique to its form.
     *
     * @param  list<string>  $extra
     */
    protected function resetWizard(array $extra = []): void
    {
        $this->form->resetSigningFields();
        $this->reset(array_merge([
            'step',
            'showEncounterPicker',
            'episodeUuid',
            'selectedEncounter',
            'authMethods',
            'acknowledgedMissingAuthMethod',
            'asyncJobId',
            'asyncJobStatus',
            'asyncJobErrors',
            'compositionUuid',
            'compositionDetail',
            'integrationData',
            'printFormHtml',
            'showPrintModal',
            'showSignatureModal',
        ], $extra));
    }

    /** Use the selected encounter's performer, rechecking their current eligibility. */
    protected function authorEmployeeUuid(): ?string
    {
        if (
            $this->selectedEncounter === null
            || $this->selectedEncounter['uuid'] !== $this->form->encounterUuid
            || $this->selectedEncounter['subjectUuid'] !== $this->encounterSubjectUuid()
        ) {
            return null;
        }

        return Auth::user()->getCompositionAuthorEmployees($this->conclusionType())
            ->firstWhere('uuid', $this->selectedEncounter['authorUuid'])?->uuid;
    }

    private function currentOperation(): ?CompositionOperation
    {
        return MedicalEventsRepository::compositionOperation()->findForWizard($this->patient(), $this->asyncJobId ?? '', $this->conclusionType(), Auth::user());
    }

    protected function resolveCreatedComposition(
        string $patientUuid,
        string $encounterUuid,
        CompositionType $type
    ): ?string {
        try {
            $results = EHealth::composition()->search([
                'subject' => $patientUuid,
                'encounter' => $encounterUuid,
                'type' => $type->value,
                'limit' => 2,
            ])->validate();
        } catch (Throwable $exception) {
            Log::warning('Could not locate the created composition by encounter', [
                'encounter' => $encounterUuid,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $candidates = collect($results)
            ->pluck('identifier.value')
            ->filter()
            ->unique()
            ->values();

        // A later date does not associate a conclusion with this async job.
        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
