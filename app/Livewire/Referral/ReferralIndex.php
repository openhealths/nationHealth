<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Classes\eHealth\EHealth;
use App\Core\BaseForm;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class ReferralIndex extends Component
{
    use WithFileUploads;

    #[Locked]
    public LegalEntity $legalEntity;

    public BaseForm $form;

    public string $requisition = '';
    public string $patient = '';
    public array $status = [];

    /** The authoritative search response, not client-supplied action data. */
    #[Locked]
    public array $searchResults = [];

    public bool $hasSearched = false;
    public ?string $errorMessage = null;
    public bool $showCancelModal = false;
    public bool $showErrorModal = false;
    public bool $showRecallModal = false;
    public bool $showCompleteModal = false;
    public bool $showDetailsModal = false;
    public bool $showSignatureModal = false;

    #[Locked]
    public ?string $referralToCancel = null;
    #[Locked]
    public ?string $referralToError = null;
    #[Locked]
    public ?string $referralToRecall = null;
    #[Locked]
    public ?string $referralToComplete = null;
    #[Locked]
    public ?string $referralToSign = null;
    #[Locked]
    public ?string $actionType = null;
    #[Locked]
    public array $referralDetails = [];

    public string $cancelExplanatoryLetter = '';
    public string $recallExplanatoryLetter = '';
    public string $errorReason = '';
    public string $selectedEmzType = 'encounter';
    public string $selectedEmzUuid = '';
    #[Locked]
    public array $availableEmzResources = [];

    /** @var list<string> */
    #[Locked]
    public array $emzTypes = ['encounter', 'procedure', 'diagnostic_report'];

    public function search(): void
    {
        abort_unless(auth()->user()?->can('service_request:read'), 403);
        $this->validate([
            'requisition' => 'required|string|max:64',
            'patient' => 'string|max:255',
            'status' => 'array',
            'status.*' => [Rule::in(['active', 'new', 'completed', 'entered_in_error', 'revoked', 'draft', 'in_progress', 'in_queue'])],
        ]);
        $this->errorMessage = null;
        $this->hasSearched = true;
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->requisition));
        $this->requisition = trim(preg_replace('/(.{4})/', '$1-', $clean), '-');

        try {
            $rows = EHealth::serviceRequest()->searchForServiceRequestsByParams([
                'requisition' => $this->requisition,
            ])->getData();
            $this->searchResults = array_values($rows['data'] ?? $rows);
        } catch (Throwable $exception) {
            Log::error('Referral search failed', ['exception' => $exception::class]);
            $this->searchResults = [];
            $this->errorMessage = __('referrals.messages.search_failed');
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['requisition', 'patient', 'status', 'searchResults', 'hasSearched', 'errorMessage']);
        $this->resetValidation(['requisition', 'patient', 'status', 'referral']);
    }

    public function filteredReferrals(): array
    {
        $patient = mb_strtolower(trim($this->patient));

        return array_values(array_filter($this->searchResults, function (array $referral) use ($patient): bool {
            $status = $this->normalizedStatus((string) ($referral['status'] ?? ''));
            $matchesStatus = $this->status === [] || in_array($status, $this->status, true);
            $subject = mb_strtolower((string) data_get($referral, 'subject.display', ''));
            $patientId = mb_strtolower((string) data_get($referral, 'subject.identifier.value', ''));

            return $matchesStatus && ($patient === '' || str_contains($subject, $patient) || $patientId === $patient);
        }));
    }

    public function canAct(array $referral, string $action): bool
    {
        $scope = $this->actionScope($action);
        if ($scope === null || !auth()->user()?->can($scope)) {
            return false;
        }

        $status = $this->normalizedStatus((string) ($referral['status'] ?? ''));
        $inUse = in_array($status, ['in_progress', 'in_queue'], true)
            || ($status === 'active' && ($referral['program_processing_status'] ?? '') === 'in_progress');

        return match ($action) {
            'process' => in_array($status, ['active', 'new'], true) && !$inUse,
            'complete', 'cancel_usage' => $inUse,
            'cancel_referral', 'recall_referral' => $status === 'active' && !$inUse,
            default => false,
        };
    }

    public function process(string $uuid, string $patientUuid, ReferralRequestLifecycleService $service): void
    {
        $referral = $this->selectedReferral($uuid, 'process');
        $patientId = $this->patientUuid($referral);
        abort_unless($patientUuid === $patientId, 422);

        try {
            $employee = auth()->user()->employees()->where('legal_entity_id', $this->legalEntity->id)->first();
            if (!$employee) {
                throw new \RuntimeException(__('referrals.messages.employee_required'));
            }
            $programId = data_get($referral, 'program.identifier.value')
                ?? data_get($referral, 'program.id') ?? ($referral['medical_program_id'] ?? null);
            $service->takeIntoWork($uuid, $employee, $patientId, array_filter([
                'program_id' => is_string($programId) && $programId !== '' ? $programId : null,
            ]));
            $this->updateResultStatus($uuid, 'in_progress', 'in_progress');
            Session::flash('success', __('referrals.messages.taken_into_work'));
        } catch (Throwable $exception) {
            $this->flashFailure($exception);
        }
    }

    public function openCancelModal(string $uuid): void
    {
        $this->selectedReferral($uuid, 'cancel_usage');
        $this->resetValidation();
        $this->referralToCancel = $uuid;
        $this->cancelExplanatoryLetter = '';
        $this->showCancelModal = true;
    }

    public function cancelUsage(string $uuid): void
    {
        $this->openCancelModal($uuid);
    }

    public function confirmCancelUsage(ReferralRequestLifecycleService $service): void
    {
        $uuid = (string) $this->referralToCancel;
        $referral = $this->selectedReferral($uuid, 'cancel_usage');
        $this->validate(['cancelExplanatoryLetter' => 'required|string|max:2000']);
        $letter = trim($this->cancelExplanatoryLetter);
        if ($letter === '') {
            throw ValidationException::withMessages(['cancelExplanatoryLetter' => __('validation.required', ['attribute' => __('referrals.modals.cancel.reason_label')])]);
        }

        try {
            $service->cancelUsage($uuid, $this->patientUuid($referral), ['explanatory_letter' => $letter]);
            $this->updateResultStatus($uuid, 'active', 'new');
            $this->showCancelModal = false;
            Session::flash('success', __('referrals.messages.usage_cancelled'));
        } catch (Throwable $exception) {
            $this->flashFailure($exception);
        }
    }

    public function openErrorModal(string $uuid): void
    {
        $this->selectedReferral($uuid, 'cancel_referral');
        $this->resetValidation();
        $this->referralToError = $uuid;
        $this->errorReason = '';
        $this->showErrorModal = true;
    }

    public function confirmErrorUsage(): void
    {
        $this->selectedReferral((string) $this->referralToError, 'cancel_referral');
        $this->validate(['errorReason' => ['required', Rule::in(['entered_in_error'])]]);
        $this->beginSignature((string) $this->referralToError, 'cancel_referral');
        $this->showErrorModal = false;
    }

    public function openRecallModal(string $uuid): void
    {
        $this->selectedReferral($uuid, 'recall_referral');
        $this->resetValidation();
        $this->referralToRecall = $uuid;
        $this->recallExplanatoryLetter = '';
        $this->showRecallModal = true;
    }

    public function confirmRecall(): void
    {
        $this->selectedReferral((string) $this->referralToRecall, 'recall_referral');
        $this->validate(['recallExplanatoryLetter' => 'required|string|max:2000']);
        if (trim($this->recallExplanatoryLetter) === '') {
            throw ValidationException::withMessages(['recallExplanatoryLetter' => __('care-plan.referral_recall_letter_required')]);
        }
        $this->beginSignature((string) $this->referralToRecall, 'recall_referral');
        $this->showRecallModal = false;
    }

    private function beginSignature(string $uuid, string $action): void
    {
        $this->form->resetSigningFields();
        $this->referralToSign = $uuid;
        $this->actionType = $action;
        $this->showSignatureModal = true;
    }

    public function updatedShowSignatureModal(bool $value): void
    {
        if (!$value) {
            $this->form->resetSigningFields();
            $this->actionType = null;
            $this->referralToSign = null;
        }
    }

    public function sign(ReferralRequestLifecycleService $service): void
    {
        abort_unless(in_array($this->actionType, ['cancel_referral', 'recall_referral'], true), 422);
        $uuid = (string) $this->referralToSign;
        $referral = $this->selectedReferral($uuid, $this->actionType);
        $patientId = $this->patientUuid($referral);
        $payload = $this->actionType === 'cancel_referral'
            ? ['status_reason' => 'entered-in-error']
            : ['explanatory_letter' => trim($this->recallExplanatoryLetter)];
        if ($this->actionType === 'recall_referral' && $payload['explanatory_letter'] === '') {
            throw ValidationException::withMessages(['recallExplanatoryLetter' => __('care-plan.referral_recall_letter_required')]);
        }
        $validated = $this->form->validate($this->form->signingRules());
        $action = $this->actionType;

        try {
            $signed = signatureService()->signData($payload, $validated['password'], $validated['knedp'], $validated['keyContainerUpload'], auth()->user()->party->taxId);
            if (!is_string($signed) || $signed === '') {
                throw new \RuntimeException(__('referrals.messages.operation_failed'));
            }
            $signedPayload = [...$payload, 'signed_data' => $signed, 'signed_data_encoding' => 'base64'];
            $response = $action === 'cancel_referral'
                ? $service->submitSignedCancel('service_request', $patientId, $uuid, $signedPayload)
                : $service->submitSignedRecall($patientId, $uuid, $signedPayload);
            if ($response === []) {
                throw new \RuntimeException(__('referrals.messages.operation_failed'));
            }
            $status = $action === 'cancel_referral' ? 'entered-in-error' : 'recalled';
            $entity = $response['result'] ?? $response['data'] ?? $response;
            if (is_array($entity) && array_is_list($entity)) {
                $entity = $entity[0] ?? [];
            }
            $returnedStatus = is_array($entity) ? ($entity['status'] ?? null) : null;
            $isResource = is_array($entity) && (isset($entity['id']) || isset($entity['uuid']) || isset($response['result']));
            if (is_string($returnedStatus) && ($isResource || !in_array($returnedStatus, ['processed', 'success', 'completed'], true))) {
                if ($this->normalizedStatus($returnedStatus) !== $this->normalizedStatus($status)) {
                    throw new \RuntimeException(__('referrals.messages.operation_failed'));
                }
                $status = $returnedStatus;
            }
            $this->persistSignedStatus($uuid, $patientId, $status);
            $this->updateResultStatus($uuid, $status);
            $this->showSignatureModal = false;
            Session::flash('success', __('referrals.messages.'.($action === 'cancel_referral' ? 'error_marked_success' : 'recalled')));
        } catch (Throwable $exception) {
            $this->flashFailure($exception);
        } finally {
            $this->form->resetSigningFields();
            $this->referralToSign = null;
            $this->actionType = null;
            $this->showSignatureModal = false;
        }
    }

    protected function persistSignedStatus(string $uuid, string $patientId, string $status): void
    {
        ServiceRequestRequest::query()->where('uuid', $uuid)
            ->whereHas('person', fn ($query) => $query->where('uuid', $patientId))
            ->whereHas('employee', fn ($query) => $query->where('legal_entity_id', $this->legalEntity->id))
            ->update(['status' => $status]);
    }

    public function openDetailsModal(string $uuid): void
    {
        abort_unless(auth()->user()?->can('service_request:read'), 403);
        $this->referralDetails = $this->findReferral($uuid);
        $this->showDetailsModal = true;
    }

    public function openCompleteModal(string $uuid): void
    {
        $this->selectedReferral($uuid, 'complete');
        $this->resetValidation();
        $this->referralToComplete = $uuid;
        $this->selectedEmzType = 'encounter';
        $this->loadEmzResourcesForComplete($uuid);
        $this->showCompleteModal = true;
    }

    public function updatedSelectedEmzType(string $value): void
    {
        if ($this->referralToComplete && in_array($value, $this->emzTypes, true)) {
            $this->loadEmzResourcesForComplete($this->referralToComplete);
        }
    }

    public function confirmComplete(ReferralRequestLifecycleService $service): void
    {
        $uuid = (string) $this->referralToComplete;
        $this->selectedReferral($uuid, 'complete');
        $this->validate([
            'selectedEmzType' => ['required', Rule::in($this->emzTypes)],
            'selectedEmzUuid' => 'required|uuid',
        ]);
        if (!$this->assertEmzLinkedToReferral($uuid, $this->selectedEmzType, $this->selectedEmzUuid)) {
            Session::flash('error', __('care-plan.referral_complete_emz_not_linked'));

            return;
        }

        try {
            $service->completeReferral($uuid, $this->selectedEmzUuid, $this->selectedEmzType);
            $this->updateResultStatus($uuid, 'completed', 'completed');
            $this->showCompleteModal = false;
            Session::flash('success', __('referrals.messages.completed'));
        } catch (Throwable $exception) {
            $this->flashFailure($exception);
        }
    }

    private function findReferral(string $uuid): array
    {
        $referral = collect($this->searchResults)->firstWhere('id', $uuid);
        abort_unless(is_array($referral), 404);

        return $referral;
    }

    private function selectedReferral(string $uuid, string $action): array
    {
        $scope = $this->actionScope($action);
        abort_unless($scope !== null && auth()->user()?->can($scope), 403);
        $referral = $this->findReferral($uuid);
        if (!$this->canAct($referral, $action)) {
            throw ValidationException::withMessages(['referral' => __('referrals.messages.invalid_state')]);
        }

        return $referral;
    }

    private function actionScope(string $action): ?string
    {
        return match ($action) {
            'process' => 'service_request:makeinprogress',
            'complete' => 'service_request:complete',
            'cancel_usage' => 'service_request:use',
            'cancel_referral' => 'service_request:cancel',
            'recall_referral' => 'service_request:recall',
            default => null,
        };
    }

    private function patientUuid(array $referral): string
    {
        $patientId = data_get($referral, 'subject.identifier.value');
        if (!is_string($patientId) || $patientId === '') {
            throw ValidationException::withMessages(['referral' => __('referrals.messages.patient_required')]);
        }

        return $patientId;
    }

    private function normalizedStatus(string $status): string
    {
        return match ($status) {
            'entered-in-error' => 'entered_in_error',
            'recalled' => 'revoked',
            default => $status,
        };
    }

    private function updateResultStatus(string $uuid, string $status, ?string $programStatus = null): void
    {
        foreach ($this->searchResults as $key => $referral) {
            if (($referral['id'] ?? '') === $uuid) {
                $this->searchResults[$key]['status'] = $status;
                if ($programStatus !== null && isset($referral['program_processing_status'])) {
                    $this->searchResults[$key]['program_processing_status'] = $programStatus;
                }
            }
        }
    }

    private function flashFailure(Throwable $exception): void
    {
        Log::error('Referral operation failed', ['exception' => $exception::class]);
        Session::flash('error', $exception instanceof \App\Exceptions\EHealth\EHealthValidationException
            ? $exception->getTranslatedMessage() : __('referrals.messages.operation_failed'));
    }

    public function render()
    {
        return view('livewire.referral.referral-index', ['visibleReferrals' => $this->filteredReferrals()]);
    }

    protected function loadEmzResourcesForComplete(string $referralUuid): void
    {
        $this->availableEmzResources = [];
        $this->selectedEmzUuid = '';

        $referral = collect($this->searchResults)->firstWhere('id', $referralUuid);
        $patientId = $referral['subject']['identifier']['value'] ?? null;
        if (!$patientId) {
            return;
        }

        $person = Person::where('uuid', $patientId)->first();
        if (!$person) {
            return;
        }

        match ($this->selectedEmzType) {
            'procedure' => $this->loadLinkedProcedures($person, $referralUuid),
            'diagnostic_report' => $this->loadLinkedDiagnosticReports($person, $referralUuid),
            default => $this->loadLinkedEncounters($person, $referralUuid),
        };
    }

    private function loadLinkedEncounters(Person $person, string $referralUuid): void
    {
        $query = Encounter::query()
            ->with('incomingReferral.type.coding')
            ->where('person_id', $person->id)
            ->whereHas('incomingReferral', static function ($q) use ($referralUuid): void {
                $q->where('value', $referralUuid);
            })
            ->latest('created_at')
            ->take(50);

        foreach ($query->get(['id', 'uuid', 'created_at', 'status', 'incoming_referral_id']) as $encounter) {
            $statusMap = [
                'finished' => 'Завершено',
                'entered-in-error' => 'Помилково введено',
                'in_progress' => 'В процесі',
            ];
            $statusCode = $encounter->status instanceof \BackedEnum
                ? $encounter->status->value
                : (string) $encounter->status;
            $statusLabel = $statusMap[$statusCode] ?? $statusCode;
            $date = $encounter->created_at ? $encounter->created_at->format('d.m.Y H:i') : '';

            $this->availableEmzResources[] = [
                'uuid' => $encounter->uuid,
                'label' => "Encounter {$encounter->uuid} від {$date} ({$statusLabel})",
            ];
        }
    }

    private function loadLinkedProcedures(Person $person, string $referralUuid): void
    {
        $procedures = Procedure::query()
            ->with('basedOn.type.coding')
            ->where('person_id', $person->id)
            ->whereHas('basedOn', static function ($q) use ($referralUuid): void {
                $q->where('value', $referralUuid);
            })
            ->latest('created_at')
            ->take(50)
            ->get();

        foreach ($procedures as $procedure) {
            $date = $procedure->created_at?->format('d.m.Y H:i') ?? '';

            $this->availableEmzResources[] = [
                'uuid' => $procedure->uuid,
                'label' => "Procedure {$procedure->uuid} від {$date}",
            ];
        }
    }

    private function loadLinkedDiagnosticReports(Person $person, string $referralUuid): void
    {
        $reports = DiagnosticReport::query()
            ->with('basedOn.type.coding')
            ->where('person_id', $person->id)
            ->whereHas('basedOn', static function ($q) use ($referralUuid): void {
                $q->where('value', $referralUuid);
            })
            ->latest('created_at')
            ->take(50)
            ->get();

        foreach ($reports as $report) {
            $date = $report->created_at?->format('d.m.Y H:i') ?? '';

            $this->availableEmzResources[] = [
                'uuid' => $report->uuid,
                'label' => "DiagnosticReport {$report->uuid} від {$date}",
            ];
        }
    }

    protected function assertEmzLinkedToReferral(string $referralUuid, string $resourceType, string $resourceUuid): bool
    {
        return match ($resourceType) {
            'encounter' => $this->encounterLinked($referralUuid, $resourceUuid),
            'procedure' => $this->procedureLinked($referralUuid, $resourceUuid),
            'diagnostic_report' => $this->diagnosticReportLinked($referralUuid, $resourceUuid),
            default => false,
        };
    }

    private function encounterLinked(string $referralUuid, string $resourceUuid): bool
    {
        $encounter = Encounter::query()
            ->with('incomingReferral')
            ->where('uuid', $resourceUuid)
            ->first();

        return $encounter !== null && $encounter->incomingReferral?->value === $referralUuid;
    }

    private function procedureLinked(string $referralUuid, string $resourceUuid): bool
    {
        $procedure = Procedure::query()
            ->with('basedOn')
            ->where('uuid', $resourceUuid)
            ->first();

        return $procedure !== null && $procedure->basedOn?->value === $referralUuid;
    }

    private function diagnosticReportLinked(string $referralUuid, string $resourceUuid): bool
    {
        $report = DiagnosticReport::query()
            ->with('basedOn')
            ->where('uuid', $resourceUuid)
            ->first();

        return $report !== null && $report->basedOn?->value === $referralUuid;
    }
}
