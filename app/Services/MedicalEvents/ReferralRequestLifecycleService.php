<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\Person\DeviceRequestStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Livewire\Referral\Forms\ServiceRequestForm;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\Dictionary\ServiceSearch;
use App\Services\MedicalEvents\Concerns\ResolvesEmployeeContext;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

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
    public function createCarePlanDraft(CarePlan $carePlan, array $formData, float $qty, array $employeeContext, bool $prequalify = true): string
    {
        $activity = Repository::serviceRequest()->activityForDraft((int) $formData['activity_id'], (int) $carePlan->id, (int) $carePlan->personId);

        $resolvedKind = $activity->resolvedKind();
        if (!in_array($resolvedKind, ['service_request', 'device_request'], true)) {
            throw new InvalidArgumentException(__('care-plan.referral_wrong_activity_kind'));
        }

        $formData['kind'] = $resolvedKind;

        $existingDraft = $resolvedKind === 'service_request'
            ? Repository::serviceRequest()->findDraftByActivity((string) $activity->uuid)
            : null;
        if ($existingDraft && (int) $existingDraft->personId !== (int) $carePlan->personId) {
            throw new InvalidArgumentException(__('care-plan.document_context_unavailable'));
        }
        if ($existingDraft !== null) {
            $this->assertDraftEditable($existingDraft);
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
            'uuid' => $existingDraft?->uuid ?? (string) Uuid::uuid1(),
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
            'permitted_resources' => Repository::serviceRequest()->permittedResources((int) $carePlan->personId, $this->referralReferences($formData)),
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
            Repository::serviceRequest()->assertFhirStorageReady(beforeSubmission: true);
            $dbData['service_id'] = $activity->productReference;
            $activityContext = Repository::serviceRequest()->activityContext((string) $activity->uuid, (int) $carePlan->personId);
            $dbData = array_replace($dbData, $activityContext);
            $dbData['program_id'] = $activityContext['program_id'] ?? ($activity->program ?: null);

            $author = Repository::serviceRequest()->authorForDraft($dbData['employee_id'], $uuids['employee_uuid']);
            if ($author === null || Auth::user()->cannot('createCode', [ServiceRequestRequest::class, $author, (string) $dbData['service_id']])) {
                throw ValidationException::withMessages(['serviceId' => __('referrals.messages.category_forbidden')]);
            }
            $mapper = Fhir::serviceRequest();

            // A care-plan referral is checked even when the activity has no medical program.
            if ($prequalify) {
                $prequalifyPayload = $mapper->toPrequalifyPayload(
                    $dbData,
                    $uuids,
                    $carePlan->uuid,
                    (string) $activity->uuid
                );
                $verdicts = EHealth::serviceRequest()->prequalify($carePlan->person->uuid, $prequalifyPayload)->validate();
                $this->assertSelectedProgramVerdict($verdicts, $dbData['program_id']);
                // The native service-request contract permits an empty verdict list when no program was requested.
                // Program-dependent writes still require the matching verdict above and the shared job validation.
                if ($verdicts !== []) {
                    $this->runPrequalify($verdicts);
                }
            }

            DB::transaction(function () use ($existingDraft, $dbData, $carePlan, $uuids): void {
                if ($existingDraft !== null) {
                    $draft = ServiceRequestRequest::whereKey($existingDraft->id)->lockForUpdate()->firstOrFail();
                    $this->assertDraftEditable($draft);
                }
                $this->saveServiceRequest($dbData, $carePlan->personId, $uuids + ['care_plan_uuid' => $carePlan->uuid]);
            });

            return $dbData['uuid'];
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
        if ($prequalify && !empty($dbData['program_id'])) {
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

    public function createEncounterDraft(Encounter $encounter, array $formData, array $employeeContext, ?string $draftUuid = null): string
    {
        return $this->saveEncounterDraft($encounter, $formData, $employeeContext, $draftUuid, prequalify: false);
    }

    /** A laboratory referral can use a specimen as its focus without inventing an encounter. */
    public function createSpecimenDraft(Specimen $specimen, Person|Preperson $patient, array $formData, Employee $employee, ?string $draftUuid = null, bool $prequalify = false): ServiceRequestRequest
    {
        $repository = Repository::serviceRequest();
        $specimen = $repository->specimenForPatient($specimen->id, $patient);
        if (Auth::user()->cannot('createFromSpecimen', [ServiceRequestRequest::class, $employee, $specimen])) {
            throw ValidationException::withMessages(['executorId' => __('referrals.messages.category_forbidden')]);
        }
        if ($draftUuid !== null) {
            $this->assertDraftEditable($repository->requestForSpecimen($draftUuid, $specimen, $patient));
        }
        $data = $formData + [
            'status' => 'draft', 'intent' => 'order', 'priority' => 'routine',
            'category' => 'laboratory_procedure', 'context_uuid' => null,
            'reason_reference' => [], 'supporting_info' => [],
        ];
        $data['uuid'] = $draftUuid ?? (string) Uuid::uuid1();
        $data['status'] = 'draft';
        $data['intent'] = 'order';
        $data['context_uuid'] = null;
        if (Auth::user()->cannot('createCode', [ServiceRequestRequest::class, $employee, (string) $data['service_id']])) {
            throw ValidationException::withMessages(['serviceId' => __('referrals.messages.category_forbidden')]);
        }
        $catalog = dictionary()->services()->flattened();
        $choice = $catalog->firstWhere('id', $data['service_id']);
        $members = ($choice['type'] ?? '') === 'service_group'
            ? ServiceSearch::memberServiceIds($data['service_id'], dictionary()->services()->toArray())
            : [$data['service_id']];
        if ($choice === null || empty($choice['request_allowed']) || empty($choice['is_active']) || $members === []
            || !$catalog->whereIn('id', $members)->every(static fn (array $member): bool => ($member['category'] ?? '') === 'laboratory_procedure')) {
            throw ValidationException::withMessages(['serviceId' => __('referrals.messages.category_forbidden')]);
        }
        $data['service_type'] = $choice['type'];
        // The category and focus come from the authorized specimen, never hydrated form fields.
        $data['category'] = 'laboratory_procedure';
        $data['focus'] = [['uuid' => $specimen->uuid, 'type' => 'specimen']];
        $data['specimens'] = [['uuid' => $specimen->uuid, 'type' => 'specimen']];
        $data['employee_id'] = $employee->id;
        $data['division_id'] = $employee->divisionId;
        $uuids = ['person_uuid' => $patient->uuid, 'employee_uuid' => $employee->uuid, 'legal_entity_uuid' => $employee->legalEntity->uuid];
        if ($prequalify) {
            $this->prequalifyEncounterDraft($data, $uuids, 'service_request');
        }

        return $this->saveServiceRequest($data, $patient, $uuids);
    }

    private function referralReferences(array $formData): array
    {
        $references = [];
        foreach (['reason_reference', 'supporting_info'] as $field) {
            $items = $formData[$field] ?? [];
            $references = array_merge($references, is_string($items) ? json_decode($items, true, flags: JSON_THROW_ON_ERROR) : $items);
        }

        return $references;
    }

    public function prepareEncounterDraftForSigning(Encounter $encounter, array $formData, array $employeeContext, ?string $draftUuid = null): string
    {
        return $this->saveEncounterDraft($encounter, $formData, $employeeContext, $draftUuid, prequalify: true);
    }

    private function saveEncounterDraft(Encounter $encounter, array $formData, array $employeeContext, ?string $draftUuid, bool $prequalify): string
    {
        $kind = $formData['kind'] ?? 'service_request';
        if ($kind === 'service_request') {
            $author = Repository::serviceRequest()->authorForDraft($employeeContext['employee_id'] ?? null, $employeeContext['employee_uuid'] ?? null);
            if ($author === null || Auth::user()->cannot('create', [ServiceRequestRequest::class, $author, $formData['category'] ?? '', $encounter])) {
                throw ValidationException::withMessages(['encounterReferralForm' => __('referrals.messages.category_forbidden')]);
            }
            if (Auth::user()->cannot('createCode', [ServiceRequestRequest::class, $author, (string) ($formData['service_id'] ?? '')])) {
                throw ValidationException::withMessages(['encounterReferralForm.service_id' => __('referrals.messages.category_forbidden')]);
            }
            if (($formData['category'] ?? null) === 'hospitalization' && blank($formData['performer_type'] ?? null)) {
                throw ValidationException::withMessages(['encounterReferralForm.performer_type' => __('referrals.messages.hospitalization_speciality_required')]);
            }
            $formData = Repository::serviceRequest()->encounterActivityContext($encounter, $formData, $author->uuid);
        }
        $existingDraft = null;
        if ($draftUuid !== null) {
            if ($kind !== 'service_request') {
                throw new InvalidArgumentException(__('care-plan.referral_wrong_activity_kind'));
            }
            $existingDraft = app(MedicalRequestOwnership::class)->serviceForEncounter($draftUuid, $encounter);
            $this->assertDraftEditable($existingDraft);
        }

        $dbData = $this->buildEncounterDraftData($encounter, $formData, $employeeContext, $existingDraft?->uuid);

        $patient = Repository::serviceRequest()->patientForEncounter($encounter);
        $personUuid = $patient?->uuid;

        $uuids = [
            'person_uuid' => $personUuid,
            'encounter_uuid' => $encounter->uuid,
            'episode_uuid' => $encounter->episode?->value ?? null,
            'employee_uuid' => $employeeContext['employee_uuid'] ?? null,
            'legal_entity_uuid' => $employeeContext['legal_entity_uuid'] ?? null,
        ];

        if ($kind === 'service_request') {
            Repository::serviceRequest()->assertFhirStorageReady(beforeSubmission: true);
            $dbData['service_id'] = $formData['service_id'] ?? null;
            $dbData['service_type'] = $formData['service_type'] ?? 'service';
            if ($prequalify) {
                $this->prequalifyEncounterDraft($dbData, $uuids, $kind);
            }

            if ($existingDraft === null) {
                $this->saveServiceRequest($dbData, $patient, $uuids);
            } else {
                // Prequalification may outlive another submission; recheck before updating the locked row.
                DB::transaction(function () use ($existingDraft, $dbData, $encounter, $uuids, $patient): void {
                    $draft = ServiceRequestRequest::whereKey($existingDraft->id)->lockForUpdate()->firstOrFail();
                    $this->assertDraftEditable($draft);
                    $this->saveServiceRequest($dbData, $patient, $uuids);
                });
            }

            return $dbData['uuid'];
        }

        $dbData['device_id'] = $formData['device_id'] ?? null;
        $dbData['device_code_type'] = $formData['device_code_type'] ?? 'DEVICE_DEFINITION';
        if ($prequalify) {
            $this->prequalifyEncounterDraft($dbData, $uuids, $kind);
        }

        return $this->persistLocalDraft($dbData, (int) $encounter->person_id, 'device_request');
    }

    private function buildEncounterDraftData(Encounter $encounter, array $formData, array $employeeContext, ?string $draftUuid): array
    {
        $kind = $formData['kind'] ?? 'service_request';

        return [
            'uuid' => $draftUuid ?? (string) Uuid::uuid1(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $encounter->person_id,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => $this->draftStatus($kind),
            'started_at' => $kind === 'service_request' ? ($formData['started_at'] ?? now()->toDateString()) : convertToYmd($formData['started_at'] ?? now()->toDateString()),
            'ended_at' => $kind === 'service_request' ? ($formData['ended_at'] ?? now()->addMonths(1)->toDateString()) : convertToYmd($formData['ended_at'] ?? now()->addMonths(1)->toDateString()),
            'quantity' => (float) ($formData['quantity'] ?? 1),
            'quantity_system' => $formData['quantity_system'] ?? 'SERVICE_UNIT',
            'quantity_code' => $formData['quantity_code'] ?? 'PIECE',
            'quantity_unit' => $formData['quantity_unit'] ?? null,
            'program_id' => $formData['program_id'] ?? null,
            'intent' => $formData['intent'] ?? 'order',
            'category' => $formData['category'] ?? null,
            'priority' => $formData['priority'] ?? 'routine',
            'note' => $formData['note'] ?? null,
            'patient_instruction' => $formData['patient_instruction'] ?? null,
            'reason_reference' => $formData['reason_reference'] ?? null,
            'inform_with' => $formData['inform_with'] ?? null,
            'supporting_info' => $formData['supporting_info'] ?? null,
            'permitted_resources' => Repository::serviceRequest()->permittedResources(Repository::serviceRequest()->patientForEncounter($encounter), $this->referralReferences($formData)),
            'based_on_uuid' => $formData['based_on_uuid'] ?? null,
            'care_plan_uuid' => $formData['care_plan_uuid'] ?? null,
            'context_uuid' => $encounter->uuid,
            'performer' => $formData['performer'] ?? null,
            'location_reference' => $formData['location_reference'] ?? null,
            'performer_type' => $formData['performer_type'] ?? null,
        ];
    }

    private function prequalifyEncounterDraft(array $dbData, array $uuids, string $kind): void
    {
        $personUuid = $uuids['person_uuid'] ?? null;
        $requiresPrequalification = $kind === 'service_request' || !empty($dbData['program_id']);
        if (!$personUuid || !$requiresPrequalification) {
            return;
        }

        $mapper = $kind === 'service_request' ? Fhir::serviceRequest() : Fhir::deviceRequest();
        $api = $kind === 'service_request' ? EHealth::serviceRequest() : EHealth::deviceRequest();
        $response = $api->prequalify((string) $personUuid, $mapper->toPrequalifyPayload($dbData, $uuids, $dbData['care_plan_uuid'] ?? null, $dbData['based_on_uuid'] ?? null));
        $verdicts = $kind === 'service_request' ? $response->validate() : $response;
        if ($kind === 'service_request') {
            $this->assertSelectedProgramVerdict($verdicts, $dbData['program_id'] ?? null);
        }
        // A validated empty service-request verdict with no program has no asynchronous outcome to resolve.
        // Device requests and nonempty program verdicts retain the shared job checks.
        if ($kind !== 'service_request' || $verdicts !== []) {
            $this->runPrequalify($verdicts);
        }
    }

    private function assertSelectedProgramVerdict(array $verdicts, ?string $programId): void
    {
        if ($programId !== null && collect($verdicts)->firstWhere('program_id', $programId) === null) {
            throw ValidationException::withMessages(['programId' => __('referrals.messages.program_verdict_missing')]);
        }
    }

    private function assertDraftEditable(ServiceRequestRequest $draft): void
    {
        if (!in_array(ServiceRequestStatus::resolve((string) $draft->status), [ServiceRequestStatus::DRAFT, ServiceRequestStatus::NEW], true)
            || filled($draft->requestNumber)) {
            throw ValidationException::withMessages(['encounterReferralForm' => __('referrals.draft_not_editable')]);
        }
        if (Repository::serviceRequest()->hasPendingSubmission($draft)) {
            throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
        }
    }

    public function resendSms(string $personUuid, string $requestId, string $kind): EHealthResponse
    {
        $response = $kind === 'service_request'
            ? EHealth::serviceRequest()->resendSms($personUuid, $requestId)
            : EHealth::deviceRequest()->resendSms($personUuid, $requestId);
        if ($kind === 'service_request') {
            // The empty documented 200 acknowledgement must validate before success feedback.
            $response->validate();
        }

        return $response;
    }

    public function buildPrintoutHtml(CarePlan|Encounter|ServiceRequestRequest|DeviceRequestRequest $contextModel, string $requestId): string
    {
        $record = Repository::serviceRequest()->findByUuid($requestId)
            ?? Repository::deviceRequest()->findByUuid($requestId);

        if (!$record instanceof ServiceRequestRequest && !$record instanceof DeviceRequestRequest) {
            throw new RuntimeException('Направлення не знайдено');
        }

        $record->loadMissing(['employee', 'employee.party']);
        if ($contextModel instanceof CarePlan) {
            $contextModel->loadMissing(['person']);
        }

        $code = $record instanceof ServiceRequestRequest ? $record->serviceId : $record->deviceId;
        $name = $record instanceof ServiceRequestRequest
            ? __('care-plan.referral_printout_type_service')
            : __('care-plan.referral_printout_type_device');
        $statusLabel = ServiceRequestStatus::labelFor((string) $record->status);
        $employeeName = $record->employee?->fullName ?? ($record->employee?->full_name ?? '—');
        $patient = $contextModel instanceof CarePlan
            ? $contextModel->person
            : ($contextModel instanceof Encounter ? Repository::serviceRequest()->patientForEncounter($contextModel) : ($contextModel->person ?? $contextModel->preperson));
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
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Термін дії:</td><td style='padding: 8px 0;'>з " . e(Carbon::parse($record->startedAt)->format('d.m.Y')) . " по " . e(Carbon::parse($record->endedAt)->format('d.m.Y')) . "</td></tr>
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
            $generator = new BarcodeGeneratorPNG();
            $binary = $generator->getBarcode($value, $generator::TYPE_CODE_128, 2, 60);

            return '<img alt="CODE128 '.e($value).'" src="data:image/png;base64,'.base64_encode($binary).'" style="max-width:100%;height:auto;" />'
                .'<div style="font-family:monospace;font-size:12px;margin-top:4px;">'.e($value).'</div>';
        } catch (Throwable $exception) {
            Log::warning('Failed to render CODE128 barcode for referral printout: '.$exception->getMessage());

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
    public function saveServiceRequest(array $data, int|Person|Preperson $personId, array $uuids = []): ServiceRequestRequest
    {
        $repository = Repository::serviceRequest();
        $existing = $repository->findByUuid($data['uuid'] ?? $data['id']);
        $original = $existing?->detailsSyncedAt !== null ? $repository->getResource($existing) : [];
        if (!empty($data['based_on_uuid'])) {
            $data += $repository->activityContext((string) $data['based_on_uuid'], is_int($personId) ? $personId : $personId->id);
        }
        if ($uuids !== []) {
            $author = $repository->authorForDraft($data['employee_id'] ?? null, $uuids['employee_uuid'] ?? null);
            $authorUuid = $uuids['employee_uuid'] ?? $author?->uuid;
            $entityUuid = $uuids['legal_entity_uuid'] ?? $author?->legalEntity?->uuid;
            if ($author === null || blank($authorUuid) || blank($entityUuid)
                || $author->uuid !== $authorUuid || $author->legalEntity?->uuid !== $entityUuid) {
                throw new InvalidArgumentException(__('care-plan.referral_author_unavailable'));
            }
            $data['employee_id'] = $author->id;
            $uuids['employee_uuid'] = $authorUuid;
            $uuids['legal_entity_uuid'] = $entityUuid;
            $data['context_type'] ??= 'encounter';
        } elseif ($original !== []) {
            $data['context_type'] ??= data_get($original, 'context.identifier.type.coding.0.code');
        }
        $options = ['resource' => $original, 'timezone' => config('app.timezone'), 'carePlanUuid' => $data['care_plan_uuid'] ?? null] + $uuids;
        $resource = Fhir::serviceRequest()->toFhir($data, $options);

        // store() owns pending-submission locking; the mapper has already run here.
        $repository->store($resource, $personId, $data['employee_id'] ?? $existing?->employeeId, $data['division_id'] ?? $existing?->divisionId);

        return $repository->findByUuid($resource['id']);
    }

    private function persistLocalDraft(array $dbData, int $personId, string $kind): string
    {
        if ($kind === 'service_request') {
            $this->saveServiceRequest($dbData, $personId);
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
        CarePlan|Encounter $contextModel,
        ?CarePlanActivity $activity,
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        string $kind,
        array $localDbData,
        ?array $remote = null
    ): array {
        $personUuid = $contextModel instanceof CarePlan ? $contextModel->person->uuid : Repository::serviceRequest()->patientForEncounter($contextModel)?->uuid;
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

        $personId = $contextModel instanceof CarePlan ? $contextModel->personId : $contextModel->person_id;
        if ($kind === 'service_request') {
            Repository::serviceRequest()->importResource(Fhir::serviceRequest()->normalize($remote), $contextModel instanceof Encounter ? Repository::serviceRequest()->patientForEncounter($contextModel) : (int) $personId, $dbData['employee_id'], $dbData['division_id']);
        } else {
            $this->persistSignedReferral($dbData, $kind, (int) $personId);
        }

        return $dbData;
    }

    public function trySyncDraftFromEHealth(
        CarePlan|Encounter $contextModel,
        ?CarePlanActivity $activity,
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        string $kind
    ): bool {
        if (!$this->isDraft($requestRecord)) {
            return false;
        }

        $personUuid = $contextModel instanceof CarePlan ? $contextModel->person->uuid : Repository::serviceRequest()->patientForEncounter($contextModel)?->uuid;
        if (!$personUuid) {
            return false;
        }

        try {
            $response = $kind === 'service_request'
                ? EHealth::serviceRequest()->getById((string) $personUuid, (string) $requestRecord->uuid)
                : EHealth::deviceRequest()->getById((string) $personUuid, (string) $requestRecord->uuid);
            $remote = $kind === 'service_request' ? $response->validate() : $response->getData();
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

        $remote = $kind === 'service_request' ? $response->validate() : $response->getData();
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
            'uuid' => $remote['id'] ?? ($mapped['uuid'] ?? null),
            'status' => $remote['status'] ?? ($mapped['status'] ?? null),
            'request_number' => $remote['request_number'] ?? ($remote['requisition'] ?? ($mapped['request_number'] ?? null)),
            'started_at' => $this->normalizeRemoteDate(
                data_get($remote, 'occurrence_period.start')
                    ?? (data_get($remote, 'occurrencePeriod.start')
                    ?? ($mapped['started_at'] ?? null))
            ),
            'ended_at' => $this->normalizeRemoteDate(
                data_get($remote, 'occurrence_period.end')
                    ?? (data_get($remote, 'occurrencePeriod.end')
                    ?? ($mapped['ended_at'] ?? null))
            ),
            'quantity' => data_get($remote, 'quantity.value') ?? ($mapped['quantity'] ?? null),
            'program_id' => data_get($remote, 'program.identifier.value') ?? ($mapped['program_id'] ?? null),
            'intent' => $remote['intent'] ?? ($mapped['intent'] ?? null),
            'category' => data_get($remote, 'category.coding.0.code')
                ?? (data_get($remote, 'category.0.coding.0.code')
                ?? ($mapped['category'] ?? null)),
            'priority' => $remote['priority'] ?? ($mapped['priority'] ?? null),
            'note' => data_get($remote, 'note.0.text') ?? ((is_string($remote['note'] ?? null) ? $remote['note'] : null) ?? ($mapped['note'] ?? null)),
            'patient_instruction' => $mapped['patient_instruction'] ?? (data_get($remote, 'patient_instruction') ?? null),
            'inform_with' => $mapped['inform_with'] ?? (data_get($remote, 'inform_with') ?? null),
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
                ?? (data_get($remote, 'code.coding.0.code')
                ?? ($mapped['service_id'] ?? null));
        } else {
            $dbData['device_id'] = data_get($remote, 'code_reference.identifier.value')
                ?? (data_get($remote, 'code.coding.0.code')
                ?? ($mapped['device_id'] ?? null));
        }

        return array_filter($dbData, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<array{type?: string, uuid?: string}>
     */
    private function mapRemoteSupportingInfo(array $remote): array
    {
        $items = $remote['supporting_info'] ?? ($remote['supportingInfo'] ?? []);
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
        CarePlan|Encounter|null $context = null,
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
            'started_at' => $startedAt instanceof DateTimeInterface
                ? $startedAt->format($requestRecord instanceof ServiceRequestRequest ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d')
                : (string) $startedAt,
            'ended_at' => $endedAt instanceof DateTimeInterface
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
     * Post a signed request; service receipts are returned pending without polling.
     *
     * Livewire still builds the KEP body and persists locally.
     *
     * @return array<string, mixed>
     */
    public function submitSignedCreate(string $kind, string $patientUuid, string $signedContent): array
    {
        if ($kind === 'service_request') {
            return $this->submitServiceRequestOperation(null, ['status' => 'active'], fn () => EHealth::serviceRequest()->createSigned($patientUuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']), 'service_request:write', []);
        }
        $payload = [
            'signed_data' => $signedContent,
            'signed_data_encoding' => 'base64',
        ];

        $response = match ($kind) {
            'device_request' => EHealth::deviceRequest()->createSigned($patientUuid, $payload),
            default => throw new InvalidArgumentException(__('care-plan.referral_wrong_activity_kind')),
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
            return $this->submitServiceRequestOperation($this->signedServiceRequestRecord($patientUuid, $referralUuid), ['status' => ServiceRequestStatus::ENTERED_IN_ERROR->value], fn () => EHealth::serviceRequest()->cancel($patientUuid, $referralUuid, ServiceRequestForm::buildSignedEnvelope($payload)), 'service_request:cancel', []);
        }
        $response = match ($kind) {
            'device_request' => EHealth::deviceRequest()->cancel($patientUuid, $referralUuid, $payload),
            default => throw new InvalidArgumentException(__('care-plan.referral_wrong_activity_kind')),
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
            throw new InvalidArgumentException(__('care-plan.referral_recall_letter_required'));
        }

        return $this->submitServiceRequestOperation($this->signedServiceRequestRecord($patientUuid, $referralUuid), ['status' => 'recalled'], fn () => EHealth::serviceRequest()->recall($patientUuid, $referralUuid, ServiceRequestForm::buildSignedEnvelope($payload)), 'service_request:recall', []);
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
        int|Person|Preperson $personId
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
            ?? ($entity['requisition']
            ?? ($dbData['request_number']
            ?? null));
        $dbData['uuid'] = $entity['id'] ?? ($dbData['uuid'] ?? null);

        $this->persistSignedReferral($dbData, $kind, $personId);

        if (empty($dbData['request_number']) && !empty($dbData['uuid'])) {
            try {
                $personUuid = is_int($personId) ? Repository::serviceRequest()->person($personId)?->uuid : $personId->uuid;
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
            } catch (Throwable $exception) {
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
        CarePlan|Encounter $contextModel
    ): array {
        if ($requestRecord instanceof ServiceRequestRequest) {
            return ['uuid' => $requestRecord->uuid, 'employee_id' => $requestRecord->employeeId, 'division_id' => $requestRecord->divisionId];
        }

        return $this->buildSignDbData($requestRecord, $activity, $contextModel);
    }

    /**
     * @param  array<string, mixed>  $dbData
     */
    private function persistSignedReferral(array $dbData, string $kind, int|Person|Preperson $personId): void
    {
        if ($kind === 'service_request') {
            $this->saveServiceRequest($dbData, $personId);
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
        if (Auth::user()?->cannot('use', [ServiceRequestRequest::class, $employee])) {
            throw new InvalidArgumentException(__('referrals.messages.executor_unavailable'));
        }
        $model = Repository::serviceRequest()->findByUuid($referralUuid);
        $patientUuid ??= $model === null ? null : Repository::serviceRequest()->patientForRequest($model)?->uuid;
        if (!$patientUuid || ($model !== null && Repository::serviceRequest()->patientForRequest($model)?->uuid !== $patientUuid)) {
            throw new InvalidArgumentException(__('Направлення не належить поточному пацієнту.'));
        }

        // The patient client projects id to uuid; restore the native shape only after its response validator succeeds.
        $remote = Arr::toSnakeCase((new ServiceRequestMapper())->normalize($this->fetchRemoteReferral($patientUuid, $referralUuid, 'service_request')));
        if (($remote['id'] ?? null) !== $referralUuid
            || data_get($remote, 'subject.identifier.value') !== $patientUuid) {
            throw new InvalidArgumentException(__('Направлення не належить поточному пацієнту.'));
        }

        if (Auth::user()?->cannot('use', [ServiceRequestRequest::class, $employee, data_get($remote, 'category.coding.0.code')])) {
            throw new InvalidArgumentException(__('referrals.messages.executor_unavailable'));
        }
        $owner = data_get($remote, 'used_by_legal_entity.identifier.value');
        if (filled($owner) && $owner !== legalEntity()->uuid
            && Auth::user()?->cannot('takeIntoWork', [ServiceRequestRequest::class, $remote])) {
            throw new InvalidArgumentException(__('referrals.messages.action_unavailable'));
        }

        $status = strtolower((string) ($remote['status'] ?? ''));
        $processingStatus = strtolower((string) ($remote['program_processing_status'] ?? ''));
        if ($status !== ServiceRequestStatus::ACTIVE->value
            || $processingStatus === ServiceRequestStatus::COMPLETED->value) {
            throw new RuntimeException(__('Направлення недоступне для взяття в роботу.'));
        }

        // An explicit executor choice takes precedence over the issuer's program.
        $programId = array_key_exists('program_id', $payload)
            ? $payload['program_id']
            : (data_get($payload, 'program.identifier.value') ?? (data_get($remote, 'program.identifier.value') ?? $model?->programId));

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
                $qualifyResponse = EHealth::serviceRequest()->qualify($referralUuid, [
                    'programs' => [$payload['program']],
                ])->validate();
                $selectedResult = collect($qualifyResponse)->firstWhere('program_id', $programId);
                if ($selectedResult === null) {
                    throw new InvalidArgumentException(__('referrals.messages.program_verdict_missing'));
                }
                $this->jobResolver->assertPrequalifyValid($qualifyResponse);
            } catch (EHealthValidationException $e) {
                throw new RuntimeException(
                    __('care-plan.referral_qualify_blocked', [
                        'reason' => $e->getTranslatedMessage() ?: $e->getFormattedMessage(),
                    ]),
                    previous: $e
                );
            } catch (Throwable $e) {
                logger()->warning('Qualify failed (blocking): '.$e->getMessage(), [
                    'referralUuid' => $referralUuid,
                ]);

                throw new RuntimeException(
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
            if ($model === null || $model->detailsSyncedAt === null) {
                $model = Repository::serviceRequest()->cacheExecutionResource($remote, $patientUuid, $employee) ?? $model;
                if ($model !== null && $model->detailsSyncedAt === null) {
                    throw new InvalidArgumentException(__('care-plan.referral_full_details_required'));
                }
            }
            $response = $this->submitServiceRequestOperation($model, ['status' => $remote['status'], 'programProcessingStatus' => 'in_progress', ...Arr::toCamelCase($payload)], fn () => EHealth::serviceRequest()->process($referralUuid, $payload), 'service_request:use', ['execution' => true, 'programId' => $programId]);
            if ($response['submissionPending'] ?? false) {
                return $response;
            }
        }

        if ($model === null && $patientUuid !== null) {
            $model = Repository::serviceRequest()->cacheExecutionResource(array_replace($remote, $this->extractSignedCreateEntity($response)), $patientUuid, $employee);
        }
        if ($model !== null && $alreadyUsed) {
            Repository::serviceRequest()->applyExecutionResult($model, array_replace(Arr::toCamelCase($payload), $response), $programId);
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
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            throw ValidationException::withMessages(['referral' => __('referrals.messages.complete_forbidden')]);
        }
        $allowedTypes = ['encounter', 'procedure', 'diagnostic_report'];
        if (!in_array($resourceType, $allowedTypes, true)) {
            throw new InvalidArgumentException(__('care-plan.referral_complete_invalid_emz_type'));
        }

        if ($resourceUuid === '') {
            throw new InvalidArgumentException(__('care-plan.referral_complete_emz_required'));
        }

        $person = Repository::serviceRequest()->assertCompletionResourceOwned($referralUuid, $resourceUuid, $resourceType);

        $acknowledged = ($payload['acknowledge_remaining_quantity'] ?? false) === true;
        $payload = ServiceRequestForm::buildCompleteContent($resourceUuid, $resourceType, $payload);

        $model = Repository::serviceRequest()->findByUuid($referralUuid);
        if ($model !== null && Repository::serviceRequest()->hasPendingSubmission($model)) {
            throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
        }
        // Re-read execution ownership and remaining quantity immediately before completion.
        $detail = EHealth::serviceRequest()->getById($person->uuid, $referralUuid, preserveApiKeys: true)->validate();
        $detail = (new ServiceRequestMapper())->normalize($detail[0] ?? $detail);
        if (($detail['id'] ?? null) !== $referralUuid) {
            throw new InvalidArgumentException(__('care-plan.referral_full_details_required'));
        }
        if (Auth::user()->cannot('completeReferral', [ServiceRequestRequest::class, Arr::toSnakeCase($detail)])) {
            throw ValidationException::withMessages(['referral' => __('referrals.messages.complete_forbidden')]);
        }
        $localStatus = $model?->status ?? $detail['status'];
        $model = Repository::serviceRequest()->importResource($detail, $person, $model?->employeeId, $model?->divisionId);
        // Full detail owns the clinical status; omitted execution metadata does not undo an acknowledged local use.
        if ($detail['status'] === 'active' && !isset($detail['programProcessingStatus'])) {
            Repository::serviceRequest()->saveStatus($model, $localStatus);
        }
        Repository::serviceRequest()->assertCompletionResourceOwned($referralUuid, $resourceUuid, $resourceType);
        if (Auth::user()->cannot('completeRecord', [ServiceRequestRequest::class, $model])) {
            throw ValidationException::withMessages(['referral' => __('referrals.messages.complete_forbidden')]);
        }
        if ((float) ($model->remainingQuantity?->value ?? 0) > 0 && !$acknowledged) {
            throw ValidationException::withMessages(['acknowledgeRemainingQuantity' => __('referrals.remaining_quantity_warning')]);
        }
        $response = $this->submitServiceRequestOperation($model, ['status' => 'completed', 'programProcessingStatus' => 'completed', 'completedWith' => Arr::toCamelCase($payload['completed_with'])], fn () => EHealth::serviceRequest()->complete($referralUuid, $payload), 'service_request:complete', ['localStatus' => 'completed']);
        if ($response['submissionPending'] ?? false) {
            return $response;
        }

        return $response;
    }

    public function checkCompletion(ServiceRequestRequest $record, string $requiredScope = 'service_request:complete'): array
    {
        $link = Repository::serviceRequest()->latestCompletion($record, $requiredScope);
        if ($link === null) {
            return ['submissionPending' => false, 'status' => $record->status];
        }
        if ($link->status === JobStatus::PENDING->value) {
            if (!$link->href) {
                return ['submissionPending' => true];
            }
            $job = EHealth::job()->getDetailsByHref($link->href)->validate();
            if (in_array(strtolower($job['status']), ['pending', 'processing', 'accepted', 'queued'], true)) {
                return ['submissionPending' => true];
            }
            RemoteEHealthLinksProcessing::finishSubmission($link, $job, Auth::user(), legalEntity(), Session::get(config('ehealth.api.oauth.bearer_token')));
            $link->refresh()->load('job');
        }
        if ($link->status === JobStatus::FAILED->value) {
            $job = $link->job->response_data ?? [];
            $job['error'] ??= $link->error ?? ['message' => __('referrals.messages.operation_failed')];

            throw EHealth::job()->jobError($job);
        }

        return ['submissionPending' => false, 'status' => $record->fresh()->status];
    }

    /**
     * Recall Service Request (TV 3.17.1.13) — Active → Recalled (not entered-in-error).
     *
     * @param  array<string, mixed>  $payload  Must include explanatory_letter; typically also signed_data
     * @return array<string, mixed>
     */
    public function recallReferral(string $patientUuid, string $referralUuid, array $payload): array
    {
        return $this->submitSignedRecall($patientUuid, $referralUuid, $payload);
    }

    /**
     * @param  string  $referralUuid
     * @param  string  $patientId
     * @param  array  $payload  Optional payload for cancel
     * @return array
     */
    public function cancelUsage(string $referralUuid, string $patientId, array $payload = []): array
    {
        if (Auth::user()->cannot('use', ServiceRequestRequest::class)) {
            throw ValidationException::withMessages(['referral' => __('referrals.messages.use_forbidden')]);
        }
        $snapshot = EHealth::serviceRequest()->getById($patientId, $referralUuid, preserveApiKeys: true)->validate();
        $snapshot = Arr::toSnakeCase($snapshot[0] ?? $snapshot);
        if (($snapshot['id'] ?? null) !== $referralUuid
            || data_get($snapshot, 'subject.identifier.value') !== $patientId
            || Auth::user()->cannot('manageUsage', [ServiceRequestRequest::class, $snapshot])) {
            throw ValidationException::withMessages(['referral' => __('referrals.messages.use_forbidden')]);
        }

        return $this->submitServiceRequestOperation(
            $this->signedServiceRequestRecord($patientId, $referralUuid),
            ['status' => 'active', 'programProcessingStatus' => 'new'],
            fn () => EHealth::serviceRequest()->cancelUsage($referralUuid, $patientId),
            'service_request:use',
            ['localStatus' => 'active', 'releaseExplanation' => $payload['explanatory_letter'] ?? null]
        );
    }

    private function buildTakeIntoWorkPayload(Employee $employee, mixed $programId): array
    {
        return ServiceRequestForm::buildUseContent(Repository::serviceRequest()->executorContext($employee), $programId);
    }

    private function signedServiceRequestRecord(string $patientUuid, string $referralUuid): ?ServiceRequestRequest
    {
        $repository = Repository::serviceRequest();
        $record = $repository->findByUuid($referralUuid);
        if ($record === null) {
            return null;
        }
        $person = $repository->patientForRequest($record);
        if ($person?->uuid !== $patientUuid) {
            throw new InvalidArgumentException('The Service Request belongs to another patient.');
        }
        if ($repository->hasPendingSubmission($record)) {
            throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
        }
        if ($record->detailsSyncedAt === null) {
            $detail = EHealth::serviceRequest()->getById($patientUuid, $referralUuid)->validate();
            $detail = (new ServiceRequestMapper())->normalize($detail[0] ?? $detail);
            if (($detail['id'] ?? null) !== $referralUuid) {
                throw new InvalidArgumentException(__('care-plan.referral_full_details_required'));
            }
            $record = $repository->importResource($detail, $person, $record->employeeId, $record->divisionId);
        }

        return $record;
    }

    private function resolveMutation(array $response): array
    {
        if ($response === [] || in_array(strtolower((string) ($response['status'] ?? '')), ['failed', 'rejected', 'error'], true) || !empty($response['error'])) {
            throw new EHealthValidationException(['error' => ['message' => __('errors.ehealth.messages.request_error')]]);
        }

        return $response;
    }

    private function submitServiceRequestOperation(?ServiceRequestRequest $record, array $changes, callable $send, string $requiredScope, array $operationContext): array
    {
        if ($record !== null) {
            $result = RemoteEHealthLinksProcessing::submitOperation($record, $changes, $send, $requiredScope, $operationContext);

            return $result ?? ['submissionPending' => true];
        }
        // A remote-only API consumer receives the native receipt; there is no local resource to advance.
        $response = $send();
        $data = $response->validate();
        if (!empty($data['error']) || in_array(strtolower((string) ($data['status'] ?? '')), ['failed', 'rejected', 'error'], true)) {
            return $this->resolveMutation($data);
        }
        $jobLink = collect($data['links'] ?? [])->contains(static fn (array $link): bool => str_contains(strtolower((string) ($link['href'] ?? '')), '/jobs/'));
        if ($response->getStatusCode() === 202 || $jobLink || isset($data['job_id']) || in_array(strtolower((string) ($data['status'] ?? '')), ['pending', 'processing', 'accepted', 'queued'], true)) {
            return ['submissionPending' => true] + $data;
        }

        return $this->resolveMutation($data);
    }
}
