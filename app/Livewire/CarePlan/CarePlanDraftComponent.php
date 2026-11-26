<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Dto\CarePlan\Ehealth as CarePlanEhealthData;
use App\Dto\CarePlan\Model as CarePlanData;
use App\Enums\CarePlanStatus;
use App\Enums\Episode\Status as EpisodeStatus;
use App\Enums\Person\ProcedureStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Exceptions\Signature\SignatureException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\CarePlan\Forms\PatientSearchForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\Repository;
use ArrayObject;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Livewire\Attributes\Locked;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

abstract class CarePlanDraftComponent extends BasePatientComponent
{
    public CarePlanForm $form;
    public bool $showSignatureModal = false;

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public string $draftUuid = '';

    #[Locked]
    public string $draftRevision = '';

    #[Locked]
    public int $draftLegalEntityId;

    #[Locked]
    public int $draftOpenedById;

    #[Locked]
    public string $conditionUuid = '';

    public string $medicalRecordType = 'CONDITION';

    public PatientSearchForm $patientSearch;

    public array $categories = [];

    #[Locked]
    public array $allowedCategoryCodes = [];
    public array $diagnoses = [];
    public array $patientSearchResults = [];
    #[Locked]
    public bool $allowsPatientChange = false;
    public bool $showAdditionalSearchParams = false;
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

    public function save(): void
    {
        $isNew = $this->draftId === null;
        if ($this->saveDraft() === null) {
            return;
        }

        Session::flash('success', __($isNew ? 'care-plan.draft_saved' : 'care-plan.draft_updated'));
        if ($isNew) {
            $this->redirectRoute('care-plans.edit', [legalEntity(), $this->draftId], navigate: true);
        }
    }

    public function startSigningProcess(): void
    {
        try {
            $this->synchronizeSystemFields();
            $this->form->validate();
            Repository::carePlan()->assertCategoryForEncounter($this->form->category, $this->form->encounter, (int) $this->personId);
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        $this->showSignatureModal = true;
    }

    public function sign(string $keyContainer = '', string $password = ''): void
    {
        $this->form->useSigningSecrets($keyContainer, $password);

        try {
            $signing = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        $saved = $this->saveDraft();
        if ($saved === null) {
            return;
        }

        try {
            $plan = $saved['plan'];
            $content = objectMapper()->map(new ArrayObject($saved['resource']), CarePlanEhealthData::class);
            $payload = $content->toPayload();
            $this->form->validateCreatePayload($payload);
            $signedContent = signatureService()->signData(
                $payload,
                $signing['knedp'],
                $signing['keyContainer'],
                $signing['password'],
                Auth::user()->party->taxId
            );

            $result = RemoteEHealthLinksProcessing::submitOperation(
                $plan,
                ['status' => CarePlanStatus::PENDING->value],
                fn () => EHealth::carePlan()->create($this->uuid, [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                ], $plan->uuid),
                operationContext: ['expectedRevision' => $this->draftRevision]
            );
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        } catch (SignatureException $exception) {
            $exception->handle('Error when signing care plan');

            return;
        } catch (EHealthConnectionException|EHealthResponseException|EHealthValidationException $exception) {
            $exception->handle('Error when submitting care plan');

            return;
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', __('care-plan.unexpected_error'));

            return;
        }

        $this->showSignatureModal = false;
        if ($result !== null) {
            Session::flash('success', __('care-plan.submission_confirmed'));
        } elseif (!Session::has('warning')) {
            Session::flash('info', __('care-plan.submission_pending'));
        }

        $this->redirectRoute('care-plans.show', [legalEntity(), $this->draftId], navigate: true);
    }

    /** @return array{plan: CarePlan, resource: array}|null */
    protected function saveDraft(): ?array
    {
        $user = Auth::user();
        $legalEntity = legalEntity();
        if ($user === null || $legalEntity === null || (int) $legalEntity->id !== $this->draftLegalEntityId
            || (int) $user->id !== $this->draftOpenedById) {
            Session::flash('error', __('care-plan.document_context_unavailable'));

            return null;
        }

        $repository = Repository::carePlan();
        $mapper = objectMapper();
        try {
            if ($this->draftId === null) {
                if ($user->cannot('create', CarePlan::class)) {
                    Session::flash('error', __('care-plan.no_permission_create'));

                    return null;
                }

                $person = $repository->findPatient((int) $this->personId);
                if ($person === null || !Str::isUuid((string) $person->uuid) || !Str::isUuid($this->draftUuid)) {
                    throw ValidationException::withMessages(['form.patient' => __('validation.required', [
                        'attribute' => __('care-plan.patient'),
                    ])]);
                }

                $data = $this->prepareDraft($mapper, $user, $legalEntity, $person);
                $saved = $repository->createDraft($data, $mapper);
            } else {
                $saved = $repository->updateDraft(
                    $this->draftId,
                    (int) $this->personId,
                    $legalEntity->id,
                    fn (CarePlan $draft, string $revision): ?CarePlanData => $this->prepareLockedDraft(
                        $draft,
                        $revision,
                        $mapper,
                        $user,
                        $legalEntity
                    ),
                    $mapper
                );
            }
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return null;
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());

            return null;
        }

        if ($saved === null) {
            Session::flash('error', __('care-plan.no_permission_update'));

            return null;
        }

        $this->draftId = $saved['plan']->id;
        $this->draftRevision = $repository->draftRevision($saved['resource']);
        $this->allowsPatientChange = false;

        return $saved;
    }

    protected function prepareLockedDraft(
        CarePlan $draft,
        string $revision,
        ObjectMapperInterface $mapper,
        User $user,
        LegalEntity $legalEntity
    ): ?CarePlanData {
        if ($user->cannot('update', $draft)) {
            return null;
        }

        $this->form->validateRevision($this->draftRevision, $revision);

        return $this->prepareDraft($mapper, $user, $legalEntity, $draft->person, $draft);
    }

    protected function prepareDraft(
        ObjectMapperInterface $mapper,
        User $user,
        LegalEntity $legalEntity,
        Person $person,
        ?CarePlan $draft = null
    ): CarePlanData {
        $encounter = $this->prepareDraftEncounter($person);
        $episodes = $this->draftEpisodes($person, $encounter, $user);
        $medicalRecords = $this->form->medicalRecords === [] ? collect() : $this->draftMedicalRecords($person, $encounter, $user);

        // Keep previously authorized selections for an unchanged encounter, including archived supporting episodes.
        if ($draft?->encounterId === $encounter->id) {
            foreach ($draft->supportingInfoReferences as $reference) {
                $type = $reference->type?->coding->first()?->code;
                if ($type === 'episode_of_care' && !$episodes->has($reference->value)) {
                    $episodes->put($reference->value, $reference->displayValue);
                } elseif (in_array($type, ['diagnostic_report', 'procedure'], true)
                    && !$medicalRecords->has($type.':'.$reference->value)) {
                    $medicalRecords->put($type.':'.$reference->value, $reference->displayValue ?? $reference->value);
                }
            }
        }

        $doctorUuids = array_column(Repository::carePlan()->doctors($legalEntity->id), 'uuid');
        $existingCoAuthors = $draft?->contributors->pluck('value')->all() ?? [];
        $fields = $this->form->validateDraft(
            [...$doctorUuids, ...$existingCoAuthors],
            $episodes->keys()->all(),
            $medicalRecords->keys()->all()
        );
        $repository = Repository::carePlan();
        $repository->assertCategoryForEncounter($this->form->category, $encounter->uuid, $person->id);
        $addresses = $repository->encounterData($encounter->uuid, $person->id, $this->conditionUuid ?: null)['addresses'];
        if ($addresses === []) {
            throw ValidationException::withMessages(['form.category' => __('care-plan.category_diagnosis_unavailable')]);
        }

        $author = $user->getCarePlanWriterEmployee($this->form->termsOfService);
        $authorUuid = $author?->uuid ?? $draft?->authorReference?->value;
        if (!Str::isUuid((string) $authorUuid)) {
            throw ValidationException::withMessages(['form.author' => __('care-plan.referral_api_no_active_employee')]);
        }

        $data = $mapper->map($this->form, CarePlanData::class);
        $data->uuid = $this->draftUuid;
        $data->personId = $person->id;
        $data->legalEntityId = $legalEntity->id;
        $data->authorId = $author?->id ?? $draft?->authorId;
        $data->authorUuid = $authorUuid;
        $data->encounterId = $encounter->id;
        $data->addresses = $addresses;
        $data->prepareLocal(
            $mapper,
            $person->uuid,
            $legalEntity->uuid,
            $fields['coAuthors'],
            $fields['episodes'],
            array_intersect_key($medicalRecords->all(), array_flip($fields['medicalRecords']))
        );

        return $data;
    }

    protected function initializeDraftForm(LegalEntity $legalEntity, ?Person $person): void
    {
        $this->draftLegalEntityId = $legalEntity->id;
        $this->draftOpenedById = (int) Auth::id();

        if ($person !== null) {
            $this->form->patient = $person->fullName ?? '';
            $this->availableEpisodes = Repository::carePlan()->activeEpisodes($person);
        } else {
            $this->personId = 0;
            $this->uuid = '';
            $this->patientFullName = __('care-plan.new_care_plan');
        }

        $this->form->medicalNumber = Repository::carePlan()->nextDisplayNumber();
        $this->loadAvailableEncounters();
        $this->loadDoctorsAndDictionaries();
    }

    /**
     * Re-resolve the care plan writer employee for the currently selected terms_of_service and
     * refresh the read-only "Автор" display field. eHealth ties the author to a specific
     * healthcare_service.providing_condition, so the effective author can change when the user
     * switches "умови надання послуг" (terms_of_service).
     */
    public function updatedFormTermsOfService(): void
    {
        $this->synchronizeSystemFields();
    }

    protected function synchronizeSystemFields(): void
    {
        $this->form->intent = 'order';
        $this->form->termsOfService = Repository::carePlan()->encounterTermsOfService(
            $this->form->encounter,
            (int) $this->personId
        );
        $this->refreshAuthorDisplay();
    }

    protected function refreshCategoryOptions(): void
    {
        $this->allowedCategoryCodes = Repository::carePlan()->categoryCodesForEncounter($this->form->encounter, (int) $this->personId);
        $this->resetValidation('form.category');
        if (!in_array($this->form->category, $this->allowedCategoryCodes, true)) {
            $this->form->category = count($this->allowedCategoryCodes) === 1 ? $this->allowedCategoryCodes[0] : '';
        }
    }

    protected function refreshAuthorDisplay(): void
    {
        $party = Auth::user()?->getCarePlanWriterEmployee($this->form->termsOfService ?: null)?->party;
        $this->form->author = implode(' ', array_filter([
            $party?->last_name, $party?->first_name, $party?->second_name,
        ]));
    }

    protected function loadDoctorsAndDictionaries(): void
    {
        $this->doctors = Repository::carePlan()->doctors($this->draftLegalEntityId);

        try {
            $this->getDictionary();
            $this->categories = $this->dictionaries['eHealth/care_plan_categories'] ?? [];
        } catch (Exception $exception) {
            report($exception);
            Log::warning('CarePlan draft: failed to load dictionaries: ' . $exception->getMessage());
        }
    }

    /**
     * Show validation feedback and focus the relevant part of the draft form.
     */
    protected function handleValidationFailed(ValidationException $exception): void
    {
        $message = $exception->validator->errors()->first() ?: (__('validation.failed'));

        Session::flash('error', $message);
        $this->setErrorBag($exception->validator->getMessageBag());

        $firstKey = (string) array_key_first($exception->validator->errors()->messages());
        $selector = match ($firstKey) {
            'form.category', 'category' => '#category',
            'form.periodEnd', 'periodEnd' => '#period_end',
            'form.periodStart', 'periodStart' => '#period_start',
            default => null,
        };

        if ($selector !== null) {
            $this->dispatch('scroll-to-element', selector: $selector);
        } else {
            $this->dispatch('scroll-to-error');
        }
    }

    protected function prepareDraftEncounter(Person $person): Encounter
    {
        $encounter = $this->draftEncounter($person) ?? throw ValidationException::withMessages([
            'form.encounter' => __('validation.exists', ['attribute' => __('care-plan.encounter')]),
        ]);
        $this->synchronizeSystemFields();

        return $encounter;
    }

    protected function draftEncounter(Person $person): ?Encounter
    {
        $user = Auth::user();
        if ($user === null || !Str::isUuid($this->form->encounter)) {
            return null;
        }

        $encounter = Repository::carePlan()->findEncounter($this->form->encounter, $person->id);
        if ($encounter === null || $user->cannot('view', [$encounter, $person])) {
            return null;
        }

        return $encounter;
    }

    protected function loadAvailableEncounters(): void
    {
        $this->availableEncounters = $this->personId
            ? Repository::carePlan()->encounterChoices($this->personId)
            : [];
    }

    public function updatedFormEncounter(): void
    {
        $person = Repository::carePlan()->findPatient((int) $this->personId);
        $encounter = $person ? $this->draftEncounter($person) : null;
        $this->form->encounter = $encounter?->uuid ?? '';
        $this->form->episodes = [];
        $this->form->medicalRecords = [];
        $this->conditionUuid = '';

        $this->synchronizeSystemFields();
        if ($encounter !== null) {
            $this->applyEncounterToForm($encounter);
        } else {
            $this->form->medicalNumber = Repository::carePlan()->nextDisplayNumber();
            $this->diagnoses = [];
            $this->form->episodes = [];
        }

        $this->refreshCategoryOptions();
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
        if ($episode) {
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

    protected function redirectToCarePlanContext(?int $personId, ?int $encounterId): void
    {
        if ($personId && $encounterId) {
            $this->redirectRoute('persons.encounters.package.show', [legalEntity(), $personId, $encounterId], navigate: true);

            return;
        }

        if ($personId) {
            $this->redirectRoute('persons.care-plans', [legalEntity(), $personId], navigate: true);

            return;
        }

        $this->redirectRoute('care-plans.index', legalEntity(), navigate: true);
    }
}
