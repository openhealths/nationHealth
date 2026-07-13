<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Models\EhealthJob;
use App\Models\EhealthLink;
use App\Models\MedicalEvents\Mongo\Approval as MongoApproval;
use App\Models\MedicalEvents\Sql\Approval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ApprovalRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Approval());
    }

    /**
     * Fetch approvals from eHealth and sync them locally for a given polymorphic entity.
     *
     * - Stores the full raw eHealth JSON in MongoDB (Mongo\Approval).
     * - Extracts reason_id (FK → identifiers) from the reason object — never writes a raw string.
     * - Extracts granted_to_id (FK → identifiers) from the granted_to identifier value.
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

                // Filter to only approvals that reference this specific resource
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

            $syncedUuids = [];
            foreach ($data as $approvalData) {
                // Persist full raw eHealth JSON to MongoDB
                try {
                    MongoApproval::updateOrCreate(
                        ['id' => $approvalData['id']],
                        $approvalData
                    );
                } catch (\Throwable $e) {
                    Log::warning('MedicalEvents\ApprovalRepository Mongo sync failed: ' . $e->getMessage());
                }

                $syncedUuids[] = $approvalData['id'];

                // Resolve granted_to → Identifier FK
                $grantedToValue = $approvalData['granted_to']['identifier']['value'] ?? null;
                $grantedToCode = $approvalData['granted_to']['identifier']['type']['coding'][0]['code'] ?? 'legal_entity';

                // Resolve reason → Identifier FK (never a raw string)
                $reasonValue = $approvalData['reason']['value'] ?? null;

                // Resolve created_by → Identifier FK
                $createdByValue = $approvalData['created_by']['identifier']['value'] ?? null;

                Approval::updateOrCreate(
                    ['uuid' => $approvalData['id']],
                    [
                        'approvable_type' => get_class($entity),
                        'approvable_id' => $entity->id,
                        'granted_to_id' => $this->resolveIdentifierId($grantedToValue),
                        'granted_to_type' => $grantedToCode,
                        'reason_id' => $this->resolveIdentifierId($reasonValue),
                        'created_by_id' => $this->resolveIdentifierId($createdByValue),
                        'status' => $approvalData['status'] ?? ($approvalData['is_verified'] ? 'active' : 'pending'),
                        'access_level' => $approvalData['access_level'] ?? 'read',
                        'is_verified' => (bool) ($approvalData['is_verified'] ?? false),
                        'expires_at' => $approvalData['expires_at'] ?? null,
                    ]
                );
            }

            Approval::where('approvable_type', get_class($entity))
                ->where('approvable_id', $entity->id)
                ->whereNotIn('uuid', $syncedUuids)
                ->update(['status' => 'inactive']);
        } catch (\Exception $e) {
            Log::error('MedicalEvents\ApprovalRepository syncing failed: ' . $e->getMessage());
        }
    }

    /**
     * Attach an EhealthLink to an Approval after a 202 async response.
     *
     * @param  array{href: string}  $link  The link object from the eHealth 202 response.
     */
    public function attachEhealthLink(Approval $approval, array $link): EhealthLink
    {
        $job = EhealthJob::create([
            'processing_method' => 'ASYNC',
            'status' => 'PROCESSING',
        ]);

        return EhealthLink::create([
            'linkable_type' => Approval::class,
            'linkable_id' => $approval->id,
            'ehealth_job_id' => $job->id,
            'entity' => 'approval',
            'href' => $link['href'],
        ]);
    }

    /**
     * Find or create an Identifier record by its UUID value and return its PK.
     */
    private function resolveIdentifierId(?string $uuid): ?int
    {
        if (!$uuid) {
            return null;
        }

        $identifier = \App\Models\MedicalEvents\Sql\Identifier::where('value', $uuid)->first()
            ?? Repository::identifier()->store($uuid);

        return $identifier->id;
    }
}
