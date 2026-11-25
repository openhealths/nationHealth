<?php

declare(strict_types=1);

namespace App\Livewire\CarePlanRequest;

use App\Dto\CarePlanRequest\Model as CarePlanRequestData;
use App\Enums\Person\EncounterStatus;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlan;
use App\Models\CarePlanRequest;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Repositories\Repository;
use App\Traits\LogsExceptions;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create a local care plan request from an existing patient encounter.
 */
class CarePlanRequestCreate extends Component
{
    use LogsExceptions;

    /** Shared care plan fields and validation used by the local editor. */
    public CarePlanForm $form;

    /** Patient bound to the route and used as the subject of the request. */
    #[Locked]
    public int $personId;

    /** Patient name displayed by the patient layout. */
    #[Locked]
    public string $patientFullName;

    /** Facility in which the editor was opened. */
    #[Locked]
    public int $legalEntityId;

    /** Encounter bound to the route and reloaded before saving. */
    #[Locked]
    public int $encounterId;

    /** Stable UUID used to identify repeat submissions of this editor. */
    #[Locked]
    public string $requestUuid;

    /** Identifier shown to the clinician after the draft is saved. */
    #[Locked]
    public ?int $savedRequestId = null;

    /** Allowed diagnosis-based categories and their display labels. */
    #[Locked]
    public array $categoryOptions = [];

    /**
     * Initialize the editor from the authorized route and prepare the available categories.
     */
    public function mount(LegalEntity $legalEntity, Person $person, Encounter $encounter): void
    {
        $this->personId = $person->id;
        $this->patientFullName = $person->fullName;
        $this->legalEntityId = $legalEntity->id;
        $this->encounterId = $encounter->id;
        $this->requestUuid = Str::uuid()->toString();
        $this->form->periodStart = now()->format(config('app.date_format'));

        $categoryCodes = Repository::carePlan()->categoryCodesForEncounter($encounter->uuid, $person->id);
        $labels = dictionary()->basics()->byName('eHealth/care_plan_categories')?->asCodeDescription()->toArray() ?? [];
        foreach ($categoryCodes as $code) {
            $this->categoryOptions[$code] = $labels[$code] ?? $code;
        }

        if (count($categoryCodes) === 1) {
            $this->form->category = $categoryCodes[0];
        }
    }

    /**
     * Check the current context, validate and persist the local draft.
     */
    public function save(): void
    {
        if ($this->savedRequestId !== null) {
            return;
        }

        $legalEntity = legalEntity();
        $user = Auth::user();
        if ($legalEntity?->id !== $this->legalEntityId || $user === null || $user->cannot('create', CarePlan::class)) {
            Session::flash('error', __('care-plan.no_permission_create'));

            return;
        }

        $repository = Repository::carePlan();
        $person = $repository->findPatient($this->personId);
        $encounter = $repository->findEncounter($this->encounterId, $this->personId);
        if ($person === null || $encounter === null || $user->cannot('view', [$encounter, $person])
            || $encounter->status !== EncounterStatus::FINISHED || !$encounter->belongsToCurrentLegalEntity()) {
            Session::flash('error', __('care-plan.document_context_unavailable'));

            return;
        }

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
        if (filled($encounter->episode?->value)) {
            $data->episodes = [['uuid' => $encounter->episode->value]];
        }

        $createPlanRequest = new CarePlanRequest();
        $createPlanRequest->uuid = $this->requestUuid;
        $createPlanRequest->userId = $user->id;
        $createPlanRequest->person()->associate($person);
        $createPlanRequest->legalEntity()->associate($legalEntity);
        $mapper->map($data, $createPlanRequest);

        try {
            $createPlanRequest = $repository->createRequest($createPlanRequest);
        } catch (QueryException $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store care plan request');

            return;
        }

        $this->savedRequestId = $createPlanRequest->id;
        Session::flash('success', __('care-plan.draft_saved'));
    }

    /**
     * Render the local editor and its saved-request confirmation.
     */
    public function render(): View
    {
        return view('livewire.care-plan-request.care-plan-request-create');
    }
}
