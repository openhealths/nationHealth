<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\Api\Concerns\ResolvesSignedPatientRequests;
use App\Classes\eHealth\Api\ServiceRequest as ServiceRequestExecutorApi;
use App\Classes\eHealth\EHealth;
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
    use ResolvesSignedPatientRequests;

    public function qualifyAndValidate(string $id, mixed $programId): void
    {
        try {
            $response = $this->qualify($id, ['programs' => [['id' => $programId]]])->getData();
            $job = EHealth::job();
            $job->assertPrequalifyValid($job->resolve(is_array($response) ? $response : []));
        } catch (EHealthValidationException $exception) {
            throw new \RuntimeException(__('care-plan.referral_qualify_blocked', [
                'reason' => $exception->getTranslatedMessage() ?: $exception->getFormattedMessage(),
            ]), previous: $exception);
        } catch (\Throwable $exception) {
            Log::warning('Qualify failed (blocking): '.$exception->getMessage(), ['referral_uuid' => $id]);
            throw new \RuntimeException(__('care-plan.referral_qualify_blocked', [
                'reason' => $exception->getMessage(),
            ]), previous: $exception);
        }
    }

    public function processAndResolve(string $id, array $payload): array
    {
        $response = $this->process($id, $payload)->getData();

        return EHealth::job()->resolve(is_array($response) ? $response : []);
    }

    public function completeAndResolve(string $id, array $payload): array
    {
        $response = $this->complete($id, $payload)->getData();

        return EHealth::job()->resolve(is_array($response) ? $response : []);
    }

    public function recallAndResolve(string $patientId, string $id, array $payload): array
    {
        if (trim((string) ($payload['explanatory_letter'] ?? '')) === '') {
            throw new \InvalidArgumentException(__('care-plan.referral_recall_letter_required'));
        }

        return EHealth::job()->resolve($this->recall($patientId, $id, $payload)->getData());
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
