<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Referral;

use App\Dto\ServiceRequest\SignedReferralResult;
use App\Enums\Person\DeviceRequestStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Dto\DeviceRequest\Model as DeviceRequestModelData;
use App\Dto\ServiceRequest\Model as ServiceRequestModelData;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use Illuminate\Support\Facades\Log;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

trait SynchronizesReferrals
{
    use SelectsReferralApi;
    use PreparesReferralSigning;

    protected function syncReferralFromRemote(CarePlan|Encounter $context, ?CarePlanActivity $activity, ServiceRequestRequest|DeviceRequestRequest $record, string $kind, array $local, ?array $remote = null): array
    {
        $personUuid = $context instanceof CarePlan ? $context->person->uuid : Person::find($context->person_id)?->uuid;
        $remote ??= $this->fetchRemoteReferral((string) $personUuid, (string) $record->uuid, $kind);
        $data = array_merge($local, $this->remoteReferralPatch($remote, $kind));
        $data['employee_id'] = $local['employee_id'] ?? $record->employeeId;
        $data['division_id'] = $local['division_id'] ?? $record->divisionId;
        $data['based_on_uuid'] = $local['based_on_uuid'] ?? $record->basedOn?->value ?? $activity?->uuid;
        $data['context_uuid'] = $local['context_uuid'] ?? $record->context?->value
            ?? ($context instanceof CarePlan ? $context->encounter?->uuid : $context->uuid);
        $this->referralRepository($kind)->store($data, (int) $context->person_id);

        return $data;
    }

    protected function trySyncDraftFromEHealth(CarePlan|Encounter $context, ?CarePlanActivity $activity, ServiceRequestRequest|DeviceRequestRequest $record, string $kind): bool
    {
        $draft = $record instanceof ServiceRequestRequest
            ? ServiceRequestStatus::resolve((string) $record->status) === ServiceRequestStatus::DRAFT
            : DeviceRequestStatus::resolve((string) $record->status) === DeviceRequestStatus::DRAFT;
        if (!$draft) {
            return false;
        }
        $personUuid = $context instanceof CarePlan ? $context->person->uuid : Person::find($context->person_id)?->uuid;
        if (!$personUuid) {
            return false;
        }

        try {
            $remote = $this->referralApi($kind)->getById((string) $personUuid, (string) $record->uuid)->getData();
        } catch (EHealthResponseException|EHealthValidationException) {
            return false;
        }
        $status = ServiceRequestStatus::resolve((string) ($remote['status'] ?? ''));
        if ($remote === [] || $status === null || $status === ServiceRequestStatus::DRAFT) {
            return false;
        }
        $this->syncReferralFromRemote($context, $activity, $record, $kind, $this->referralSignData($record, $activity, $context), $remote);

        return true;
    }

    protected function persistAfterSignedCreate(array $data, array $response, string $kind, int $personId): array
    {
        $patch = app(ObjectMapperInterface::class)->map(SignedReferralResult::entity($response), SignedReferralResult::class)->toPatch();
        $data = array_replace($data, $patch);
        $data['request_number'] ??= null;
        $repository = $this->referralRepository($kind);
        $repository->store($data, $personId);

        // The accepted resource remains saved even if this best-effort GET fails.
        if (empty($data['request_number']) && !empty($data['uuid'])) {
            try {
                $personUuid = Person::query()->whereKey($personId)->value('uuid');
                if (is_string($personUuid) && $personUuid !== '') {
                    $remote = $this->remoteReferralPatch($this->fetchRemoteReferral($personUuid, (string) $data['uuid'], $kind), $kind);
                    if (!empty($remote['request_number'])) {
                        $data['request_number'] = $remote['request_number'];
                    }
                    if (SignedReferralResult::isClinicalStatus($remote['status'] ?? null)) {
                        $data['status'] = $remote['status'];
                    }
                    $repository->store($data, $personId);
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to enrich referral requisition after signed create', [
                    'uuid' => $data['uuid'] ?? null, 'kind' => $kind, 'message' => $exception->getMessage(),
                ]);
            }
        }

        return $data;
    }

    protected function fetchRemoteReferral(string $personUuid, string $uuid, string $kind): array
    {
        $response = $this->referralApi($kind)->getById($personUuid, $uuid);
        $remote = $response->getData();
        if ($remote === []) {
            throw new EHealthResponseException($response);
        }

        return $remote;
    }

    private function remoteReferralPatch(array $remote, string $kind): array
    {
        return app(ObjectMapperInterface::class)->map((object) $remote, $kind === 'service_request' ? ServiceRequestModelData::class : DeviceRequestModelData::class)->toSyncPatch();
    }
}
