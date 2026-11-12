<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\Api\Patient\PatientApiBase;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\MedicationResponseContract;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

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
    /** Official General MIS 9.15.12 prescription-specific price catalogue link. */
    public function getPriceCatalogLink(string $personId, string $prescriptionId): EHealthResponse
    {
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'price_catalog'));

        return $this->get("/api/persons/{$personId}/medication_requests/{$prescriptionId}/price_catalog_link");
    }

    public function createRequest(array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'request_create'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'request_sign'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'detail'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'detail'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'list'));

        $this->setDefaultPageSize();
        $mergedQuery = array_merge($this->options['query'], $query);

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'request_list'));

        $this->setDefaultPageSize();
        $mergedQuery = array_merge($this->options['query'], $query);

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'resend'));

        return $this->patch("/api/medication_requests/{$id}/actions/resend", []);
    }

    /**
     * Pre-qualify medication request data before creation.
     *
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     */
    public function prequalify(array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'prequalify'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'request_reject'));

        return $this->patch("/api/medication_request_requests/{$requestId}/actions/reject", []);
    }

    /**
     * Reject a Medication Request (Відхилення активного рецепту).
     *
     * API-005-043-0006 request schema allows only signed_medication_reject and
     * signed_content_encoding. reject_reason_code / reject_reason belong in the
     * KEP-signed blob (the stored Medication Request plus those two fields).
     *
     * @param  string  $patientId  Included for signature compatibility; active MR actions in ESOZ use root path.
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     */
    public function reject(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'reject'));

        $signed = $payload['signed_medication_reject'] ?? ($payload['signed_content'] ?? ($payload['signed_data'] ?? null));

        return $this->patch("/api/medication_requests/{$id}/actions/reject", [
            'signed_medication_reject' => $signed,
            'signed_content_encoding' => $payload['signed_content_encoding'] ?? 'base64',
        ]);
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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'qualify'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'care_plan_list'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'dispenses'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'printout'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'block'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'unblock'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'composition_detail'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'pharmacy_detail'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'pharmacy_block'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'pharmacy_unblock'));

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
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'pharmacy_reject'));

        return $this->patch("/api/pharmacy/medication_requests/{$id}/actions/reject", $payload);
    }

    /**
     * Search medication requests as a pharmacy user (API-006).
     *
     * @param  array<string, mixed>  $query
     */
    public function searchByPharmacy(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'pharmacy_list'));

        return $this->get('/api/pharmacy/medication_requests', $query);
    }

    /** Read unsigned requests from any MIS in the care-plan context. */
    public function getRequestsByCarePlan(string $carePlanId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $this->setValidator(static fn (EHealthResponse $response): array => (new MedicationResponseContract())->validate($response, 'request_care_plan_list'));

        return $this->get("/api/care_plans/{$carePlanId}/medication_request_requests", array_merge($this->options['query'], $query));
    }
}
