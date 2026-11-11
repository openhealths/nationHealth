<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Enums\MedicalProgram\Type as MedicalProgramType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ReferralIndex extends Component
{
    #[Locked]
    public int $legalEntityId;

    public string $requisition = '';

    public bool $showUseModal = false;

    #[Locked]
    public ?string $referralToUse = null;

    public int $selectedExecutorId = 0;

    public string $selectedProgramId = '';

    public bool $acknowledgeRemainingQuantity = false;

    #[Locked]
    public float $remainingQuantity = 0;

    #[Locked]
    public array $searchResults = [];

    #[Locked]
    public array $pendingCompletions = [];

    public bool $hasSearched = false;

    public ?string $errorMessage = null;

    public bool $showCompleteModal = false;

    public ?string $referralToComplete = null;

    /** @var list<array{uuid: string, label: string}> */
    public array $availableEmzResources = [];

    public string $selectedEmzType = 'encounter';

    public string $selectedEmzUuid = '';

    public bool $showCancelModal = false;

    public ?string $referralToCancel = null;

    public string $cancelExplanatoryLetter = '';

    /** @var list<string> */
    public array $emzTypes = ['encounter', 'procedure', 'diagnostic_report'];

    public function mount(LegalEntity $legalEntity): void
    {
        $this->legalEntityId = $legalEntity->id;
    }

    /** Reuse the patient's canonical status/signature/print workflow for the selected native referral. */
    public function openRegisteredAction(string $uuid, string $action): void
    {
        if (Auth::user()->cannot('search', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $native = collect($this->searchResults)->firstWhere('id', $uuid);
        $patient = $native === null ? null : Person::whereUuid(data_get($native, 'subject.identifier.value'))->first();
        if ($patient === null || Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $native, $action])) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $record = $patient === null ? null : Repository::serviceRequest()->findByUuid($uuid);
        if ($record === null) {
            try {
                $snapshot = EHealth::serviceRequest()->getById($patient->uuid, $uuid, preserveApiKeys: true)->validate();
                if (($snapshot['id'] ?? null) !== $uuid || data_get($snapshot, 'subject.identifier.value') !== $patient->uuid
                    || Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, $action])) {
                    Session::flash('error', __('referrals.messages.action_unavailable'));

                    return;
                }
                $record = Repository::serviceRequest()->importResource((new ServiceRequestMapper())->normalize($snapshot), $patient);
            } catch (Exception $exception) {
                $this->flashOperationError($exception);

                return;
            }
        }
        $ability = match ($action) {
            'recall' => 'recall', 'error' => 'cancel', default => 'viewRecord'
        };
        if (!in_array($action, ['view', 'print', 'recall', 'error'], true) || $record === null
            || $record->personId !== $patient->id || Auth::user()->cannot($ability, $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $source = $action === 'view' ? Repository::serviceRequest()->registeredViewRoute($record) : null;
        if ($source !== null) {
            $this->redirect($source, navigate: true);

            return;
        }
        $this->redirectRoute('persons.referrals', ['legalEntity' => $this->legalEntityId, 'person' => $patient->id, 'referral' => $uuid, 'referral_action' => $action], navigate: true);
    }

    public function confirmUse(ReferralRequestLifecycleService $service): void
    {
        $referral = collect($this->searchResults)->firstWhere('id', $this->referralToUse);
        if ($referral !== null) {
            $this->process($this->referralToUse, (string) data_get($referral, 'subject.identifier.value', ''), $service);
        }
    }

    public function search(): void
    {
        if (Auth::user()->cannot('search', ServiceRequestRequest::class)) {
            Session::flash('error', __('service-requests.policy.search'));

            return;
        }

        $this->searchResults = [];
        $this->pendingCompletions = [];
        $this->errorMessage = null;
        $this->hasSearched = false;

        $cleanRequisition = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->requisition));
        $formattedRequisition = trim(preg_replace('/(.{4})/', '$1-', $cleanRequisition), '-');
        $this->requisition = $formattedRequisition;

        $this->validate([
            'requisition' => ['required', 'string', 'max:19'],
        ], [], [
            'requisition' => __('referrals.requisition'),
        ]);

        try {
            $results = [];

            foreach (['active', 'completed', 'entered_in_error', 'recalled'] as $status) {
                $page = 1;

                do {
                    $response = EHealth::serviceRequest()->searchForServiceRequestsByParams([
                        'requisition' => $this->requisition,
                        'status' => $status,
                        'page' => $page,
                        'page_size' => 100,
                    ]);
                    $referrals = $response->validate();
                    $results = [...$results, ...($referrals['data'] ?? $referrals)];
                    $totalPages = (int) ($response->getPaging()['total_pages'] ?? 1);
                    $page++;
                } while ($page <= $totalPages);
            }

            $this->searchResults = collect($results)->unique('id')->values()->all();
            $this->hasSearched = true;
            $this->restoreCompletionTracking();
        } catch (EHealthConnectionException|EHealthResponseException|EHealthValidationException $e) {
            $this->errorMessage = __('referrals.messages.search_failed');
            $e->handle('Search referral error');
        } catch (Exception $e) {
            Log::error('Search referral error: '.$e->getMessage());
            $this->errorMessage = __('referrals.messages.search_failed');
            Session::flash('error', $this->errorMessage);
        }
    }

    #[Computed]
    public function executorChoices(): array
    {
        $referral = collect($this->searchResults)->firstWhere('id', $this->referralToUse);

        return Repository::serviceRequest()->executorChoices((string) data_get($referral, 'category.coding.0.code', ''));
    }

    #[Computed]
    public function programChoices(): array
    {
        return dictionary()->medicalPrograms()->where('is_active', true)->where('type', MedicalProgramType::SERVICE->value)->values()->all();
    }

    public function openUseModal(string $uuid): void
    {
        if (Auth::user()->cannot('use', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.use_forbidden'));

            return;
        }
        $referral = collect($this->searchResults)->firstWhere('id', $uuid);
        if ($referral === null || !$this->canTakeIntoWork($referral)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $this->referralToUse = $uuid;
        $this->selectedExecutorId = 0;
        $this->selectedProgramId = (string) (data_get($referral, 'program.identifier.value') ?? '');
        $this->resetValidation();
        $this->showUseModal = true;
    }

    public function process(string $uuid, string $patientUuid, ReferralRequestLifecycleService $service): void
    {
        if (!$this->showUseModal || $this->referralToUse !== $uuid) {
            $this->openUseModal($uuid);

            return;
        }
        if (Auth::user()->cannot('use', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.use_forbidden'));

            return;
        }
        $referral = collect($this->searchResults)->firstWhere('id', $uuid);
        if (!$referral || !$this->canTakeIntoWork($referral)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $this->validate([
            'selectedExecutorId' => ['required', 'integer', 'min:1', 'in:'.implode(',', array_column($this->executorChoices, 'id'))],
            'selectedProgramId' => ['nullable', 'uuid', 'in:'.implode(',', array_column($this->programChoices, 'id'))],
        ], [], ['selectedExecutorId' => __('referrals.executor')]);
        try {
            $employee = Repository::serviceRequest()->executorForUse($this->selectedExecutorId, (string) data_get($referral, 'category.coding.0.code', ''));
            $response = $service->takeIntoWork($uuid, $employee, $patientUuid ?: null, ['program_id' => $this->selectedProgramId ?: null]);
            if ($response['submissionPending'] ?? false) {
                Session::flash('info', __('care-plan.submission_pending'));
                $this->showUseModal = false;

                return;
            }
            $this->updateSearchResult($uuid, 'in_progress', $response);
            $this->showUseModal = false;
            Session::flash('success', __('referrals.messages.taken_into_work'));
        } catch (Exception $e) {
            // A rejected qualification leaves both selectors open for another choice or cancellation.
            $this->flashOperationError($e);
        }
    }

    public function openCancelModal(string $uuid): void
    {
        if (Auth::user()->cannot('use', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.use_forbidden'));

            return;
        }
        $referral = collect($this->searchResults)->firstWhere('id', $uuid);
        if (!$referral || !$this->canManageUsage($referral)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $this->resetValidation('cancelExplanatoryLetter');
        $this->referralToCancel = $uuid;
        $this->cancelExplanatoryLetter = '';
        $this->showCancelModal = true;
    }

    public function confirmCancelUsage(ReferralRequestLifecycleService $service): void
    {
        if (Auth::user()->cannot('use', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.use_forbidden'));

            return;
        }
        $uuid = $this->referralToCancel;

        if (empty($uuid)) {
            return;
        }

        $referral = collect($this->searchResults)->firstWhere('id', $uuid);
        if (!$referral || !$this->canManageUsage($referral)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        $this->cancelExplanatoryLetter = trim($this->cancelExplanatoryLetter);
        $this->validate([
            'cancelExplanatoryLetter' => 'required|string',
        ], [], [
            'cancelExplanatoryLetter' => __('referrals.modals.cancel_usage.letter_label'),
        ]);

        try {
            $patientId = $referral['subject']['identifier']['value'] ?? null;

            if (!$patientId) {
                throw new Exception('Не вдалося знайти ідентифікатор пацієнта.');
            }

            $response = $service->cancelUsage($uuid, $patientId, [
                'explanatory_letter' => $this->cancelExplanatoryLetter,
            ]);

            if ($response['submissionPending'] ?? false) {
                Session::flash('info', __('care-plan.submission_pending'));
                $this->showCancelModal = false;

                return;
            }
            $this->updateSearchResult($uuid, 'active', $response);

            Session::flash('success', __('referrals.messages.cancel_usage_success'));
            $this->showCancelModal = false;
        } catch (Exception $e) {
            $this->flashOperationError($e);
        }
    }

    public function cancelUsage(string $uuid, ReferralRequestLifecycleService $service)
    {
        $this->openCancelModal($uuid);
    }

    public function updatedSelectedEmzType(string $value): void
    {
        if ($this->referralToComplete) {
            $this->loadEmzResourcesForComplete($this->referralToComplete);
        }
    }

    public function openCompleteModal(string $uuid): void
    {
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.complete_forbidden'));

            return;
        }
        $this->referralToComplete = $uuid;
        $this->availableEmzResources = [];
        $this->selectedEmzUuid = '';
        $this->selectedEmzType = 'encounter';

        $referral = collect($this->searchResults)->firstWhere('id', $uuid);

        if (!$referral) {
            Session::flash('error', __('referrals.messages.not_found'));

            return;
        }

        if (!$this->canCompleteReferral($referral)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        $patientId = $referral['subject']['identifier']['value'] ?? null;

        if (!$patientId) {
            Session::flash('error', __('referrals.messages.patient_identifier_missing'));

            return;
        }

        $person = (Person::whereUuid($patientId)->first() ?? Preperson::whereUuid($patientId)->first());

        if (!$person) {
            Session::flash('error', __('referrals.messages.patient_not_found'));

            return;
        }

        $record = Repository::serviceRequest()->findByUuid($uuid);
        $this->emzTypes = $record !== null ? Repository::serviceRequest()->completionTypes($record) : ['encounter', 'procedure', 'diagnostic_report'];
        $this->selectedEmzType = $this->emzTypes[0];
        $this->loadEmzResourcesForComplete($uuid);
        foreach (['diagnostic_report', 'procedure', 'encounter'] as $type) {
            if (Repository::serviceRequest()->completionResources($person, $uuid, $type) !== []) {
                $this->selectedEmzType = $type;
                $this->loadEmzResourcesForComplete($uuid);
                break;
            }
        }
        $this->remainingQuantity = (float) data_get($referral, 'remaining_quantity.value', 0);
        $this->acknowledgeRemainingQuantity = false;
        $this->showCompleteModal = true;
    }

    public function confirmComplete(ReferralRequestLifecycleService $service): void
    {
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.complete_forbidden'));

            return;
        }
        $uuid = $this->referralToComplete;
        $resourceUuid = $this->selectedEmzUuid;
        $resourceType = $this->selectedEmzType;

        if (empty($uuid) || empty($resourceUuid) || empty($resourceType)) {
            Session::flash('error', __('care-plan.referral_complete_emz_required'));

            return;
        }

        if (!in_array($resourceType, $this->emzTypes, true)) {
            Session::flash('error', __('care-plan.referral_complete_invalid_emz_type'));

            return;
        }

        $referral = collect($this->searchResults)->firstWhere('id', $uuid);
        if (!$referral || !$this->canCompleteReferral($referral)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        if (!$this->assertEmzLinkedToReferral($uuid, $resourceType, $resourceUuid)) {
            Session::flash('error', __('care-plan.referral_complete_emz_not_linked'));

            return;
        }

        if ((float) data_get($referral, 'remaining_quantity.value', 0) > 0 && !$this->acknowledgeRemainingQuantity) {
            Session::flash('warning', __('referrals.messages.remaining_quantity_warning', ['quantity' => data_get($referral, 'remaining_quantity.value')]));

            return;
        }

        try {
            $response = $service->completeReferral($uuid, $resourceUuid, $resourceType, ['acknowledge_remaining_quantity' => $this->acknowledgeRemainingQuantity]);
            if ($response['submissionPending'] ?? false) {
                Session::flash('info', __('care-plan.submission_pending'));
                $this->pendingCompletions[$uuid] = true;
                $this->markCompletionAvailable($uuid);
                $this->showCompleteModal = false;

                return;
            }
            $this->updateSearchResult($uuid, 'completed', $response);

            Session::flash('success', __('referrals.messages.complete_success'));
            $this->showCompleteModal = false;
        } catch (Exception $e) {
            $this->flashOperationError($e);
        }
    }

    public function render()
    {
        return view('livewire.referral.referral-index');
    }

    public function checkPendingCompletions(): void
    {
        foreach (array_keys($this->pendingCompletions) as $uuid) {
            $this->checkReferralCompletion($uuid);
        }
    }

    public function checkReferralCompletion(string $uuid): void
    {
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            $this->pendingCompletions = [];
            Session::flash('error', __('referrals.messages.complete_forbidden'));

            return;
        }
        $referral = collect($this->searchResults)->firstWhere('id', $uuid);
        $record = $referral === null ? null : Repository::serviceRequest()->findByUuid($uuid);
        if ($record === null || Repository::serviceRequest()->patientForRequest($record)?->uuid !== data_get($referral, 'subject.identifier.value')) {
            unset($this->pendingCompletions[$uuid]);

            return;
        }
        try {
            $response = app(ReferralRequestLifecycleService::class)->checkCompletion($record);
            if ($response['submissionPending']) {
                $this->pendingCompletions[$uuid] = true;

                return;
            }
            unset($this->pendingCompletions[$uuid]);
            $this->updateSearchResult($uuid, $response['status'], $response);
            Session::flash($response['status'] === 'completed' ? 'success' : 'warning', __($response['status'] === 'completed'
                ? 'referrals.messages.complete_success'
                : 'referrals.messages.action_unavailable'));
        } catch (Exception $exception) {
            unset($this->pendingCompletions[$uuid]);
            $this->flashOperationError($exception);
        }
    }

    private function restoreCompletionTracking(): void
    {
        $this->pendingCompletions = [];
        $states = Repository::serviceRequest()->completionStates(array_column($this->searchResults, 'id'));
        foreach ($this->searchResults as $index => $referral) {
            if (isset($states[$referral['id']])) {
                $this->searchResults[$index]['hasCompletion'] = true;
            }
            if (($states[$referral['id']] ?? null) === JobStatus::PENDING->value) {
                $this->pendingCompletions[$referral['id']] = true;
            }
        }
    }

    private function markCompletionAvailable(string $uuid): void
    {
        foreach ($this->searchResults as $index => $referral) {
            if ($referral['id'] === $uuid) {
                $this->searchResults[$index]['hasCompletion'] = true;
            }
        }
    }

    public function canTakeIntoWork(array $referral): bool
    {
        return Auth::user()->can('takeIntoWork', [ServiceRequestRequest::class, $referral]);
    }

    public function canManageUsage(array $referral): bool
    {
        return Auth::user()->can('manageUsage', [ServiceRequestRequest::class, $referral]);
    }

    public function canCompleteReferral(array $referral): bool
    {
        return Auth::user()->can('completeReferral', [ServiceRequestRequest::class, $referral]);
    }

    private function updateSearchResult(string $uuid, string $status, array $response): void
    {
        foreach ($this->searchResults as $key => $result) {
            if (($result['id'] ?? '') !== $uuid) {
                continue;
            }

            $this->searchResults[$key]['status'] = $status;
            unset($this->searchResults[$key]['program_processing_status']);
            // Confirmed execution references also control the next available actions.
            foreach (['program_processing_status', 'used_by_employee', 'used_by_legal_entity', 'used_by_legal_entity_history', 'completed_with', 'program'] as $field) {
                if (array_key_exists($field, $response)) {
                    $this->searchResults[$key][$field] = $response[$field];
                }
            }
        }
    }

    private function flashOperationError(Exception $exception): void
    {
        Log::error('ReferralIndex: operation failed', ['exception' => $exception]);
        Session::flash('error', match (true) {
            $exception instanceof EHealthValidationException => $exception->getFormattedMessage(),
            $exception instanceof ValidationException => $exception->validator->errors()->first(),
            default => __('referrals.messages.operation_failed'),
        });
    }

    private function loadEmzResourcesForComplete(string $referralUuid): void
    {
        $this->availableEmzResources = [];
        $this->selectedEmzUuid = '';

        $referral = collect($this->searchResults)->firstWhere('id', $referralUuid);
        $patientId = $referral['subject']['identifier']['value'] ?? null;
        if (!$patientId) {
            return;
        }

        $person = (Person::whereUuid($patientId)->first() ?? Preperson::whereUuid($patientId)->first());
        if (!$person) {
            return;
        }

        $this->availableEmzResources = Repository::serviceRequest()->completionResources($person, $referralUuid, $this->selectedEmzType);
    }

    private function assertEmzLinkedToReferral(string $referralUuid, string $resourceType, string $resourceUuid): bool
    {
        $referral = collect($this->searchResults)->firstWhere('id', $referralUuid);
        $person = (Person::whereUuid(data_get($referral, 'subject.identifier.value'))->first() ?? Preperson::whereUuid(data_get($referral, 'subject.identifier.value'))->first());

        return $person !== null && in_array($resourceUuid, array_column(
            Repository::serviceRequest()->completionResources($person, $referralUuid, $resourceType),
            'uuid'
        ), true);
    }
}
