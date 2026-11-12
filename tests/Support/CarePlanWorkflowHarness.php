<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Forms\CarePlanActivityForm;
use App\Livewire\CarePlan\Forms\CarePlanEPrescriptionForm;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\CarePlan\Forms\CarePlanReferralForm;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\LegalEntity;
use App\Models\User;

/** Exposes component instance methods for the existing contract and workflow assertions. */
class CarePlanWorkflowHarness extends CarePlanComponent
{
    public function __construct()
    {
        $this->form = new CarePlanForm($this, 'form');
        $this->activityForm = new CarePlanActivityForm($this, 'activityForm');
        $this->ePrescriptionForm = new CarePlanEPrescriptionForm($this, 'ePrescriptionForm');
        $this->referralForm = new CarePlanReferralForm($this, 'referralForm');
    }

    public function setCarePlan(CarePlan $carePlan): void
    {
        $this->carePlan = $carePlan;
        $this->carePlanId = $carePlan->id;
    }

    protected function renderCarePlan()
    {
        return '';
    }

    public function buildCreateCarePlanContent(array $resource): array
    {
        return parent::buildCreateCarePlanContent($resource);
    }

    public function buildCancelCarePlanContent(array $snapshot, array $statusReason): array
    {
        return parent::buildCancelCarePlanContent($snapshot, $statusReason);
    }

    public function buildCreateCarePlanActivityContent(array $resource): array
    {
        return parent::buildCreateCarePlanActivityContent($resource);
    }

    public function buildCancelCarePlanActivityContent(array $snapshot, array $reason): array
    {
        return parent::buildCancelCarePlanActivityContent($snapshot, $reason);
    }

    public function buildCompleteCarePlanActivityContent(array $reason, array $outcomes): array
    {
        return parent::buildCompleteCarePlanActivityContent($reason, $outcomes);
    }

    public function carePlanResourceFromFields(array $data, mixed ...$context): array
    {
        return parent::carePlanResourceFromFields($data, ...$context);
    }

    public function carePlanFieldsFromResource(array $data, mixed ...$context): array
    {
        return parent::carePlanFieldsFromResource($data, ...$context);
    }

    public function carePlanActivityResourceFromFields(array $data, mixed ...$context): array
    {
        return parent::carePlanActivityResourceFromFields($data, ...$context);
    }

    public function carePlanActivityFieldsFromResource(array $data, mixed ...$context): array
    {
        return parent::carePlanActivityFieldsFromResource($data, ...$context);
    }

    public function buildCarePlanApprovalPayload(
        CarePlan $carePlan,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
    ): array {
        return parent::buildCarePlanApprovalPayload($carePlan, $employeeUuid, $accessLevel, $authorizeWith, $legalEntity);
    }

    public function createCarePlanApproval(
        CarePlan $carePlan,
        string $patientUuid,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
        ?User $user = null,
        ?string $bearerToken = null,
    ): array {
        return parent::createCarePlanApproval($carePlan, $patientUuid, $employeeUuid, $accessLevel, $authorizeWith, $legalEntity, $user, $bearerToken);
    }

    public function carePlanApprovalResultForLink(int $linkId): array
    {
        return parent::carePlanApprovalResultForLink($linkId);
    }

    public function pendingCarePlanApprovalConfirmation(string $patientUuid, string $approvalId, ?string $authorizeWith = null): array
    {
        return parent::pendingCarePlanApprovalConfirmation($patientUuid, $approvalId, $authorizeWith);
    }

    public function verifyCarePlanApproval(string $patientUuid, string $approvalId, ?int $code, ?string $bearerToken = null): EHealthResponse
    {
        return parent::verifyCarePlanApproval($patientUuid, $approvalId, $code, $bearerToken);
    }

    public function confirmCarePlanApprovalWithoutOtp(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        return parent::confirmCarePlanApprovalWithoutOtp($patientUuid, $approvalId, $bearerToken);
    }

    public function resendCarePlanApprovalSms(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        return parent::resendCarePlanApprovalSms($patientUuid, $approvalId, $bearerToken);
    }

    public function syncCarePlanApprovals(CarePlan $carePlan): void
    {
        parent::syncCarePlanApprovals($carePlan);
    }

    public function requestCarePlanApprovalSmsResend(CarePlan $plan, string $approvalId, LegalEntity $legalEntity, User $user, string $bearerToken): array
    {
        return parent::requestCarePlanApprovalSmsResend($plan, $approvalId, $legalEntity, $user, $bearerToken);
    }

    public function resolveCarePlanApprovalJob(int $pollingLinkId): array
    {
        return parent::resolveCarePlanApprovalJob($pollingLinkId);
    }

    public function planCancelBlockReason(CarePlan $carePlan): ?string
    {
        return parent::planCancelBlockReason($carePlan);
    }

    public function planCompleteBlockReason(CarePlan $carePlan): ?string
    {
        return parent::planCompleteBlockReason($carePlan);
    }

    public function activityStatusChangeBlockReason(CarePlanActivity $activity, string $action): ?string
    {
        return parent::activityStatusChangeBlockReason($activity, $action);
    }

    public function saveCarePlanDraft(array $fields, int $personId, int $legalEntityId, ?int $localId = null): CarePlan
    {
        return parent::saveCarePlanDraft($fields, $personId, $legalEntityId, $localId);
    }

    public function saveActivityDraft(array $fields, CarePlan $plan, ?int $localId = null): CarePlanActivity
    {
        return parent::saveActivityDraft($fields, $plan, $localId);
    }

    public function createCarePlanDraft(array $fields): CarePlan
    {
        $fields = Arr::toCamelCase($fields);

        return $this->saveCarePlanDraft($fields, (int) $fields['personId'], (int) $fields['legalEntityId']);
    }

    public function createActivityDraft(array $fields): CarePlanActivity
    {
        $fields = Arr::toCamelCase($fields);

        return $this->saveActivityDraft($fields, CarePlan::findOrFail($fields['carePlanId']));
    }

    public function updateCarePlanDraft(CarePlan $plan, array $fields): void
    {
        $this->saveCarePlanDraft($fields, $plan->personId, $plan->legalEntityId, $plan->id);
    }

    public function resolveEpisodeLabels(array $references, int $personId): array
    {
        return parent::resolveEpisodeLabels($references, $personId);
    }

    public function buildDeviceQuantityOptions(array $packagingCounts, int $maxDailyCount, string $start, string $end): array
    {
        return parent::buildDeviceQuantityOptions($packagingCounts, $maxDailyCount, $start, $end);
    }
}
