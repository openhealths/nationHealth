<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\Api\ServiceRequest as ServiceRequestExecutorApi;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ServiceRequest extends PatientApiBase
{
    public function buildCreateContent(array $resource): array
    {
        Validator::make($resource, ['id' => ['required', 'uuid'], 'code.identifier.value' => ['required'], 'requesterEmployee.identifier.value' => ['required', 'uuid'], 'requesterLegalEntity.identifier.value' => ['required', 'uuid']])->validate();

        return \App\Services\MedicalEvents\Fhir::serviceRequest()->createContent($resource);
    }

    /** Fetch and return the raw signed contract, before any application normalization. */
    public function fetchSignedSnapshot(string $patientUuid, string $requestUuid): array
    {
        $resource = $this->getById($patientUuid, $requestUuid)->getData();
        Validator::make($resource, ['id' => ['required', 'in:'.$requestUuid], 'status' => ['required', 'in:active'], 'subject.identifier.value' => ['sometimes', 'required', 'in:'.$patientUuid]])->validate();

        return $resource;
    }

    public function buildStatusContent(array $snapshot, string $reason, string $explanatoryLetter): array
    {
        Validator::make(['snapshot' => $snapshot, 'reason' => $reason, 'letter' => trim($explanatoryLetter)], ['snapshot.id' => ['required', 'uuid'], 'reason' => ['required', 'string'], 'letter' => ['required', 'string']])->validate();
        $snapshot['status_reason'] = $reason;
        $snapshot['explanatory_letter'] = $explanatoryLetter;

        return $snapshot;
    }

    public function submitSignedCreate(string $patientUuid, string $signedContent): array
    {
        return $this->resolveMutation($this->createSigned($patientUuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64'])->getData());
    }

    public function cancelAndResolve(string $patientUuid, string $requestUuid, array $payload): array
    {
        return $this->resolveMutation($this->cancel($patientUuid, $requestUuid, $this->signedEnvelope($payload))->getData());
    }

    public function recallAndResolve(string $patientUuid, string $requestUuid, array $payload): array
    {
        return $this->resolveMutation($this->recall($patientUuid, $requestUuid, $this->signedEnvelope($payload))->getData());
    }

    /** A pending/error response without a job link must not become local success. */
    public function resolveMutation(array $response): array
    {
        $resolved = app(\App\Services\MedicalEvents\EHealthJobResolver::class)->resolve($response);
        if (in_array(strtolower((string) ($resolved['status'] ?? '')), ['pending', 'processing', 'accepted', 'queued', 'failed', 'rejected', 'error'], true) || !empty($resolved['error'])) {
            throw new EHealthValidationException(['error' => ['message' => __('errors.ehealth.messages.request_error')]]);
        }

        return $resolved;
    }

    public function buildUseContent(array $context, mixed $programId): array
    {
        return $this->executorApi()->buildUseContent($context, $programId);
    }

    public function buildCompleteContent(string $resourceUuid, string $resourceType, array $optional = []): array
    {
        Validator::make(['uuid' => $resourceUuid, 'type' => $resourceType], ['uuid' => ['required', 'uuid'], 'type' => ['required', 'in:encounter,procedure,diagnostic_report']])->validate();

        return array_intersect_key($optional, array_flip(['program_service', 'status_reason'])) + ['completed_with' => ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $resourceType]]], 'value' => $resourceUuid]]];
    }

    private function signedEnvelope(array $payload): array
    {
        Validator::make($payload, ['signed_data' => ['required', 'string']])->validate();

        return ['signed_data' => $payload['signed_data'], 'signed_data_encoding' => 'base64'];
    }

    /**
     * Create a signed Service Request in eHealth (PKCS#7).
     *
     * @see REST API Create Service Request [API-007-062-0002]
     */
    public function createSigned(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/service_requests", $payload);
    }

    /**
     * Cancel a Service Request (Скасування направлення як entered-in-error).
     */
    public function cancel(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/{$patientId}/service_requests/{$id}/actions/cancel", $payload);
    }

    /**
     * Recall a Service Request (відміна за непотрібністю, TV 3.17.1.13).
     *
     * @param  array<string, mixed>  $payload
     */
    public function recall(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/{$patientId}/service_requests/{$id}/actions/recall", $payload);
    }

    /**
     * Search for Service Requests by parameters.
     */
    public function searchForServiceRequestsByParams(array $params): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        return $this->get('/api/service_requests', $params);
    }

    /**
     * Get Service Requests by search parameters in patient context.
     *
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $this->setValidator($this->validateMany(...));

        $query = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/{$patientId}/service_requests", $query);
    }

    /**
     * Get a specific Service Request by ID.
     */
    public function getById(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDetails(...));

        return $this->get(self::URL . "/{$patientId}/service_requests/{$id}", $query);
    }

    /**
     * Pre-qualify service request data before creation.
     *
     * @see REST API PreQualify Service Request [API-007-062-0001]
     */
    public function prequalify(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/service_requests/prequalify", $payload);
    }

    /**
     * Resend SMS with OTP for an active service request.
     *
     * @see REST API Resend SMS on Service Request [API-007-062-0009]
     */
    public function resendSms(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/service_requests/{$id}/actions/resend", []);
    }

    /**
     * Executor actions live on the facility-scoped Service Request API.
     * EHealth::serviceRequest() is bound to this Patient client for create/search,
     * so these methods delegate to Api\ServiceRequest for use / complete / qualify.
     */
    public function qualify(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->executorApi()->qualify($id, $payload);
    }

    public function process(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->executorApi()->process($id, $payload);
    }

    public function complete(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->executorApi()->complete($id, $payload);
    }

    public function cancelUsage(string $id, string $patientId, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->executorApi()->cancelUsage($id, $patientId, $payload);
    }

    private function executorApi(): ServiceRequestExecutorApi
    {
        return app(ServiceRequestExecutorApi::class);
    }

    protected function validateDetails(EHealthResponse $response): array
    {
        $data = $this->replaceEHealthPropNames($response->getData());
        $toValidate = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;

        $validator = Validator::make($toValidate, [
            'uuid' => 'required|string',
            'status' => 'required|string',
            'requisition' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'ServiceRequest details validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new ValidationException($validator);
        }

        return $data;
    }

    protected function validateMany(EHealthResponse $response): array
    {
        $transformedData = [];
        $items = $response->getData();
        if (isset($items['data']) && is_array($items['data'])) {
            $items = $items['data'];
        }
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $transformedData[] = $this->replaceEHealthPropNames($item);
                }
            }
        }

        $validator = Validator::make($transformedData, [
            '*' => 'array',
            '*.uuid' => 'required|string',
            '*.status' => 'required|string',
            '*.requisition' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'ServiceRequest many validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new ValidationException($validator);
        }

        return $response->getData();
    }
}
