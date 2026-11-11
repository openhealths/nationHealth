<?php

declare(strict_types=1);

namespace App\Livewire\Referral\Forms;

use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Facades\Validator;
use Livewire\Form;

class ServiceRequestForm extends Form
{
    public static function buildCreateContent(array $resource): array
    {
        Validator::make($resource, ['id' => ['required', 'uuid'], 'code.identifier.value' => ['required'], 'requesterEmployee.identifier.value' => ['required', 'uuid'], 'requesterLegalEntity.identifier.value' => ['required', 'uuid']])->validate();

        return Fhir::serviceRequest()->createContent($resource);
    }

    public static function buildStatusContent(array $snapshot, array|string $reason, string $explanatoryLetter, ?string $reasonSystem = null): array
    {
        if (is_string($reason)) {
            Validator::make(['code' => $reason, 'system' => $reasonSystem], ['code' => ['required', 'string'], 'system' => ['required', 'string']])->validate();
            $reason = FhirResource::make()->coding($reasonSystem, $reason)->toCodeableConcept();
        }
        Validator::make(['snapshot' => $snapshot, 'reason' => $reason, 'letter' => trim($explanatoryLetter)], [
            'snapshot.id' => ['required', 'uuid'], 'reason.coding' => ['required', 'array', 'min:1'],
            'reason.coding.*.system' => ['required', 'string'], 'reason.coding.*.code' => ['required', 'string'],
            'letter' => ['required', 'string'],
        ])->validate();
        $snapshot['status_reason'] = $reason;
        $snapshot['explanatory_letter'] = $explanatoryLetter;

        return $snapshot;
    }

    public static function buildCompleteContent(string $resourceUuid, string $resourceType, array $optional = []): array
    {
        Validator::make(['uuid' => $resourceUuid, 'type' => $resourceType], ['uuid' => ['required', 'uuid'], 'type' => ['required', 'in:encounter,procedure,diagnostic_report']])->validate();

        return array_intersect_key($optional, array_flip(['program_service', 'status_reason'])) + ['completed_with' => ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $resourceType]]], 'value' => $resourceUuid]]];
    }

    public static function buildSignedEnvelope(array $payload): array
    {
        Validator::make($payload, ['signed_data' => ['required', 'string']])->validate();

        return ['signed_data' => $payload['signed_data'], 'signed_data_encoding' => 'base64'];
    }

    public static function buildUseContent(array $context, mixed $programId): array
    {
        $payload = [];
        $references = ['used_by_employee' => ['employee_uuid', 'employee'], 'used_by_division' => ['division_uuid', 'division'], 'used_by_legal_entity' => ['legal_entity_uuid', 'legal_entity']];
        if ($programId !== null && $programId !== '') {
            $context['program_uuid'] = (string) $programId;
            $references['program'] = ['program_uuid', 'medical_program'];
        }
        foreach ($references as $field => [$key, $type]) {
            if (!empty($context[$key])) {
                $payload[$field] = ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]], 'value' => $context[$key]]];
            }
        }

        return $payload;
    }
}
