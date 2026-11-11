<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage\Concerns;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use RuntimeException;
use Throwable;

/**
 * Select the incoming referral before signing an encounter package.
 * The component keeps its existing Livewire actions; repositories and the lifecycle own persistence and ESOZ mutations.
 */
trait ManagesIncomingReferralSelection
{
    /**
     * UUID of the electronic referral already taken into work at selection time.
     * Keeps qualify/process off the KEP signing path.
     */
    public ?string $preparedElectronicReferralUuid = null;

    /** Server-confirmed UUID; browser state must never authorize signing. */
    #[Locked]
    public ?string $confirmedElectronicReferralUuid = null;

    /** Accepted Use receipt that still needs a final ESOZ result. */
    #[Locked]
    public ?string $pendingElectronicReferralUuid = null;

    /**
     * Called when the doctor picks an electronic referral from the dropdown.
     * Runs eHealth use/qualify here so signing the encounter stays on Cipher only.
     */
    public function selectElectronicReferral(string $referralUuid, ReferralRequestLifecycleService $lifecycle): bool|string
    {
        if (Auth::user()->cannot('use', ServiceRequestRequest::class)) {
            Session::flash('error', __('service-requests.policy.use'));

            return false;
        }
        $this->resetIncomingReferralSelection();

        $referralUuid = trim($referralUuid);
        if ($referralUuid === '' || !Str::isUuid($referralUuid)) {
            return false;
        }

        $referral = collect($this->availableReferrals)->firstWhere('id', $referralUuid);
        if ($referral === null) {
            Session::flash('error', __('encounters.messages.referral_not_found'));

            return false;
        }

        $this->selectedReferralUuid = $referralUuid;
        $this->form->encounter['referralType'] = 'electronic';
        $this->form->encounter['referralNumber'] = (string) ($referral['requisition'] ?? $referralUuid);

        try {
            if (!$this->ensureReferralTakenIntoWork($lifecycle, $referralUuid)) {
                $this->pendingElectronicReferralUuid = $referralUuid;
                Session::flash('info', __('encounters.messages.referral_use_pending'));

                return 'pending';
            }
            $this->preparedElectronicReferralUuid = $referralUuid;
            $this->confirmedElectronicReferralUuid = $referralUuid;

            return true;
        } catch (Throwable $exception) {
            $this->preparedElectronicReferralUuid = null;
            $this->selectedReferralUuid = null;
            report($exception);
            $message = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : $exception->getMessage();
            $this->addError('form.encounter.referralNumber', $message);
            Session::flash(
                'error',
                __('encounters.messages.referral_use_failed', ['message' => $message])
            );

            return false;
        }
    }

    /**
     * Resolve a pasted requisition in ESOZ, then select its only matching patient referral.
     * A requisition can identify several documents, so ambiguous matches remain available for an explicit choice.
     */
    public function selectElectronicReferralByNumber(string $number, ReferralRequestLifecycleService $lifecycle): bool|string
    {
        if (Auth::user()->cannot('search', ServiceRequestRequest::class)
            || Auth::user()->cannot('use', ServiceRequestRequest::class)) {
            Session::flash('error', __('service-requests.policy.use'));

            return false;
        }

        $this->resetIncomingReferralSelection();
        $cleanNumber = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $number) ?? '');
        $this->form->encounter['referralType'] = 'electronic';
        $this->form->encounter['referralNumber'] = implode('-', str_split($cleanNumber, 4));

        if ($cleanNumber === '' || strlen($cleanNumber) > 16 || ($this->personId === null && $this->prepersonId === null)) {
            $this->addError('form.encounter.referralNumber', __('encounters.messages.referral_number_invalid'));

            return false;
        }

        try {
            $patient = $this->patient();
            if ($patient === null || $patient->uuid !== $this->patientUuid) {
                throw new RuntimeException(__('encounters.messages.referral_not_found'));
            }
            $results = $this->searchIncomingReferralMatches($cleanNumber);
            $this->importIncomingReferralMatches($results, $patient);
            $this->refreshIncomingReferralOptions($results);

            if (count($this->availableReferrals) === 1) {
                return $this->selectElectronicReferral($this->availableReferrals[0]['id'], $lifecycle);
            }
            if ($this->availableReferrals !== []) {
                Session::flash('info', __('encounters.messages.referral_number_multiple'));

                return false;
            }
            $this->addError('form.encounter.referralNumber', __('encounters.messages.referral_not_found'));
            Session::flash('error', __('encounters.messages.referral_not_found'));
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('form.encounter.referralNumber', __('errors.ehealth.messages.electronic_referral_search_failed'));
            Session::flash('error', __('errors.ehealth.messages.electronic_referral_search_failed'));
        }

        return false;
    }

    /** Confirm an accepted Use request without submitting the mutation a second time. */
    public function checkElectronicReferralPreparation(ReferralRequestLifecycleService $lifecycle): bool|string
    {
        if (Auth::user()->cannot('use', ServiceRequestRequest::class)) {
            Session::flash('error', __('service-requests.policy.use'));

            return false;
        }

        $uuid = $this->pendingElectronicReferralUuid;
        if ($uuid === null) {
            return false;
        }

        try {
            $record = Repository::serviceRequest()->findByUuid($uuid);
            if ($record === null || Repository::serviceRequest()->patientForRequest($record)?->uuid !== $this->patientUuid) {
                throw new RuntimeException(__('encounters.messages.referral_not_found'));
            }
            $result = $lifecycle->checkCompletion($record, 'service_request:use');
            if ($result['submissionPending'] ?? false) {
                return 'pending';
            }
            $record = $record->fresh();
            if (Auth::user()->cannot('manageUsage', [ServiceRequestRequest::class, Arr::toSnakeCase(Repository::serviceRequest()->getResource($record))])) {
                throw new RuntimeException(__('encounters.messages.referral_selection_required'));
            }
            $this->confirmedElectronicReferralUuid = $uuid;
            $this->preparedElectronicReferralUuid = $uuid;
            $this->pendingElectronicReferralUuid = null;
            $this->resetValidation('form.encounter.referralNumber');
            Session::flash('success', __('encounters.referral_selected'));

            return true;
        } catch (Throwable $exception) {
            $this->pendingElectronicReferralUuid = null;
            $this->selectedReferralUuid = null;
            report($exception);
            $message = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : $exception->getMessage();
            $this->addError('form.encounter.referralNumber', $message);
            Session::flash('error', __('encounters.messages.referral_use_failed', ['message' => $message]));

            return false;
        }
    }

    /** Clear the previous choice before resolving a different referral, including its pending receipt. */
    private function resetIncomingReferralSelection(): void
    {
        $this->preparedElectronicReferralUuid = null;
        $this->confirmedElectronicReferralUuid = null;
        $this->pendingElectronicReferralUuid = null;
        $this->selectedReferralUuid = null;
        $this->resetValidation('form.encounter.referralNumber');
    }

    /** Read every requisition page and keep only usable matches for the locked patient context. */
    private function searchIncomingReferralMatches(string $cleanNumber): array
    {
        $results = [];
        $page = 1;
        do {
            $response = EHealth::serviceRequest()->searchForServiceRequestsByParams([
                'requisition' => $this->form->encounter['referralNumber'],
                'status' => 'active', 'page' => $page, 'page_size' => 100,
            ]);
            foreach ($response->validate() as $referral) {
                if (data_get($referral, 'subject.identifier.value') === $this->patientUuid
                    && ($referral['status'] ?? '') === 'active'
                    && ($referral['program_processing_status'] ?? '') !== 'completed'
                    && strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $referral['requisition'] ?? '') ?? '') === $cleanNumber) {
                    $results[$referral['id']] = $referral;
                }
            }
            $page++;
        } while ($response->isNotLast());

        return $results;
    }

    /** Validate complete snapshots before import; the repository preserves any pending local operation. */
    private function importIncomingReferralMatches(array $results, Person|Preperson $patient): void
    {
        foreach (array_keys($results) as $uuid) {
            $details = EHealth::serviceRequest()->getById($this->patientUuid, $uuid)->validate();
            $resource = (new ServiceRequestMapper())->normalize($details);
            if (($resource['id'] ?? '') !== $uuid
                || data_get($resource, 'subject.identifier.value') !== $this->patientUuid) {
                throw new RuntimeException(__('encounters.messages.referral_not_found'));
            }
            Repository::serviceRequest()->importPatientResource($resource, $patient);
        }
    }

    /** Refresh the dropdown while retaining ESOZ initials and full age for the requisition's matches. */
    private function refreshIncomingReferralOptions(array $results): void
    {
        $this->referralsLoaded = false;
        $this->loadAvailableReferrals();
        $this->availableReferrals = collect($this->availableReferrals)
            ->filter(static fn (array $referral): bool => isset($results[$referral['id']]))
            ->map(static function (array $referral) use ($results): array {
                $identity = $results[$referral['id']]['identity'] ?? [];
                $referral['identity'] = trim(implode(' ', array_filter([
                    $identity['last_name'] ?? '',
                    filled($identity['first_name'] ?? null) ? Str::substr($identity['first_name'], 0, 1).'.' : '',
                    filled($identity['second_name'] ?? null) ? Str::substr($identity['second_name'], 0, 1).'.' : '',
                    isset($identity['age']) ? __('encounters.referral_patient_age', ['age' => $identity['age']]) : '',
                ])));

                return $referral;
            })->values()->all();
    }

    /**
     * Take an active electronic referral into work (eHealth use / qualify).
     * The lifecycle verifies current eHealth processing status and executor.
     */
    private function ensureReferralTakenIntoWork(ReferralRequestLifecycleService $service, string $referralUuid): bool
    {
        $local = Repository::serviceRequest()->findByUuid($referralUuid);
        if ($local === null || Repository::serviceRequest()->patientForRequest($local)?->uuid !== $this->patientUuid) {
            throw new RuntimeException(__('encounters.messages.referral_not_found'));
        }

        if (Repository::serviceRequest()->latestCompletion($local, 'service_request:use')?->status === JobStatus::PENDING->value) {
            return false;
        }

        $category = $local->category?->coding?->first()?->code ?? '';
        $choices = Repository::serviceRequest()->executorChoices($category);
        // Reuse the encounter's explicit performer choice; never silently select the first linked employee.
        $performerUuid = $this->form->encounter['performerId'] ?? null;
        $choice = filled($performerUuid) ? collect($choices)->firstWhere('uuid', $performerUuid) : (count($choices) === 1 ? $choices[0] : null);
        if ($choice === null) {
            throw new RuntimeException(__('referrals.messages.executor_unavailable'));
        }
        $employee = Repository::serviceRequest()->executorForUse($choice['id'], $category);

        $response = $service->takeIntoWork(
            $referralUuid,
            $employee,
            $this->patientUuid ?: null,
            array_filter([
                'program_id' => $local?->programId,
            ])
        );

        return !($response['submissionPending'] ?? false);
    }

    /** Resolve package references through the parent and require confirmed Use before KEP signing. */
    protected function resolveAllReferrals(array &$validated, bool $requirePreparation = false): void
    {
        parent::resolveAllReferrals($validated);

        if (
            $requirePreparation
            && ($validated['encounter']['referralType'] ?? '') === 'electronic'
            && !empty($validated['encounter']['referralNumber'])
            && $this->confirmedElectronicReferralUuid !== $validated['encounter']['referralNumber']
        ) {
            throw ValidationException::withMessages([
                'form.encounter.referralNumber' => __('encounters.messages.referral_selection_required'),
            ]);
        }
    }
}
