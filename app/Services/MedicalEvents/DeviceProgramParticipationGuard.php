<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Enums\Contract\ContractStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Contracts\Contract;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DeviceProgramParticipationGuard
{
    private const ACTIVE_CONTRACT_STATUSES = [
        ContractStatus::ACTIVE->value,
    ];

    /**
     * @return list<string>
     */
    public function resolveParticipatingProgramIds(LegalEntity $legalEntity, bool $attemptRemoteSync = true): array
    {
        $local = $this->loadProgramIdsFromDatabase($legalEntity);

        if ($local !== [] || !$attemptRemoteSync) {
            return $local;
        }

        try {
            $response = EHealth::contract()->getMany([
                'contractor_legal_entity_id' => $legalEntity->uuid,
            ]);

            foreach ($response->getData() as $item) {
                if (is_array($item)) {
                    Repository::contract()->saveFromEHealth($item);
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('DeviceProgramParticipationGuard: contract sync failed', [
                'legal_entity_uuid' => $legalEntity->uuid,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        return $this->loadProgramIdsFromDatabase($legalEntity);
    }

    public function assess(CarePlan $carePlan, CarePlanActivity $activity, LegalEntity $legalEntity): DeviceActivityReadinessAssessment
    {
        $blockingIssues = [];
        $warnings = [];

        if (empty($activity->program)) {
            $blockingIssues[] = __('care-plan.device_program_required_before_sign');

            return new DeviceActivityReadinessAssessment($blockingIssues, $warnings);
        }

        $programId = (string) $activity->program;
        $programName = $this->resolveProgramName($programId);
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

        $deviceId = (string) ($activity->product_reference ?: '');
        if ($deviceId === '' && empty($activity->product_codeable_concept)) {
            $blockingIssues[] = __('care-plan.device_product_reselect_required');
        } elseif ($deviceId !== '') {
            $catalogResult = $this->lookupDeviceInProgramCatalog($programId, $deviceId);
            if ($catalogResult === 'missing') {
                $blockingIssues[] = __('care-plan.device_not_in_program_catalog', [
                    'device_id' => $deviceId,
                    'program' => $programName,
                    'program_id' => $programId,
                ]);
            } elseif ($catalogResult === 'inactive') {
                $blockingIssues[] = __('care-plan.device_definition_not_active', [
                    'device_id' => $deviceId,
                    'program' => $programName,
                    'program_id' => $programId,
                ]);
            } elseif ($catalogResult === null) {
                $warnings[] = __('care-plan.device_catalog_lookup_failed', [
                    'device_id' => $deviceId,
                    'program_id' => $programId,
                ]);
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

    /**
     * @param  Collection<int, array<string, mixed>>  $programs
     * @param  list<string>  $participatingProgramIds
     * @return Collection<int, array<string, mixed>>
     */
    public function filterProgramsForParticipation(Collection $programs, array $participatingProgramIds): Collection
    {
        if ($participatingProgramIds === []) {
            return $programs;
        }

        return $programs->filter(
            fn (array $program): bool => in_array((string) ($program['id'] ?? ''), $participatingProgramIds, true)
        );
    }

    public function isDeviceInProgramCatalog(string $programId, string $deviceDefinitionId): bool
    {
        return $this->lookupDeviceInProgramCatalog($programId, $deviceDefinitionId) === 'active';
    }

    /**
     * @return 'active'|'inactive'|'missing'|null active/inactive/missing, or null when lookup failed
     */
    private function lookupDeviceInProgramCatalog(string $programId, string $deviceDefinitionId): ?string
    {
        try {
            $response = EHealth::deviceDefinition()->getMany([
                'medical_program_id' => $programId,
                'page_size' => 300,
            ]);
            $devices = $response->getData();

            if (!is_array($devices)) {
                return null;
            }

            foreach ($devices as $device) {
                if (!is_array($device)) {
                    continue;
                }

                $id = (string) ($device['id'] ?? $device['uuid'] ?? '');
                if ($id === $deviceDefinitionId) {
                    $isActive = $device['is_active'] ?? $device['isActive'] ?? true;

                    return $isActive ? 'active' : 'inactive';
                }
            }

            return 'missing';
        } catch (\Throwable $exception) {
            Log::warning('DeviceProgramParticipationGuard: device catalog lookup failed', [
                'program_id' => $programId,
                'device_id' => $deviceDefinitionId,
                'message' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function loadProgramIdsFromDatabase(LegalEntity $legalEntity): array
    {
        $contracts = Contract::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->get();

        return $this->extractProgramIdsFromContracts($contracts);
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @return list<string>
     */
    private function extractProgramIdsFromContracts(Collection $contracts): array
    {
        $ids = [];

        foreach ($contracts as $contract) {
            $status = strtoupper((string) ($contract->status?->value ?? $contract->status ?? ''));
            if (!in_array($status, self::ACTIVE_CONTRACT_STATUSES, true)) {
                continue;
            }

            foreach ($contract->medical_programs ?? [] as $program) {
                if (is_string($program) && $program !== '') {
                    $ids[] = $program;

                    continue;
                }

                if (is_array($program)) {
                    $programId = $program['id'] ?? $program['medical_program_id'] ?? null;
                    if (is_string($programId) && $programId !== '') {
                        $ids[] = $programId;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function resolveProgramName(string $programId): string
    {
        try {
            $program = dictionary()->medicalPrograms()->firstWhere('id', $programId);

            return is_array($program) ? (string) ($program['name'] ?? $programId) : $programId;
        } catch (\Throwable) {
            return $programId;
        }
    }
}
