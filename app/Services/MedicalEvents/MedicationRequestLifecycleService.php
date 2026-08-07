<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\Api\MedicationRequest;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Services\MedicalEvents\Concerns\ResolvesEmployeeContext;
use Illuminate\Support\Str;

class MedicationRequestLifecycleService
{
    use ResolvesEmployeeContext;

    /**
     * PreQualify Medication Request.
     *
     * @param  array  $payload
     * @return array
     */
    public function preQualify(array $payload): array
    {
        try {
            $response = MedicationRequest::preQualify($payload);

            return $response['data'] ?? $response;
        } catch (Exception $e) {
            Log::error('ePrescription Prequalify failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create Medication Request (Draft).
     */
    public function createDraft(mixed $carePlanOrPayload, mixed $activity = null, array $formData = [], array $employeeContext = []): array|string
    {
        if (is_array($carePlanOrPayload)) {
            try {
                $response = MedicationRequest::createMedicationRequest($carePlanOrPayload);

                return $response['data'] ?? $response;
            } catch (Exception $e) {
                Log::error('ePrescription Create Draft failed: ' . $e->getMessage());
                throw $e;
            }
        }

        $carePlan = $carePlanOrPayload;

        $activeEncounter = \App\Models\MedicalEvents\Sql\Encounter::query()
            ->where('person_id', $carePlan->person_id)
            ->where('status', 'finished')
            ->whereDate('created_at', today())
            ->latest('id')
            ->first();

        if (!$activeEncounter) {
            throw new \Exception('Для виписування рецепту необхідно мати збережену взаємодію з пацієнтом. Будь ласка, створіть взаємодію сьогодні перед виписуванням рецепту.');
        }

        $dbData = [
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $carePlan->person_id,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => 'draft',
            'started_at' => $formData['started_at'] ?? now()->toDateString(),
            'ended_at' => $formData['ended_at'] ?? now()->addDays(30)->toDateString(),
            'medication_id' => $formData['medication_id'] ?? null,
            'medication_qty' => (float) ($formData['medication_qty'] ?? 1),
            'medication_program_id' => $formData['program_id'] ?? null,
            'intent' => 'order',
            'category' => 'community',
            'based_on_id' => $activity ? $activity->id : null,
            'context_id' => $activeEncounter->id,
            'based_on_uuid' => $activity ? $activity->uuid : null,
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
                        ]
                    ],
                    'max_dose_per_administration' => (float) ($formData['max_dose_per_administration'] ?? 1.0),
                    'max_dose_per_period' => (float) ($formData['max_dose_per_period'] ?? 1.0),
                ]
            ],
            'inform_with' => $formData['inform_with'] ?? null,
        ];

        $uuids = [
            'person_uuid' => $carePlan->person->uuid,
            'encounter_uuid' => $activeEncounter->uuid,
            'episode_uuid' => $carePlan->episode_id,
            'employee_uuid' => $employeeContext['employee_uuid'] ?? null,
            'legal_entity_uuid' => $employeeContext['legal_entity_uuid'] ?? null,
            'division_uuid' => $employeeContext['division_id'] ? \App\Models\Division::find($employeeContext['division_id'])?->uuid : null,
        ];

        $mapper = new \App\Services\MedicalEvents\Mappers\MedicationRequestMapper();

        if (!empty($dbData['medication_program_id'])) {
            $prequalifyPayload = $mapper->toPrequalifyPayload($dbData, $uuids, $carePlan->uuid);
            $response = MedicationRequest::preQualify($prequalifyPayload);

            if (app()->bound(\App\Services\MedicalEvents\EHealthJobResolver::class)) {
                $finalResponse = app(\App\Services\MedicalEvents\EHealthJobResolver::class)->resolve($response);
                app(\App\Services\MedicalEvents\EHealthJobResolver::class)->assertPrequalifyValid($finalResponse);
            }
        }

        $createPayload = $mapper->toCreateRequestPayload($dbData, $uuids, $carePlan->uuid);
        Log::debug('ePrescription Create Request Payload:', $createPayload);
        $createResponse = MedicationRequest::createMedicationRequest($createPayload);

        if (app()->bound(\App\Services\MedicalEvents\EHealthJobResolver::class)) {
            $finalCreateResponse = app(\App\Services\MedicalEvents\EHealthJobResolver::class)->resolve($createResponse);
            Log::debug('ePrescription Create Response from eHealth:', ['response' => $createResponse, 'resolved' => $finalCreateResponse]);
            if (($finalCreateResponse['status'] ?? null) === 'failed') {
                throw new \App\Exceptions\EHealth\EHealthValidationException($finalCreateResponse);
            }
            $dbData['request_number'] = $finalCreateResponse['request_number'] ?? ($finalCreateResponse['requisition'] ?? ($finalCreateResponse['data']['request_number'] ?? null));
            $dbData['uuid'] = $finalCreateResponse['id'] ?? ($finalCreateResponse['data']['id'] ?? $dbData['uuid']);
            $payloadToStore = $finalCreateResponse['data'] ?? (isset($finalCreateResponse['person']) || isset($finalCreateResponse['based_on']) ? $finalCreateResponse : ($createResponse['data'] ?? (isset($createResponse['person']) || isset($createResponse['based_on']) ? $createResponse : $finalCreateResponse)));
            $dbData['ehealth_payload'] = is_array($payloadToStore) ? $payloadToStore : (array) $payloadToStore;
        } else {
            Log::debug('ePrescription Create Response from eHealth:', ['response' => $createResponse]);
            $dbData['request_number'] = $createResponse['request_number'] ?? ($createResponse['data']['request_number'] ?? null);
            $dbData['uuid'] = $createResponse['id'] ?? ($createResponse['data']['id'] ?? $dbData['uuid']);
            $payloadToStore = $createResponse['data'] ?? (isset($createResponse['person']) || isset($createResponse['based_on']) ? $createResponse : $createResponse);
            $dbData['ehealth_payload'] = is_array($payloadToStore) ? $payloadToStore : (array) $payloadToStore;
        }

        app(MedicationRequestRepository::class)->store($dbData, (int) $carePlan->person_id);

        return $dbData['uuid'];
    }

    /**
     * Create Medication Request (Draft) directly from Encounter without Care Plan.
     */
    public function createEncounterDraft(\App\Models\MedicalEvents\Sql\Encounter $encounter, array $formData = [], array $employeeContext = []): string
    {
        $dbData = [
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $encounter->person_id,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => 'draft',
            'started_at' => $formData['started_at'] ?? now()->toDateString(),
            'ended_at' => $formData['ended_at'] ?? now()->addDays(30)->toDateString(),
            'medication_id' => $formData['medication_id'] ?? null,
            'medication_qty' => (float) ($formData['medication_qty'] ?? 1),
            'medication_program_id' => $formData['program_id'] ?? null,
            'intent' => 'order',
            'category' => 'community',
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

        $mapper = new \App\Services\MedicalEvents\Mappers\MedicationRequestMapper();

        if (!empty($dbData['medication_program_id'])) {
            $prequalifyPayload = $mapper->toPrequalifyPayload($dbData, $uuids, null);
            $response = MedicationRequest::preQualify($prequalifyPayload);

            if (app()->bound(\App\Services\MedicalEvents\EHealthJobResolver::class)) {
                $finalResponse = app(\App\Services\MedicalEvents\EHealthJobResolver::class)->resolve($response);
                app(\App\Services\MedicalEvents\EHealthJobResolver::class)->assertPrequalifyValid($finalResponse);
            }
        }

        $createPayload = $mapper->toCreateRequestPayload($dbData, $uuids, null);
        Log::debug('ePrescription (Encounter) Create Request Payload:', $createPayload);
        $createResponse = MedicationRequest::createMedicationRequest($createPayload);

        if (app()->bound(\App\Services\MedicalEvents\EHealthJobResolver::class)) {
            $finalCreateResponse = app(\App\Services\MedicalEvents\EHealthJobResolver::class)->resolve($createResponse);
            Log::debug('ePrescription (Encounter) Create Response from eHealth:', ['response' => $createResponse, 'resolved' => $finalCreateResponse]);
            if (($finalCreateResponse['status'] ?? null) === 'failed') {
                throw new \App\Exceptions\EHealth\EHealthValidationException($finalCreateResponse);
            }
            $dbData['request_number'] = $finalCreateResponse['request_number'] ?? ($finalCreateResponse['requisition'] ?? ($finalCreateResponse['data']['request_number'] ?? null));
            $dbData['uuid'] = $finalCreateResponse['id'] ?? ($finalCreateResponse['data']['id'] ?? $dbData['uuid']);
            $payloadToStore = $finalCreateResponse['data'] ?? (isset($finalCreateResponse['person']) || isset($finalCreateResponse['based_on']) ? $finalCreateResponse : ($createResponse['data'] ?? (isset($createResponse['person']) || isset($createResponse['based_on']) ? $createResponse : $finalCreateResponse)));
            $dbData['ehealth_payload'] = is_array($payloadToStore) ? $payloadToStore : (array) $payloadToStore;
        } else {
            Log::debug('ePrescription (Encounter) Create Response from eHealth:', ['response' => $createResponse]);
            $dbData['request_number'] = $createResponse['request_number'] ?? ($createResponse['data']['request_number'] ?? null);
            $dbData['uuid'] = $createResponse['id'] ?? ($createResponse['data']['id'] ?? $dbData['uuid']);
            $payloadToStore = $createResponse['data'] ?? (isset($createResponse['person']) || isset($createResponse['based_on']) ? $createResponse : $createResponse);
            $dbData['ehealth_payload'] = is_array($payloadToStore) ? $payloadToStore : (array) $payloadToStore;
        }

        app(MedicationRequestRepository::class)->store($dbData, (int) $encounter->person_id);

        return $dbData['uuid'];
    }

    /**
     * Sign Medication Request.
     */
    public function sign(mixed $idOrCarePlan, mixed $payloadOrRequestRecord, array $formData = [], string $informWith = '', float $remainingQty = 0.0): array
    {
        if (is_string($idOrCarePlan) && is_array($payloadOrRequestRecord)) {
            try {
                $response = MedicationRequest::signMedicationRequest($idOrCarePlan, $payloadOrRequestRecord);

                return $response['data'] ?? $response;
            } catch (Exception $e) {
                Log::error('ePrescription Sign failed: ' . $e->getMessage());
                throw $e;
            }
        }

        $requestRecord = $payloadOrRequestRecord;

        $signedContent = $formData['signed_medication_request_request'] ?? ($formData['signed_content'] ?? ($formData['signed_data'] ?? null));

        if (empty($signedContent) && isset($formData['password'], $formData['knedp']) && ($idOrCarePlan instanceof \App\Models\CarePlan || $idOrCarePlan instanceof \App\Models\MedicalEvents\Sql\Encounter) && $requestRecord instanceof \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest) {
            $signPayload = $this->buildSignPayload($idOrCarePlan, $requestRecord, $informWith);

            $signedContent = signatureService()->signData(
                $signPayload,
                $formData['password'],
                $formData['knedp'],
                $formData['keyContainerUpload'] ?? null,
                \Illuminate\Support\Facades\Auth::user()?->party?->taxId
            );
        }

        $payload = [
            'signed_medication_request_request' => $signedContent,
            'signed_content_encoding' => 'base64',
        ];

        $response = MedicationRequest::signMedicationRequest($requestRecord->uuid, $payload);

        $result = $response['data'] ?? $response;

        if (app()->bound(\App\Services\MedicalEvents\EHealthJobResolver::class)) {
            $finalResponse = app(\App\Services\MedicalEvents\EHealthJobResolver::class)->resolve($response);
            $result = $finalResponse['data'] ?? $finalResponse;
        }

        $requestRecord->update(['status' => 'active']);

        if (!isset($result['success_message'])) {
            $result['success_message'] = 'Рецепт успішно підписано КЕП та переведено у статус «Активний»!';
        }

        return $result;
    }

    /**
     * Reject Medication Request (ACTIVE).
     */
    public function reject(mixed $idOrCarePlan, mixed $payloadOrRequestRecord = [], array $formData = [], string $statusReason = ''): array
    {
        if (is_string($idOrCarePlan) && is_array($payloadOrRequestRecord)) {
            try {
                $response = MedicationRequest::rejectMedicationRequest($idOrCarePlan, $payloadOrRequestRecord);

                return $response['data'] ?? $response;
            } catch (Exception $e) {
                Log::error('ePrescription Reject failed: ' . $e->getMessage());
                throw $e;
            }
        }

        $requestRecord = $payloadOrRequestRecord;

        if (in_array(strtolower((string) $requestRecord->status), ['draft', 'new'], true)) {
            try {
                MedicationRequest::rejectUnsignedMedicationRequest((string) $requestRecord->uuid, []);
            } catch (\Exception $e) {
                Log::warning('eHealth reject draft returned exception: ' . $e->getMessage());
            }

            $requestRecord->update(['status' => 'rejected']);

            return [];
        }

        $personUuid = $idOrCarePlan instanceof \App\Models\CarePlan
            ? $idOrCarePlan->person->uuid
            : ($requestRecord->person_uuid ?? ($requestRecord->person->uuid ?? ''));

        $signedContent = $formData['signed_content'] ?? ($formData['signed_data'] ?? null);

        if (empty($signedContent) && isset($formData['password'], $formData['knedp'])) {
            $signPayload = [
                'id' => (string) $requestRecord->uuid,
                'reject_reason_code' => $statusReason,
                'reject_reason' => $statusReason,
            ];

            $signedContent = signatureService()->signData(
                $signPayload,
                $formData['password'],
                $formData['knedp'],
                $formData['keyContainerUpload'] ?? null,
                \Illuminate\Support\Facades\Auth::user()?->party?->taxId
            );
        }

        $payload = [
            'person_id' => $personUuid,
            'reject_reason_code' => $statusReason,
            'reject_reason' => $statusReason,
            'signed_content' => $signedContent,
            'signed_content_encoding' => 'base64',
        ];

        $activeId = $this->resolveActiveEhealthId($personUuid, (string) $requestRecord->uuid);
        $response = MedicationRequest::rejectMedicationRequest($activeId, $payload);

        $result = $response['data'] ?? $response;

        if (app()->bound(\App\Services\MedicalEvents\EHealthJobResolver::class)) {
            $finalResponse = app(\App\Services\MedicalEvents\EHealthJobResolver::class)->resolve($response);
            $result = $finalResponse['data'] ?? $finalResponse;
        }

        $newStatus = strtolower((string) ($result['status'] ?? 'rejected'));
        $requestRecord->update(['status' => $newStatus === 'active' ? 'rejected' : $newStatus]);

        return $result;
    }

    /**
     * Reject un-signed Medication Request (NEW).
     */
    public function rejectDraft(string $id, array $payload): array
    {
        try {
            $response = MedicationRequest::rejectUnsignedMedicationRequest($id, $payload);

            return $response['data'] ?? $response;
        } catch (Exception $e) {
            Log::error('ePrescription Reject Draft failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Build payload for KEP signing of Medication Request Request.
     *
     * @param  \App\Models\CarePlan  $carePlan
     * @param  \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest  $requestRecord
     * @param  string  $informWith
     * @return array<string, mixed>
     */
    protected function buildSignPayload(\App\Models\CarePlan|\App\Models\MedicalEvents\Sql\Encounter $contextModel, \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest $requestRecord, string $informWith): array
    {
        if (!empty($requestRecord->ehealth_payload) && is_array($requestRecord->ehealth_payload)) {
            $signedContent = $requestRecord->ehealth_payload;
            if (isset($signedContent['data']) && is_array($signedContent['data'])) {
                $signedContent = $signedContent['data'];
            }
            Log::debug('ePrescription Sign Request Payload (from stored eHealth payload):', $signedContent);

            return $signedContent;
        }

        $personUuid = $contextModel instanceof \App\Models\CarePlan
            ? ($contextModel->person->uuid ?? null)
            : (\App\Models\Person\Person::find($contextModel->person_id)?->uuid ?? null);

        try {
            if ($personUuid) {
                $response = \App\Classes\eHealth\Api\MedicationRequest::getRequestsBySearchParams((string) $personUuid, ['id' => $requestRecord->uuid]);
                $fetchedData = $response['data'][0] ?? ($response[0] ?? null);
                if (!empty($fetchedData) && is_array($fetchedData) && ($fetchedData['id'] ?? null) === $requestRecord->uuid) {
                    $requestRecord->update(['ehealth_payload' => $fetchedData]);
                    Log::debug('ePrescription Sign Request Payload (fetched from eHealth):', $fetchedData);

                    return $fetchedData;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Could not fetch MedicationRequestRequest from eHealth for signing fallback: ' . $e->getMessage());
        }

        $employee = \App\Models\Employee\Employee::find($requestRecord->employee_id);
        $division = \App\Models\Division::find($requestRecord->division_id);
        $encounter = \App\Models\MedicalEvents\Sql\Encounter::find($requestRecord->context_id);

        $uuids = [
            'person_uuid' => $personUuid,
            'encounter_uuid' => $encounter ? $encounter->uuid : null,
            'employee_uuid' => $employee ? $employee->uuid : null,
            'division_uuid' => $division ? $division->uuid : null,
        ];

        $dosageInstructions = [];
        foreach ($requestRecord->dosageInstructions as $inst) {
            $doseAndRate = !empty($inst->dose_and_rate) && is_string($inst->dose_and_rate)
                ? json_decode($inst->dose_and_rate, true)
                : ($inst->dose_and_rate ?: []);

            $timing = !empty($inst->timing) && is_string($inst->timing)
                ? json_decode($inst->timing, true)
                : ($inst->timing ?: null);

            $text = !empty($inst->text) ? $inst->text : 'За призначенням лікаря';
            $patientInstruction = !empty($inst->patient_instruction) ? $inst->patient_instruction : $text;

            $dosageInstructions[] = [
                'sequence' => $inst->sequence ?? 1,
                'text' => $text,
                'patient_instruction' => $patientInstruction,
                'as_needed_boolean' => (bool) ($inst->as_needed_boolean ?? false),
                'route' => $inst->route ?? 'oral',
                'method' => $inst->method ?? null,
                'timing' => $timing,
                'dose_and_rate' => $doseAndRate,
                'max_dose_per_administration' => $inst->max_dose_per_administration !== null ? (float) $inst->max_dose_per_administration : null,
                'max_dose_per_period' => $inst->max_dose_per_period !== null ? (float) $inst->max_dose_per_period : null,
                'max_dose_per_lifetime' => $inst->max_dose_per_lifetime !== null ? (float) $inst->max_dose_per_lifetime : null,
            ];
        }

        $startedAt = !empty($requestRecord->started_at)
            ? \Carbon\Carbon::parse($requestRecord->started_at)->format('Y-m-d')
            : null;

        $endedAt = !empty($requestRecord->ended_at)
            ? \Carbon\Carbon::parse($requestRecord->ended_at)->format('Y-m-d')
            : null;

        $createdAt = !empty($requestRecord->created_at)
            ? \Carbon\Carbon::parse($requestRecord->created_at)->format('Y-m-d')
            : now()->format('Y-m-d');

        $data = [
            'created_at' => $createdAt,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'medication_id' => $requestRecord->medication_id,
            'medication_qty' => (float) $requestRecord->medication_qty,
            'medication_program_id' => $requestRecord->medication_program_id,
            'intent' => $requestRecord->intent ?? 'order',
            'category' => $requestRecord->category ?? 'community',
            'based_on_uuid' => $requestRecord->based_on_uuid ?? ($activity ? $activity->uuid : null),
            'container_dosage' => $requestRecord->container_dosage,
            'note' => $requestRecord->note,
            'inform_with' => $informWith !== '' ? $informWith : ($requestRecord->inform_with ?? ''),
            'dosage_instructions' => $dosageInstructions,
        ];

        $mapper = new \App\Services\MedicalEvents\Mappers\MedicationRequestMapper();
        $signedContent = $mapper->toCreateSignedContent($data, $uuids, $carePlan->uuid);

        Log::debug('ePrescription Sign Request Payload:', $signedContent);

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
     * @param  \App\Models\CarePlan  $carePlan
     * @param  string  $prescriptionId
     * @param  string|null  $signatureText
     * @param  array|null  $ehealthData
     * @return string
     */
    public function buildFallbackPrintoutHtml(\App\Models\CarePlan $carePlan, string $prescriptionId, ?string $signatureText = null, ?array $ehealthData = null): string
    {
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

        $startDate = $record?->started_at ? \Carbon\Carbon::parse($record->started_at)->format('d.m.Y') : ($ehealthData['created_at'] ?? now()->format('d.m.Y'));
        $endDate = $record?->ended_at ? \Carbon\Carbon::parse($record->ended_at)->format('d.m.Y') : ($ehealthData['ended_at'] ?? '—');

        $author = null;
        if ($record && !empty($record->employee_id)) {
            $field = is_numeric($record->employee_id) ? 'id' : 'uuid';
            $author = \App\Models\Employee\Employee::where($field, $record->employee_id)->first();
        }

        $doctorName = $author?->party?->full_name ?? ($author?->full_name ?? ($ehealthData['employee']['name'] ?? ($record?->ehealth_payload['employee']['name'] ?? (\Illuminate\Support\Facades\Auth::user()?->party?->full_name ?? '—'))));
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

        $medicationQty = $ehealthData['medication_qty'] ?? ($ehealthData['medication']['qty'] ?? ($record?->medication_qty ? "{$record->medication_qty} од." : '—'));
        $programName = $ehealthData['medical_program_name']
            ?? ($ehealthData['medical_program']['name']
            ?? ($record?->ehealth_payload['medical_program']['name']
            ?? ($record?->ehealth_payload['medical_program_name']
            ?? ($record?->medication_program_id ? ($record->medication_program_id === '5e3e2307-8898-4428-a400-e3776a39d56f' ? 'Реімбурсація (Доступні ліки)' : 'Державна програма / Реімбурсація') : 'За власні кошти'))));

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

        if ($requestRecord && !empty($requestRecord->ehealth_payload['active_id'])) {
            return (string) $requestRecord->ehealth_payload['active_id'];
        }

        if (empty($personUuid) && $requestRecord) {
            $personUuid = (string) ($requestRecord->person_id ? \App\Models\Person\Person::where('id', $requestRecord->person_id)->value('uuid') : '');
        }

        if (empty($personUuid)) {
            return $localId;
        }

        try {
            $queries = [];
            if ($requestRecord && !empty($requestRecord->request_number)) {
                $queries[] = ['request_number' => $requestRecord->request_number];
            }
            $queries[] = [];

            foreach ($queries as $query) {
                $activeResponse = \App\Classes\eHealth\Api\MedicationRequest::getBySearchParams($personUuid, $query);
                $activeItems = $activeResponse['data'] ?? ($activeResponse[0] ?? []);

                if (is_array($activeItems)) {
                    foreach ($activeItems as $item) {
                        if (empty($item['id'])) {
                            continue;
                        }
                        $isMatch = $item['id'] === $localId
                            || ($requestRecord && !empty($requestRecord->request_number) && ($item['request_number'] ?? '') === $requestRecord->request_number);

                        if ($isMatch) {
                            if ($requestRecord && $item['id'] !== $localId) {
                                $payload = is_array($requestRecord->ehealth_payload) ? $requestRecord->ehealth_payload : [];
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
}
