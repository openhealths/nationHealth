<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Classes\eHealth\Api\ServiceRequest;
use App\Models\LegalEntity;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Exception;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ReferralIndex extends Component
{
    public LegalEntity $legalEntity;

    public string $requisition = '';

    public array $searchResults = [];
    public bool $hasSearched = false;
    public ?string $errorMessage = null;

    public bool $showCompleteModal = false;
    public ?string $referralToComplete = null;
    public array $availableEncounters = [];
    public string $selectedEncounterUuid = '';

    public bool $showCancelModal = false;
    public ?string $referralToCancel = null;
    public string $cancelExplanatoryLetter = '';

    public function search()
    {
        $this->validate([
            'requisition' => 'required|string', // e.g. XXXX-XXXX-XXXX-XXXX
        ]);

        $this->errorMessage = null;
        $this->hasSearched = true;

        // Ensure the requisition is correctly formatted as XXXX-XXXX-XXXX-XXXX
        $cleanRequisition = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->requisition));
        $formattedRequisition = trim(preg_replace('/(.{4})/', '$1-', $cleanRequisition), '-');
        $this->requisition = $formattedRequisition;

        try {
            $params = ['requisition' => $this->requisition];
            $response = ServiceRequest::searchForServiceRequestsByParams($params);

            $this->searchResults = $response['data'] ?? $response ?? [];

            if (empty($this->searchResults)) {
                $this->errorMessage = 'Направлення не знайдено.';
            }

        } catch (Exception $e) {
            Log::error('Search referral error: ' . $e->getMessage());
            $this->errorMessage = 'Помилка під час пошуку: ' . $e->getMessage();
            $this->searchResults = [];
        }
    }

    public function process(string $uuid, string $patientUuid, ReferralRequestLifecycleService $service)
    {
        try {
            $employee = auth()->user()->employees()->where('legal_entity_id', $this->legalEntity->id)->first();

            if (!$employee) {
                throw new Exception('Не знайдено співробітника для виконання дії.');
            }

            $service->takeIntoWork($uuid, $employee, $patientUuid ?: null);

            // Optimistic UI update to avoid stale data from eHealth eventual consistency
            foreach ($this->searchResults as $key => $result) {
                if (($result['id'] ?? '') === $uuid) {
                    $this->searchResults[$key]['status'] = 'in_progress';
                }
            }

            $this->dispatch('notify', ['type' => 'success', 'message' => 'Направлення успішно взято в роботу']);
        } catch (Exception $e) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Помилка: ' . $e->getMessage()]);
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

            // Optimistic UI update
            foreach ($this->searchResults as $key => $result) {
                if (($result['id'] ?? '') === $uuid) {
                    $this->searchResults[$key]['status'] = 'active';
                }
            }

            $this->dispatch('notify', ['type' => 'success', 'message' => 'Використання направлення успішно відмінено']);
            $this->showCancelModal = false;
        } catch (Exception $e) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Помилка: ' . $e->getMessage()]);
        }
    }

    public function cancelUsage(string $uuid, ReferralRequestLifecycleService $service)
    {
        // Legacy direct call — now handled by openCancelModal/confirmCancelUsage
        $this->openCancelModal($uuid);
    }

    public function openCompleteModal(string $uuid)
    {
        $this->referralToComplete = $uuid;
        $this->availableEncounters = [];
        $this->selectedEncounterUuid = '';

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

        $person = \App\Models\Person\Person::where('uuid', $patientId)->first();

        if (!$person) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Пацієнт не знайдений у локальній базі даних.']);

            return;
        }

        $employee = auth()->user()->employees()->where('legal_entity_id', $this->legalEntity->id)->first();

        if ($employee) {
            $encounters = \App\Models\MedicalEvents\Sql\Encounter::where('performer_id', $employee->id)
                ->where('person_id', $person->id)
                ->latest('created_at')
                ->take(50)
                ->get(['uuid', 'created_at', 'status']);

            foreach ($encounters as $encounter) {
                $statusMap = [
                    'finished' => 'Завершено',
                    'entered-in-error' => 'Помилково введено',
                    'in_progress' => 'В процесі',
                ];
                $statusLabel = $statusMap[$encounter->status] ?? $encounter->status;
                $date = $encounter->created_at ? $encounter->created_at->format('d.m.Y H:i') : '';

                $this->availableEncounters[] = [
                    'uuid' => $encounter->uuid,
                    'label' => "ЕМЗ {$encounter->uuid} від {$date} ({$statusLabel})"
                ];
            }
        }

        $this->showCompleteModal = true;
    }

    public function confirmComplete(ReferralRequestLifecycleService $service)
    {
        $uuid = $this->referralToComplete;
        $encounterUuid = $this->selectedEncounterUuid;

        if (empty($uuid) || empty($encounterUuid)) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Виберіть ЕМЗ для погашення']);

            return;
        }

        try {
            $service->completeReferral($uuid, $encounterUuid);

            // Optimistic UI update
            foreach ($this->searchResults as $key => $result) {
                if (($result['id'] ?? '') === $uuid) {
                    $this->searchResults[$key]['status'] = 'completed';
                }
            }

            $this->dispatch('notify', ['type' => 'success', 'message' => 'Направлення успішно погашено']);
            $this->showCompleteModal = false;
        } catch (Exception $e) {
            $this->dispatch('notify', ['type' => 'error', 'message' => 'Помилка: ' . $e->getMessage()]);
        }
    }

    public function render()
    {
        return view('livewire.referral.referral-index');
    }
}
