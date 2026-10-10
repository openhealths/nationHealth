<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\MedicationRequest;

use App\Classes\eHealth\EHealth;

use App\Dto\MedicationRequest\Ehealth as MedicationRequestEhealth;
use App\Dto\MedicationRequest\Model as ModelData;
use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

trait PreparesMedicationRequestSigning
{
    protected function buildSignPayload(CarePlan|Encounter $contextModel, MedicationRequestRequest $requestRecord, string $informWith): array
    {
        if (!empty($requestRecord->ehealthPayload) && is_array($requestRecord->ehealthPayload)) {
            $document = $requestRecord->ehealthPayload;

            return isset($document['data']) && is_array($document['data']) ? $document['data'] : $document;
        }

        $repository = app(MedicationRequestRepository::class);
        $personUuid = $contextModel instanceof CarePlan
            ? ($contextModel->person->uuid ?? null)
            : $repository->personUuid((int) $contextModel->person_id);
        try {
            if ($personUuid) {
                $response = EHealth::medicationRequest()->getRequestsBySearchParams($personUuid, ['id' => $requestRecord->uuid])->getData();
                $document = $response['data'][0] ?? ($response[0] ?? null);
                if (!empty($document) && is_array($document) && ($document['id'] ?? null) === $requestRecord->uuid) {
                    $requestRecord->update(['ehealth_payload' => $document]);

                    return $document;
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Could not fetch MedicationRequestRequest from eHealth for signing fallback: '.$exception->getMessage());
        }

        $context = $repository->signingContext($contextModel, $requestRecord, $personUuid);
        $fields = app(ObjectMapperInterface::class)->map($requestRecord, ModelData::class)->toSigningFields();
        $fields['created_at'] ??= now()->format('Y-m-d');
        $fields['based_on_uuid'] = $context['activity_uuid'];
        $fields['inform_with'] = $informWith !== '' ? $informWith : ($requestRecord->informWith ?? '');

        return app(ObjectMapperInterface::class)->map(
            MedicationRequestEhealth::source($fields, $context['uuids'], CarbonImmutable::now(), $context['care_plan_uuid']),
            MedicationRequestEhealth::class,
        )->toArray();
    }
}
