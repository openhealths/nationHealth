<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;
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
    public bool $searchLoading = false;
    public ?string $searchError = null;

    public string $filterStatus = '';
    public string $filterStartedAtFrom = '';
    public string $filterStartedAtTo = '';
    public string $filterEndedAtFrom = '';
    public string $filterEndedAtTo = '';

    public array $additionalFilters = [
        'medication_id' => '', 'encounter_id' => '', 'medical_program_id' => '',
        'legal_entity_id' => '', 'employee_id' => '', 'care_plan_id' => '', 'episode_id' => '',
        'created_from' => '', 'created_to' => '',
        'dispense_valid_from_start' => '', 'dispense_valid_from_end' => '',
        'dispense_valid_to_start' => '', 'dispense_valid_to_end' => '',
    ];

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

        $repo = Repository::medicationRequest();

        // Resource kind, rather than origin, determines the registry tab.
        $this->medicationRequests = $repo->searchByPersonId(
            $this->personId,
            [
                ...array_filter($this->additionalFilters, static fn ($value): bool => $value !== ''),
                'status' => $this->filterStatus !== '' ? $this->filterStatus : null,
                'request_number' => $this->searchRequestNumber !== '' ? $this->searchRequestNumber : null,
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
                ...array_filter($this->additionalFilters, static fn ($value): bool => $value !== ''),
                'status' => $this->filterStatus !== '' ? $this->filterStatus : null,
                'request_number' => $this->searchRequestNumber !== '' ? $this->searchRequestNumber : null,
                'started_at_from' => $this->filterStartedAtFrom !== '' ? $this->filterStartedAtFrom : null,
                'started_at_to' => $this->filterStartedAtTo !== '' ? $this->filterStartedAtTo : null,
                'ended_at_from' => $this->filterEndedAtFrom !== '' ? $this->filterEndedAtFrom : null,
                'ended_at_to' => $this->filterEndedAtTo !== '' ? $this->filterEndedAtTo : null,
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
        $this->isSearchMode = false;
        $this->eHealthResults = [];
        $this->searchError = null;
        $this->loadLocalData();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'searchRequestNumber',
            'filterStatus',
            'filterStartedAtFrom',
            'filterStartedAtTo',
            'filterEndedAtFrom',
            'filterEndedAtTo',
            'additionalFilters',
        ]);
        $this->applyFilters();
    }

    /**
     * Search eHealth for MedicationRequests (signed prescriptions) by request_number / status.
     * Results are shown inline; the user then selects individual records to upsert locally.
     */
    public function searchInEHealth(): void
    {
        if (!$this->authorizeSearch()) {
            return;
        }

        if ($this->personId === null) {
            return;
        }

        $this->searchLoading = true;
        $this->searchError = null;
        $this->eHealthResults = [];

        try {
            $this->validate([
                'additionalFilters.created_from' => ['nullable', 'date'],
                'additionalFilters.created_to' => ['nullable', 'date', 'after_or_equal:additionalFilters.created_from'],
                'filterStartedAtFrom' => ['nullable', 'date'], 'filterStartedAtTo' => ['nullable', 'date', 'after_or_equal:filterStartedAtFrom'],
                'filterEndedAtFrom' => ['nullable', 'date'], 'filterEndedAtTo' => ['nullable', 'date', 'after_or_equal:filterEndedAtFrom'],
                'additionalFilters.dispense_valid_from_start' => ['nullable', 'date'],
                'additionalFilters.dispense_valid_from_end' => ['nullable', 'date', 'after_or_equal:additionalFilters.dispense_valid_from_start'],
                'additionalFilters.dispense_valid_to_start' => ['nullable', 'date'],
                'additionalFilters.dispense_valid_to_end' => ['nullable', 'date', 'after_or_equal:additionalFilters.dispense_valid_to_start'],
            ]);
            $params = array_filter(array_replace($this->additionalFilters, [
                'request_number' => $this->searchRequestNumber,
                'status' => $this->filterStatus,
                'started_at_from' => $this->filterStartedAtFrom, 'started_at_to' => $this->filterStartedAtTo,
                'ended_at_from' => $this->filterEndedAtFrom, 'ended_at_to' => $this->filterEndedAtTo,
            ]), static fn ($value): bool => $value !== '');
            $apiParams = $params;
            if ($this->activeTab === 'requests') {
                // MRR list has fewer native filters. Apply its unsupported date/number filters after all pages are validated.
                $apiParams = array_intersect_key($params, array_flip(['legal_entity_id', 'employee_id', 'episode_id', 'care_plan_id', 'encounter_id', 'status', 'intent']));
            }
            $items = app(MedicationRequestLifecycleService::class)->searchPatient($this->uuid, $this->activeTab === 'requests', $apiParams);
            foreach ($items as $item) {
                if (data_get($item, 'person.id') !== $this->uuid) {
                    throw new InvalidArgumentException(__('medication-requests.detail_unavailable'));
                }
            }
            $this->eHealthResults = $this->activeTab === 'requests'
                ? app(MedicationRequestLifecycleService::class)->filterDocuments($items, $params)
                : $items;
            $this->isSearchMode = true;

            if (empty($this->eHealthResults)) {
                $this->searchError = __('medication-requests.search_empty');
            }
        } catch (EHealthValidationException $e) {
            $this->searchError = $e->getTranslatedMessage();
        } catch (EHealthResponseException $e) {
            Log::warning('PatientMedicationRequests eHealth search rejected', ['status' => $e->getCode()]);
            $this->searchError = __('medication-requests.search_failed');
        } catch (EHealthConnectionException $e) {
            $this->searchError = __('errors.ehealth.messages.no_connection');
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
        if (!$this->authorizeSearch()) {
            return;
        }

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
            $resourceType = $this->activeTab === 'requests' ? MedicationRequestRequest::TYPE_REQUEST : MedicationRequestRequest::TYPE_PRESCRIPTION;
            $detail = $resourceType === MedicationRequestRequest::TYPE_REQUEST
                ? EHealth::medicationRequestRequest()->getById($this->uuid, $uuid)->validate()
                : EHealth::medicationRequest()->getById($this->uuid, $uuid)->validate();
            app(MedicationRequestLifecycleService::class)->assertPatientSnapshot($detail, $this->uuid, $uuid);
            Repository::medicationRequest()->storeResource((new MedicationRequestMapper())->fromEHealth($detail), $this->personId, resourceType: $resourceType, native: $detail);
            Session::flash('success', __('medication-requests.import_saved'));
        } catch (Throwable $e) {
            Log::error('PatientMedicationRequests import failed', ['exception_type' => $e::class]);
            $this->searchError = __('medication-requests.import_failed');

            return;
        }

        $this->resetSearch();
    }

    protected function authorizeSearch(): bool
    {
        if (Auth::user()->cannot($this->activeTab === 'requests' ? 'medication_request_request:read' : 'medication_request:read')) {
            Session::flash('error', __('medication-requests.permission_denied'));

            return false;
        }

        return true;
    }

    public function synchronizeSignedPrescriptions(): void
    {
        if (Auth::user()->cannot('medication_request:read')) {
            Session::flash('error', __('medication-requests.permission_denied'));

            return;
        }
        if ($this->personId === null) {
            return;
        }
        try {
            foreach (Repository::medicationRequest()->pendingSignedPrescriptions($this->personId) as $request) {
                app(MedicationRequestLifecycleService::class)->synchronizeSignedPrescription($request);
            }
            Session::flash('success', __('medication-requests.signed_details_synchronized'));
        } catch (Throwable $exception) {
            Log::warning('Signed prescription synchronization failed', ['exceptionType' => $exception::class]);
            Session::flash('error', __('medication-requests.signed_details_pending'));
        }
        $this->resetSearch();
    }

    public function resetSearch(): void
    {
        $this->isSearchMode = false;
        $this->eHealthResults = [];
        $this->searchError = null;
        $this->searchRequestNumber = '';
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
