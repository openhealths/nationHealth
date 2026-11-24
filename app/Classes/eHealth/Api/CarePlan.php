<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Enums\CarePlanStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CarePlan extends Request
{
    protected const string URL = '/api/care_plans';

    /**
     * Create a new Care Plan in eHealth.
     *
     * @param  string  $patientId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->post("/api/patients/{$patientId}/care_plans", $payload);
    }

    /**
     * Get Care Plans by search parameters.
     *
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        return $this->get(self::URL, $query);
    }

    /**
     * Get a specific Care Plan by Patient ID and Care Plan ID.
     *
     * @param  string  $patientId
     * @param  string  $id
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getDetails(string $patientId, string $id, array $query = [], bool $preserveApiKeys = false): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateDetails($response, $preserveApiKeys));

        return $this->get("/api/patients/{$patientId}/care_plans/{$id}", $query);
    }

    /**
     * Cancel a Care Plan.
     * Note: This requires a Digital Signature (DS).
     * The payload must contain 'signed_content' which is the Care Plan object (without activities) + status_reason, signed by KEП.
     *
     * @param  string  $personId
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function cancel(string $personId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(Job::validateSubmissionResponse(...));

        return $this->patch("/api/patients/$personId/care_plans/$id/actions/cancel", $payload);
    }

    /**
     * Complete a Care Plan.
     * Note: This does NOT require a Digital Signature (DS).
     * The payload just needs to contain 'status_reason'.
     *
     * @param  string  $personId
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function complete(string $personId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(Job::validateSubmissionResponse(...));

        return $this->patch("/api/patients/$personId/care_plans/$id/actions/complete", $payload);
    }

    /**
     * Get Care Plans by search parameters.
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        return $this->get("/api/patients/$patientId/care_plans", $query);
    }

    /**
     * Get a list of summary info about care plans.
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-care-plans
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get("/api/patients/$patientId/summary/care_plans", $mergedQuery);
    }

    /**
     * Validate the creation response. Accepted requests return job metadata;
     * a completed synchronous request returns validated plan details. The array
     * contract is retained for the existing clinical submission consumer.
     */
    protected function validateResponse(EHealthResponse $response): array
    {
        if ($response->getStatusCode() === 202) {
            return Validator::make($response->getData(), [
                'status' => ['required', 'string'],
                'eta' => ['sometimes', 'string'],
                'links' => ['sometimes', 'array'],
                'links.*.entity' => ['sometimes', 'string'],
                'links.*.href' => ['required', 'string'],
            ])->validate();
        }

        $data = Arr::toCamelCase($response->getData());
        $validator = Validator::make($data, $this->validationRules());
        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('CarePlan response validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /** Full plan details required before mapping a registered plan to Model DTO. */
    protected function validationRules(): array
    {
        $statuses = [
            CarePlanStatus::PENDING->value, CarePlanStatus::ACTIVE->value,
            CarePlanStatus::COMPLETED->value, CarePlanStatus::CANCELLED->value,
            CarePlanStatus::TERMINATED->value,
        ];
        $rules = [
            'id' => ['required', 'uuid'],
            'status' => ['required', Rule::in($statuses)],
            'title' => ['required', 'string'],
            'intent' => ['required', 'in:order'],
            'period.start' => ['required', 'date'],
            'period.end' => ['sometimes', 'nullable', 'date'],
            'requisition' => ['required', 'string'],
            'addresses' => ['required', 'array', 'min:1'],
            'statusHistory' => ['present', 'array'],
            'statusHistory.*.status' => ['required', Rule::in($statuses)],
            'statusHistory.*.insertedAt' => ['required', 'date'],
            'statusHistory.*.insertedBy' => ['required', 'uuid'],
            'insertedAt' => ['required', 'date'],
            'updatedAt' => ['required', 'date'],
            'insertedBy' => ['required', 'uuid'],
            'updatedBy' => ['required', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'context' => ['sometimes', 'nullable', 'string'],
            'informWith.authMethodId' => ['required_with:informWith', 'uuid'],
        ];
        foreach (['encounter', 'author', 'subject', 'managingOrganization', 'basedOn', 'partOf', 'careManager'] as $reference) {
            $required = in_array($reference, ['encounter', 'author', 'subject', 'managingOrganization'], true)
                ? 'required' : 'required_with:'.$reference;
            $rules[$reference.'.identifier.value'] = [$required, 'uuid'];
            $rules[$reference.'.identifier.type.coding'] = [$required, 'array', 'min:1'];
            $rules[$reference.'.identifier.type.coding.*.code'] = ['required', 'string'];
            $rules[$reference.'.identifier.type.coding.*.system'] = ['required', 'string'];
        }
        foreach (['category', 'termsOfService', 'addresses.*', 'statusReason'] as $concept) {
            $required = $concept === 'statusReason' ? 'required_with:statusReason' : 'required';
            $rules[$concept.'.coding'] = [$required, 'array', 'min:1'];
            $rules[$concept.'.coding.*.code'] = ['required', 'string'];
            $rules[$concept.'.coding.*.system'] = ['required', 'string'];
            $rules[$concept.'.coding.*.display'] = ['sometimes', 'nullable', 'string'];
            $rules[$concept.'.text'] = ['sometimes', 'nullable', 'string'];
        }
        foreach (['contributor', 'supportingInfo'] as $references) {
            $rules[$references] = ['sometimes', 'array'];
            $rules[$references.'.*.identifier.value'] = ['required', 'uuid'];
            $rules[$references.'.*.identifier.type.coding'] = ['required', 'array', 'min:1'];
            $rules[$references.'.*.identifier.type.coding.*.code'] = ['required', 'string'];
            $rules[$references.'.*.identifier.type.coding.*.system'] = ['required', 'string'];
        }

        return $rules;
    }

    protected function validateDetails(EHealthResponse $response, bool $preserveApiKeys = false): array
    {
        $native = $response->getData();
        if (array_is_list($native) && count($native) === 1) {
            $native = $native[0];
        }
        $data = Arr::toCamelCase($native);

        $validator = Validator::make($data, [
            'id' => 'required|uuid',
            'status' => 'required|string',
            'title' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'CarePlan details validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new ValidationException($validator);
        }

        // Signed snapshots retain the API's keys; both forms pass the same validator.
        return $preserveApiKeys ? $native : $data;
    }

    protected function validateMany(EHealthResponse $response): array
    {
        $transformedData = [];
        foreach ($response->getData() as $item) {
            $transformedData[] = Arr::toCamelCase($item);
        }

        $validator = Validator::make($transformedData, [
            '*' => 'array',
            '*.id' => 'required|uuid',
            '*.status' => 'required|string',
            '*.title' => 'nullable|string',
            '*.managingOrganization' => ['required', 'array'],
            '*.managingOrganization.identifier.value' => ['required', 'uuid'],
            '*.managingOrganization.displayValue' => ['required', 'string']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'CarePlan validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new ValidationException($validator);
        }

        return $transformedData;
    }

}
