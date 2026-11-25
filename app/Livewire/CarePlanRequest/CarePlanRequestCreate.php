<?php

declare(strict_types=1);

namespace App\Livewire\CarePlanRequest;

use App\Dto\CarePlanRequest\Model as CarePlanRequestData;
use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Dto\CarePlan\Ehealth as EhealthData;
use App\Dto\CarePlan\Model as CarePlanData;
use App\Dto\Input\FormCollection;
use App\Enums\CarePlanStatus;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\CarePlan;
use App\Models\CarePlanRequest;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use App\Repositories\Repository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Save a care plan request from an encounter and open its prepared local plan for signing.
 */
class CarePlanRequestCreate extends BasePatientComponent
{
    /** Care plan form shared with the existing editor. */
    public CarePlanForm $form;

    /** Facility in which the editor was opened. */
    #[Locked]
    public int $legalEntityId;

    /** Encounter bound to the route and reloaded before any write. */
    #[Locked]
    public int $encounterId;

    /** Stable request and signing-resource UUID for retries from the same form. */
    #[Locked]
    public string $requestUuid;

    /** Local request identifier returned after a successful save. */
    #[Locked]
    public ?int $savedRequestId = null;

    /**
     * Options and read-only clinical values used in the shared editor sections.
     *
     * @var array<string, array>
     */
    #[Locked]
    public array $editor = [];

    /** Populate the existing editor fields and select options for this encounter. */
    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?Encounter $encounter = null,
    ): void {
        abort_if($person === null || $encounter === null || $preperson !== null
            || $encounter->status !== EncounterStatus::FINISHED || !$encounter->belongsToCurrentLegalEntity(), 404);
        parent::mount($legalEntity, $person);
        $this->legalEntityId = $legalEntity->id;
        $this->encounterId = $encounter->id;
        $this->requestUuid = Str::uuid()->toString();
        $repository = Repository::carePlan();
        $now = now();
        $encounterStart = $encounter->period?->getRawOriginal('start');
        $encounterDate = $encounterStart
            ? CarbonImmutable::parse($encounterStart)->format(config('app.date_format'))
            : $now->format(config('app.date_format'));

        $this->form->patient = $person->fullName;
        $this->form->medical_number = (string) $encounter->id;
        $this->form->encounter = $encounter->uuid;
        $this->form->title = __('План лікування від ') . $encounterDate;
        $this->form->periodStart = $now->format(config('app.date_format'));
        $this->form->termsOfService = $repository->encounterTermsOfService($encounter->uuid, $person->id);
        $user = Auth::user();
        $author = $user->getCarePlanWriterEmployee($this->form->termsOfService);
        $this->form->author = $author?->party?->fullName ?? '';

        $allowedCategories = $repository->categoryCodesForEncounter($encounter->uuid, $person->id);
        $dictionaries = dictionary()->basics();
        $categoryLabels = $dictionaries->byName('eHealth/care_plan_categories')?->asCodeDescription()->toArray() ?? [];
        $this->dictionaries = $dictionaries->getMultipleFormatted(['eHealth/encounter_classes'])->toArray();
        $episodes = $repository->activeEpisodes($person, $user);
        $this->editor = [
            'categories' => array_combine($allowedCategories, array_map(
                static fn (string $code): string => $categoryLabels[$code] ?? $code,
                $allowedCategories
            )),
            'allowedCategoryCodes' => $allowedCategories,
            'doctors' => $repository->doctors($legalEntity->id),
            'availableEncounters' => [['uuid' => $encounter->uuid, 'label' => __('care-plan.encounter').' №'.$encounter->id]],
            'availableEpisodes' => $episodes,
            'medicalRecords' => $repository->requestMedicalRecords($person, $encounter, $user),
            'diagnoses' => array_map(
                static fn (array $address): array => [
                    'date' => $encounterDate,
                    'name' => $address['text'] ?? data_get($address, 'coding.0.code', '—'),
                ],
                $repository->encounterData($encounter->uuid, $person->id)['addresses']
            ),
        ];

        if (count($allowedCategories) === 1) {
            $this->form->category = $allowedCategories[0];
        }
        if (in_array($encounter->episode?->value, array_column($episodes, 'uuid'), true)) {
            $this->form->episodes = [$encounter->episode->value];
        }
    }

    /** Validate and save the local request, keeping its UUID stable for subsequent actions. */
    public function save(): void
    {
        if ($this->savedRequestId !== null || ($context = $this->authorizedContext()) === null) {
            return;
        }

        [$legalEntity, $user, $person, $encounter] = $context;
        $repository = Repository::carePlan();
        $this->form->encounter = $encounter->uuid;
        $this->form->intent = 'order';
        $this->form->termsOfService = $repository->encounterTermsOfService($encounter->uuid, $person->id);
        $this->form->validate();
        $repository->assertCategoryForEncounter($this->form->category, $encounter->uuid, $person->id);

        $mapper = objectMapper();
        $data = $mapper->map($this->form, CarePlanRequestData::class);
        $data->periodStartTime = now()->format('H:i');
        $data->authorUuid = $user->getCarePlanWriterEmployee($data->termsOfService)?->uuid;
        $data->addresses = $repository->encounterData($encounter->uuid, $person->id)['addresses'];
        $data->coAuthors = $this->selectedRecords(
            array_values(array_filter($this->form->coAuthors, static fn (mixed $value): bool => $value !== '' && $value !== null)),
            array_column($repository->doctors($legalEntity->id), 'uuid', 'uuid'),
            'coAuthors'
        );
        $data->episodes = $this->selectedRecords(
            $this->form->episodes,
            array_column($repository->activeEpisodes($person, $user), null, 'uuid'),
            'episodes'
        );
        $data->medicalRecords = $this->selectedRecords(
            $this->form->medicalRecords,
            $repository->requestMedicalRecords($person, $encounter, $user),
            'medicalRecords'
        );

        $createPlanRequest = new CarePlanRequest();
        $createPlanRequest->uuid = $this->requestUuid;
        $createPlanRequest->userId = $user->id;
        $createPlanRequest->person()->associate($person);
        $createPlanRequest->legalEntity()->associate($legalEntity);

        try {
            $createPlanRequest = $repository->saveRequest($data, $createPlanRequest);
        } catch (QueryException $exception) {
            $this->flashStorageError($exception);

            return;
        }

        $this->savedRequestId = $createPlanRequest->id;
        Session::flash('success', __('care-plan.draft_saved'));
    }

    /** Open the existing signing editor with a local plan prepared from the saved request. */
    public function prepareForSigning(): void
    {
        if ($this->savedRequestId === null) {
            $this->save();
        }

        if ($this->savedRequestId === null || ($context = $this->authorizedContext()) === null) {
            return;
        }

        [$legalEntity, $user, $person, $encounter] = $context;
        if ($user->cannot('view', new CarePlan(['legal_entity_id' => $legalEntity->id]))) {
            Session::flash('error', __('care-plan.no_permission_create'));

            return;
        }

        try {
            $plan = Repository::carePlan()->withLockedRequest(
                $this->requestUuid,
                $user->id,
                $legalEntity->id,
                $person->id,
                $encounter->uuid,
                fn (CarePlanRequest $request): CarePlan => $this->prepareLockedRequest($request, $user, $person, $encounter)
            );
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        if ($plan === null) {
            Session::flash('error', __('care-plan.document_context_unavailable'));

            return;
        }

        $route = $user->can('update', $plan) ? 'care-plans.edit' : 'care-plans.show';
        $this->redirectRoute($route, [$legalEntity, $plan->id], navigate: true);
    }

    /**
     * Validate the signed resource shape before creating the normalized local plan.
     * An existing same-UUID plan is reused, since it may already be signed or awaiting a result.
     */
    protected function prepareLockedRequest(CarePlanRequest $request, User $user, Person $person, Encounter $encounter): CarePlan
    {
        $repository = Repository::carePlan();
        $existing = $repository->findByUuid($request->uuid, $request->legalEntityId, $request->personId);
        if ($existing !== null) {
            return $existing;
        }

        $data = objectMapper()->map($request, CarePlanRequestData::class);
        $repository->assertCategoryForEncounter($data->category, $encounter->uuid, $person->id);
        $terms = $repository->encounterTermsOfService($encounter->uuid, $person->id);
        $author = $user->getCarePlanWriterEmployee($terms);
        if ($author === null || !$repository->authorHasActiveRoleForTermsOfService($author, $terms)) {
            throw ValidationException::withMessages(['form.author' => __('validation.exists', ['attribute' => __('care-plan.author')])]);
        }

        $data->termsOfService = $terms;
        $data->addresses = $repository->encounterData($encounter->uuid, $person->id)['addresses'];
        $data->coAuthors = $this->selectedRecords(
            $data->coAuthors,
            array_column($repository->doctors($request->legalEntityId), 'uuid', 'uuid'),
            'coAuthors'
        );
        $data->episodes = $this->selectedRecords(
            array_column($data->episodes, 'uuid'),
            array_column($repository->activeEpisodes($person, $user), null, 'uuid'),
            'episodes'
        );
        $data->medicalRecords = $this->selectedRecords(
            array_map(static fn (array $record): string => ($record['type'] ?? '').':'.($record['uuid'] ?? ''), $data->medicalRecords),
            $repository->requestMedicalRecords($person, $encounter, $user),
            'medicalRecords'
        );

        $data->authorUuid = $author->uuid;
        $mapper = objectMapper();
        $payload = $mapper->map(new FormCollection($data->toArray()), EhealthData::class);
        $payload->id = $request->uuid;
        $payload->informWith = $data->informWith === '' ? null : ['auth_method_id' => $data->informWith];
        $resource = $payload->toArray();
        CarePlanForm::buildCreateContent($resource);
        $resource['status'] = CarePlanStatus::DRAFT->value;
        $resource['context'] = $data->context;
        $planData = $mapper->map(new CarePlanDetails($resource), CarePlanData::class);
        $repository->assertFhirStorageReady();

        return $repository->save($planData, new CarePlan([
            'person_id' => $request->personId,
            'legal_entity_id' => $request->legalEntityId,
        ]));
    }

    /**
     * Reload the patient and encounter and enforce the current facility and operation access.
     *
     * @return array{LegalEntity, User, Person, Encounter}|null
     */
    protected function authorizedContext(): ?array
    {
        $legalEntity = legalEntity();
        $user = Auth::user();
        if ($legalEntity?->id !== $this->legalEntityId || $this->personId === null
            || $user === null || $user->cannot('create', CarePlan::class)) {
            Session::flash('error', __('care-plan.no_permission_create'));

            return null;
        }

        $repository = Repository::carePlan();
        $person = $repository->findPatient($this->personId);
        $encounter = $repository->findEncounter($this->encounterId, $this->personId);
        if ($person === null || $encounter === null || $user->cannot('view', [$encounter, $person])
            || $encounter->status !== EncounterStatus::FINISHED || !$encounter->belongsToCurrentLegalEntity()) {
            Session::flash('error', __('care-plan.document_context_unavailable'));

            return null;
        }

        return [$legalEntity, $user, $person, $encounter];
    }

    /**
     * Resolve user-selected references against the current authorized records and discard duplicate keys.
     *
     * @param  array  $keys  Livewire selection keys or keys derived from the stored request.
     * @param  array  $options  Server-side records keyed by identifier.
     * @return list<mixed>
     */
    protected function selectedRecords(array $keys, array $options, string $field): array
    {
        $selected = [];
        foreach ($keys as $key) {
            if (!is_string($key) || !isset($options[$key])) {
                throw ValidationException::withMessages(['form.'.$field => __('care-plan.reference_unavailable')]);
            }
            $selected[$key] = $options[$key];
        }

        return array_values($selected);
    }

    /** Record a storage failure without writing clinical values and SQL parameters to the log. */
    protected function flashStorageError(QueryException $exception): void
    {
        Log::channel('db_errors')->error('Care plan request storage failed', ['sqlstate' => $exception->getCode()]);
        Session::flash('error', __('messages.database_error'));
    }

    /** Render the shared care plan fields and the local draft actions. */
    public function render(): View
    {
        return view('livewire.care-plan-request.care-plan-request-create', $this->editor);
    }
}
