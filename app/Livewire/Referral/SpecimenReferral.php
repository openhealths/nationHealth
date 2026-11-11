<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Classes\eHealth\EHealth;
use App\Core\BaseForm;
use App\Enums\MedicalProgram\Type as MedicalProgramType;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Concerns\SubmitsClinicalOperations;
use App\Livewire\Referral\Forms\ServiceRequestForm;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\Dictionary\ServiceSearch;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class SpecimenReferral extends Component
{
    use SubmitsClinicalOperations;
    use WithFileUploads;

    public BaseForm $form;

    #[Locked]
    public ?int $personId = null;

    #[Locked]
    public ?int $prepersonId = null;

    #[Locked]
    public int $specimenId;

    #[Locked]
    public ?string $draftUuid = null;

    #[Locked]
    public array $rows = [];

    public int $executorId = 0;
    public string $serviceId = '';
    public string $programId = '';
    public string $startedAt = '';
    public string $endedAt = '';
    public float $quantity = 1;
    public string $note = '';
    public bool $showSignatureModal = false;
    public ?string $actionType = null;

    public function mount(int $specimenId, ?int $personId = null, ?int $prepersonId = null): void
    {
        $this->specimenId = $specimenId;
        $this->personId = $personId;
        $this->prepersonId = $prepersonId;
        $this->startedAt = now()->addHour()->format('Y-m-d\TH:i');
        $this->endedAt = now()->addMonth()->format('Y-m-d\TH:i');
        $draft = Repository::serviceRequest()->draftForSpecimen($this->specimen(), $this->patient());
        if ($draft !== null) {
            $this->draftUuid = $draft->uuid;
            $this->executorId = (int) $draft->employeeId;
            $this->serviceId = $draft->serviceId;
            $this->programId = $draft->programId ?? '';
            $this->quantity = (float) $draft->quantity;
            $this->note = $draft->note ?? '';
            $this->startedAt = $draft->startedAt?->format('Y-m-d\TH:i') ?? $this->startedAt;
            $this->endedAt = $draft->endedAt?->format('Y-m-d\TH:i') ?? $this->endedAt;
        }
    }

    private function patient(): Person|Preperson
    {
        if (($this->personId === null) === ($this->prepersonId === null)) {
            throw ValidationException::withMessages(['specimenId' => __('referrals.messages.specimen_unavailable')]);
        }

        return $this->prepersonId !== null ? Preperson::findOrFail($this->prepersonId) : Person::findOrFail($this->personId);
    }

    private function specimen(): Specimen
    {
        return Repository::serviceRequest()->specimenForPatient($this->specimenId, $this->patient());
    }

    #[Computed]
    public function authorChoices(): array
    {
        return Repository::serviceRequest()->specimenAuthorChoices($this->specimen());
    }

    #[Computed]
    public function serviceChoices(): array
    {
        $dictionary = dictionary()->services();
        $catalog = $dictionary->flattened();

        return $catalog->filter(static function (array $choice) use ($catalog, $dictionary): bool {
            if (empty($choice['is_active']) || empty($choice['request_allowed'])) {
                return false;
            }
            if (($choice['type'] ?? '') !== 'service_group') {
                return ($choice['category'] ?? '') === 'laboratory_procedure';
            }
            $members = ServiceSearch::memberServiceIds($choice['id'], $dictionary->toArray());

            return $members !== [] && $catalog->whereIn('id', $members)->every(static fn (array $service): bool => ($service['category'] ?? '') === 'laboratory_procedure');
        })->values()->all();
    }

    #[Computed]
    public function programChoices(): array
    {
        return dictionary()->medicalPrograms()->where('is_active', true)->where('type', MedicalProgramType::SERVICE->value)->values()->all();
    }

    public function loadReferrals(): void
    {
        if (Auth::user()->cannot('view', Specimen::class) || Auth::user()->cannot('search', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.use_forbidden'));

            return;
        }
        try {
            $page = 1;
            $rows = [];
            do {
                $response = EHealth::serviceRequest()->getBySpecimen($this->specimen()->uuid, ['page' => $page]);
                $validated = $response->validate();
                $rows = [...$rows, ...Repository::serviceRequest()->specimenReferralRows($validated)];
                $totalPages = (int) ($response->getPaging()['total_pages'] ?? 1);
                $page++;
            } while ($page <= $totalPages);
            // The API's subject and identity never reach public state or patient persistence.
            $this->rows = collect($rows)->unique('id')->values()->all();
            Session::flash('success', __('referrals.messages.specimen_loaded'));
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', __('referrals.messages.specimen_failed'));
        }
    }

    public function saveDraft(bool $prepareSigning = false): bool
    {
        $employee = Repository::serviceRequest()->authorForDraft($this->executorId, null);
        if ($employee === null || Auth::user()->cannot('createFromSpecimen', [ServiceRequestRequest::class, $employee, $this->specimen()])) {
            Session::flash('error', __('referrals.messages.category_forbidden'));

            return false;
        }
        $this->validate([
            'executorId' => ['required', 'integer', Rule::in(array_column($this->authorChoices, 'id'))],
            'serviceId' => ['required', 'uuid', Rule::in(array_column($this->serviceChoices, 'id'))],
            'programId' => ['nullable', 'uuid', Rule::in(array_column($this->programChoices, 'id'))],
            'startedAt' => ['required', 'date', 'after:now'],
            'endedAt' => ['required', 'date', 'after:startedAt'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'note' => ['string', 'max:1000'],
        ]);
        try {
            $record = app(ReferralRequestLifecycleService::class)->createSpecimenDraft($this->specimen(), $this->patient(), [
                'service_id' => $this->serviceId, 'service_type' => collect($this->serviceChoices)->firstWhere('id', $this->serviceId)['type'],
                'program_id' => $this->programId === '' ? null : $this->programId,
                'started_at' => $this->startedAt, 'ended_at' => $this->endedAt,
                'quantity' => $this->quantity, 'note' => $this->note,
            ], $employee, $this->draftUuid, $prepareSigning);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            Session::flash('error', $exception->validator->errors()->first());

            return false;
        } catch (EHealthValidationException $exception) {
            Session::flash('error', $exception->getTranslatedMessage());

            return false;
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', __('referrals.messages.operation_failed'));

            return false;
        }
        $this->draftUuid = $record->uuid;
        Session::flash('success', __('referrals.draft_saved'));
        if ($prepareSigning) {
            $this->actionType = 'sign_referral';
            $this->showSignatureModal = true;
        }

        return true;
    }

    public function prepareSigning(): void
    {
        $this->saveDraft(true);
    }

    public function sign(): void
    {
        if ($this->draftUuid === null) {
            return;
        }
        $record = Repository::serviceRequest()->requestForSpecimen($this->draftUuid, $this->specimen(), $this->patient());
        $employee = Repository::serviceRequest()->authorForDraft($record->employeeId, null);
        if ($employee === null || Auth::user()->cannot('createFromSpecimen', [ServiceRequestRequest::class, $employee, $this->specimen()])) {
            Session::flash('error', __('referrals.messages.category_forbidden'));

            return;
        }
        if (Repository::serviceRequest()->hasPendingSubmission($record)) {
            Session::flash('warning', __('referrals.submission_pending'));
            $this->showSignatureModal = false;

            return;
        }
        // Repeat prequalification with the saved fields before obtaining a signature.
        if (!$this->saveDraft(true)) {
            return;
        }
        try {
            $validated = $this->form->validate($this->form->signingRules());
            $content = ServiceRequestForm::buildCreateContent(Repository::serviceRequest()->getResource($record->fresh()));
            $signed = signatureService()->signData($content, $validated['password'], $validated['knedp'], $validated['keyContainerUpload'], Auth::user()->party->taxId);
            $result = $this->submitClinicalOperation($record, ['status' => 'active'], fn () => EHealth::serviceRequest()->createSigned($this->patient()->uuid, ['signed_data' => $signed, 'signed_data_encoding' => 'base64']), 'service_request:write');
            if ($result !== null) {
                $this->showSignatureModal = false;
                Session::flash('success', __('referrals.submission_created_without_number'));
            }
            $this->form->resetSigningFields();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', __('referrals.messages.operation_failed'));
        }
    }

    public function checkStatus(): void
    {
        if (Auth::user()->cannot('search', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.use_forbidden'));

            return;
        }
        if ($this->draftUuid === null) {
            return;
        }
        $record = Repository::serviceRequest()->requestForSpecimen($this->draftUuid, $this->specimen(), $this->patient());
        $state = app(ReferralRequestLifecycleService::class)->checkCompletion($record, 'service_request:write');
        if ($state['submissionPending'] || ($state['status'] ?? '') === 'active') {
            Session::flash('info', __($state['submissionPending'] ? 'referrals.submission_pending' : 'referrals.submission_created_without_number'));
        }
    }

    public function render(): View
    {
        return view('livewire.referral.specimen-referral');
    }
}
