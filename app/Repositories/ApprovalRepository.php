<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\EHealth;
use App\Models\Approval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ApprovalRepository
{
    public function create(array $data): Approval
    {
        return Approval::create($data);
    }

    /**
     * Sync approvals for a specific polymorphic entity.
     */
    public function syncApprovals(Model $entity, string $resourceType): void
    {
        if (empty($entity->uuid)) {
            return;
        }

        try {
            $patientUuid = null;
            if (method_exists($entity, 'person') && $entity->person) {
                $patientUuid = $entity->person->uuid;
            } elseif (isset($entity->person_id)) {
                $person = \App\Models\Person\Person::find($entity->person_id);
                $patientUuid = $person?->uuid;
            }

            if ($patientUuid) {
                $response = EHealth::approval()->getPatientApprovals($patientUuid);
                $data = $response->getData();
                if (!empty($data) && is_array($data)) {
                    $filteredData = [];
                    foreach ($data as $approvalData) {
                        $grantedResources = $approvalData['granted_resources'] ?? [];
                        foreach ($grantedResources as $resource) {
                            $typeCode = $resource['identifier']['type']['coding'][0]['code'] ?? null;
                            $value = $resource['identifier']['value'] ?? null;
                            if ($typeCode === $resourceType && $value === $entity->uuid) {
                                $filteredData[] = $approvalData;
                                break;
                            }
                        }
                    }
                    $data = $filteredData;
                }
            } else {
                $response = EHealth::approval()->getMany([
                    'granted_resource_type' => $resourceType,
                    'granted_resource_id' => $entity->uuid,
                ]);
                $data = $response->getData();
            }

            if (empty($data)) {
                return;
            }

            foreach ($data as $approvalData) {
                // Save raw response to Mongo
                try {
                    \App\Models\MedicalEvents\Mongo\Approval::updateOrCreate(
                        ['id' => $approvalData['id']],
                        $approvalData
                    );
                } catch (\Throwable $e) {
                    Log::warning('ApprovalRepository Mongo sync failed: ' . $e->getMessage());
                }

                $grantedToValue = $approvalData['granted_to']['identifier']['value'] ?? null;
                $grantedToCode = $approvalData['granted_to']['identifier']['type']['coding'][0]['code'] ?? 'legal_entity';

                // Map to SQL
                Approval::updateOrCreate(
                    [
                        'uuid' => $approvalData['id'],
                    ],
                    [
                        'approvable_type' => get_class($entity),
                        'approvable_id' => $entity->id,
                        'granted_to_id' => $this->resolveGrantedTo($grantedToValue, $grantedToCode),
                        'granted_to_type' => $grantedToCode,
                        'status' => $approvalData['status'] ?? 'active',
                    ]
                );
            }
        } catch (\Exception $e) {
            Log::error("ApprovalRepository syncing failed: " . $e->getMessage());
        }
    }

    private function resolveGrantedTo(?string $uuid, string $type): ?int
    {
        if (!$uuid) {
            return null;
        }

        $identifier = \App\Models\MedicalEvents\Sql\Identifier::where('value', $uuid)->first();
        if (!$identifier) {
            $identifier = \App\Repositories\MedicalEvents\Repository::identifier()->store($uuid);
        }

        return $identifier->id;
    }
}
