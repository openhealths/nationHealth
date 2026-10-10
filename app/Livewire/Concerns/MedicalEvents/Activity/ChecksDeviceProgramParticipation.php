<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Activity;

use App\Classes\eHealth\EHealth;
use App\Dto\MedicalEvents\DeviceActivityReadinessAssessment;
use App\Enums\JobStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

trait ChecksDeviceProgramParticipation
{
    protected function resolveParticipatingProgramIds(LegalEntity $legalEntity, bool $attemptRemoteSync = true): array
    {
        $repository = Repository::contract();
        $local = $repository->participatingProgramIds($legalEntity);
        if (!$attemptRemoteSync || ($local !== [] && $legalEntity->getEntityStatus(LegalEntity::ENTITY_CONTRACT) === JobStatus::COMPLETED)) {
            return $local;
        }
        try {
            $contracts = EHealth::contract()->getValidatedForLegalEntity($legalEntity->uuid);
            $repository->saveParticipationSnapshot($contracts, $legalEntity);
        } catch (Throwable $exception) {
            Log::warning('CarePlan: contract participation sync failed', [
                'legal_entity_uuid' => $legalEntity->uuid,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        return $repository->participatingProgramIds($legalEntity);
    }

    protected function assessDeviceActivity(CarePlan $carePlan, CarePlanActivity $activity, LegalEntity $legalEntity): DeviceActivityReadinessAssessment
    {
        $blockingIssues = [];
        $warnings = [];

        if (empty($activity->program)) {
            // Device requests without a medical program are valid (Encounter Package based_on).
            $deviceId = (string) ($activity->productReference ?: '');
            if ($deviceId === '' && empty($activity->productCodeableConcept)) {
                $blockingIssues[] = __('care-plan.device_product_reselect_required');
            }

            return new DeviceActivityReadinessAssessment($blockingIssues, $warnings);
        }

        $programId = (string) $activity->program;
        $programName = $this->resolveProgramName($programId);

        try {
            $programPayload = dictionary()->medicalPrograms()->firstWhere('id', $programId);
        } catch (Throwable) {
            $programPayload = null;
        }

        if (is_array($programPayload)) {
            $tosBlock = $this->providingConditionsBlockReason($carePlan, $programPayload);
            if ($tosBlock !== null) {
                $blockingIssues[] = $tosBlock;
            }
        }

        $participatingProgramIds = $this->resolveParticipatingProgramIds($legalEntity);

        if ($participatingProgramIds === []) {
            $warnings[] = __('care-plan.device_program_participation_unknown', [
                'program' => $programName,
                'program_id' => $programId,
            ]);
        } elseif (!in_array($programId, $participatingProgramIds, true)) {
            $blockingIssues[] = __('care-plan.device_program_not_participant', [
                'program' => $programName,
                'program_id' => $programId,
            ]);
        }

        $deviceId = (string) ($activity->productReference ?: '');
        if ($deviceId === '' && empty($activity->productCodeableConcept)) {
            $blockingIssues[] = __('care-plan.device_product_reselect_required');
        } elseif ($deviceId !== '') {
            $catalogResult = EHealth::deviceDefinition()->lookupDeviceInProgramCatalog($programId, $deviceId);
            switch ($catalogResult) {
                case 'missing':
                    $blockingIssues[] = __('care-plan.device_not_in_program_catalog', [
                        'device_id' => $deviceId, 'program' => $programName, 'program_id' => $programId,
                    ]);
                    break;
                case 'inactive':
                    $blockingIssues[] = __('care-plan.device_definition_not_active', [
                        'device_id' => $deviceId, 'program' => $programName, 'program_id' => $programId,
                    ]);
                    break;
                case null:
                    $warnings[] = __('care-plan.device_catalog_lookup_failed', [
                        'device_id' => $deviceId, 'program_id' => $programId,
                    ]);
                    break;
            }
        }

        if ($blockingIssues === [] && $participatingProgramIds === []) {
            $warnings[] = __('care-plan.device_program_participation_ehealth_hint', [
                'legal_entity_uuid' => $legalEntity->uuid,
                'program_id' => $programId,
            ]);
        }

        return new DeviceActivityReadinessAssessment($blockingIssues, $warnings);
    }

    private function resolveProgramName(string $programId): string
    {
        try {
            $program = dictionary()->medicalPrograms()->firstWhere('id', $programId);

            return is_array($program) ? (string) ($program['name'] ?? $programId) : $programId;
        } catch (Throwable) {
            return $programId;
        }
    }
}
