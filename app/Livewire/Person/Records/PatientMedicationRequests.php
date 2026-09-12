<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\Api\MedicationRequest as MedicationRequestApi;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;

class PatientMedicationRequests extends BasePatientComponent
{
    // ── Tabs ──────────────────────────────────────────────────────────────────
    /** 'requests' = MedicationRequestRequests (drafts/signed by us); 'prescriptions' = MedicationRequests from eHealth */
    public string $activeTab = 'requests';

    // ── Local data (from our DB) ───────────────────────────────────────────────
    /** @var list<array<string, mixed>> */
    public array $medicationRequests = [];

    /** @var list<array<string, mixed>> */
    public array $prescriptions = [];

    // ── eHealth search ────────────────────────────────────────────────────────
    /** @var list<array<string, mixed>> */
    public array $eHealthResults = [];

    public bool $isSearchMode = false;

    public string $searchRequestNumber = '';
    public string $searchStatus = '';
    public bool $searchLoading = false;
    public ?string $searchError = null;

    // ── Shared filters for local view ─────────────────────────────────────────
    public string $filterStatus = '';
    public string $filterStartedAtFrom = '';
    public string $filterStartedAtTo = '';
    public string $filterEndedAtFrom = '';
    public string $filterEndedAtTo = '';

    // ── Expanded row UUID (for request details) ───────────────────────────────
    public ?string $expandedUuid = null;

    protected function initializeComponent(): void
    {
        $this->loadLocalData();
    }

    // ── Data loading ──────────────────────────────────────────────────────────

    public function loadLocalData(): void
    {
        if ($this->personId === null) {
            $this->medicationRequests = [];
            $this->prescriptions = [];
            return;
        }

        $repo = app(MedicationRequestRepository::class);

        // eRx-requests (drafted here) – exclude ehealth-sourced prescriptions
        $this->medicationRequests = $repo->searchByPersonId(
            $this->personId,
            [
                'status'           => $this->filterStatus !== '' ? $this->filterStatus : null,
                'started_at_from'  => $this->filterStartedAtFrom !== '' ? $this->filterStartedAtFrom : null,
                'started_at_to'    => $this->filterStartedAtTo !== '' ? $this->filterStartedAtTo : null,
                'ended_at_from'    => $this->filterEndedAtFrom !== '' ? $this->filterEndedAtFrom : null,
                'ended_at_to'      => $this->filterEndedAtTo !== '' ? $this->filterEndedAtTo : null,
                'source'           => MedicationRequestRequest::SOURCE_LOCAL,
            ]
        );

        // Cached prescriptions from eHealth that the user previously saved to the card
        $this->prescriptions = $repo->searchEHealthPrescriptionsByPersonId(
            $this->personId,
            [
                'status'         => $this->filterStatus !== '' ? $this->filterStatus : null,
                'request_number' => $this->searchRequestNumber !== '' ? $this->searchRequestNumber : null,
            ]
        );
    }

    public function loadMedicationRequests(): void
    {
        $this->loadLocalData();
    }

    // ── Tab switching ─────────────────────────────────────────────────────────

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetSearch();
    }

    // ── Local filters ─────────────────────────────────────────────────────────

    public function applyFilters(): void
    {
        $this->loadLocalData();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterStatus',
            'filterStartedAtFrom',
            'filterStartedAtTo',
            'filterEndedAtFrom',
            'filterEndedAtTo',
        ]);
        $this->loadLocalData();
    }

    // ── eHealth search ────────────────────────────────────────────────────────

    /**
     * Search eHealth for MedicationRequests (signed prescriptions) by request_number / status.
     * Results are shown inline; the user then selects individual records to upsert locally.
     */
    public function searchInEHealth(): void
    {
        if ($this->personId === null) {
            return;
        }

        $this->searchLoading = true;
        $this->searchError = null;
        $this->eHealthResults = [];

        try {
            $params = array_filter([
                'request_number' => $this->searchRequestNumber !== '' ? $this->searchRequestNumber : null,
                'status'         => $this->searchStatus !== '' ? strtolower($this->searchStatus) : null,
            ]);

            if ($this->activeTab === 'requests') {
                // MedicationRequestRequests – drafts / requests
                $response = MedicationRequestApi::getRequestsBySearchParams($this->uuid, $params);
            } else {
                // MedicationRequests – signed prescriptions
                $response = MedicationRequestApi::getBySearchParams($this->uuid, $params);
            }

            $this->eHealthResults = $response['data'] ?? $response ?? [];
            $this->isSearchMode = true;

            if (empty($this->eHealthResults)) {
                $this->searchError = 'Нічого не знайдено в ЄСОЗ за вказаними параметрами.';
            }
        } catch (\Throwable $e) {
            Log::error('PatientMedicationRequests eHealth search failed: ' . $e->getMessage());
            $this->searchError = 'Помилка пошуку в ЄСОЗ: ' . $e->getMessage();
        } finally {
            $this->searchLoading = false;
        }
    }

    /**
     * Upsert a single record from eHealth search results into the local DB,
     * then return to local view so the user sees it immediately in the list.
     */
    public function saveFromEHealth(string $uuid): void
    {
        if ($this->personId === null) {
            return;
        }

        $record = collect($this->eHealthResults)->firstWhere('id', $uuid)
            ?? collect($this->eHealthResults)->firstWhere('uuid', $uuid);

        if ($record === null) {
            $this->searchError = 'Запис не знайдено в результатах пошуку.';
            return;
        }

        try {
            app(MedicationRequestRepository::class)->upsertFromEHealth((array) $record, $this->personId);
            session()->flash('success', 'Рецепт збережено до картки пацієнта.');
        } catch (\Throwable $e) {
            Log::error('PatientMedicationRequests upsertFromEHealth failed: ' . $e->getMessage());
            session()->flash('error', 'Не вдалося зберегти запис: ' . $e->getMessage());
        }

        $this->resetSearch();
    }

    public function resetSearch(): void
    {
        $this->isSearchMode = false;
        $this->eHealthResults = [];
        $this->searchError = null;
        $this->searchRequestNumber = '';
        $this->searchStatus = '';
        $this->loadLocalData();
    }

    public function toggleExpand(string $uuid): void
    {
        $this->expandedUuid = $this->expandedUuid === $uuid ? null : $uuid;
    }

    public function render(): View
    {
        return view('livewire.person.records.medication-requests');
    }
}
