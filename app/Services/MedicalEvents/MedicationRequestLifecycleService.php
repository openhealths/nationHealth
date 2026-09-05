<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\Api\MedicationRequest;
use App\Contracts\EHealthRequestLifecycleContract;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\MedicationRequestStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use App\Services\MedicalEvents\Concerns\ResolvesEmployeeContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            throw new \InvalidArgumentException(__('care-plan.kep_signature_required'));
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
        if ($signatureText === '') {
            throw new \InvalidArgumentException(__('care-plan.eprescription_signature_required'));
        }

        $maxDosePerAdministration = (float) ($formData['max_dose_per_administration'] ?? 0);
        $maxDosePerPeriod = (float) ($formData['max_dose_per_period'] ?? 0);
        if ($maxDosePerAdministration <= 0 || $maxDosePerPeriod <= 0) {
            throw new \InvalidArgumentException(__('care-plan.eprescription_dose_required'));
        }

        if ($activity !== null) {
            app(ActivityRemainingQuantityGuard::class)->assertCanIssue(
                (int) $activity->id,
                (float) ($formData['medication_qty'] ?? 1),
                function (int $activityId): float {
                    return (float) MedicationRequestRequest::query()
                        ->where('based_on_id', $activityId)
                        ->whereNotIn('status', ActivityRemainingQuantityGuard::occupyingStatusesExcluded())
                        ->sum('medication_qty');
                }
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

        $patientInstruction = trim((string) ($formData['patient_instruction'] ?? ''));
        if ($patientInstruction === '') {
            $patientInstruction = $signatureText;
        }

        $dbData = [
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $carePlan->personId,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => MedicationRequestStatus::DRAFT->value,
            'started_at' => $formData['started_at'] ?? now()->toDateString(),
            'ended_at' => $formData['ended_at'] ?? now()->addDays(30)->toDateString(),
            'medication_id' => $formData['medication_id'] ?? null,
            'medication_qty' => (float) ($formData['medication_qty'] ?? 1),
            'medication_program_id' => $formData['program_id'] ?? null,
            'intent' => 'order',
            'category' => $formData['category'] ?? 'community',
            'based_on_id' => $activity ? $activity->id : null,
            'context_id' => $activeEncounter->id,
            'based_on_uuid' => $activity ? $activity->uuid : null,
            'container_dosage' => $formData['container_dosage'] ?? null,
            'note' => $formData['note'] ?? null,
            'dosage_instructions' => [
                [
                    'sequence' => 1,
                    'text' => $signatureText,
                    'patient_instruction' => $patientInstruction,
                    'route' => $formData['route'] ?? 'oral',
                    'dose_and_rate' => [
                        [
                            'dose_quantity_value' => $maxDosePerAdministration,
                            'dose_quantity_unit' => $formData['medication_unit'] ?? 'од.',
                        ]
                    ],
                    'max_dose_per_administration' => $maxDosePerAdministration,
                    'max_dose_per_period' => $maxDosePerPeriod,
                ]
            ],
            'inform_with' => $formData['inform_with'] ?? null,
        ];

        $uuids = [
            'person_uuid' => $carePlan->person->uuid,
            'encounter_uuid' => $activeEncounter->uuid,
            'episode_uuid' => $carePlan->episodeUuid() ?? $activeEncounter->episode?->value,
            'employee_uuid' => $employeeContext['employee_uuid'] ?? null,
            'legal_entity_uuid' => $employeeContext['legal_entity_uuid'] ?? null,
            'division_uuid' => $employeeContext['division_id'] ? \App\Models\Division::find($employeeContext['division_id'])?->uuid : null,
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
            'started_at' => $formData['started_at'] ?? now()->toDateString(),
            'ended_at' => $formData['ended_at'] ?? now()->addDays(30)->toDateString(),
            'medication_id' => $formData['medication_id'] ?? null,
            'medication_qty' => (float) ($formData['medication_qty'] ?? 1),
            'medication_program_id' => $formData['program_id'] ?? null,
            'intent' => 'order',
            'category' => $formData['category'] ?? 'community',
            'based_on_id' => null,
            'context_id' => $encounter->id,
            'based_on_uuid' => null,
            'container_dosage' => $formData['container_dosage'] ?? null,
            'note' => $formData['note'] ?? null,
            'dosage_instructions' => [
                [
                    'sequence' => 1,
                    'text' => !empty($formData['signature_text']) ? $formData['signature_text'] : 'За призначенням лікаря',
                    'patient_instruction' => !empty($formData['patient_instruction']) ? $formData['patient_instruction'] : (!empty($formData['signature_text']) ? $formData['signature_text'] : 'За призначенням лікаря'),
                    'route' => $formData['route'] ?? 'oral',
                    'dose_and_rate' => [
                        [
                            'dose_quantity_value' => (float) ($formData['max_dose_per_administration'] ?? 1.0),
                            'dose_quantity_unit' => $formData['medication_unit'] ?? 'од.',
                        ],
                    ],
                    'max_dose_per_administration' => (float) ($formData['max_dose_per_administration'] ?? 1.0),
                    'max_dose_per_period' => (float) ($formData['max_dose_per_period'] ?? 1.0),
                ],
            ],
            'inform_with' => $formData['inform_with'] ?? null,
        ];

        $personUuid = \App\Models\Person\Person::find($encounter->person_id)?->uuid;
        $episodeUuid = $encounter->episode?->value ?? null;

        $uuids = [
            'person_uuid' => $personUuid,
            'encounter_uuid' => $encounter->uuid,
            'episode_uuid' => $episodeUuid,
            'employee_uuid' => $employeeContext['employee_uuid'] ?? null,
            'legal_entity_uuid' => $employeeContext['legal_entity_uuid'] ?? null,
            'division_uuid' => $employeeContext['division_id'] ? \App\Models\Division::find($employeeContext['division_id'])?->uuid : null,
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
        $mapper = new \App\Services\MedicalEvents\Mappers\MedicationRequestMapper();

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

        $dbData['request_number'] = $finalCreateResponse['request_number'] ?? ($finalCreateResponse['requisition'] ?? ($finalCreateResponse['data']['request_number'] ?? null));
        $dbData['uuid'] = $finalCreateResponse['id'] ?? ($finalCreateResponse['data']['id'] ?? $dbData['uuid']);
        $dbData['ehealth_payload'] = $this->resolveStoredPayload($createResponse, $finalCreateResponse);

        app(MedicationRequestRepository::class)->store($dbData, $personId);

        return $dbData['uuid'];
    }

    /**
     * The accepted draft comes back either on the job result or on the create response, either
     * wrapped in `data` or flat.
     *
     * @param  array<string, mixed>  $createResponse
     * @param  array<string, mixed>  $jobResult
     * @return array<string, mixed>
     */
    private function resolveStoredPayload(array $createResponse, array $jobResult): array
    {
        foreach ([$jobResult, $createResponse] as $candidate) {
            if (isset($candidate['data'])) {
                return is_array($candidate['data']) ? $candidate['data'] : (array) $candidate['data'];
            }

            if (isset($candidate['person']) || isset($candidate['based_on'])) {
                return $candidate;
            }
        }

        return $jobResult;
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

        if ($requestRecord->basedOnId) {
            app(ActivityRemainingQuantityGuard::class)->assertCanIssue(
                (int) $requestRecord->basedOnId,
                (float) ($requestRecord->medicationQty ?? 0),
                function (int $activityId) use ($requestRecord): float {
                    return (float) MedicationRequestRequest::query()
                        ->where('based_on_id', $activityId)
                        ->where('uuid', '!=', $requestRecord->uuid)
                        ->whereNotIn('status', ActivityRemainingQuantityGuard::occupyingStatusesExcluded())
                        ->sum('medication_qty');
                }
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
        $result = $finalResponse['data'] ?? $finalResponse;

        $requestRecord->update(['status' => MedicationRequestStatus::ACTIVE->value]);

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
            $result['warning_message'] = $this->buildRemainingQtyWarningMessage($remainingQty, $unit);
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
        if (MedicationRequestStatus::resolve((string) $requestRecord->status)?->isUnsigned()) {
            MedicationRequest::rejectUnsignedMedicationRequest((string) $requestRecord->uuid, []);

            $requestRecord->update(['status' => MedicationRequestStatus::REJECTED->value]);

            return [];
        }

        $this->requireLocalKep($formData);

        $personUuid = $contextModel instanceof CarePlan
            ? $contextModel->person->uuid
            : ($requestRecord->person_uuid ?? ($requestRecord->person->uuid ?? ''));

        $signedContent = null;

        if ($statusReason === '') {
            throw new \InvalidArgumentException('Для відхилення активного рецепта потрібен код причини (reject_reason_code).');
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
        $result = $finalResponse['data'] ?? $finalResponse;

        // eHealth can echo the pre-reject status back, so an active answer still means rejected here.
        $reported = MedicationRequestStatus::resolve((string) ($result['status'] ?? ''));
        $newStatus = $reported === null || $reported === MedicationRequestStatus::ACTIVE
            ? MedicationRequestStatus::REJECTED
            : $reported;

        $requestRecord->update(['status' => $newStatus->value]);

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
        $medicationRequest['id'] = $medicationRequest['id'] ?? $activeId;
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
            if (in_array($exception->getCode(), [401, 403], true)) {
                throw new \RuntimeException(
                    __('care-plan.eprescription_reject_wrong_legal_entity'),
                    $exception->getCode(),
                    $exception
                );
            }

            Log::warning('Could not fetch Medication Request by id for reject signing: '.$exception->getMessage());
        } catch (\Throwable $exception) {
            Log::warning('Could not fetch Medication Request by id for reject signing: '.$exception->getMessage());
        }

        throw new \RuntimeException(__('care-plan.eprescription_reject_fetch_failed'));
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
    protected function buildSignPayload(CarePlan|Encounter $contextModel, MedicationRequestRequest $requestRecord, string $informWith): array
    {
        if (!empty($requestRecord->ehealthPayload) && is_array($requestRecord->ehealthPayload)) {
            $signedContent = $requestRecord->ehealthPayload;
            if (isset($signedContent['data']) && is_array($signedContent['data'])) {
                $signedContent = $signedContent['data'];
            }

            return $signedContent;
        }

        $personUuid = $contextModel instanceof CarePlan
            ? ($contextModel->person->uuid ?? null)
            : (\App\Models\Person\Person::find($contextModel->person_id)?->uuid ?? null);

        try {
            if ($personUuid) {
                $response = \App\Classes\eHealth\Api\MedicationRequest::getRequestsBySearchParams((string) $personUuid, ['id' => $requestRecord->uuid]);
                $fetchedData = $response['data'][0] ?? ($response[0] ?? null);
                if (!empty($fetchedData) && is_array($fetchedData) && ($fetchedData['id'] ?? null) === $requestRecord->uuid) {
                    $requestRecord->update(['ehealth_payload' => $fetchedData]);

                    return $fetchedData;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Could not fetch MedicationRequestRequest from eHealth for signing fallback: ' . $e->getMessage());
        }

        $employee = \App\Models\Employee\Employee::find($requestRecord->employeeId);
        $division = \App\Models\Division::find($requestRecord->divisionId);
        $encounter = Encounter::find($requestRecord->contextId);
        $activity = $requestRecord->basedOnId
            ? CarePlanActivity::find($requestRecord->basedOnId)
            : null;

        $carePlanUuid = $contextModel instanceof CarePlan
            ? $contextModel->uuid
            : ($activity?->carePlan?->uuid ?? null);

        $uuids = [
            'person_uuid' => $personUuid,
            'encounter_uuid' => $encounter ? $encounter->uuid : null,
            'employee_uuid' => $employee ? $employee->uuid : null,
            'division_uuid' => $division ? $division->uuid : null,
        ];

        $dosageInstructions = [];
        foreach ($requestRecord->dosageInstructions as $inst) {
            $doseAndRate = !empty($inst->doseAndRate) && is_string($inst->doseAndRate)
                ? json_decode($inst->doseAndRate, true)
                : ($inst->doseAndRate ?: []);

            $timing = !empty($inst->timing) && is_string($inst->timing)
                ? json_decode($inst->timing, true)
                : ($inst->timing ?: null);

            $text = !empty($inst->text) ? $inst->text : 'За призначенням лікаря';
            $patientInstruction = !empty($inst->patientInstruction) ? $inst->patientInstruction : $text;

            $dosageInstructions[] = [
                'sequence' => $inst->sequence ?? 1,
                'text' => $text,
                'patient_instruction' => $patientInstruction,
                'as_needed_boolean' => (bool) ($inst->asNeededBoolean ?? false),
                'route' => $inst->route ?? 'oral',
                'method' => $inst->method ?? null,
                'timing' => $timing,
                'dose_and_rate' => $doseAndRate,
                'max_dose_per_administration' => $inst->maxDosePerAdministration !== null ? (float) $inst->maxDosePerAdministration : null,
                'max_dose_per_period' => $inst->maxDosePerPeriod !== null ? (float) $inst->maxDosePerPeriod : null,
                'max_dose_per_lifetime' => $inst->maxDosePerLifetime !== null ? (float) $inst->maxDosePerLifetime : null,
            ];
        }

        $startedAt = !empty($requestRecord->startedAt)
            ? \Carbon\Carbon::parse($requestRecord->startedAt)->format('Y-m-d')
            : null;

        $endedAt = !empty($requestRecord->endedAt)
            ? \Carbon\Carbon::parse($requestRecord->endedAt)->format('Y-m-d')
            : null;

        $createdAt = !empty($requestRecord->createdAt)
            ? \Carbon\Carbon::parse($requestRecord->createdAt)->format('Y-m-d')
            : now()->format('Y-m-d');

        $data = [
            'created_at' => $createdAt,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'medication_id' => $requestRecord->medicationId,
            'medication_qty' => (float) $requestRecord->medicationQty,
            'medication_program_id' => $requestRecord->medicationProgramId,
            'intent' => $requestRecord->intent ?? 'order',
            'category' => $requestRecord->category ?? 'community',
            'based_on_uuid' => $activity?->uuid,
            'container_dosage' => $requestRecord->containerDosage,
            'note' => $requestRecord->note,
            'inform_with' => $informWith !== '' ? $informWith : ($requestRecord->informWith ?? ''),
            'dosage_instructions' => $dosageInstructions,
        ];

        $mapper = new \App\Services\MedicalEvents\Mappers\MedicationRequestMapper();
        $signedContent = $mapper->toCreateSignedContent($data, $uuids, $carePlanUuid);

        return $signedContent;
    }

    /**
     * Resend SMS code for Medication Request.
     *
     * @param  string  $personId
     * @param  string  $prescriptionId
     * @return \App\Classes\eHealth\EHealthResponse
     */
    public function resendSms(string $personId, string $prescriptionId): \App\Classes\eHealth\EHealthResponse
    {
        $activeId = $this->resolveActiveEhealthId($personId, $prescriptionId);

        return \App\Classes\eHealth\EHealth::medicationRequest()->resendSms($personId, $activeId);
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
            $response = \App\Classes\eHealth\EHealth::person()->getMedicationRequestPrintoutForm($personId, $activeId);
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
        } catch (\Throwable $e) {
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
        $record = app(\App\Repositories\MedicalEvents\MedicationRequestRepository::class)->findByUuid($prescriptionId);
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

        $patientBirthDate = $carePlan->person?->birth_date ? \Carbon\Carbon::parse($carePlan->person->birth_date)->format('d.m.Y') : ($ehealthData['person']['birth_date'] ?? ($record?->ehealth_payload['person']['birth_date'] ?? '—'));
        if ($patientBirthDate !== '—' && !str_contains((string) $patientBirthDate, '.')) {
            try {
                $patientBirthDate = \Carbon\Carbon::parse((string) $patientBirthDate)->format('d.m.Y');
            } catch (\Exception $e) {
                // keep original
            }
        }

        $startDate = $record?->started_at ? \Carbon\Carbon::parse($record->startedAt)->format('d.m.Y') : ($ehealthData['created_at'] ?? now()->format('d.m.Y'));
        $endDate = $record?->ended_at ? \Carbon\Carbon::parse($record->endedAt)->format('d.m.Y') : ($ehealthData['ended_at'] ?? '—');

        $author = null;
        if ($record && !empty($record->employeeId)) {
            $field = is_numeric($record->employeeId) ? 'id' : 'uuid';
            $author = \App\Models\Employee\Employee::where($field, $record->employeeId)->first();
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
        return \App\Classes\eHealth\Api\MedicationRequest::block($personId, $prescriptionId, $payload);
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
        return \App\Classes\eHealth\Api\MedicationRequest::unblock($personId, $prescriptionId, $payload);
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

        return \App\Classes\eHealth\Api\MedicationRequest::getDetails($personId, $activeId);
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
        return \App\Classes\eHealth\Api\MedicationRequest::getByCarePlan($carePlanId, $query);
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
        $requestRecord = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('uuid', $localId)->first();

        if ($requestRecord && !empty($requestRecord->ehealthPayload['active_id'])) {
            return (string) $requestRecord->ehealthPayload['active_id'];
        }

        if (empty($personUuid) && $requestRecord) {
            $personUuid = (string) ($requestRecord->personId ? \App\Models\Person\Person::where('id', $requestRecord->personId)->value('uuid') : '');
        }

        if (empty($personUuid)) {
            return $localId;
        }

        try {
            $queries = [];
            if ($requestRecord && !empty($requestRecord->requestNumber)) {
                $queries[] = ['request_number' => $requestRecord->requestNumber];
            }
            $queries[] = [];

            foreach ($queries as $query) {
                $activeResponse = \App\Classes\eHealth\Api\MedicationRequest::getBySearchParams($personUuid, $query);
                $activeItems = isset($activeResponse['data']) && is_array($activeResponse['data'])
                    ? $activeResponse['data']
                    : (is_array($activeResponse) ? $activeResponse : []);

                if (is_array($activeItems)) {
                    foreach ($activeItems as $item) {
                        if (empty($item['id'])) {
                            continue;
                        }
                        $isMatch = $item['id'] === $localId
                            || ($requestRecord && !empty($requestRecord->requestNumber) && ($item['request_number'] ?? '') === $requestRecord->requestNumber);

                        if ($isMatch) {
                            if ($requestRecord && $item['id'] !== $localId) {
                                $payload = is_array($requestRecord->ehealthPayload) ? $requestRecord->ehealthPayload : [];
                                $payload['active_id'] = $item['id'];
                                $requestRecord->update(['ehealth_payload' => $payload]);
                            }

                            return (string) $item['id'];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve active eHealth ID for prescription: ' . $e->getMessage());
        }

        return $localId;
    }

    /**
     * TV 3.9.1.1.2 — finished encounters by current performer with period.end = today.
     *
     * @return Collection<int, Encounter>
     */
    public function findEligibleEncountersForEPrescription(int $personId, ?string $employeeUuid): Collection
    {
        if ($employeeUuid === null || $employeeUuid === '') {
            return collect();
        }

        return Encounter::query()
            ->where('person_id', $personId)
            ->where('status', EncounterStatus::FINISHED)
            ->whereHas('performer', static function ($query) use ($employeeUuid): void {
                $query->where('value', $employeeUuid);
            })
            ->whereHas('period', static function ($query): void {
                $query->whereDate('end', today());
            })
            ->with(['period', 'performer'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Resolve and validate encounter selected for MRR create (TV 3.9.1.1.2).
     */
    public function resolveEligibleEncounterForCreate(int $personId, ?string $employeeUuid, ?int $selectedEncounterId): Encounter
    {
        $eligible = $this->findEligibleEncountersForEPrescription($personId, $employeeUuid);

        if ($eligible->isEmpty()) {
            throw new \RuntimeException(__('care-plan.eprescription_encounter_none'));
        }

        if ($selectedEncounterId === null || $selectedEncounterId <= 0) {
            throw new \InvalidArgumentException(__('care-plan.eprescription_encounter_required'));
        }

        $selected = $eligible->firstWhere('id', $selectedEncounterId);
        if (!$selected instanceof Encounter) {
            throw new \InvalidArgumentException(__('care-plan.eprescription_encounter_invalid'));
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
