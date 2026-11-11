<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\BaseForm;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

class PatientMedicationRequestView extends BasePatientComponent
{
    use WithFileUploads;

    #[Locked]
    public string $requestId = '';

    #[Locked]
    public array $detail = [];

    #[Locked]
    public array $dispenses = [];

    #[Locked]
    public array $programSettings = [];

    public BaseForm $form;

    public bool $showSignatureModal = false;

    #[Locked]
    public string $actionType = '';

    public string $statusReason = '';

    public string $rejectReason = '';

    public bool $smsConfirmed = false;

    #[Locked]
    public string $printoutHtml = '';

    #[Locked]
    public string $priceCatalogueUrl = '';

    protected function resourceType(): string
    {
        return MedicationRequestRequest::TYPE_PRESCRIPTION;
    }

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null, ?string $requestId = null): void
    {
        $this->requestId = $requestId ?? '';
        // Laravel may resolve an omitted nullable model as an empty instance.
        parent::mount($legalEntity, $person, $preperson?->exists ? $preperson : null);
    }

    protected function initializeComponent(): void
    {
        if ($this->personId === null) {
            return;
        }
        $record = $this->record();
        if (!$this->allowed('view', $record)) {
            return;
        }
        $this->detail = is_array($record->ehealthPayload) ? $record->ehealthPayload : [];
        $this->detail['status'] = $record->status;
        $this->detail['id'] = $record->uuid;
        $this->detail['request_number'] = $record->requestNumber;
        $this->detail['dosage_instruction'] ??= $record->dosageInstructions->toArray();
        $programId = $record->medicationProgramId;
        $program = $programId === null ? [] : dictionary()->medicalPrograms()->firstWhere('id', $programId);
        $this->programSettings = $program['settings'] ?? [];
    }

    private function record(): MedicationRequestRequest
    {
        $record = app(MedicalRequestOwnership::class)->medicationForPerson($this->requestId, (int) $this->personId);
        abort_unless($record->resourceType === $this->resourceType(), 404);

        return $record;
    }

    private function allowed(string $ability, MedicationRequestRequest $record): bool
    {
        if (Auth::user()->cannot($ability, $record)) {
            session()->flash('error', __('medication-requests.permission_denied'));

            return false;
        }

        return true;
    }

    public function refreshDetail(): void
    {
        $record = $this->record();
        if (!$this->allowed('view', $record)) {
            return;
        }
        try {
            $detail = $this->resourceType() === MedicationRequestRequest::TYPE_REQUEST
                ? EHealth::medicationRequestRequest()->getById($this->uuid, $record->uuid)->validate()
                : EHealth::medicationRequest()->getById($this->uuid, $record->uuid)->validate();
            app(MedicationRequestLifecycleService::class)->assertPatientSnapshot($detail, $this->uuid, $record->uuid);
            $native = array_replace($detail, ['_local_form' => $record->ehealthPayload['_local_form'] ?? []]);
            Repository::medicationRequest()->storeResource((new MedicationRequestMapper())->fromEHealth($detail), (int) $this->personId, resourceType: $record->resourceType, native: $native);
            $this->detail = $native;
            session()->flash('success', __('medication-requests.detail_sync'));
        } catch (Throwable $exception) {
            $this->failure($exception);
        }
    }

    /** BA05: only a fresh, patient-scoped native result can confirm rejection or active status. */
    public function checkValidity(): void
    {
        $record = $this->record();
        if (!$this->allowed('checkValidity', $record)) {
            return;
        }

        try {
            $detail = $this->resourceType() === MedicationRequestRequest::TYPE_REQUEST
                ? EHealth::medicationRequestRequest()->getById($this->uuid, $record->uuid)->validate()
                : EHealth::medicationRequest()->getById($this->uuid, $record->uuid)->validate();
            app(MedicationRequestLifecycleService::class)->assertPatientSnapshot($detail, $this->uuid, $record->uuid);
            $status = strtolower($detail['status']);
            $confirmed = in_array($status, ['active', 'rejected', 'expired', 'blocked', 'completed', 'entered-in-error'], true);
            $message = $confirmed ? 'validity_'.str_replace('-', '_', $status) : 'validity_unconfirmed';
            // NEW/SIGNED and asynchronous statuses describe requests; they do not certify an issued prescription.
            $native = array_replace($detail, ['_local_form' => $record->ehealthPayload['_local_form'] ?? []]);
            Repository::medicationRequest()->storeResource((new MedicationRequestMapper())->fromEHealth($detail), (int) $this->personId, resourceType: $record->resourceType, native: $native);
            $this->detail = $native;
            session()->flash($confirmed && $status !== 'active' ? 'warning' : 'info', __('medication-requests.'.$message));
        } catch (Throwable $exception) {
            $this->failure($exception);
        }
    }

    public function openSigning(): void
    {
        $record = $this->record();
        if (!$this->allowed('sign', $record)) {
            return;
        }
        $this->actionType = 'sign';
        $this->showSignatureModal = true;
    }

    public function reject(): void
    {
        $record = $this->record();
        if (!$this->allowed('reject', $record)) {
            return;
        }
        $this->actionType = 'reject';
        if (strtolower($record->status) === 'new') {
            $this->executeTransition($record, []);

            return;
        }
        $this->validate(['statusReason' => ['required', 'string']]);
        $this->actionType = 'reject';
        $this->showSignatureModal = true;
    }

    public function sign(): void
    {
        $record = $this->record();
        if (!$this->allowed($this->actionType === 'sign' ? 'sign' : 'reject', $record)) {
            return;
        }
        try {
            $credentials = $this->form->validate($this->form->signingRules());
            $this->executeTransition($record, $credentials);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'form.') ? $field : 'form.'.$field, $messages[0]);
            }
            session()->flash('error', __('forms.validation_error'));
        }
    }

    private function executeTransition(MedicationRequestRequest $record, array $credentials): void
    {
        try {
            $context = $this->actionType === 'sign'
                ? Repository::medicationRequest()->clinicalContext($record)
                : $this->patient();
            if ($context === null) {
                throw new RuntimeException(__('care-plan.document_context_unavailable'));
            }
            $lifecycle = app(MedicationRequestLifecycleService::class);
            $storedForm = $record->ehealthPayload['_local_form'] ?? [];
            $form = array_replace($storedForm, $credentials, ['signer_tax_id' => Auth::user()->party?->taxId, 'reject_reason' => $this->rejectReason]);
            // The saved NEW identity survives navigation; the signing service re-reads its native snapshot before KEP.
            $result = $this->actionType === 'sign'
                ? $lifecycle->signPrescription($context, $record, $form, (string) ($storedForm['inform_with'] ?? $record->informWith))
                : $lifecycle->rejectPrescription($context, $record, $form, $this->statusReason);
            $this->showSignatureModal = false;
            $this->actionType = '';
            $this->form->resetSigningFields();
            $this->initializeComponent();
            session()->flash('success', $result['success_message'] ?? __('medication-requests.transition_completed'));
            if (!empty($result['warning_message'])) {
                session()->flash('warning', $result['warning_message']);
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'form.') ? $field : 'form.'.$field, $messages[0]);
            }
            session()->flash('error', __('forms.validation_error'));
        } catch (Throwable $exception) {
            $this->failure($exception);
        }
    }

    public function loadPriceCatalogue(): void
    {
        $record = $this->record();
        if (!$this->allowed('priceCatalog', $record)) {
            return;
        }
        try {
            $this->priceCatalogueUrl = app(MedicationRequestLifecycleService::class)->priceCatalogLink($this->uuid, $record->uuid);
        } catch (Throwable $exception) {
            $this->failure($exception);
        }
    }

    public function loadDispenses(): void
    {
        $record = $this->record();
        if (!$this->allowed('dispenses', $record)) {
            return;
        }
        try {
            $this->dispenses = app(MedicationRequestLifecycleService::class)->getDispenseHistory($this->uuid, $record->uuid);
        } catch (Throwable $exception) {
            $this->failure($exception);
        }
    }

    public function loadPrintout(): void
    {
        $record = $this->record();
        if (!$this->allowed('view', $record)) {
            return;
        }
        try {
            $this->printoutHtml = app(MedicationRequestLifecycleService::class)->fetchPrintoutFromEhealth($this->uuid, $record->uuid);
        } catch (Throwable $exception) {
            $this->failure($exception);
        }
    }

    public function resendSms(): void
    {
        $record = $this->record();
        if (!$this->allowed('resend', $record)) {
            return;
        }
        $this->validate(['smsConfirmed' => ['accepted']]);
        $this->smsConfirmed = false;
        try {
            app(MedicationRequestLifecycleService::class)->resendSms($this->uuid, $record->uuid);
            session()->flash('success', __('medication-requests.sms_sent'));
        } catch (Throwable $exception) {
            $this->failure($exception);
        }
    }

    private function failure(Throwable $exception): void
    {
        Log::warning('Patient prescription action failed', ['exceptionType' => $exception::class]);
        session()->flash('error', __('medication-requests.detail_unavailable'));
    }

    public function render(): View
    {
        return view('livewire.person.records.patient-medication-request-view');
    }
}
