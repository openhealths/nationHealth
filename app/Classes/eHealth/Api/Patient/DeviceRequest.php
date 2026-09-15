<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;

class DeviceRequest extends PatientApiBase
{
    /**
     * Create a signed Device Request in eHealth (PKCS#7).
     *
     * @see REST API Create Device Request [API-007-020-0003]
     */
    public function createSigned(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/device_requests", $payload);
    }

    /**
     * Cancel a Device Request (Скасування направлення на виріб).
     */
    public function cancel(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/{$patientId}/device_requests/{$id}/actions/cancel", $payload);
    }

    /**
     * Get a specific Device Request by ID.
     */
    public function getById(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/{$patientId}/device_requests/{$id}", $query);
    }

    /**
     * Get Device Requests by search parameters in patient context.
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     *
     * @see REST API Get Device Requests by Search Params [API-007-020-0002]
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));
        $this->setDefaultPageSize();

        $query = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/{$patientId}/device_requests", $query);
    }

    /**
     * Validate Device Requests collection.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateMany(EHealthResponse $response): array
    {
        $items = $response->getData();
        $transformedData = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $transformedData[] = $this->replaceEHealthPropNames($item);
            }
        }

        $validator = Validator::make($transformedData, [
            '*.uuid' => ['required', 'uuid'],
            '*.status' => ['required', 'string'],
            '*.intent' => ['required', 'string'],
            '*.requisition' => ['nullable', 'string'],
            '*.code' => ['nullable', 'array'],
            '*.code.coding' => ['nullable', 'array'],
            '*.code.coding.*.system' => ['nullable', 'string'],
            '*.code.coding.*.code' => ['nullable', 'string'],
            '*.code_reference' => ['nullable', 'array'],
            '*.code_reference.identifier.value' => ['nullable', 'uuid'],
            '*.code_reference.display_value' => ['nullable', 'string'],
            '*.program' => ['nullable', 'array'],
            '*.program.identifier.value' => ['nullable', 'uuid'],
            '*.quantity' => ['nullable', 'array'],
            '*.quantity.value' => ['nullable', 'numeric'],
            '*.quantity.code' => ['nullable', 'string'],
            '*.based_on' => ['nullable', 'array'],
            '*.based_on.identifier.value' => ['nullable', 'uuid'],
            '*.dispense_valid_to' => ['nullable', 'date'],
        ]);

        return $validator->validate();
    }

    /**
     * Pre-qualify device request data before creation.
     *
     * @see REST API PreQualify Device Request [API-007-020-0009]
     */
    public function prequalify(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/device_requests/prequalify", $payload);
    }

    /**
     * Resend SMS with OTP for an active device request.
     *
     * @see REST API Resend SMS on Device Request [API-007-020-0005]
     */
    public function resendSms(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/device_requests/{$id}/actions/resend", []);
    }
}
