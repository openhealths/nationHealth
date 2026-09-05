<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Exceptions\ApiException;

/**
 * Legacy static-API shim for MedicationRequest.
 *
 * Delegates every call to the modern instance-based
 * App\Classes\eHealth\Api\Patient\MedicationRequest via EHealth::medicationRequest().
 * This allows MedicationRequestLifecycleService to keep its current static-style call
 * surface while the underlying HTTP transport uses the correct new client.
 */
class MedicationRequest
{
    /**
     * PreQualify Medication Request (API-005-044-0001).
     *
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function preQualify(array $payload): array
    {
        return EHealth::medicationRequest()->prequalify($payload)->getData();
    }

    /**
     * Create Medication Request (draft) (API-005-044-0002).
     *
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function createMedicationRequest(array $payload): array
    {
        return EHealth::medicationRequest()->createRequest($payload)->getData();
    }

    /**
     * Sign Medication Request (API-005-044-0006).
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function signMedicationRequest(string $id, array $payload): array
    {
        return EHealth::medicationRequest()->signRequest($id, $payload)->getData();
    }

    /**
     * Reject Medication Request (ACTIVE) (API-005-043-0006).
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function rejectMedicationRequest(string $id, array $payload): array
    {
        // Person UUID is required by the new API; resolve from the request payload if present.
        $personUuid = $payload['person_id'] ?? '';

        return EHealth::medicationRequest()->reject($personUuid, $id, $payload)->getData();
    }

    /**
     * Reject un-signed Medication Request (NEW) (API-005-044-0007).
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function rejectUnsignedMedicationRequest(string $id, array $payload): array
    {
        return EHealth::medicationRequest()->rejectRequest($id)->getData();
    }

    /**
     * Get a Medication Request by ID (API-005-043-0002).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     * @throws ApiException
     */
    public static function getById(string $patientId, string $id, array $query = []): array
    {
        return EHealth::medicationRequest()->getById($patientId, $id, $query)->getData();
    }

    /**
     * Get Medication Requests by search parameters (API-005-043-0001).
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return array
     * @throws ApiException
     */
    public static function getBySearchParams(string $patientId, array $query = []): array
    {
        return EHealth::medicationRequest()->getBySearchParams($patientId, $query)->getData();
    }

    /**
     * Get Medication Request Requests by search parameters (API-005-044-0003).
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return array
     * @throws ApiException
     */
    public static function getRequestsBySearchParams(string $patientId, array $query = []): array
    {
        return EHealth::medicationRequest()->getRequestsBySearchParams($patientId, $query)->getData();
    }

    /**
     * Resend SMS code for active Medication Request.
     */
    public static function resendSms(string $patientId, string $id): array
    {
        return EHealth::medicationRequest()->resendSms($patientId, $id)->getData();
    }

    /**
     * Qualify Medication Request by ID.
     */
    public static function qualify(string $id, array $payload = []): array
    {
        return EHealth::medicationRequest()->qualify($id, $payload)->getData();
    }

    /**
     * Get Medication Requests in Care Plan context.
     */
    public static function getByCarePlan(string $carePlanId, array $query = []): array
    {
        return EHealth::medicationRequest()->getByCarePlan($carePlanId, $query)->getData();
    }

    /**
     * Get Medication Request details (dispenses/redemptions).
     */
    public static function getDetails(string $patientId, string $id, array $query = []): array
    {
        return EHealth::medicationRequest()->getDetails($patientId, $id, $query)->getData();
    }

    /**
     * Get Medication Request printout form.
     */
    public static function getPrintoutForm(string $patientId, string $id): array
    {
        return EHealth::medicationRequest()->getPrintoutForm($patientId, $id)->getData();
    }

    /**
     * Block Medication Request.
     */
    public static function block(string $patientId, string $id, array $payload = []): array
    {
        return EHealth::medicationRequest()->block($patientId, $id, $payload)->getData();
    }

    /**
     * Unblock Medication Request.
     */
    public static function unblock(string $patientId, string $id, array $payload = []): array
    {
        return EHealth::medicationRequest()->unblock($patientId, $id, $payload)->getData();
    }

    /**
     * Get Medication Request details in composition context.
     */
    public static function getInCompositionContext(string $patientId, string $compositionId, string $id, array $query = []): array
    {
        return EHealth::medicationRequest()->getInCompositionContext($patientId, $compositionId, $id, $query)->getData();
    }

    /**
     * Get Medication Request by ID by pharmacy user.
     */
    public static function getByIdByPharmacy(string $id, array $query = []): array
    {
        return EHealth::medicationRequest()->getByIdByPharmacy($id, $query)->getData();
    }

    /**
     * Block Medication Request by pharmacy user.
     */
    public static function blockByPharmacy(string $id, array $payload = []): array
    {
        return EHealth::medicationRequest()->blockByPharmacy($id, $payload)->getData();
    }

    /**
     * Unblock Medication Request by pharmacy user.
     */
    public static function unblockByPharmacy(string $id, array $payload = []): array
    {
        return EHealth::medicationRequest()->unblockByPharmacy($id, $payload)->getData();
    }

    /**
     * Reject Medication Request by pharmacy user.
     */
    public static function rejectByPharmacy(string $id, array $payload = []): array
    {
        return EHealth::medicationRequest()->rejectByPharmacy($id, $payload)->getData();
    }

    /**
     * Search medication requests as a pharmacy user.
     *
     * @param  array<string, mixed>  $query
     * @return array
     */
    public static function searchByPharmacy(array $query = []): array
    {
        return EHealth::medicationRequest()->searchByPharmacy($query)->getData();
    }
}
