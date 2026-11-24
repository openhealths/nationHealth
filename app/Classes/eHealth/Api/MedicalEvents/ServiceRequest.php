<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\MedicalEvents;

use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequest;
use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestPrequalification;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ServiceRequest extends PatientServiceRequest
{
    /** @see REST API PreQualify Service Request [API-007-062-0001] */
    public function prequalify(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        $programIds = data_get($payload, 'programs.*.identifier.value', []);
        $this->setValidator(fn (EHealthResponse $response) => $this->validatePrequalify($response, $programIds));

        return parent::prequalify($patientId, $payload);
    }

    protected function validatePrequalify(EHealthResponse $response, array $programIds): ServiceRequestPrequalification
    {
        $verdicts = $response->collect('data');
        // Keep the returned spelling of each requested UUID. UUID comparison is case-insensitive.
        $matchingIds = $verdicts->pluck('program_id')
            ->filter(fn ($id) => is_string($id))
            ->intersectUsing($programIds, 'strcasecmp');
        $rules = [
            'error' => ['missing'],
            'data' => [
                'bail', Rule::requiredIf($programIds !== [] || $response->json('data', []) !== []),
                'array', 'list', 'size:'.count($programIds),
            ],
            'verdicts.*' => ['required', 'array'],
            'verdicts.*.program_id' => ['bail', 'required', 'uuid', 'distinct:ignore_case', Rule::in($matchingIds->all())],
            'verdicts.*.program_name' => ['present', 'string'],
            'verdicts.*.status' => ['required', Rule::in(['VALID', 'INVALID'])],
            'verdicts.*.rejection_reason' => ['sometimes', 'string'],
            'urgent.authentication_method_current.type' => ['required', Rule::in(['OTP', 'OFFLINE', 'THIRD_PERSON'])],
            'urgent.authentication_method_current.number' => ['sometimes', 'string'],
        ];

        // The associative decoder loses the distinction between an empty JSON object and an empty list.
        $validated = Validator::make(Arr::only((array) $response->object(), ['data', 'error']) + [
            'verdicts' => $verdicts->all(),
            'urgent' => $response->json('urgent'),
        ], $rules)->validate();

        return new ServiceRequestPrequalification($validated['verdicts'] ?? []);
    }
}
