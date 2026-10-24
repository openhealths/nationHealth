<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Person\DeviceRequestStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Concerns\ResolvesEmployeeContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReferralRequestLifecycleService extends EHealthRequestLifecycleService
{
    use ResolvesEmployeeContext;

    protected function requestType(): string
    {
        return 'Referral Request';
    }

    public function sumIssuedQuantity(CarePlanActivity $activity): float
    {
        if ($activity->kind === 'service_request') {
            return Repository::serviceRequest()->sumIssuedQuantityByActivity((string) $activity->uuid);
        }

        return Repository::deviceRequest()->sumIssuedQuantityByActivity((string) $activity->uuid);
    }

    public function findDraftByActivity(CarePlanActivity $activity): ServiceRequestRequest|DeviceRequestRequest|null
    {
        if ($activity->kind === 'service_request') {
            return Repository::serviceRequest()->findDraftByActivity((string) $activity->uuid);
        }

        return Repository::deviceRequest()->findDraftByActivity((string) $activity->uuid);
    }

    /**
     * Create and locally persist a referral draft for a care plan activity.
     *
     * @param  array<string, mixed>  $formData
     * @param  array<string, int|string|null>  $employeeContext
     */
    public function createCarePlanDraft(CarePlan $carePlan, array $formData, float $qty, array $employeeContext): string
    {
        $activity = Repository::serviceRequest()->activityForDraft((int) $formData['activity_id'], (int) $carePlan->id, (int) $carePlan->personId);

        $resolvedKind = $activity->resolvedKind();
        if (!in_array($resolvedKind, ['service_request', 'device_request'], true)) {
            throw new \InvalidArgumentException(__('care-plan.referral_wrong_activity_kind'));
        }

        $formData['kind'] = $resolvedKind;

        $existingDraft = $resolvedKind === 'service_request'
            ? Repository::serviceRequest()->findDraftByActivity((string) $activity->uuid)
            : null;
        if ($existingDraft && (int) $existingDraft->personId !== (int) $carePlan->personId) {
            throw new \InvalidArgumentException(__('care-plan.document_context_unavailable'));
        }

        app(ActivityRemainingQuantityGuard::class)->assertCanIssue(
            (int) $activity->id,
            $qty,
            function (int $activityId) use ($resolvedKind, $activity, $existingDraft): float {
                $repository = $resolvedKind === 'service_request' ? Repository::serviceRequest() : Repository::deviceRequest();

                return $resolvedKind === 'service_request'
                    ? $repository->sumIssuedQuantityByActivity((string) $activity->uuid, ActivityRemainingQuantityGuard::occupyingStatusesExcluded(), $existingDraft?->uuid)
                    : $repository->sumIssuedQuantityByActivity((string) $activity->uuid, ActivityRemainingQuantityGuard::occupyingStatusesExcluded());
            }
        );

        $dbData = [
            'uuid' => $existingDraft?->uuid ?? (string) Str::uuid(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $carePlan->personId,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => $this->draftStatus($resolvedKind),
            'started_at' => $resolvedKind === 'service_request' ? $formData['started_at'] : convertToYmd($formData['started_at']),
            'ended_at' => $resolvedKind === 'service_request' ? $formData['ended_at'] : convertToYmd($formData['ended_at']),
            'quantity' => $qty,
            'quantity_system' => $activity->quantitySystem ?: 'SERVICE_UNIT',
            'quantity_code' => $activity->quantityCode ?: 'PIECE',
            'program_id' => $formData['program_id'] ?? null,
            'intent' => $formData['intent'] ?? 'order',
            'category' => $formData['category'] ?? null,
            'priority' => $formData['priority'],
            'note' => $formData['note'] ?? null,
            'patient_instruction' => $formData['patient_instruction'] ?? null,
            'reason_reference' => $formData['reason_reference'] ?? null,
            'inform_with' => $formData['inform_with'] ?? null,
            'supporting_info' => $formData['supporting_info'] ?? null,
            'based_on_uuid' => $activity->uuid,
            'context_uuid' => $carePlan->encounter?->uuid,
        ];

        $uuids = [
            'person_uuid' => $carePlan->person->uuid,
            'encounter_uuid' => $carePlan->encounter?->uuid ?? null,
            'episode_uuid' => $carePlan->episodeUuid(),
            'employee_uuid' => $employeeContext['employee_uuid'] ?? null,
            'legal_entity_uuid' => $employeeContext['legal_entity_uuid'] ?? null,
        ];

        if ($formData['kind'] === 'service_request') {
            $dbData['service_id'] = $activity->productReference;
            $activityContext = Repository::serviceRequest()->activityContext((string) $activity->uuid, (int) $carePlan->personId);
            $dbData = array_replace($dbData, $activityContext);
            $dbData['program_id'] = $activityContext['program_id'] ?? ($activity->program ?: null);

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

            return $this->persistLocalDraft($dbData, $carePlan->personId, 'service_request');
        }

        $dbData['device_id'] = $activity->productReference ?: $activity->productCodeableConcept;
        $dbData['device_code_type'] = !empty($activity->productReference) ? 'DEVICE_DEFINITION' : 'CLASSIFICATION_TYPE';
        if (str_contains(strtolower((string) $activity->kind), 'device')) {
            $dbData['quantity_system'] = $activity->quantitySystem ?: 'device_unit';
            $dbData['quantity_code'] = strtolower($activity->quantityCode ?: 'piece');
        }

        // Prefer activity program when the form left it blank.
        if (empty($dbData['program_id']) && !empty($activity->program)) {
            $dbData['program_id'] = $activity->program;
        }

        // PreQualify schema requires $.programs; Create Device Request allows optional program.
        // Mirror service_request: only prequalify when a medical program is present.
        if (!empty($dbData['program_id'])) {
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
        }

        return $this->persistLocalDraft($dbData, $carePlan->personId, 'device_request');
    }

    public function createEncounterDraft(\App\Models\MedicalEvents\Sql\Encounter $encounter, array $formData, float $qty, array $employeeContext): string
    {
        $kind = $formData['kind'] ?? 'service_request';
        $dbData = [
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $encounter->person_id,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => $this->draftStatus($kind),
            'started_at' => $kind === 'service_request' ? ($formData['started_at'] ?? now()->toDateString()) : convertToYmd($formData['started_at'] ?? now()->toDateString()),
            'ended_at' => $kind === 'service_request' ? ($formData['ended_at'] ?? now()->addMonths(1)->toDateString()) : convertToYmd($formData['ended_at'] ?? now()->addMonths(1)->toDateString()),
            'quantity' => $qty,
            'quantity_system' => $formData['quantity_system'] ?? 'SERVICE_UNIT',
            'quantity_code' => $formData['quantity_code'] ?? 'PIECE',
            'program_id' => $formData['program_id'] ?? null,
            'intent' => $formData['intent'] ?? 'order',
            'category' => $formData['category'] ?? null,
            'priority' => $formData['priority'] ?? 'routine',
            'note' => $formData['note'] ?? null,
            'patient_instruction' => $formData['patient_instruction'] ?? null,
            'reason_reference' => $formData['reason_reference'] ?? null,
            'inform_with' => $formData['inform_with'] ?? null,
            'supporting_info' => $formData['supporting_info'] ?? null,
            'based_on_uuid' => null,
            'context_uuid' => $encounter->uuid,
            'performer' => ($formData['category'] ?? null) === 'transfer_of_care'
                ? ($formData['performer'] ?? null)
                : null,
            'location_reference' => ($formData['category'] ?? null) === 'transfer_of_care'
                ? ($formData['location_reference'] ?? null)
                : null,
            'performer_type' => ($formData['category'] ?? null) === 'transfer_of_care'
                ? ($formData['performer_type'] ?? null)
                : null,
        ];

        $personUuid = Repository::serviceRequest()->person((int) $encounter->person_id)?->uuid;

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

            if ($personUuid && (!empty($dbData['program_id']) || $dbData['category'] === 'transfer_of_care')) {
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
        if ($personUuid && !empty($dbData['program_id'])) {
            $mapper = Fhir::deviceRequest();
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

        $record->loadMissing(['employee', 'employee.party']);
        if ($contextModel instanceof CarePlan) {
            $contextModel->loadMissing(['person']);
        }

        $code = $record instanceof ServiceRequestRequest ? $record->serviceId : $record->deviceId;
        $name = $record instanceof ServiceRequestRequest
            ? __('care-plan.referral_printout_type_service')
            : __('care-plan.referral_printout_type_device');
        $statusLabel = \App\Enums\Person\ServiceRequestStatus::labelFor((string) $record->status);
        $employeeName = $record->employee?->fullName ?? $record->employee?->full_name ?? '—';
        $patient = $contextModel instanceof CarePlan
            ? $contextModel->person
            : Repository::serviceRequest()->person((int) $contextModel->person_id);
        if ($record instanceof DeviceRequestRequest && $patient !== null) {
            app(DeviceRequestLifecycleService::class)->authorizeAction(legalEntity(), 'device_request:read');

            return app(DeviceRequestLifecycleService::class)->printoutHtml($patient, $requestId);
        }
        $patientName = $patient?->fullName
            ?? ($patient?->primaryName ? trim($patient->primaryName->last_name.' '.$patient->primaryName->first_name) : '—');
        $adviceText = $record instanceof ServiceRequestRequest
            ? 'Зверніться до будь-якого медичного закладу, що надає відповідні послуги за контрактом з НСЗУ.'
            : 'Зверніться до аптеки або закладу, що бере участь у програмі реімбурсації чи відпуску відповідних медичних виробів за контрактом з НСЗУ.';

        $requisition = (string) ($record->requestNumber ?: $record->uuid);
        $barcodeHtml = $this->buildCode128BarcodeHtml($requisition);

        return "
            <div style='font-family: sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; border: 1px solid #ccc; border-radius: 8px;'>
                <h2 style='text-align: center; color: #1e3a8a;'>ІНФОРМАЦІЙНА ДОВІДКА НАПРАВЛЕННЯ</h2>
                <p style='text-align: center; font-size: 14px; color: #555;'>Електронне направлення № " . e($requisition) . "</p>
                <div style='text-align: center; margin: 16px 0;'>" . $barcodeHtml . "</div>
                <hr style='border-top: 1px solid #eee; margin: 20px 0;'/>
                <table style='width: 100%; font-size: 14px; border-collapse: collapse;'>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Тип:</td><td style='padding: 8px 0;'>" . e($name) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Статус:</td><td style='padding: 8px 0;'>" . e($statusLabel) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Пацієнт:</td><td style='padding: 8px 0;'>" . e($patientName) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Код послуги/виробу:</td><td style='padding: 8px 0;'>" . e($code) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Кількість:</td><td style='padding: 8px 0;'>" . e((string) $record->quantity) . " од.</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Термін дії:</td><td style='padding: 8px 0;'>з " . e(\Carbon\Carbon::parse($record->startedAt)->format('d.m.Y')) . " по " . e(\Carbon\Carbon::parse($record->endedAt)->format('d.m.Y')) . "</td></tr>
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
     * TV 3.17.1.10.2.1 — requisition as CODE128 barcode on the printout.
     */
    public function buildCode128BarcodeHtml(string $requisition): string
    {
        $value = trim($requisition);
        if ($value === '') {
            return '';
        }

        try {
            $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();
            $binary = $generator->getBarcode($value, $generator::TYPE_CODE_128, 2, 60);

            return '<img alt="CODE128 '.e($value).'" src="data:image/png;base64,'.base64_encode($binary).'" style="max-width:100%;height:auto;" />'
                .'<div style="font-family:monospace;font-size:12px;margin-top:4px;">'.e($value).'</div>';
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Failed to render CODE128 barcode for referral printout: '.$exception->getMessage());

            return '<div style="font-family:monospace;font-size:14px;">'.e($value).'</div>';
        }
    }

    /**
     * @param  array<string, mixed>  $dbData
     */
    /**
     * Referrals and device prescriptions share this lifecycle but keep separate status
     * vocabularies, so the draft status is resolved from the kind rather than hard-coded.
     */
    private function draftStatus(string $kind): string
    {
        return $kind === 'service_request'
            ? ServiceRequestStatus::DRAFT->value
            : DeviceRequestStatus::DRAFT->value;
    }

    private function isDraft(ServiceRequestRequest|DeviceRequestRequest $requestRecord): bool
    {
        return $requestRecord instanceof ServiceRequestRequest
            ? ServiceRequestStatus::resolve((string) $requestRecord->status) === ServiceRequestStatus::DRAFT
            : DeviceRequestStatus::resolve((string) $requestRecord->status) === DeviceRequestStatus::DRAFT;
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
        $personUuid = $contextModel instanceof CarePlan ? $contextModel->person->uuid : Repository::serviceRequest()->person((int) $contextModel->person_id)?->uuid;
        $remote ??= $this->fetchRemoteReferral((string) $personUuid, (string) $requestRecord->uuid, $kind);
        $dbData = array_merge($localDbData, $this->mapRemoteReferralFields($remote, $kind));

        $dbData['employee_id'] = $localDbData['employee_id'] ?? $requestRecord->employeeId;
        $dbData['division_id'] = $localDbData['division_id'] ?? $requestRecord->divisionId;
        $dbData['based_on_uuid'] = $localDbData['based_on_uuid']
            ?? $requestRecord->basedOn?->value
            ?? $activity?->uuid;
        $dbData['context_uuid'] = $localDbData['context_uuid']
            ?? $requestRecord->context?->value
            ?? ($contextModel instanceof CarePlan
                ? $contextModel->encounter?->uuid
                : $contextModel->uuid);

        $this->persistSignedReferral($dbData, $kind, (int) $contextModel->person_id);

        return $dbData;
    }

    public function trySyncDraftFromEHealth(
        CarePlan|\App\Models\MedicalEvents\Sql\Encounter $contextModel,
        ?CarePlanActivity $activity,
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        string $kind
    ): bool {
        if (!$this->isDraft($requestRecord)) {
            return false;
        }

        $personUuid = $contextModel instanceof CarePlan ? $contextModel->person->uuid : Repository::serviceRequest()->person((int) $contextModel->person_id)?->uuid;
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

        $remoteStatus = ServiceRequestStatus::resolve((string) ($remote['status'] ?? ''));
        if ($remoteStatus === null || $remoteStatus === ServiceRequestStatus::DRAFT) {
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
            'patient_instruction' => $mapped['patient_instruction'] ?? data_get($remote, 'patient_instruction') ?? null,
            'inform_with' => $mapped['inform_with'] ?? data_get($remote, 'inform_with') ?? null,
        ];

        $supportingInfo = $this->mapRemoteSupportingInfo($remote);
        if ($supportingInfo !== []) {
            $dbData['supporting_info'] = $supportingInfo;
        }

        $reasonReference = $mapped['reason_reference'] ?? [];
        if ($reasonReference !== []) {
            $dbData['reason_reference'] = $reasonReference;
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

        if ($kind === 'service_request' && isset($mapped['_fhir']['code']['identifier']['value'])) {
            $dbData['_fhir'] = $mapped['_fhir'];
            $dbData['_fhirImport'] = true;
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
     * Local + eHealth payload for signing or syncing a referral.
     *
     * Quantity units live on the care-plan activity. Encounter drafts have no
     * activity, so they use SERVICE_UNIT / PIECE. Those keys are not columns on
     * service_request_requests / device_request_requests.
     *
     * @param  array{
     *     employee_id?: int|null,
     *     division_id?: int|null,
     *     employee_uuid?: string|null,
     *     legal_entity_uuid?: string|null
     * }|null  $employeeContext
     * @return array<string, mixed>
     */
    public function buildSignDbData(
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        ?CarePlanActivity $activity = null,
        CarePlan|\App\Models\MedicalEvents\Sql\Encounter|null $context = null,
        ?array $employeeContext = null
    ): array {
        $startedAt = $requestRecord->startedAt ?? $activity?->scheduledPeriodStart;
        $endedAt = $requestRecord->endedAt ?? $activity?->scheduledPeriodEnd;

        if ($employeeContext === null) {
            if ($context instanceof Encounter) {
                $employeeContext = $this->resolveEncounterEmployeeContext(
                    $context,
                    $requestRecord->employeeId !== null ? (int) $requestRecord->employeeId : null
                );
            } elseif ($context instanceof CarePlan) {
                $employeeContext = $this->resolveEmployeeContext(
                    $context,
                    $activity,
                    $requestRecord->employeeId !== null ? (int) $requestRecord->employeeId : null
                );
            }
        }

        $contextUuid = $requestRecord->context?->value
            ?? ($context instanceof CarePlan ? $context->encounter?->uuid : $context?->uuid);

        $dbData = [
            'uuid' => $requestRecord->uuid,
            'employee_id' => $employeeContext['employee_id'] ?? $requestRecord->employeeId,
            'division_id' => $employeeContext['division_id'] ?? $requestRecord->divisionId,
            'quantity' => $requestRecord->quantity,
            'intent' => $requestRecord->intent?->code ?? 'order',
            'category' => $requestRecord->category?->text,
            'program_id' => $requestRecord->programId,
            'priority' => $requestRecord->priority?->text ?? 'routine',
            'note' => $requestRecord->note,
            'patient_instruction' => $requestRecord instanceof ServiceRequestRequest
                ? ($requestRecord->patientInstruction ?? null)
                : null,
            'reason_reference' => $requestRecord instanceof ServiceRequestRequest
                ? ($requestRecord->reasonReference ?? null)
                : null,
            'inform_with' => $requestRecord instanceof ServiceRequestRequest
                ? ($requestRecord->informWith ?? null)
                : null,
            'supporting_info' => $requestRecord->supportingInfo,
            'started_at' => $startedAt instanceof \DateTimeInterface
                ? $startedAt->format($requestRecord instanceof ServiceRequestRequest ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d')
                : (string) $startedAt,
            'ended_at' => $endedAt instanceof \DateTimeInterface
                ? $endedAt->format($requestRecord instanceof ServiceRequestRequest ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d')
                : (string) $endedAt,
            'based_on_uuid' => $requestRecord->basedOn?->value ?? $activity?->uuid,
            'context_uuid' => $contextUuid,
        ];

        if ($requestRecord instanceof ServiceRequestRequest) {
            $form = Repository::serviceRequest()->formData($requestRecord);
            $form['inform_with'] = $dbData['inform_with'];

            return array_replace($dbData, $form);
        }

        $dbData['quantity_system'] = $activity?->quantitySystem ?: 'device_unit';
        $dbData['quantity_code'] = strtolower((string) ($activity?->quantityCode ?: 'piece'));
        if ($activity !== null && empty($activity->productReference) && !empty($activity->productCodeableConcept)) {
            $dbData['device_id'] = $requestRecord->deviceId ?: $activity->productCodeableConcept;
            $dbData['device_code_type'] = 'CLASSIFICATION_TYPE';
        } else {
            $dbData['device_id'] = $requestRecord->deviceId ?: $activity?->productReference;
            $dbData['device_code_type'] = 'DEVICE_DEFINITION';
        }

        return $dbData;
    }

    /**
     * Post a signed service/device request to eHealth and wait for the job.
     *
     * Livewire still builds the KEP body and persists locally.
     *
     * @return array<string, mixed>
     */
    public function submitSignedCreate(string $kind, string $patientUuid, string $signedContent): array
    {
        if ($kind === 'service_request') {
            return EHealth::serviceRequest()->submitSignedCreate($patientUuid, $signedContent);
        }
        $payload = [
            'signed_data' => $signedContent,
            'signed_data_encoding' => 'base64',
        ];

        $response = match ($kind) {
            'device_request' => EHealth::deviceRequest()->createSigned($patientUuid, $payload),
            default => throw new \InvalidArgumentException(__('care-plan.referral_wrong_activity_kind')),
        };

        return $this->jobResolver->resolve($response->getData());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitSignedCancel(string $kind, string $patientUuid, string $referralUuid, array $payload): array
    {
        if ($kind === 'service_request') {
            return EHealth::serviceRequest()->cancelAndResolve($patientUuid, $referralUuid, $payload);
        }
        $response = match ($kind) {
            'device_request' => EHealth::deviceRequest()->cancel($patientUuid, $referralUuid, $payload),
            default => throw new \InvalidArgumentException(__('care-plan.referral_wrong_activity_kind')),
        };

        return $this->jobResolver->resolve($response->getData());
    }

    /**
     * Recall a service request (Active → Recalled). Device requests cannot be recalled.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitSignedRecall(string $patientUuid, string $referralUuid, array $payload): array
    {
        $letter = trim((string) ($payload['explanatory_letter'] ?? ''));
        if ($letter === '') {
            throw new \InvalidArgumentException(__('care-plan.referral_recall_letter_required'));
        }

        return EHealth::serviceRequest()->recallAndResolve($patientUuid, $referralUuid, $payload);
    }

    /**
     * Persist local referral state after a signed create job finishes.
     *
     * Async job envelopes often report status=processed without embedding the
     * clinical resource (requisition / active). Prefer the entity payload when
     * present, otherwise default to active and enrich requisition from GET by id.
     *
     * @param  array<string, mixed>  $dbData
     * @param  array<string, mixed>  $finalResponse
     * @return array<string, mixed>
     */
    public function persistAfterSignedCreate(
        array $dbData,
        array $finalResponse,
        string $kind,
        int $personId
    ): array {
        $entity = $this->extractSignedCreateEntity($finalResponse);

        $entityStatus = strtolower((string) ($entity['status'] ?? ''));
        $jobEnvelopeStatuses = ['processed', 'completed', 'success', 'pending', 'processing', 'accepted', 'queued'];

        if ($entityStatus !== '' && !in_array($entityStatus, $jobEnvelopeStatuses, true)) {
            $dbData['status'] = $entity['status'];
        } else {
            $dbData['status'] = ServiceRequestStatus::ACTIVE->value;
        }

        $dbData['request_number'] = $entity['request_number']
            ?? $entity['requisition']
            ?? $dbData['request_number']
            ?? null;
        $dbData['uuid'] = $entity['id'] ?? $dbData['uuid'] ?? null;

        $this->persistSignedReferral($dbData, $kind, $personId);

        if (empty($dbData['request_number']) && !empty($dbData['uuid'])) {
            try {
                $personUuid = Repository::serviceRequest()->person($personId)?->uuid;
                if (is_string($personUuid) && $personUuid !== '') {
                    $remote = $this->fetchRemoteReferral($personUuid, (string) $dbData['uuid'], $kind);
                    $mapped = $this->mapRemoteReferralFields($remote, $kind);

                    if (!empty($mapped['request_number'])) {
                        $dbData['request_number'] = $mapped['request_number'];
                    }

                    $remoteStatus = strtolower((string) ($mapped['status'] ?? ''));
                    if ($remoteStatus !== '' && !in_array($remoteStatus, $jobEnvelopeStatuses, true)) {
                        $dbData['status'] = $mapped['status'];
                    }

                    $this->persistSignedReferral($dbData, $kind, $personId);
                }
            } catch (\Throwable $exception) {
                Log::warning('ReferralRequestLifecycle: failed to enrich requisition after signed create', [
                    'uuid' => $dbData['uuid'] ?? null,
                    'kind' => $kind,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $dbData;
    }

    /**
     * Unwrap the clinical resource from an async job response when present.
     *
     * @param  array<string, mixed>  $finalResponse
     * @return array<string, mixed>
     */
    private function extractSignedCreateEntity(array $finalResponse): array
    {
        $result = $finalResponse['result'] ?? null;

        if (is_array($result)) {
            if (array_is_list($result) && isset($result[0]) && is_array($result[0])) {
                return $result[0];
            }

            if (isset($result['data']) && is_array($result['data'])) {
                $data = $result['data'];
                if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
                    return $data[0];
                }

                return $data;
            }

            if (isset($result['id']) || isset($result['requisition']) || isset($result['request_number'])) {
                return $result;
            }
        }

        if (isset($finalResponse['data']) && is_array($finalResponse['data'])) {
            $data = $finalResponse['data'];
            if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
                return $data[0];
            }

            return $data;
        }

        return $finalResponse;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLocalSyncBaseData(
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        ?CarePlanActivity $activity,
        CarePlan|\App\Models\MedicalEvents\Sql\Encounter $contextModel
    ): array {
        if ($requestRecord instanceof ServiceRequestRequest) {
            return ['uuid' => $requestRecord->uuid, 'employee_id' => $requestRecord->employeeId, 'division_id' => $requestRecord->divisionId];
        }

        return $this->buildSignDbData($requestRecord, $activity, $contextModel);
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
        $patientUuid ??= $model?->person?->uuid;
        if (!$patientUuid || ($model !== null && $model->person?->uuid !== $patientUuid)) {
            throw new \InvalidArgumentException(__('Направлення не належить поточному пацієнту.'));
        }

        $remote = $this->fetchRemoteReferral($patientUuid, $referralUuid, 'service_request');
        if (($remote['id'] ?? null) !== $referralUuid
            || data_get($remote, 'subject.identifier.value') !== $patientUuid) {
            throw new \InvalidArgumentException(__('Направлення не належить поточному пацієнту.'));
        }

        $status = strtolower((string) ($remote['status'] ?? ''));
        $processingStatus = strtolower((string) ($remote['program_processing_status'] ?? ''));
        if ($status !== ServiceRequestStatus::ACTIVE->value
            || $processingStatus === ServiceRequestStatus::COMPLETED->value) {
            throw new \RuntimeException(__('Направлення недоступне для взяття в роботу.'));
        }

        $programId = data_get($remote, 'program.identifier.value')
            ?? $model?->programId
            ?? ($payload['program_id'] ?? null)
            ?? data_get($payload, 'program.identifier.value');

        if (is_string($programId)) {
            $programId = trim($programId) !== '' ? $programId : null;
        } else {
            $programId = $programId ?: null;
        }

        $payload = $this->buildTakeIntoWorkPayload($employee, $programId);

        $usedByLegalEntity = data_get($remote, 'used_by_legal_entity.identifier.value');
        $alreadyUsed = $processingStatus === ServiceRequestStatus::IN_PROGRESS->value
            && is_string($usedByLegalEntity) && $usedByLegalEntity !== ''
            && $usedByLegalEntity === data_get($payload, 'used_by_legal_entity.identifier.value');

        // Qualify must block process when program is present (TV 3.17.3.2 / 3.17.3.3.2).
        if (!$alreadyUsed && $programId) {
            try {
                $qualifyResponse = \App\Classes\eHealth\EHealth::serviceRequest()->qualify($referralUuid, [
                    'programs' => [$payload['program']],
                ])->getData();
                $resolvedQualify = $this->jobResolver->resolve(
                    is_array($qualifyResponse) ? $qualifyResponse : []
                );
                $this->jobResolver->assertPrequalifyValid($resolvedQualify);
            } catch (EHealthValidationException $e) {
                throw new \RuntimeException(
                    __('care-plan.referral_qualify_blocked', [
                        'reason' => $e->getTranslatedMessage() ?: $e->getFormattedMessage(),
                    ]),
                    previous: $e
                );
            } catch (\Throwable $e) {
                logger()->warning('Qualify failed (blocking): '.$e->getMessage(), [
                    'referralUuid' => $referralUuid,
                ]);

                throw new \RuntimeException(
                    __('care-plan.referral_qualify_blocked', [
                        'reason' => $e->getMessage(),
                    ]),
                    previous: $e
                );
            }
        }

        // Use Service Request (взяти в роботу)
        $response = $remote;
        if (!$alreadyUsed) {
            $response = EHealth::serviceRequest()->process($referralUuid, $payload)->getData();
            $response = EHealth::serviceRequest()->resolveMutation(is_array($response) ? $response : []);
        }

        if ($model === null && $patientUuid !== null) {
            $model = Repository::serviceRequest()->cacheExecutionResource(array_replace($remote, $this->extractSignedCreateEntity($response)), $patientUuid, $employee);
        }
        if ($model !== null) {
            Repository::serviceRequest()->applyExecutionResult($model, $response, $programId);
        }

        return $response;
    }

    /**
     * @param  string  $referralUuid
     * @param  string  $resourceUuid  UUID of encounter / procedure / diagnostic_report
     * @param  string  $resourceType  eHealth resource code: encounter|procedure|diagnostic_report
     * @param  array  $payload  Optional payload for complete
     * @return array
     */
    public function completeReferral(
        string $referralUuid,
        string $resourceUuid,
        string $resourceType = 'encounter',
        array $payload = []
    ): array {
        $allowedTypes = ['encounter', 'procedure', 'diagnostic_report'];
        if (!in_array($resourceType, $allowedTypes, true)) {
            throw new \InvalidArgumentException(__('care-plan.referral_complete_invalid_emz_type'));
        }

        if ($resourceUuid === '') {
            throw new \InvalidArgumentException(__('care-plan.referral_complete_emz_required'));
        }

        $this->assertCompletionResourceOwned($referralUuid, $resourceUuid, $resourceType);

        $payload = EHealth::serviceRequest()->buildCompleteContent($resourceUuid, $resourceType, $payload);

        $response = \App\Classes\eHealth\EHealth::serviceRequest()->complete($referralUuid, $payload)->getData();
        $response = EHealth::serviceRequest()->resolveMutation(is_array($response) ? $response : []);

        $model = Repository::serviceRequest()->findByUuid($referralUuid);
        if ($model) {
            Repository::serviceRequest()->updateClinicalStatus($model, ServiceRequestStatus::COMPLETED->value);
        }

        return $response;
    }

    /**
     * Recall Service Request (TV 3.17.1.13) — Active → Recalled (not entered-in-error).
     *
     * @param  array<string, mixed>  $payload  Must include explanatory_letter; typically also signed_data
     * @return array<string, mixed>
     */
    public function recallReferral(string $patientUuid, string $referralUuid, array $payload): array
    {
        $letter = trim((string) ($payload['explanatory_letter'] ?? ''));
        if ($letter === '') {
            throw new \InvalidArgumentException(__('care-plan.referral_recall_letter_required'));
        }

        $response = \App\Classes\eHealth\EHealth::serviceRequest()->recallAndResolve($patientUuid, $referralUuid, $payload);

        $model = Repository::serviceRequest()->findByUuid($referralUuid);
        if ($model) {
            Repository::serviceRequest()->updateClinicalStatus($model, ServiceRequestStatus::RECALLED->value);
        }

        return is_array($response) ? $response : [];
    }

    /**
     * @param  string  $referralUuid
     * @param  string  $patientId
     * @param  array  $payload  Optional payload for cancel
     * @return array
     */
    public function cancelUsage(string $referralUuid, string $patientId, array $payload = []): array
    {
        return EHealth::serviceRequest()->cancelUsage($referralUuid, $patientId, $payload)->getData();
    }

    private function buildTakeIntoWorkPayload(Employee $employee, mixed $programId): array
    {
        return EHealth::serviceRequest()->buildUseContent(Repository::serviceRequest()->executorContext($employee), $programId);
    }

    private function assertCompletionResourceOwned(string $referralUuid, string $resourceUuid, string $resourceType): void
    {
        Repository::serviceRequest()->assertCompletionResourceOwned($referralUuid, $resourceUuid, $resourceType);
    }

}
