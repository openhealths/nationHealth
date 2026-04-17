<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\EHealth;
use App\Models\Approval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ApprovalRepository
{
    /**
     * Sync approvals for a specific polymorphic entity.
     */
    public function syncApprovals(Model $entity, string $resourceType): void
    {
        if (empty($entity->uuid)) {
            return;
        }

        try {
            $response = EHealth::approval()->getMany([
                'granted_resource_type' => $resourceType,
                'granted_resource_id' => $entity->uuid,
            ]);

            $data = $response->getData();
            if (empty($data)) return;

            foreach ($data as $approvalData) {
                // Save raw response to Mongo
                try {
                    \App\Models\MedicalEvents\Mongo\Approval::updateOrCreate(
                        ['id' => $approvalData['id']],
                        $approvalData
                    );
                } catch (\Exception $e) {
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
        if (!$uuid) return null;
        
        if ($type === 'employee') {
            $emp = \App\Models\Employee::where('uuid', $uuid)->first();
            return $emp?->id;
        }

        // Implicitly 'legal_entity'
        $le = \App\Models\LegalEntity::where('uuid', $uuid)->first();
        return $le?->id;
    }
}
