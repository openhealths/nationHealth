<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\Api\MedicationRequest as MedicationRequestApi;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Throwable;

class PatientMedicationRequests extends BasePatientComponent
{
    /** 'requests' = MedicationRequestRequests (drafts/signed by us); 'prescriptions' = MedicationRequests from eHealth */
    #[Locked]
    public string $activeTab = 'requests';

    /** @var list<array<string, mixed>> */
    public array $medicationRequests = [];

    /** @var list<array<string, mixed>> */
    public array $prescriptions = [];

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $eHealthResults = [];

    public bool $isSearchMode = false;

    public string $searchRequestNumber = '';
    public string $searchStatus = '';
    public bool $searchLoading = false;
    public ?string $searchError = null;

    public string $filterStatus = '';
    public string $filterStartedAtFrom = '';
    public string $filterStartedAtTo = '';
    public string $filterEndedAtFrom = '';
    public string $filterEndedAtTo = '';

    public ?string $expandedUuid = null;

    protected function initializeComponent(): void
    {
        $this->loadLocalData();
    }

    public function loadLocalData(): void
    {
        if ($this->personId === null) {
            $this->medicationRequests = [];
            $this->prescriptions = [];

            return;
        }

        $repo = app(MedicationRequestRepository::class);

        // Resource kind, rather than origin, determines the registry tab.
        $this->medicationRequests = $repo->searchByPersonId(
            $this->personId,
            [
                'status' => $this->filterStatus !== '' ? $this->filterStatus : null,
                'started_at_from' => $this->filterStartedAtFrom !== '' ? $this->filterStartedAtFrom : null,
                'started_at_to' => $this->filterStartedAtTo !== '' ? $this->filterStartedAtTo : null,
                'ended_at_from' => $this->filterEndedAtFrom !== '' ? $this->filterEndedAtFrom : null,
                'ended_at_to' => $this->filterEndedAtTo !== '' ? $this->filterEndedAtTo : null,
                'resource_type' => MedicationRequestRequest::TYPE_REQUEST,
            ]
        );

        // Cached prescriptions from eHealth that the user previously saved to the card
        $this->prescriptions = $repo->searchEHealthPrescriptionsByPersonId(
            $this->personId,
            [
                'status' => $this->filterStatus !== '' ? $this->filterStatus : null,
                'request_number' => $this->searchRequestNumber !== '' ? $this->searchRequestNumber : null,
            ]
        );
    }

    public function loadMedicationRequests(): void
    {
        $this->loadLocalData();
    }

    public function switchTab(string $tab): void
    {
        if (!in_array($tab, ['requests', 'prescriptions'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->resetSearch();
    }

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

    /**
     * Search eHealth for MedicationRequests (signed prescriptions) by request_number / status.
     * Results are shown inline; the user then selects individual records to upsert locally.
     */
    public function searchInEHealth(): void
    {
        $this->authorizeSearch();

        if ($this->personId === null) {
            return;
        }

        $this->searchLoading = true;
        $this->searchError = null;
        $this->eHealthResults = [];

        try {
            $params = array_filter([
                'request_number' => $this->searchRequestNumber !== '' ? $this->searchRequestNumber : null,
                'status' => $this->searchStatus !== '' ? strtolower($this->searchStatus) : null,
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
                $this->searchError = __('medication-requests.search_empty');
            }
        } catch (Throwable $e) {
            Log::error('PatientMedicationRequests eHealth search failed', ['exception_type' => $e::class]);
            $this->searchError = __('medication-requests.search_failed');
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
        $this->authorizeSearch();

        if ($this->personId === null) {
            return;
        }

        $record = collect($this->eHealthResults)->firstWhere('id', $uuid)
            ?? collect($this->eHealthResults)->firstWhere('uuid', $uuid);

        if ($record === null) {
            $this->searchError = __('medication-requests.search_record_missing');

            return;
        }

        try {
            app(MedicationRequestRepository::class)->upsertFromEHealth(
                (array) $record,
                $this->personId,
                $this->activeTab === 'requests'
                    ? MedicationRequestRequest::TYPE_REQUEST
                    : MedicationRequestRequest::TYPE_PRESCRIPTION
            );
            session()->flash('success', __('medication-requests.import_saved'));
        } catch (Throwable $e) {
            Log::error('PatientMedicationRequests import failed', ['exception_type' => $e::class]);
            $this->searchError = __('medication-requests.import_failed');

            return;
        }

        $this->resetSearch();
    }

    protected function authorizeSearch(): void
    {
        $this->authorize($this->activeTab === 'requests' ? 'medication_request_request:read' : 'medication_request:read');
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
