<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Classes\eHealth\Api\ServiceRequest;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Exception;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ReferralIndex extends Component
{
    #[Locked]
    public LegalEntity $legalEntity;

    public string $requisition = '';

    public array $searchResults = [];

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

    public function search()
    {
        abort_unless(auth()->user()?->can('service_request:read'), 403);
        $this->validate([
            'requisition' => 'required|string',
        ]);

        $this->errorMessage = null;
        $this->hasSearched = true;

        $cleanRequisition = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->requisition));
        $formattedRequisition = trim(preg_replace('/(.{4})/', '$1-', $cleanRequisition), '-');
        $this->requisition = $formattedRequisition;

        try {
            $params = ['requisition' => $this->requisition];
            $response = \App\Classes\eHealth\EHealth::serviceRequest()->searchForServiceRequestsByParams($params)->getData();

            $this->searchResults = $response['data'] ?? $response ?? [];

            if (empty($this->searchResults)) {
                $this->errorMessage = 'Направлення не знайдено.';
            }
        } catch (Exception $e) {
            Log::error('Search referral error: '.$e->getMessage());
            $this->errorMessage = 'Помилка під час пошуку: '.$e->getMessage();
            $this->searchResults = [];
        }
    }

    public function process(string $uuid, string $patientUuid, ReferralRequestLifecycleService $service)
    {
        abort_unless(auth()->user()?->can('service_request:makeinprogress'), 403);
        try {
            $employee = auth()->user()->employees()->where('legal_entity_id', $this->legalEntity->id)->first();

            if (!$employee) {
                throw new Exception('Не знайдено співробітника для виконання дії.');
            }

            $service->takeIntoWork($uuid, $employee, $patientUuid ?: null);

            foreach ($this->searchResults as $key => $result) {
                if (($result['id'] ?? '') === $uuid) {
                    $this->searchResults[$key]['status'] = 'in_progress';
                }
            }

            $this->dispatch('notify', ['type' => 'success', 'message' => 'Направлення успішно взято в роботу']);
        } catch (Exception $e) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Помилка: '.$e->getMessage()]);
        }
    }

    public function openCancelModal(string $uuid)
    {
        $this->referralToCancel = $uuid;
        $this->cancelExplanatoryLetter = '';
        $this->showCancelModal = true;
    }

    public function confirmCancelUsage(ReferralRequestLifecycleService $service)
    {
        abort_unless(auth()->user()?->can('service_request:use'), 403);
        $uuid = $this->referralToCancel;

        if (empty($uuid)) {
            return;
        }

        if (empty(trim($this->cancelExplanatoryLetter))) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Вкажіть причину відміни']);

            return;
        }

        try {
            $referral = collect($this->searchResults)->firstWhere('id', $uuid);
            $patientId = $referral['subject']['identifier']['value'] ?? null;

            if (!$patientId) {
                throw new Exception('Не вдалося знайти ідентифікатор пацієнта.');
            }

            $service->cancelUsage($uuid, $patientId, [
                'explanatory_letter' => $this->cancelExplanatoryLetter,
            ]);

            foreach ($this->searchResults as $key => $result) {
                if (($result['id'] ?? '') === $uuid) {
                    $this->searchResults[$key]['status'] = 'active';
                }
            }

            $this->dispatch('notify', ['type' => 'success', 'message' => 'Використання направлення успішно відмінено']);
            $this->showCancelModal = false;
        } catch (Exception $e) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Помилка: '.$e->getMessage()]);
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

    public function openCompleteModal(string $uuid)
    {
        $this->referralToComplete = $uuid;
        $this->availableEmzResources = [];
        $this->selectedEmzUuid = '';
        $this->selectedEmzType = 'encounter';

        $referral = collect($this->searchResults)->firstWhere('id', $uuid);

        if (!$referral) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Направлення не знайдено.']);

            return;
        }

        $patientId = $referral['subject']['identifier']['value'] ?? null;

        if (!$patientId) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Не вдалося визначити пацієнта для цього направлення.']);

            return;
        }

        $person = Person::where('uuid', $patientId)->first();

        if (!$person) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Пацієнт не знайдений у локальній базі даних.']);

            return;
        }

        $this->loadEmzResourcesForComplete($uuid);
        $this->showCompleteModal = true;
    }

    public function confirmComplete(ReferralRequestLifecycleService $service)
    {
        abort_unless(auth()->user()?->can('service_request:complete'), 403);
        $uuid = $this->referralToComplete;
        $resourceUuid = $this->selectedEmzUuid;
        $resourceType = $this->selectedEmzType;

        if (empty($uuid) || empty($resourceUuid) || empty($resourceType)) {
            $this->dispatch('notify', ['type' => 'error', 'message' => __('care-plan.referral_complete_emz_required')]);

            return;
        }

        if (!in_array($resourceType, $this->emzTypes, true)) {
            $this->dispatch('notify', ['type' => 'error', 'message' => __('care-plan.referral_complete_invalid_emz_type')]);

            return;
        }

        if (!$this->assertEmzLinkedToReferral($uuid, $resourceType, $resourceUuid)) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => __('care-plan.referral_complete_emz_not_linked'),
            ]);

            return;
        }

        try {
            $service->completeReferral($uuid, $resourceUuid, $resourceType);

            foreach ($this->searchResults as $key => $result) {
                if (($result['id'] ?? '') === $uuid) {
                    $this->searchResults[$key]['status'] = 'completed';
                }
            }

            $this->dispatch('notify', ['type' => 'success', 'message' => 'Направлення успішно погашено']);
            $this->showCompleteModal = false;
        } catch (Exception $e) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Помилка: '.$e->getMessage()]);
        }
    }

    public function render()
    {
        return view('livewire.referral.referral-index');
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

    private function assertEmzLinkedToReferral(string $referralUuid, string $resourceType, string $resourceUuid): bool
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
