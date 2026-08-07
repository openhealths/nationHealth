<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Str;
use App\Services\MedicalEvents\Concerns\ResolvesEmployeeContext;

class ReferralRequestLifecycleService
{
    use ResolvesEmployeeContext;
    public function __construct(
        private readonly EHealthJobResolver $jobResolver,
    ) {
    }

    public function sumIssuedQuantity(CarePlanActivity $activity): float
    {
        if ($activity->kind === 'service_request') {
            return Repository::serviceRequest()->sumIssuedQuantityByActivity($activity->id);
        }

        return Repository::deviceRequest()->sumIssuedQuantityByActivity($activity->id);
    }

    public function findDraftByActivity(CarePlanActivity $activity): ServiceRequestRequest|DeviceRequestRequest|null
    {
        if ($activity->kind === 'service_request') {
            return Repository::serviceRequest()->findDraftByActivity($activity->id);
        }

        return Repository::deviceRequest()->findDraftByActivity($activity->id);
    }

    /**
     * @param  array<string, mixed>  $formData
     * @param  array<string, int|string|null>  $employeeContext
     */
    public function createDraft(CarePlan $carePlan, array $formData, float $qty, array $employeeContext): string
    {
        $activity = CarePlanActivity::query()
            ->with('carePlan')
            ->findOrFail($formData['activity_id']);

        $resolvedKind = $activity->resolvedKind();
        if (!in_array($resolvedKind, ['service_request', 'device_request'], true)) {
            throw new \InvalidArgumentException(__('care-plan.referral_wrong_activity_kind'));
        }

        $formData['kind'] = $resolvedKind;

        $dbData = [
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $carePlan->person_id,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => 'draft',
            'started_at' => convertToYmd($formData['started_at']),
            'ended_at' => convertToYmd($formData['ended_at']),
            'quantity' => $qty,
            'quantity_system' => $activity->quantity_system ?: 'SERVICE_UNIT',
            'quantity_code' => $activity->quantity_code ?: 'PIECE',
            'program_id' => $formData['program_id'] ?? null,
            'intent' => $formData['intent'] ?? 'order',
            'category' => $formData['category'] ?? null,
            'based_on_id' => $formData['activity_id'],
            'context_id' => $carePlan->encounter?->id ?? null,
            'priority' => $formData['priority'],
            'note' => $formData['note'] ?? null,
            'supporting_info' => $formData['supporting_info'] ?? null,
            'based_on_uuid' => $activity->uuid,
        ];

        $uuids = [
            'person_uuid' => $carePlan->person->uuid,
            'encounter_uuid' => $carePlan->encounter?->uuid ?? null,
            'episode_uuid' => $carePlan->episode_id,
            'employee_uuid' => $employeeContext['employee_uuid'] ?? null,
            'legal_entity_uuid' => $employeeContext['legal_entity_uuid'] ?? null,
        ];

        if ($formData['kind'] === 'service_request') {
            $dbData['service_id'] = $activity->product_reference;

            if (!empty($activity->program)) {
                $dbData['program_id'] = $activity->program;
            } else {
                $dbData['program_id'] = null;
            }

            $mapper = Fhir::serviceRequest();

            if (!empty($dbData['program_id'])) {
                $prequalifyPayload = $mapper->toPrequalifyPayload(
                    $dbData,
                    $uuids,
                    $carePlan->uuid,
                    (string) $activity->uuid
                );
                $this->runPrequalify(
                    EHealth::serviceRequest()->prequalify($carePlan->person->uuid, $prequalifyPayload)
                );
            }

            return $this->persistLocalDraft($dbData, $carePlan->person_id, 'service_request');
        }

        $dbData['device_id'] = $activity->product_reference ?: $activity->product_codeable_concept;
        $dbData['device_code_type'] = !empty($activity->product_reference) ? 'DEVICE_DEFINITION' : 'CLASSIFICATION_TYPE';
        if (str_contains(strtolower((string) $activity->kind), 'device')) {
            $dbData['quantity_system'] = $activity->quantity_system ?: 'device_unit';
            $dbData['quantity_code'] = strtolower($activity->quantity_code ?: 'piece');
        }

        $mapper = Fhir::deviceRequest();
        $prequalifyPayload = $mapper->toPrequalifyPayload(
            $dbData,
            $uuids,
            $carePlan->uuid,
            (string) $activity->uuid
        );
        $this->runPrequalify(
            EHealth::deviceRequest()->prequalify($carePlan->person->uuid, $prequalifyPayload)
        );

        return $this->persistLocalDraft($dbData, $carePlan->person_id, 'device_request');
    }

    public function createEncounterDraft(\App\Models\MedicalEvents\Sql\Encounter $encounter, array $formData, float $qty, array $employeeContext): string
    {
        $kind = $formData['kind'] ?? 'service_request';
        $dbData = [
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $encounter->person_id,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => 'draft',
            'started_at' => convertToYmd($formData['started_at'] ?? now()->toDateString()),
            'ended_at' => convertToYmd($formData['ended_at'] ?? now()->addMonths(1)->toDateString()),
            'quantity' => $qty,
            'quantity_system' => $formData['quantity_system'] ?? 'SERVICE_UNIT',
            'quantity_code' => $formData['quantity_code'] ?? 'PIECE',
            'program_id' => $formData['program_id'] ?? null,
            'intent' => $formData['intent'] ?? 'order',
            'category' => $formData['category'] ?? null,
            'based_on_id' => null,
            'context_id' => $encounter->id,
            'priority' => $formData['priority'] ?? 'routine',
            'note' => $formData['note'] ?? null,
            'supporting_info' => $formData['supporting_info'] ?? null,
            'based_on_uuid' => null,
        ];

        $personUuid = \App\Models\Person\Person::find($encounter->person_id)?->uuid;

        $uuids = [
            'person_uuid' => $personUuid,
            'encounter_uuid' => $encounter->uuid,
            'episode_uuid' => $encounter->episode?->value ?? null,
            'employee_uuid' => $employeeContext['employee_uuid'] ?? null,
            'legal_entity_uuid' => $employeeContext['legal_entity_uuid'] ?? null,
        ];

        if ($kind === 'service_request') {
            $dbData['service_id'] = $formData['service_id'] ?? null;
            $mapper = Fhir::serviceRequest();

            if (!empty($dbData['program_id']) && $personUuid) {
                $prequalifyPayload = $mapper->toPrequalifyPayload(
                    $dbData,
                    $uuids,
                    null,
                    null
                );
                $this->runPrequalify(
                    EHealth::serviceRequest()->prequalify((string) $personUuid, $prequalifyPayload)
                );
            }

            return $this->persistLocalDraft($dbData, (int) $encounter->person_id, 'service_request');
        }

        $dbData['device_id'] = $formData['device_id'] ?? null;
        $dbData['device_code_type'] = $formData['device_code_type'] ?? 'DEVICE_DEFINITION';
        $mapper = Fhir::deviceRequest();
        if ($personUuid) {
            $prequalifyPayload = $mapper->toPrequalifyPayload(
                $dbData,
                $uuids,
                null,
                null
            );
            $this->runPrequalify(
                EHealth::deviceRequest()->prequalify((string) $personUuid, $prequalifyPayload)
            );
        }

        return $this->persistLocalDraft($dbData, (int) $encounter->person_id, 'device_request');
    }

    public function resendSms(string $personUuid, string $requestId, string $kind): EHealthResponse
    {
        return $kind === 'service_request'
            ? EHealth::serviceRequest()->resendSms($personUuid, $requestId)
            : EHealth::deviceRequest()->resendSms($personUuid, $requestId);
    }

    public function buildPrintoutHtml(CarePlan|\App\Models\MedicalEvents\Sql\Encounter $contextModel, string $requestId): string
    {
        $record = Repository::serviceRequest()->findByUuid($requestId)
            ?? Repository::deviceRequest()->findByUuid($requestId);

        if (!$record instanceof ServiceRequestRequest && !$record instanceof DeviceRequestRequest) {
            throw new \RuntimeException('Направлення не знайдено');
        }

        $record->loadMissing('employee');

        $code = $record instanceof ServiceRequestRequest ? $record->service_id : $record->device_id;
        $name = $record instanceof ServiceRequestRequest
            ? 'Направлення на послугу (ServiceRequest)'
            : 'Направлення на виріб (DeviceRequest)';
        $employeeName = $record->employee?->full_name ?? '—';
        $patient = $contextModel instanceof CarePlan ? $contextModel->person : \App\Models\Person\Person::find($contextModel->person_id);
        $patientName = $patient?->full_name ?? ($patient?->primaryName ? ($patient->primaryName->last_name . ' ' . $patient->primaryName->first_name) : 'Пацієнт');
        $adviceText = $record instanceof ServiceRequestRequest
            ? 'Зверніться до будь-якого медичного закладу, що надає відповідні послуги за контрактом з НСЗУ.'
            : 'Зверніться до аптеки або закладу, що бере участь у програмі реімбурсації чи відпуску відповідних медичних виробів за контрактом з НСЗУ.';

        return "
            <div style='font-family: sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; border: 1px solid #ccc; border-radius: 8px;'>
                <h2 style='text-align: center; color: #1e3a8a;'>ІНФОРМАЦІЙНА ДОВІДКА НАПРАВЛЕННЯ</h2>
                <p style='text-align: center; font-size: 14px; color: #555;'>Електронне направлення № " . e($record->request_number ?: $record->uuid) . "</p>
                <hr style='border-top: 1px solid #eee; margin: 20px 0;'/>
                <table style='width: 100%; font-size: 14px; border-collapse: collapse;'>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Тип:</td><td style='padding: 8px 0;'>" . e($name) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Статус:</td><td style='padding: 8px 0;'>" . e((string) $record->status) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Пацієнт:</td><td style='padding: 8px 0;'>" . e($patientName) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Код послуги/виробу:</td><td style='padding: 8px 0;'>" . e($code) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Кількість:</td><td style='padding: 8px 0;'>" . e((string) $record->quantity) . " од.</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Термін дії:</td><td style='padding: 8px 0;'>з " . e(\Carbon\Carbon::parse($record->started_at)->format('d.m.Y')) . " по " . e(\Carbon\Carbon::parse($record->ended_at)->format('d.m.Y')) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Лікар:</td><td style='padding: 8px 0;'>" . e($employeeName) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Примітки:</td><td style='padding: 8px 0;'>" . e((string) $record->note) . "</td></tr>
                </table>
                <div style='margin-top: 40px; text-align: center; font-size: 12px; color: #888;'>
                    " . e($adviceText) . "
                </div>
            </div>
        ";
    }

    /**
     * @param  array<string, mixed>  $dbData
     */
    private function persistLocalDraft(array $dbData, int $personId, string $kind): string
    {
        if ($kind === 'service_request') {
            Repository::serviceRequest()->store($dbData, $personId);
        } else {
            Repository::deviceRequest()->store($dbData, $personId);
        }

        return $dbData['uuid'];
    }

    private function runPrequalify(EHealthResponse $response): void
    {
        $finalResponse = $this->jobResolver->resolve($response->getData());
        $this->jobResolver->assertPrequalifyValid($finalResponse);
    }

    /**
     * @param  array<string, mixed>  $dbData
     * @param  array<string, mixed>  $responseData
     */
    private function persistResponse(array $dbData, array $responseData, string $kind, int $personId): string
    {
        $finalResponse = $this->jobResolver->resolve($responseData);

        if (($finalResponse['status'] ?? null) === 'failed') {
            throw new EHealthValidationException($finalResponse);
        }

        $dbData['request_number'] = $finalResponse['request_number'] ?? ($finalResponse['requisition'] ?? null);
        $dbData['status'] = $finalResponse['status'] ?? 'NEW';
        $dbData['uuid'] = $finalResponse['id'] ?? $dbData['uuid'];

        if ($kind === 'service_request') {
            Repository::serviceRequest()->store($dbData, $personId);
        } else {
            Repository::deviceRequest()->store($dbData, $personId);
        }

        return $dbData['uuid'];
    }

    /**
     * @param  array<string, mixed>  $localDbData
     * @return array<string, mixed>
     */
    public function syncReferralFromRemote(
        CarePlan|\App\Models\MedicalEvents\Sql\Encounter $contextModel,
        ?CarePlanActivity $activity,
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        string $kind,
        array $localDbData,
        ?array $remote = null
    ): array {
        $personUuid = $contextModel instanceof CarePlan ? $contextModel->person->uuid : \App\Models\Person\Person::find($contextModel->person_id)?->uuid;
        $remote ??= $this->fetchRemoteReferral((string) $personUuid, (string) $requestRecord->uuid, $kind);
        $dbData = array_merge($localDbData, $this->mapRemoteReferralFields($remote, $kind));

        $dbData['employee_id'] = $localDbData['employee_id'] ?? $requestRecord->employee_id;
        $dbData['division_id'] = $localDbData['division_id'] ?? $requestRecord->division_id;
        $dbData['based_on_id'] = $localDbData['based_on_id'] ?? $requestRecord->based_on_id ?? $activity?->id;
        $dbData['context_id'] = $localDbData['context_id'] ?? $requestRecord->context_id ?? ($contextModel instanceof CarePlan ? $contextModel->encounter?->id : $contextModel->id);

        $this->persistSignedReferral($dbData, $kind, (int) $contextModel->person_id);

        return $dbData;
    }

    public function trySyncDraftFromEHealth(
        CarePlan|\App\Models\MedicalEvents\Sql\Encounter $contextModel,
        ?CarePlanActivity $activity,
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        string $kind
    ): bool {
        if (strtolower((string) $requestRecord->status) !== 'draft') {
            return false;
        }

        $personUuid = $contextModel instanceof CarePlan ? $contextModel->person->uuid : \App\Models\Person\Person::find($contextModel->person_id)?->uuid;
        if (!$personUuid) {
            return false;
        }

        try {
            $response = $kind === 'service_request'
                ? EHealth::serviceRequest()->getById((string) $personUuid, (string) $requestRecord->uuid)
                : EHealth::deviceRequest()->getById((string) $personUuid, (string) $requestRecord->uuid);
            $remote = $response->getData();
        } catch (EHealthResponseException|EHealthValidationException) {
            return false;
        }

        if ($remote === []) {
            return false;
        }

        $remoteStatus = strtolower((string) ($remote['status'] ?? ''));
        if ($remoteStatus === '' || $remoteStatus === 'draft') {
            return false;
        }

        $localDbData = $this->buildLocalSyncBaseData($requestRecord, $activity, $contextModel);
        $this->syncReferralFromRemote($contextModel, $activity, $requestRecord, $kind, $localDbData, $remote);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchRemoteReferral(string $personUuid, string $requestUuid, string $kind): array
    {
        $response = $kind === 'service_request'
            ? EHealth::serviceRequest()->getById($personUuid, $requestUuid)
            : EHealth::deviceRequest()->getById($personUuid, $requestUuid);

        $remote = $response->getData();
        if ($remote === []) {
            throw new EHealthResponseException($response);
        }

        return $remote;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function mapRemoteReferralFields(array $remote, string $kind): array
    {
        $mapper = $kind === 'service_request' ? Fhir::serviceRequest() : Fhir::deviceRequest();
        $mapped = $mapper->fromFhir($remote);

        $dbData = [
            'uuid' => $remote['id'] ?? $mapped['uuid'] ?? null,
            'status' => $remote['status'] ?? $mapped['status'] ?? null,
            'request_number' => $remote['request_number'] ?? $remote['requisition'] ?? $mapped['request_number'] ?? null,
            'started_at' => $this->normalizeRemoteDate(
                data_get($remote, 'occurrence_period.start')
                    ?? data_get($remote, 'occurrencePeriod.start')
                    ?? $mapped['started_at'] ?? null
            ),
            'ended_at' => $this->normalizeRemoteDate(
                data_get($remote, 'occurrence_period.end')
                    ?? data_get($remote, 'occurrencePeriod.end')
                    ?? $mapped['ended_at'] ?? null
            ),
            'quantity' => data_get($remote, 'quantity.value') ?? $mapped['quantity'] ?? null,
            'program_id' => data_get($remote, 'program.identifier.value') ?? $mapped['program_id'] ?? null,
            'intent' => $remote['intent'] ?? $mapped['intent'] ?? null,
            'category' => data_get($remote, 'category.coding.0.code')
                ?? data_get($remote, 'category.0.coding.0.code')
                ?? $mapped['category'] ?? null,
            'priority' => $remote['priority'] ?? $mapped['priority'] ?? null,
            'note' => data_get($remote, 'note.0.text') ?? (is_string($remote['note'] ?? null) ? $remote['note'] : null) ?? $mapped['note'] ?? null,
        ];

        $supportingInfo = $this->mapRemoteSupportingInfo($remote);
        if ($supportingInfo !== []) {
            $dbData['supporting_info'] = $supportingInfo;
        }

        if ($kind === 'service_request') {
            $dbData['service_id'] = data_get($remote, 'code.identifier.value')
                ?? data_get($remote, 'code.coding.0.code')
                ?? $mapped['service_id'] ?? null;
        } else {
            $dbData['device_id'] = data_get($remote, 'code_reference.identifier.value')
                ?? data_get($remote, 'code.coding.0.code')
                ?? $mapped['device_id'] ?? null;
        }

        return array_filter($dbData, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<array{type?: string, uuid?: string}>
     */
    private function mapRemoteSupportingInfo(array $remote): array
    {
        $items = $remote['supporting_info'] ?? $remote['supportingInfo'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $uuid = data_get($item, 'identifier.value');
            $type = data_get($item, 'identifier.type.coding.0.code');

            if ($uuid && $type) {
                $mapped[] = [
                    'type' => $type,
                    'uuid' => $uuid,
                ];
            }
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLocalSyncBaseData(
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        ?CarePlanActivity $activity,
        CarePlan|\App\Models\MedicalEvents\Sql\Encounter $contextModel
    ): array {
        $startedAt = $requestRecord->started_at;
        $endedAt = $requestRecord->ended_at;

        $dbData = [
            'uuid' => $requestRecord->uuid,
            'employee_id' => $requestRecord->employee_id,
            'division_id' => $requestRecord->division_id,
            'based_on_id' => $requestRecord->based_on_id ?? $activity?->id,
            'context_id' => $requestRecord->context_id ?? ($contextModel instanceof CarePlan ? $contextModel->encounter?->id : $contextModel->id),
            'quantity' => $requestRecord->quantity,
            'quantity_system' => $activity?->quantity_system ?: 'SERVICE_UNIT',
            'quantity_code' => $activity?->quantity_code ?: 'PIECE',
            'intent' => $requestRecord->intent ?? 'order',
            'category' => $requestRecord->category,
            'program_id' => $requestRecord->program_id,
            'priority' => $requestRecord->priority ?? 'routine',
            'note' => $requestRecord->note,
            'supporting_info' => $requestRecord->supporting_info,
            'started_at' => $startedAt instanceof \DateTimeInterface
                ? $startedAt->format('Y-m-d')
                : (string) $startedAt,
            'ended_at' => $endedAt instanceof \DateTimeInterface
                ? $endedAt->format('Y-m-d')
                : (string) $endedAt,
            'based_on_uuid' => $activity?->uuid,
        ];

        if ($requestRecord instanceof ServiceRequestRequest) {
            $dbData['service_id'] = $requestRecord->service_id ?: $activity?->product_reference;
        } else {
            $dbData['device_id'] = $requestRecord->device_id ?: $activity?->product_reference;
        }

        return $dbData;
    }

    /**
     * @param  array<string, mixed>  $dbData
     */
    private function persistSignedReferral(array $dbData, string $kind, int $personId): void
    {
        if ($kind === 'service_request') {
            Repository::serviceRequest()->store($dbData, $personId);
        } else {
            Repository::deviceRequest()->store($dbData, $personId);
        }
    }

    private function normalizeRemoteDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return convertToYmd($value);
    }

    /**
     * @param  string  $referralUuid
     * @param  Employee  $employee
     * @param  string|null  $patientUuid  eHealth patient UUID (subject.identifier.value from search result)
     * @param  array  $payload  Optional payload for process
     * @return array
     */
    public function takeIntoWork(string $referralUuid, Employee $employee, ?string $patientUuid = null, array $payload = []): array
    {
        $model = Repository::serviceRequest()->findByUuid($referralUuid);
        $programId = $model ? $model->program_id : null;

        if (empty($payload)) {
            $payload = [
                'used_by_employee' => [
                    'identifier' => [
                        'type' => [
                            'coding' => [
                                [
                                    'system' => 'eHealth/resources',
                                    'code' => 'employee',
                                ],
                            ],
                        ],
                        'value' => $employee->uuid,
                    ],
                ],
            ];

            // Division UUID: try employee->division_uuid first, otherwise resolve via division_id
            $divisionUuid = $employee->division_uuid;
            if (!$divisionUuid && $employee->division_id) {
                $division = \App\Models\Division::find($employee->division_id);
                $divisionUuid = $division?->uuid;
            }

            if ($divisionUuid) {
                $payload['used_by_division'] = [
                    'identifier' => [
                        'type' => [
                            'coding' => [
                                [
                                    'system' => 'eHealth/resources',
                                    'code' => 'division',
                                ],
                            ],
                        ],
                        'value' => $divisionUuid,
                    ],
                ];
            }

            // Legal entity UUID: try employee->legal_entity_uuid first, otherwise resolve via legal_entity_id
            $legalEntityUuid = $employee->legal_entity_uuid;
            if (!$legalEntityUuid && $employee->legal_entity_id) {
                $legalEntity = \App\Models\LegalEntity::find($employee->legal_entity_id);
                $legalEntityUuid = $legalEntity?->uuid;
            }

            if ($legalEntityUuid) {
                $payload['used_by_legal_entity'] = [
                    'identifier' => [
                        'type' => [
                            'coding' => [
                                [
                                    'system' => 'eHealth/resources',
                                    'code' => 'legal_entity',
                                ],
                            ],
                        ],
                        'value' => $legalEntityUuid,
                    ],
                ];
            }

            if ($programId) {
                $payload['program'] = [
                    'identifier' => [
                        'type' => [
                            'coding' => [
                                [
                                    'system' => 'eHealth/resources',
                                    'code' => 'medical_program',
                                ],
                            ],
                        ],
                        'value' => $programId,
                    ],
                ];
            }
        }

        // Qualify: optional check — wrap in try/catch so a qualify failure
        // does not prevent the process (use) call from running.
        if ($programId) {
            try {
                \App\Classes\eHealth\Api\ServiceRequest::qualify($referralUuid, [
                    'programs' => [['id' => $programId]],
                ]);
            } catch (\Throwable $e) {
                logger()->warning('Qualify failed (non-blocking): ' . $e->getMessage(), [
                    'referral_uuid' => $referralUuid,
                ]);
            }
        }

        // Use Service Request (взяти в роботу)
        $response = \App\Classes\eHealth\Api\ServiceRequest::process($referralUuid, $payload);

        // Persist status to local DB:
        // If the referral was found from eHealth search (not in our DB), upsert it with in_progress status.
        if ($model) {
            $model->update(['status' => 'in_progress']);
        } elseif ($patientUuid) {
            // The referral came from eHealth search and is not in our local DB yet.
            // Store a minimal record so we can track its status going forward.
            $personModel = \App\Models\Person\Person::where('uuid', $patientUuid)->first();
            if ($personModel) {
                $responseData = $response;
                if (isset($response['data'])) {
                    $responseData = $response['data'];
                }
                Repository::serviceRequest()->store([
                    'uuid' => $referralUuid,
                    'status' => 'in_progress',
                    'request_number' => $responseData['requisition'] ?? null,
                    'employee_id' => $employee->id,
                    'division_id' => $employee->division_id,
                    // service_id is required by the repository schema; extract from response or fallback to empty
                    'service_id' => data_get($responseData, 'code.identifier.value')
                        ?? data_get($responseData, 'code.coding.0.code')
                        ?? '',
                    'quantity' => data_get($responseData, 'quantity.value') ?? 1,
                    'category' => data_get($responseData, 'category.coding.0.code') ?? null,
                    'intent' => $responseData['intent'] ?? 'order',
                ], $personModel->id);
            }
        }

        return $response;
    }

    /**
     * @param  string  $referralUuid
     * @param  string  $encounterUuid
     * @param  array  $payload  Optional payload for complete
     * @return array
     */
    public function completeReferral(string $referralUuid, string $encounterUuid, array $payload = []): array
    {
        if (empty($payload)) {
            $payload = [
                'based_on' => [
                    [
                        'identifier' => [
                            'type' => [
                                'coding' => [
                                    [
                                        'system' => 'eHealth/resources',
                                        'code' => 'encounter'
                                    ]
                                ]
                            ],
                            'value' => $encounterUuid
                        ]
                    ]
                ]
            ];
        }

        $response = \App\Classes\eHealth\Api\ServiceRequest::complete($referralUuid, $payload);

        $model = Repository::serviceRequest()->findByUuid($referralUuid);
        if ($model) {
            $model->update(['status' => 'completed']);
        }

        return $response;
    }

    /**
     * @param  string  $referralUuid
     * @param  string  $patientId
     * @param  array  $payload  Optional payload for cancel
     * @return array
     */
    public function cancelUsage(string $referralUuid, string $patientId, array $payload = []): array
    {
        $response = \App\Classes\eHealth\Api\ServiceRequest::cancelUsage($referralUuid, $patientId, $payload);

        $model = Repository::serviceRequest()->findByUuid($referralUuid);
        if ($model) {
            // Cancel usage typically returns it to 'active' state so another facility can take it.
            $model->update(['status' => 'active']);
        }

        return $response;
    }
}
