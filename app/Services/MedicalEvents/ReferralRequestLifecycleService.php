<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Str;

class ReferralRequestLifecycleService
{
    public function __construct(
        private readonly EHealthJobResolver $jobResolver,
    ) {
    }

    public function sumIssuedQuantity(CarePlanActivity $activity): float
    {
        if ($activity->kind === 'service_request') {
            return Repository::serviceRequest()->sumIssuedQuantityByActivity($activity->id);
        }

        return Repository::deviceRequest()->sumIssuedQuantityByActivity($activity->id);
    }

    /**
     * @param  array<string, mixed>  $formData
     * @param  array<string, int|string|null>  $employeeContext
     */
    public function createDraft(CarePlan $carePlan, array $formData, float $qty, array $employeeContext): string
    {
        $activity = CarePlanActivity::query()
            ->with('carePlan')
            ->findOrFail($formData['activity_id']);

        $dbData = [
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employeeContext['employee_id'] ?? null,
            'person_id' => $carePlan->person_id,
            'division_id' => $employeeContext['division_id'] ?? null,
            'status' => 'draft',
            'started_at' => convertToYmd($formData['started_at']),
            'ended_at' => convertToYmd($formData['ended_at']),
            'quantity' => $qty,
            'program_id' => $formData['program_id'] ?? null,
            'intent' => $formData['intent'] ?? 'order',
            'category' => $formData['category'] ?? null,
            'based_on_id' => $formData['activity_id'],
            'context_id' => $carePlan->encounter?->id ?? null,
            'priority' => $formData['priority'],
            'note' => $formData['note'] ?? null,
            'supporting_info' => $formData['supporting_info'] ?? null,
            'based_on_uuid' => $activity->uuid,
        ];

        $uuids = [
            'person_uuid' => $carePlan->person->uuid,
            'encounter_uuid' => $carePlan->encounter?->uuid ?? null,
            'employee_uuid' => $employeeContext['employee_uuid'] ?? null,
            'legal_entity_uuid' => $employeeContext['legal_entity_uuid'] ?? null,
        ];

        if ($formData['kind'] === 'service_request') {
            $dbData['service_id'] = $activity->product_reference;

            $mapper = Fhir::serviceRequest();
            $prequalifyPayload = $mapper->toPrequalifyPayload(
                $dbData,
                $uuids,
                $carePlan->uuid,
                (string) $activity->uuid
            );
            $this->runPrequalify(
                EHealth::serviceRequest()->prequalify($carePlan->person->uuid, $prequalifyPayload)
            );

            $response = EHealth::serviceRequest()->createRequest(
                $carePlan->person->uuid,
                $mapper->toFhir($dbData, $uuids)
            );

            return $this->persistResponse($dbData, $response->getData(), 'service_request', $carePlan->person_id);
        }

        $dbData['device_id'] = $activity->product_reference;

        $mapper = Fhir::deviceRequest();
        $prequalifyPayload = $mapper->toPrequalifyPayload(
            $dbData,
            $uuids,
            $carePlan->uuid,
            (string) $activity->uuid
        );
        $this->runPrequalify(
            EHealth::deviceRequest()->prequalify($carePlan->person->uuid, $prequalifyPayload)
        );

        $response = EHealth::deviceRequest()->createRequest(
            $carePlan->person->uuid,
            $mapper->toFhir($dbData, $uuids)
        );

        return $this->persistResponse($dbData, $response->getData(), 'device_request', $carePlan->person_id);
    }

    public function resendSms(string $personUuid, string $requestId, string $kind): EHealthResponse
    {
        return $kind === 'service_request'
            ? EHealth::serviceRequest()->resendSms($personUuid, $requestId)
            : EHealth::deviceRequest()->resendSms($personUuid, $requestId);
    }

    public function buildPrintoutHtml(CarePlan $carePlan, string $requestId): string
    {
        $record = Repository::serviceRequest()->findByUuid($requestId)
            ?? Repository::deviceRequest()->findByUuid($requestId);

        if (!$record instanceof ServiceRequestRequest && !$record instanceof DeviceRequestRequest) {
            throw new \RuntimeException('Направлення не знайдено');
        }

        $code = $record instanceof ServiceRequestRequest ? $record->service_id : $record->device_id;
        $name = $record instanceof ServiceRequestRequest
            ? 'Направлення на послугу (ServiceRequest)'
            : 'Направлення на виріб (DeviceRequest)';

        return "
            <div style='font-family: sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; border: 1px solid #ccc; border-radius: 8px;'>
                <h2 style='text-align: center; color: #1e3a8a;'>ІНФОРМАЦІЙНА ДОВІДКА НАПРАВЛЕННЯ</h2>
                <p style='text-align: center; font-size: 14px; color: #555;'>Електронне направлення № " . e($record->request_number ?: $record->uuid) . "</p>
                <hr style='border-top: 1px solid #eee; margin: 20px 0;'/>
                <table style='width: 100%; font-size: 14px; border-collapse: collapse;'>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Тип:</td><td style='padding: 8px 0;'>" . e($name) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Пацієнт:</td><td style='padding: 8px 0;'>" . e($carePlan->person->full_name) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Код послуги/виробу:</td><td style='padding: 8px 0;'>" . e($code) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Кількість:</td><td style='padding: 8px 0;'>" . e((string) $record->quantity) . " од.</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Термін дії:</td><td style='padding: 8px 0;'>з " . e(\Carbon\Carbon::parse($record->started_at)->format('d.m.Y')) . " по " . e(\Carbon\Carbon::parse($record->ended_at)->format('d.m.Y')) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Примітки:</td><td style='padding: 8px 0;'>" . e((string) $record->note) . "</td></tr>
                </table>
                <div style='margin-top: 40px; text-align: center; font-size: 12px; color: #888;'>
                    Зверніться до будь-якого медичного закладу, що надає відповідні послуги за контрактом з НСЗУ.
                </div>
            </div>
        ";
    }

    private function runPrequalify(EHealthResponse $response): void
    {
        $finalResponse = $this->jobResolver->resolve($response->getData());
        $this->jobResolver->assertPrequalifyValid($finalResponse);
    }

    /**
     * @param  array<string, mixed>  $dbData
     * @param  array<string, mixed>  $responseData
     */
    private function persistResponse(array $dbData, array $responseData, string $kind, int $personId): string
    {
        $finalResponse = $this->jobResolver->resolve($responseData);

        if (($finalResponse['status'] ?? null) === 'failed') {
            throw new EHealthValidationException($finalResponse);
        }

        $dbData['request_number'] = $finalResponse['request_number'] ?? ($finalResponse['requisition'] ?? null);
        $dbData['status'] = $finalResponse['status'] ?? 'NEW';
        $dbData['uuid'] = $finalResponse['id'] ?? $dbData['uuid'];

        if ($kind === 'service_request') {
            Repository::serviceRequest()->store($dbData, $personId);
        } else {
            Repository::deviceRequest()->store($dbData, $personId);
        }

        return $dbData['uuid'];
    }
}
