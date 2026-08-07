<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MedicationRequest extends PatientApiBase
{
    /**
     * Create a Medication Request Request (Заявка на виписування рецепту).
     *
     * @param  string  $patientId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function createRequest(array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post('/api/medication_request_requests', $payload);
    }

    /**
     * Sign a Medication Request Request (Підпис заявки КЕП).
     *
     * @param  string  $patientId
     * @param  string  $requestId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function signRequest(string $requestId, array $payload): PromiseInterface|EHealthResponse
    {
        if (isset($payload['signed_content']) && !isset($payload['signed_medication_request_request'])) {
            $payload['signed_medication_request_request'] = $payload['signed_content'];
            unset($payload['signed_content']);
        }
        if (!isset($payload['signed_content_encoding'])) {
            $payload['signed_content_encoding'] = 'base64';
        }

        return $this->patch("/api/medication_request_requests/{$requestId}/actions/sign", $payload);
    }

    /**
     * Cancel a Medication Request (Скасування рецепту).
     *
     * @param  string  $patientId
     * @param  string  $id
     * @param  array  $payload  Requires 'status_reason' and optional KEP signature info
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function cancel(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/persons/{$patientId}/medication_requests/{$id}/actions/cancel", $payload);
    }

    /**
     * Get a specific Medication Request by ID.
     *
     * @param  string  $patientId
     * @param  string  $id
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getById(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDetails(...));

        return $this->get("/api/persons/{$patientId}/medication_requests/{$id}", $query);
    }

    /**
     * Get Medication Requests by search parameters.
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $mergedQuery = array_merge($this->options['query'], $query);
        $this->setValidator($this->validateMany(...));

        return $this->get("/api/persons/{$patientId}/medication_requests", $mergedQuery);
    }

    /**
     * Get Medication Request Requests by search parameters.
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getRequestsBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $mergedQuery = array_merge($this->options['query'], $query);
        $this->setValidator($this->validateMany(...));

        return $this->get("/api/persons/{$patientId}/medication_request_requests", $mergedQuery);
    }

    /**
     * Resend SMS code for Medication Request.
     *
     * @param  string  $patientId
     * @param  string  $id
     * @return PromiseInterface|EHealthResponse
     */
    public function resendOtp(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        return $this->resendSms($patientId, $id);
    }

    /**
     * Resend SMS code for active Medication Request (API-005-043-0005).
     *
     * @param  string  $patientId  Included for backwards compatibility with call sites; active MR actions use root URL in ESOZ.
     * @param  string  $id
     * @return PromiseInterface|EHealthResponse
     */
    public function resendSms(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        return $this->post("/api/medication_requests/{$id}/actions/resend", []);
    }

    /**
     * Pre-qualify medication request data before creation.
     *
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     */
    public function prequalify(array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post('/api/medication_request_requests/prequalify', $payload);
    }

    /**
     * Reject a Medication Request Request (Відхилення заявки).
     *
     * @param  string  $requestId
     * @return PromiseInterface|EHealthResponse
     */
    public function rejectRequest(string $requestId): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/medication_request_requests/{$requestId}/actions/reject", []);
    }

    /**
     * Reject a Medication Request (Відхилення активного рецепту).
     *
     * @param  string  $patientId  Included for signature compatibility; active MR actions in ESOZ use root path.
     * @param  string  $id
     * @param  array  $payload  Requires reject_reason_code
     * @return PromiseInterface|EHealthResponse
     */
    public function reject(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        if (isset($payload['signed_content']) && !isset($payload['signed_medication_reject'])) {
            $payload['signed_medication_reject'] = $payload['signed_content'];
        }

        unset(
            $payload['person_id'],
            $payload['reject_reason'],
            $payload['reject_reason_code'],
            $payload['signed_content'],
            $payload['signed_data']
        );

        if (!isset($payload['signed_content_encoding'])) {
            $payload['signed_content_encoding'] = 'base64';
        }

        return $this->patch("/api/medication_requests/{$id}/actions/reject", $payload);
    }

    /**
     * Qualify Medication Request by ID.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     */
    public function qualify(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->post("/api/medication_requests/{$id}/actions/qualify", $payload);
    }

    /**
     * Get Medication Requests in Care Plan context.
     *
     * @param  string  $carePlanId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     */
    public function getByCarePlan(string $carePlanId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        return $this->get("/api/care_plans/{$carePlanId}/medication_requests", $query);
    }

    /**
     * Get Medication Request details (including dispensing records / погашення).
     *
     * @param  string  $patientId
     * @param  string  $id
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     */
    public function getDetails(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get("/api/persons/{$patientId}/medication_requests/{$id}/medication_dispenses", $query);
    }

    /**
     * Get Medication Request printout form.
     *
     * @param  string  $patientId
     * @param  string  $id
     * @return PromiseInterface|EHealthResponse
     */
    public function getPrintoutForm(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        return $this->get("/api/persons/{$patientId}/medication_requests/{$id}/printout_form", []);
    }

    /**
     * Block an active Medication Request (Заблокувати рецепт).
     *
     * @param  string  $patientId
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     */
    public function block(string $patientId, string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/medication_requests/{$id}/actions/block", $payload);
    }

    /**
     * Unblock a blocked Medication Request (Розблокувати рецепт).
     *
     * @param  string  $patientId
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     */
    public function unblock(string $patientId, string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/medication_requests/{$id}/actions/unblock", $payload);
    }

    /**
     * Get Medication Request details in composition context.
     *
     * @param  string  $patientId
     * @param  string  $compositionId
     * @param  string  $id
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     */
    public function getInCompositionContext(string $patientId, string $compositionId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get("/api/persons/{$patientId}/compositions/{$compositionId}/medication_requests/{$id}", $query);
    }

    /**
     * Get Medication Request by ID by pharmacy user.
     *
     * @param  string  $id
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     */
    public function getByIdByPharmacy(string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get("/api/pharmacy/medication_requests/{$id}", $query);
    }

    /**
     * Block Medication Request by pharmacy user.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     */
    public function blockByPharmacy(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/pharmacy/medication_requests/{$id}/actions/block", $payload);
    }

    /**
     * Unblock Medication Request by pharmacy user.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     */
    public function unblockByPharmacy(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/pharmacy/medication_requests/{$id}/actions/unblock", $payload);
    }

    /**
     * Reject Medication Request by pharmacy user.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     */
    public function rejectByPharmacy(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/pharmacy/medication_requests/{$id}/actions/reject", $payload);
    }

    protected function validateDetails(EHealthResponse $response): array
    {
        $data = $this->replaceEHealthPropNames($response->getData());
        $toValidate = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;

        $validator = Validator::make($toValidate, [
            'uuid' => 'required|string',
            'status' => 'required|string',
            'request_number' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'MedicationRequest details validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new \Illuminate\Validation\ValidationException($validator);
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
            '*.request_number' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'MedicationRequest many validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return $response->getData();
    }
}
