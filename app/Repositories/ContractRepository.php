<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\Api\Contract as ContractMapper;
use App\Models\Contracts\Contract;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Enums\Contract\ContractStatus;
use App\Enums\JobStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContractRepository
{
    public function participatingProgramIds(LegalEntity $legalEntity): array
    {
        $contracts = Contract::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->get();

        return $this->extractProgramIdsFromContracts($contracts);
    }

    private function extractProgramIdsFromContracts(Collection $contracts): array
    {
        $ids = [];

        foreach ($contracts as $contract) {
            $status = strtoupper((string) ($contract->status?->value ?? $contract->status ?? ''));
            if (!in_array($status, ContractStatus::expandFilterValues([ContractStatus::VERIFIED->value]), true)) {
                continue;
            }

            foreach ($contract->medicalPrograms ?? [] as $program) {
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

    public function saveParticipationSnapshot(array $contracts, LegalEntity $legalEntity): void
    {
        DB::transaction(function () use ($contracts, $legalEntity): void {
            foreach ($contracts as $item) {
                $this->saveFromEHealth($item, $legalEntity);
            }
            $legalEntity->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CONTRACT);
        });
    }

    /**
     * Saves or updates a contract based on data received from E-Health API.
     */
    public function saveFromEHealth(array $eHealthData, ?LegalEntity $legalEntity = null): Contract
    {
        $legalEntity ??= legalEntity();
        $mapper = app(ContractMapper::class);
        $attributes = $mapper->mapCreate($eHealthData);

        unset($attributes['id']);

        $attributes['legal_entity_id'] = $legalEntity->id;

        $attributes['contractor_legal_entity_id'] = $eHealthData['contractor_legal_entity']['id']
            ?? $eHealthData['contractor_legal_entity']['uuid']
            ?? $eHealthData['contractor_legal_entity_id']
            ?? $legalEntity->uuid;

        $attributes['contractor_owner_id'] = $eHealthData['contractor_owner']['id']
            ?? $eHealthData['contractor_owner']['uuid']
            ?? $eHealthData['contractor_owner_id']
            ?? Employee::query()->activeOwners($legalEntity->id)->value('uuid');

        return Contract::updateOrCreate(
            ['uuid' => $attributes['uuid']],
            $attributes
        );
    }
}
