<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\MedicationRequest;

use App\Classes\eHealth\EHealth;
use App\Dto\MedicationRequest\Ehealth as MedicationRequestEhealth;
use App\Dto\MedicationRequest\EhealthCreate as MedicationRequestEhealthCreate;
use App\Dto\MedicationRequest\EhealthPrequalify as MedicationRequestEhealthPrequalify;

use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

trait CreatesMedicationRequestDrafts
{
    protected function submitMedicationRequestDraft(array $dbData, array $uuids, ?string $carePlanUuid, int $personId): string
    {
        if (!empty($dbData['medication_program_id'])) {
            $prequalify = app(ObjectMapperInterface::class)->map(
                MedicationRequestEhealth::source($dbData, $uuids, CarbonImmutable::now(), $carePlanUuid),
                MedicationRequestEhealthPrequalify::class,
            )->toArray();
            EHealth::medicationRequest()->prequalifyAndValidate($prequalify);
        }

        $result = EHealth::medicationRequest()->createAndResolve(
            app(ObjectMapperInterface::class)->map(
                MedicationRequestEhealth::source($dbData, $uuids, CarbonImmutable::now(), $carePlanUuid),
                MedicationRequestEhealthCreate::class,
            )->toArray()
        );

        $dbData['request_number'] = $result->requestNumber();
        $dbData['uuid'] = $result->uuid($dbData['uuid']);
        $dbData['ehealth_payload'] = $result->document();

        app(MedicationRequestRepository::class)->store($dbData, $personId);

        return $dbData['uuid'];
    }
}
