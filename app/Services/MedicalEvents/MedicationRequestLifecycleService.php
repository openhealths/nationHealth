<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\Api\MedicationRequest;
use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Contracts\EHealthRequestLifecycleContract;
use App\Enums\Person\MedicationRequestStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use App\Services\MedicalEvents\Concerns\ResolvesEmployeeContext;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MedicationRequestLifecycleService extends EHealthRequestLifecycleService implements EHealthRequestLifecycleContract
{
    use ResolvesEmployeeContext;

    public function preQualify(array $payload): array
    {
        return $this->callEHealth('Prequalify', static fn (): array => MedicationRequest::preQualify($payload));
    }

    public function createDraft(array $payload): array
    {
        return $this->callEHealth('Create Draft', static fn (): array => MedicationRequest::createMedicationRequest($payload));
    }

    public function sign(string $id, array $payload): array
    {
        return $this->callEHealth('Sign', static fn (): array => MedicationRequest::signMedicationRequest($id, $payload));
    }

    public function reject(string $id, array $payload): array
    {
        return $this->callEHealth('Reject', static fn (): array => MedicationRequest::rejectMedicationRequest($id, $payload));
    }

    protected function requestType(): string
    {
        return 'ePrescription';
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function requireLocalKep(array $formData): void
    {
        if (trim((string) ($formData['password'] ?? '')) === '' || trim((string) ($formData['knedp'] ?? '')) === '') {
            throw new InvalidArgumentException(__('care-plan.kep_signature_required'));
        }

        $this->resolveSignerTaxId($formData);
    }

    /**
     * Create and locally persist a prescription draft for a care plan activity.
     *
     * @param  array<string, mixed>  $formData
     * @param  array<string, int|string|null>  $employeeContext
     * @return string The prescription uuid assigned by eHealth
     */
    public function createCarePlanDraft(CarePlan $carePlan, ?CarePlanActivity $activity, array $formData = [], array $employeeContext = []): string
    {
        $signatureText = trim((string) ($formData['signature_text'] ?? ''));
        if ($signatureText === '' && empty($formData['dosage_instructions'])) {
            throw new InvalidArgumentException(__('care-plan.eprescription_signature_required'));
        }

        $maxDosePerAdministration = (float) ($formData['max_dose_per_administration'] ?? 0);
        $maxDosePerPeriod = (float) ($formData['max_dose_per_period'] ?? 0);
        if (empty($formData['dosage_instructions']) && ($maxDosePerAdministration <= 0 || $maxDosePerPeriod <= 0)) {
            throw new InvalidArgumentException(__('care-plan.eprescription_dose_required'));
        }

        if ($activity !== null) {
            app(MedicationRequestRepository::class)->assertActivityBasis($carePlan, $activity, $formData);
            app(ActivityRemainingQuantityGuard::class)->assertCanIssue(
                (int) $activity->id,
                (float) ($formData['medication_qty'] ?? 1),
                fn (int $activityId): float => app(MedicationRequestRepository::class)->occupiedQuantity($activity->uuid)
            );
        }

        $selectedEncounterId = isset($formData['encounter_id']) && $formData['encounter_id'] !== ''
            ? (int) $formData['encounter_id']
            : null;

        $activeEncounter = $this->resolveEligibleEncounterForCreate(
            (int) $carePlan->personId,
            $employeeContext['employee_uuid'] ?? null,
            $selectedEncounterId
        );

        $dbData = [
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $carePlan->personId,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => MedicationRequestStatus::DRAFT->value,
            'created_at' => now()->toDateString(),
            'started_at' => $formData['started_at'] ?? now()->toDateString(),
            'ended_at' => $formData['ended_at'] ?? now()->addDays(30)->toDateString(),
            'medication_id' => $formData['medication_id'] ?? null,
            'medication_qty' => (float) ($formData['medication_qty'] ?? 1),
            'medication_program_id' => $formData['program_id'] ?? null,
            'intent' => 'order',
            'category' => $formData['category'] ?? 'community',
            'based_on_uuid' => $activity ? $activity->uuid : null,
            'context_uuid' => $activeEncounter->uuid,
            'container_dosage' => $formData['container_dosage'] ?? null,
            'note' => $formData['note'] ?? null,
            'dosage_instructions' => $this->dosageInstructions($formData),
            'inform_with' => $formData['inform_with'] ?? null,
        ];

        $uuids = [
            'person_uuid' => $carePlan->person->uuid,
            'encounter_uuid' => $activeEncounter->uuid,
            'episode_uuid' => $carePlan->episodeUuid() ?? $activeEncounter->episode?->value,
            'employee_uuid' => $employeeContext['employee_uuid'] ?? null,
            'legal_entity_uuid' => $employeeContext['legal_entity_uuid'] ?? null,
            'division_uuid' => app(MedicationRequestRepository::class)->divisionUuid($employeeContext['division_id'] ?? null),
        ];

        return $this->submitDraft($dbData, $uuids, $carePlan->uuid, (int) $carePlan->personId);
    }

    /**
     * Create and locally persist a prescription draft straight from an encounter, without a
     * care plan.
     *
     * @param  array<string, mixed>  $formData
     * @param  array<string, int|string|null>  $employeeContext
     * @return string The prescription uuid assigned by eHealth
     */
    public function createEncounterDraft(Encounter $encounter, array $formData = [], array $employeeContext = []): string
    {
        $dbData = [
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $encounter->person_id,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => MedicationRequestStatus::DRAFT->value,
            'created_at' => now()->toDateString(),
            'started_at' => convertToYmd($formData['started_at'] ?? now()->toDateString()),
            'ended_at' => convertToYmd($formData['ended_at'] ?? now()->addDays(30)->toDateString()),
            'medication_id' => $formData['medication_id'] ?? null,
            'medication_qty' => (float) ($formData['medication_qty'] ?? 1),
            'medication_program_id' => $formData['program_id'] ?? null,
            'intent' => 'order',
            'category' => $formData['category'] ?? 'community',
            'based_on_uuid' => null,
            'context_uuid' => $encounter->uuid,
            'container_dosage' => $formData['container_dosage'] ?? null,
            'note' => $formData['note'] ?? null,
            'dosage_instructions' => $this->dosageInstructions($formData),
            'inform_with' => $formData['inform_with'] ?? null,
        ];

        $personUuid = app(MedicationRequestRepository::class)->personUuid((int) $encounter->person_id);
        $episodeUuid = $encounter->episode?->value ?? null;

        $uuids = [
            'person_uuid' => $personUuid,
            'encounter_uuid' => $encounter->uuid,
            'episode_uuid' => $episodeUuid,
            'employee_uuid' => $employeeContext['employee_uuid'] ?? null,
            'legal_entity_uuid' => $employeeContext['legal_entity_uuid'] ?? null,
            'division_uuid' => app(MedicationRequestRepository::class)->divisionUuid($employeeContext['division_id'] ?? null),
        ];

        return $this->submitDraft($dbData, $uuids, null, (int) $encounter->person_id);
    }

    /**
     * Prequalify the medical program when one is set, create the draft in eHealth and persist
     * the accepted answer locally.
     *
     * @param  array<string, mixed>  $dbData
     * @param  array<string, string|null>  $uuids
     * @return string The prescription uuid assigned by eHealth
     */
    private function submitDraft(array $dbData, array $uuids, ?string $carePlanUuid, int $personId): string
    {
        $mapper = new MedicationRequestMapper();

        if (!empty($dbData['medication_program_id'])) {
            $this->runPrequalify(
                MedicationRequest::preQualify($mapper->toPrequalifyPayload($dbData, $uuids, $carePlanUuid))
            );
        }

        $createResponse = MedicationRequest::createMedicationRequest(
            $mapper->toCreateRequestPayload($dbData, $uuids, $carePlanUuid)
        );

        // resolve() raises on a failed or unresolved job, so nothing below runs unless
        // eHealth accepted the draft.
        $finalCreateResponse = $this->jobResolver->resolve($createResponse);

        $accepted = MedicationRequestApi::mutationResult($finalCreateResponse, ['NEW']);
        $dbData['request_number'] = $accepted['request_number'] ?? null;
        $dbData['uuid'] = $accepted['id'];
        $dbData['status'] = MedicationRequestStatus::NEW->value;
        $dbData['care_plan_uuid'] = $carePlanUuid;
        $dbData['ehealth_payload'] = $accepted;

        app(MedicationRequestRepository::class)->store($dbData, $personId);

        return $dbData['uuid'];
    }

    /** Legacy scalar maxima remain explicit and are rejected by the mapper when units/Ratio are missing. */
    private function dosageInstructions(array $formData): array
    {
        if (isset($formData['dosage_instructions'])) {
            return $formData['dosage_instructions'];
        }

        $medication = EHealth::drug()->getPrescriptionMedication(
            (string) ($formData['medication_id'] ?? ''),
            \App\Services\Dictionary\MedicationProgramPicker::resolve($formData['program_id'] ?? null)
        );

        return (new MedicationRequestMapper())->dosageInstructionsFromForm($formData, $medication);
    }

    /**
     * Sign a locally stored prescription draft and report what the patient should be told.
     *
     * @param  array<string, mixed>  $formData
     * @param  float  $remainingQty  Care plan quantity left before this prescription
     * @return array<string, mixed>
     */
    public function signPrescription(
        CarePlan|Encounter $contextModel,
        MedicationRequestRequest $requestRecord,
        array $formData = [],
        string $informWith = '',
        float $remainingQty = 0.0
    ): array {
        $this->requireLocalKep($formData);

        $personId = $contextModel instanceof CarePlan ? (int) $contextModel->personId : (int) $contextModel->person_id;
        if ($personId !== (int) $requestRecord->personId) {
            throw new InvalidArgumentException('The medication signing context belongs to another patient.');
        }

        $activityUuid = $requestRecord->basedOn?->value;
        if ($activityUuid) {
            $activityForQty = app(MedicationRequestRepository::class)->findActivity(
                $activityUuid,
                (int) $requestRecord->personId,
                $contextModel instanceof CarePlan ? (int) $contextModel->id : null,
                legalEntity()?->id
            );
            if ($activityForQty === null) {
                throw new InvalidArgumentException('The medication activity is outside the signing context.');
            }
            app(ActivityRemainingQuantityGuard::class)->assertCanIssue(
                (int) $activityForQty->id,
                (float) ($requestRecord->medicationQty ?? 0),
                fn (int $activityId): float => app(MedicationRequestRepository::class)->occupiedQuantity($activityUuid, $requestRecord->uuid)
            );
        }

        $signedContent = signatureService()->signData(
            $this->buildSignPayload($contextModel, $requestRecord, $informWith),
            $formData['password'],
            $formData['knedp'],
            $formData['keyContainerUpload'] ?? null,
            $this->resolveSignerTaxId($formData)
        );

        $payload = [
            'signed_medication_request_request' => $signedContent,
            'signed_content_encoding' => 'base64',
        ];

        $response = MedicationRequest::signMedicationRequest($requestRecord->uuid, $payload);

        $finalResponse = $this->jobResolver->resolve($response);
        $result = MedicationRequestApi::mutationResult($finalResponse, ['ACTIVE']);
        app(MedicationRequestRepository::class)->recordSigned($requestRecord, $result);
        try {
            $this->synchronizeSignedPrescription($requestRecord);
        } catch (Throwable $exception) {
            // ESOZ already accepted the signature. Preserve it and expose a read-only retry.
            Log::warning('Signed prescription detail import is pending', ['exceptionType' => $exception::class]);
            $result['warning_message'] = __('medication-requests.signed_details_pending');
        }

        $requestNumber = (string) (
            $result['request_number']
            ?? ($result['medication_request']['request_number'] ?? null)
            ?? $requestRecord->requestNumber
            ?? $requestRecord->uuid
        );

        $informWithRaw = $informWith !== ''
            ? $informWith
            : (string) ($requestRecord->informWith ?? '');

        $notificationDisabled = filter_var(
            $formData['request_notification_disabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $result['success_message'] = $this->buildPostSignSuccessMessage(
            $requestNumber,
            $informWithRaw,
            $notificationDisabled
        );

        $medicationQty = (float) ($requestRecord->medicationQty ?? 0);
        if ($this->shouldWarnRemainingQty($remainingQty, $medicationQty)) {
            $unit = (string) ($formData['medication_unit'] ?? 'од.');
            $result['warning_message'] = trim(($result['warning_message'] ?? '').' '.$this->buildRemainingQtyWarningMessage($remainingQty, $unit));
            $result['show_remaining_qty_warning'] = true;
        }

        return $result;
    }

    /**
     * Reject a locally stored prescription: unsigned drafts go straight out, an active
     * prescription needs a KEP-signed reason code.
     *
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    public function rejectPrescription(
        CarePlan|Encounter $contextModel,
        MedicationRequestRequest $requestRecord,
        array $formData = [],
        string $statusReason = ''
    ): array {
        $personId = $contextModel instanceof CarePlan ? (int) $contextModel->personId : (int) $contextModel->person_id;
        if ($personId !== (int) $requestRecord->personId) {
            throw new InvalidArgumentException('The medication reject context belongs to another patient.');
        }
        if (MedicationRequestStatus::resolve((string) $requestRecord->status)?->isUnsigned()) {
            $result = MedicationRequestApi::mutationResult(
                $this->jobResolver->resolve(MedicationRequest::rejectUnsignedMedicationRequest((string) $requestRecord->uuid, [])),
                ['REJECTED'],
                (string) $requestRecord->uuid
            );
            app(MedicationRequestRepository::class)->updateRecord($requestRecord, ['status' => MedicationRequestStatus::REJECTED->value]);

            return $result;
        }

        $this->requireLocalKep($formData);

        $personUuid = app(MedicationRequestRepository::class)->personUuid((int) $requestRecord->personId);

        $signedContent = null;

        if ($statusReason === '') {
            throw new InvalidArgumentException('Для відхилення активного рецепта потрібен код причини (reject_reason_code).');
        }

        $signerTaxId = $this->resolveSignerTaxId($formData);
        $activeId = $this->resolveActiveEhealthId($personUuid, (string) $requestRecord->uuid);

        $signedContent = signatureService()->signData(
            $this->buildRejectSignPayload($personUuid, $activeId, $statusReason, $formData),
            $formData['password'],
            $formData['knedp'],
            $formData['keyContainerUpload'] ?? null,
            $signerTaxId
        );

        $payload = [
            'person_id' => $personUuid,
            'signed_content' => $signedContent,
            'signed_content_encoding' => 'base64',
        ];

        $response = MedicationRequest::rejectMedicationRequest($activeId, $payload);

        $finalResponse = $this->jobResolver->resolve($response);
        $result = MedicationRequestApi::mutationResult($finalResponse, ['REJECTED'], $activeId);
        app(MedicationRequestRepository::class)->updateRecord($requestRecord, ['status' => MedicationRequestStatus::REJECTED->value]);

        return $result;
    }

    /**
     * Signed reject content is the stored Medication Request plus reject_reason_code
     * (and optional reject_reason). eHealth compares the blob to the rendered MR
     * ignoring those two fields (API-005-043-0006).
     *
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    protected function buildRejectSignPayload(string $personUuid, string $activeId, string $statusReason, array $formData = []): array
    {
        $medicationRequest = $this->fetchActiveMedicationRequest($personUuid, $activeId);
        if (($medicationRequest['id'] ?? null) !== $activeId
            || ($medicationRequest['person_id'] ?? data_get($medicationRequest, 'person.id')) !== $personUuid) {
            throw new RuntimeException('ESOZ prescription identity or patient does not match the reject context.');
        }
        $medicationRequest['reject_reason_code'] = $statusReason;

        $rejectReason = trim((string) ($formData['reject_reason'] ?? ''));
        if ($rejectReason !== '') {
            $medicationRequest['reject_reason'] = $rejectReason;
        }

        return $medicationRequest;
    }

    /**
     * @return array<string, mixed>
     */
    public function synchronizeSignedPrescription(MedicationRequestRequest $request): void
    {
        $repository = app(MedicationRequestRepository::class);
        $personUuid = $repository->personUuid((int) $request->personId);
        $activeId = data_get($request->ehealthPayload, 'active_id');
        if ($request->status !== MedicationRequestStatus::SIGNED->value || !Str::isUuid($activeId ?? '')) {
            throw new InvalidArgumentException('Only a confirmed signed request can be synchronized.');
        }
        $detail = $this->unwrapMedicationRequestData(MedicationRequest::getById($personUuid, $activeId));
        $repository->completeSignedPrescription($request, $detail);
    }

    protected function fetchActiveMedicationRequest(string $personUuid, string $activeId): array
    {
        try {
            $data = $this->unwrapMedicationRequestData(
                MedicationRequest::getById($personUuid, $activeId)
            );
            if ($data !== []) {
                return $data;
            }
        } catch (EHealthResponseException $exception) {
            if (in_array($exception->response->status(), [401, 403], true)) {
                throw new RuntimeException(
                    __('care-plan.eprescription_reject_wrong_legal_entity'),
                    $exception->response->status(),
                    $exception
                );
            }

            Log::warning('Could not fetch Medication Request by id for reject signing: '.$exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Could not fetch Medication Request by id for reject signing: '.$exception->getMessage());
        }

        throw new RuntimeException(__('care-plan.eprescription_reject_fetch_failed'));
    }

    /**
     * @param  mixed  $response
     * @return array<string, mixed>
     */
    protected function unwrapMedicationRequestData(mixed $response): array
    {
        if (!is_array($response) || $response === []) {
            return [];
        }

        if (isset($response['id']) || isset($response['uuid'])) {
            return $response;
        }

        $data = $response['data'] ?? null;
        if (is_array($data) && (isset($data['id']) || isset($data['uuid']))) {
            return $data;
        }

        if (isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }

        if (isset($response[0]) && is_array($response[0])) {
            return $response[0];
        }

        return [];
    }

    /**
     * Build payload for KEP signing of Medication Request Request.
     *
     * @return array<string, mixed>
     */
    public function buildSignPayload(CarePlan|Encounter $contextModel, MedicationRequestRequest $requestRecord, string $informWith): array
    {
        $personId = $contextModel instanceof CarePlan ? (int) $contextModel->personId : (int) $contextModel->person_id;
        if ($personId !== (int) $requestRecord->personId || $requestRecord->resourceType !== MedicationRequestRequest::TYPE_REQUEST) {
            throw new InvalidArgumentException('The signing context does not match this medication request.');
        }
        $personUuid = app(MedicationRequestRepository::class)->personUuid($personId);
        $detail = MedicationRequest::getRequestById($personUuid, $requestRecord->uuid);
        if (isset($detail['data']) && is_array($detail['data'])) {
            $detail = $detail['data'];
        }

        return MedicationRequestApi::signableDetail($detail, $personUuid, $requestRecord->uuid);
    }

    /**
     * Resend SMS code for Medication Request.
     *
     * @param  string  $personId
     * @param  string  $prescriptionId
     * @return \App\Classes\eHealth\EHealthResponse
     */
    public function resendSms(string $personId, string $prescriptionId): EHealthResponse
    {
        $activeId = $this->resolveActiveEhealthId($personId, $prescriptionId);

        return EHealth::medicationRequest()->resendSms($personId, $activeId);
    }

    /**
     * Fetch printout form from eHealth API.
     *
     * @param  string  $personId
     * @param  string  $prescriptionId
     * @return string|null
     */
    public function fetchPrintoutFromEhealth(string $personId, string $prescriptionId): array|string|null
    {
        try {
            $activeId = $this->resolveActiveEhealthId($personId, $prescriptionId);
            $response = EHealth::person()->getMedicationRequestPrintoutForm($personId, $activeId);
            $data = $response->getData();

            if (is_array($data) && isset($data['printout_form'])) {
                return $data['printout_form'];
            }

            if (is_array($data) && isset($data['data'])) {
                $item = $data['data'];
                if (is_array($item) && isset($item['printout_form'])) {
                    return $item['printout_form'];
                }

                return $item;
            }

            return $data;
        } catch (Throwable $e) {
            Log::warning('Failed to fetch printout from eHealth: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Build printout HTML memo when eHealth printout form returns structured data or as fallback.
     *
     * @param  array<string, mixed>|null  $ehealthData
     * @param  string|null  $fallbackDoctorName  Used only when neither the local record nor eHealth names the author
     */
    public function buildFallbackPrintoutHtml(
        CarePlan $carePlan,
        string $prescriptionId,
        ?string $signatureText = null,
        ?array $ehealthData = null,
        ?string $fallbackDoctorName = null
    ): string {
        $record = app(MedicationRequestRepository::class)->findByUuid($prescriptionId);
        if ($record && empty($record->dosageInstructions)) {
            $record->loadMissing('dosageInstructions');
        }

        $requestNumber = $ehealthData['request_number'] ?? ($ehealthData['medication_request']['request_number'] ?? ($record?->request_number ?: $prescriptionId));

        $patientName = null;
        if ($carePlan->person) {
            $person = $carePlan->person;
            $patientName = $person->full_name ?? null;
            if (empty(trim((string) $patientName)) && $person->primaryName) {
                $patientName = trim($person->primaryName->lastName . ' ' . $person->primaryName->firstName . ' ' . ($person->primaryName->secondName ?? ''));
            }
            if (empty(trim((string) $patientName))) {
                $patientName = trim(($person->last_name ?? '') . ' ' . ($person->first_name ?? '') . ' ' . ($person->second_name ?? ''));
            }
        }
        if (empty(trim((string) $patientName)) || $patientName === 'Пацієнт') {
            $ePerson = $ehealthData['person'] ?? ($record?->ehealth_payload['person'] ?? null);
            if (is_array($ePerson)) {
                $patientName = trim(($ePerson['last_name'] ?? '') . ' ' . ($ePerson['first_name'] ?? '') . ' ' . ($ePerson['second_name'] ?? ''));
            } elseif (!empty($ehealthData['person']['name'])) {
                $patientName = $ehealthData['person']['name'];
            }
        }
        if (empty(trim((string) $patientName))) {
            $patientName = 'Пацієнт';
        }

        $patientBirthDate = $carePlan->person?->birth_date ? Carbon::parse($carePlan->person->birth_date)->format('d.m.Y') : ($ehealthData['person']['birth_date'] ?? ($record?->ehealth_payload['person']['birth_date'] ?? '—'));
        if ($patientBirthDate !== '—' && !str_contains((string) $patientBirthDate, '.')) {
            try {
                $patientBirthDate = Carbon::parse((string) $patientBirthDate)->format('d.m.Y');
            } catch (Exception $e) {
                // keep original
            }
        }

        $startDate = $record?->started_at ? Carbon::parse($record->startedAt)->format('d.m.Y') : ($ehealthData['created_at'] ?? now()->format('d.m.Y'));
        $endDate = $record?->ended_at ? Carbon::parse($record->endedAt)->format('d.m.Y') : ($ehealthData['ended_at'] ?? '—');

        $author = null;
        if ($record && !empty($record->employeeId)) {
            $author = app(MedicationRequestRepository::class)->findAuthor($record->employeeId);
        }

        $doctorName = $author?->party?->full_name ?? ($author?->full_name ?? ($ehealthData['employee']['name'] ?? ($record?->ehealth_payload['employee']['name'] ?? ($fallbackDoctorName ?: '—'))));
        $facilityName = $ehealthData['division']['name'] ?? ($record?->ehealth_payload['division']['name'] ?? (legalEntity()?->name ?? 'Медичний заклад'));

        $medicationName = $ehealthData['medication_name']
            ?? ($ehealthData['medication']['name']
            ?? ($ehealthData['medication_info']['medication_name']
            ?? ($record?->ehealth_payload['medication_info']['medication_name']
            ?? ($record?->ehealth_payload['medication_name']
            ?? ($record?->ehealth_payload['medication']['name']
            ?? 'Лікарський засіб')))));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $medicationName)) {
            $medicationName = 'Лікарський засіб';
        }

        $medicationQty = $ehealthData['medication_qty'] ?? ($ehealthData['medication']['qty'] ?? ($record?->medication_qty ? "{$record->medicationQty} од." : '—'));
        $programName = $ehealthData['medical_program_name']
            ?? ($ehealthData['medical_program']['name']
            ?? ($record?->ehealth_payload['medical_program']['name']
            ?? ($record?->ehealth_payload['medical_program_name']
            ?? ($record?->medication_program_id ? ($record->medicationProgramId === '5e3e2307-8898-4428-a400-e3776a39d56f' ? 'Реімбурсація (Доступні ліки)' : 'Державна програма / Реімбурсація') : 'За власні кошти'))));

        $instructionsList = [];
        if ($record && $record->dosageInstructions) {
            foreach ($record->dosageInstructions as $instr) {
                $text = $instr->text ?: $instr->patient_instruction;
                if ($text) {
                    $instructionsList[] = $text;
                }
            }
        }
        $dosageText = !empty($instructionsList) ? implode('; ', $instructionsList) : ($ehealthData['dosage_instruction'] ?? ($signatureText ?? 'За призначенням лікаря'));
        $otpInfo = $ehealthData['confirmation_code'] ?? 'Відправлено в SMS-повідомленні на номер телефону пацієнта';

        $idEsc = htmlspecialchars((string) $requestNumber, ENT_QUOTES, 'UTF-8');
        $patientEsc = htmlspecialchars(trim((string) $patientName), ENT_QUOTES, 'UTF-8');
        $birthDateEsc = htmlspecialchars((string) $patientBirthDate, ENT_QUOTES, 'UTF-8');
        $medNameEsc = htmlspecialchars((string) $medicationName, ENT_QUOTES, 'UTF-8');
        $medQtyEsc = htmlspecialchars((string) $medicationQty, ENT_QUOTES, 'UTF-8');
        $programEsc = htmlspecialchars((string) $programName, ENT_QUOTES, 'UTF-8');
        $dosageEsc = htmlspecialchars((string) $dosageText, ENT_QUOTES, 'UTF-8');
        $docNameEsc = htmlspecialchars((string) $doctorName, ENT_QUOTES, 'UTF-8');
        $facilityEsc = htmlspecialchars((string) $facilityName, ENT_QUOTES, 'UTF-8');
        $startEsc = htmlspecialchars((string) $startDate, ENT_QUOTES, 'UTF-8');
        $endEsc = htmlspecialchars((string) $endDate, ENT_QUOTES, 'UTF-8');
        $otpEsc = htmlspecialchars((string) $otpInfo, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div style="font-family: 'Times New Roman', Arial, sans-serif; padding: 35px; max-width: 750px; margin: 0 auto; color: #1a1a1a; border: 1px solid #ccc; border-radius: 6px;">
    <div style="text-align: center; margin-bottom: 25px;">
        <h2 style="font-size: 20px; font-weight: bold; margin: 0 0 8px 0; color: #111;">ІНФОРМАЦІЙНА ДОВІДКА<br>ЕЛЕКТРОННОГО РЕЦЕПТА (ПАМ'ЯТКА)</h2>
        <div style="font-size: 15px; font-weight: bold;">Номер рецепта в ЕСОЗ: <span style="color: #1e3a8a; font-size: 18px;">{$idEsc}</span></div>
    </div>
    <hr style="border-top: 1px solid #ddd; margin: 20px 0;">
    <table style="width: 100%; font-size: 15px; border-collapse: collapse; line-height: 1.5;">
        <tr>
            <td style="padding: 8px 10px; width: 35%; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Пацієнт:</td>
            <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">{$patientEsc} (р.н.: {$birthDateEsc})</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Медична програма:</td>
            <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">{$programEsc}</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Лікарський засіб (МНН / Назва):</td>
            <td style="padding: 8px 10px; font-weight: bold; color: #0f3460; border-bottom: 1px solid #f0f0f0;">{$medNameEsc}</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Призначена кількість:</td>
            <td style="padding: 8px 10px; font-weight: bold; border-bottom: 1px solid #f0f0f0;">{$medQtyEsc}</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Спосіб вживання (Сигнатура):</td>
            <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">{$dosageEsc}</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Термін дії рецепту:</td>
            <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">з <strong>{$startEsc}</strong> по <strong>{$endEsc}</strong></td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Код підтвердження (погашення):</td>
            <td style="padding: 8px 10px; font-weight: bold; color: #b91c1c; border-bottom: 1px solid #f0f0f0;">{$otpEsc}</td>
        </tr>
        <tr>
            <td style="padding: 8px 10px; font-weight: bold; vertical-align: top; border-bottom: 1px solid #f0f0f0;">Лікар та заклад:</td>
            <td style="padding: 8px 10px; border-bottom: 1px solid #f0f0f0;">{$docNameEsc}<br><span style="font-size: 13px; color: #555;">{$facilityEsc}</span></td>
        </tr>
    </table>
    <div style="margin-top: 30px; padding: 15px; background-color: #f8fafc; border-left: 4px solid #3b82f6; font-size: 13px; color: #334155; line-height: 1.4;">
        <strong>Увага для аптеки та пацієнта:</strong><br>
        Для отримання лікарського засобу назвіть фармацевту в аптеці 16-значний номер рецепту в ЕСОЗ та код підтвердження з SMS-повідомлення. Рецепт може бути погашений у будь-якій аптеці України, що уклала договір з НСЗУ за відповідною програмою або відпускає електронні рецепти.
    </div>
</div>
HTML;
    }

    /**
     * Block an active prescription in eHealth.
     *
     * @param  string  $personId
     * @param  string  $prescriptionId
     * @param  array  $payload
     * @return array
     */
    public function block(string $personId, string $prescriptionId, array $payload = []): array
    {
        return MedicationRequest::block($personId, $prescriptionId, $payload);
    }

    /**
     * Unblock a prescription in eHealth.
     *
     * @param  string  $personId
     * @param  string  $prescriptionId
     * @param  array  $payload
     * @return array
     */
    public function unblock(string $personId, string $prescriptionId, array $payload = []): array
    {
        return MedicationRequest::unblock($personId, $prescriptionId, $payload);
    }

    /**
     * Get dispensing records (history of medication redemption in pharmacies).
     *
     * @param  string  $personId
     * @param  string  $prescriptionId
     * @return array
     */
    public function getDispenseHistory(string $personId, string $prescriptionId): array
    {
        $activeId = $this->resolveActiveEhealthId($personId, $prescriptionId);

        return MedicationRequest::getDetails($personId, $activeId);
    }

    /**
     * Get medication requests in care plan context directly.
     *
     * @param  string  $carePlanId
     * @param  array  $query
     * @return array
     */
    public function getByCarePlan(string $carePlanId, array $query = []): array
    {
        return MedicationRequest::getByCarePlan($carePlanId, $query);
    }

    /**
     * Resolve active eHealth ID for Medication Request (which differs from local draft request UUID).
     *
     * @param  string  $personUuid
     * @param  string  $localId
     * @return string
     */
    public function resolveActiveEhealthId(string $personUuid, string $localId): string
    {
        $repository = app(MedicationRequestRepository::class);
        $record = $repository->findByUuid($localId);
        if ($record === null) {
            return $localId; // A remote MR id is verified by the subsequent patient-scoped detail GET.
        }
        $ownerUuid = $repository->personUuid((int) $record->personId);
        if ($personUuid !== '' && $personUuid !== $ownerUuid) {
            throw new InvalidArgumentException('The prescription belongs to another patient.');
        }
        if ($record->resourceType === MedicationRequestRequest::TYPE_PRESCRIPTION) {
            return $record->uuid;
        }
        $activeId = data_get($record->ehealthPayload, 'active_id');
        if (is_string($activeId) && Str::isUuid($activeId) && $activeId !== $localId) {
            return $activeId;
        }
        if ($record->requestNumber) {
            $response = MedicationRequest::getBySearchParams($ownerUuid, ['request_number' => $record->requestNumber]);
            foreach ($response['data'] ?? $response as $item) {
                if (is_array($item) && Str::isUuid($item['id'] ?? '') && $item['id'] !== $localId
                    && ($item['request_number'] ?? null) === $record->requestNumber) {
                    $payload = $record->ehealthPayload ?? [];
                    $payload['active_id'] = $item['id'];
                    $repository->updateRecord($record, ['ehealth_payload' => $payload]);

                    return $item['id'];
                }
            }
        }

        throw new RuntimeException('A confirmed MedicationRequest id is required; the request-request id cannot be used.');
    }

    /**
     * TV 3.9.1.1.2 — finished encounters by current performer with period.end = today.
     *
     * @return Collection<int, Encounter>
     */
    public function findEligibleEncountersForEPrescription(int $personId, ?string $employeeUuid): Collection
    {
        return app(MedicationRequestRepository::class)->eligibleEncounters($personId, $employeeUuid);
    }

    /**
     * Resolve and validate encounter selected for MRR create (TV 3.9.1.1.2).
     */
    public function resolveEligibleEncounterForCreate(int $personId, ?string $employeeUuid, ?int $selectedEncounterId): Encounter
    {
        $eligible = $this->findEligibleEncountersForEPrescription($personId, $employeeUuid);

        if ($eligible->isEmpty()) {
            throw new RuntimeException(__('care-plan.eprescription_encounter_none'));
        }

        if ($selectedEncounterId === null || $selectedEncounterId <= 0) {
            throw new InvalidArgumentException(__('care-plan.eprescription_encounter_required'));
        }

        $selected = $eligible->firstWhere('id', $selectedEncounterId);
        if (!$selected instanceof Encounter) {
            throw new InvalidArgumentException(__('care-plan.eprescription_encounter_invalid'));
        }

        return $selected;
    }

    /**
     * TV 3.9.3.15 — SMS vs print success copy after sign.
     */
    public function buildPostSignSuccessMessage(
        string $requestNumber,
        string $informWithRaw,
        bool $requestNotificationDisabled,
        ?string $maskedPhone = null
    ): string {
        $parts = explode('|', $informWithRaw);
        $authType = strtoupper(trim((string) ($parts[1] ?? '')));
        $phoneFromInform = trim((string) ($parts[2] ?? ''));
        $phone = $maskedPhone !== null && $maskedPhone !== ''
            ? $maskedPhone
            : ($phoneFromInform !== '' ? $phoneFromInform : '•••');

        $usesSms = !$requestNotificationDisabled
            && in_array($authType, ['OTP', 'THIRD_PERSON'], true);

        if ($usesSms) {
            return __('care-plan.eprescription_signed_sms', [
                'number' => $requestNumber,
                'phone' => $phone,
            ]);
        }

        return __('care-plan.eprescription_signed_print', [
            'number' => $requestNumber,
        ]);
    }

    /**
     * TV 3.9.3.19 — warn when leftover after this Rx is less than medication_qty.
     */
    public function shouldWarnRemainingQty(float $remainingBefore, float $medicationQty): bool
    {
        if ($medicationQty <= 0) {
            return false;
        }

        return ($remainingBefore - $medicationQty) < $medicationQty;
    }

    public function buildRemainingQtyWarningMessage(float $remainingBefore, string $unit): string
    {
        return __('care-plan.eprescription_remaining_qty_warning', [
            'remaining' => $remainingBefore,
            'unit' => $unit !== '' ? $unit : 'од.',
        ]);
    }
}
