<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Exceptions\ApiException;

/**
 * eHealth API клієнт для погашення та обліку ЕР на ЛЗ (п. 3.5.1 ТЗ)
 */
class MedicationDispense extends EHealth
{
    /**
     * 3.5.1.1: Get Medication Request by ID by Pharmacy User
     */
    public static function getMedicationRequest(string $id): array
    {
        return self::request(
            'GET',
            '/api/medication_requests/' . $id,
            self::getHeaders()
        );
    }

    /**
     * 3.5.1.2: Qualify Medication Request by ID
     */
    public static function qualifyMedicationRequest(string $id, array $payload): array
    {
        return self::request(
            'POST',
            '/api/medication_requests/' . $id . '/qualify',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.1.3: Create Medication Dispense by Pharmacy User (бронювання на 10 хв)
     */
    public static function createMedicationDispense(array $payload): array
    {
        return self::request(
            'POST',
            '/api/medication_dispenses',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.1.3.1.1.5: Process Medication Dispense by Pharmacy User (підписання КЕП)
     */
    public static function processMedicationDispense(string $id, array $payload): array
    {
        if (!isset($payload['signed_content_encoding'])) {
            $payload['signed_content_encoding'] = 'base64';
        }

        return self::request(
            'PATCH',
            '/api/medication_dispenses/' . $id . '/process',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.1.3.1.1.3: Reject Medication Dispense by Pharmacy User
     */
    public static function rejectMedicationDispense(string $id, array $payload = []): array
    {
        return self::request(
            'PATCH',
            '/api/medication_dispenses/' . $id . '/reject',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.1.5: Reject Medication Request by Pharmacy User
     */
    public static function rejectMedicationRequest(string $id, array $payload): array
    {
        return self::request(
            'PATCH',
            '/api/medication_requests/' . $id . '/reject',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.1.6.1.1: Block Medication request by Pharmacy User (резервування)
     */
    public static function blockMedicationRequest(string $id, array $payload): array
    {
        return self::request(
            'PATCH',
            '/api/medication_requests/' . $id . '/block',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.1.6.1.3: Unblock Medication request by Pharmacy User (зняття резервування)
     */
    public static function unblockMedicationRequest(string $id, array $payload): array
    {
        return self::request(
            'PATCH',
            '/api/medication_requests/' . $id . '/unblock',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.1.4.1.1: Get Medication dispenses by search params
     */
    public static function getMedicationDispenses(array $query = []): array
    {
        return self::request(
            'GET',
            '/api/medication_dispenses',
            self::getHeaders(),
            [],
            $query
        );
    }

    /**
     * 3.5.1.4.1.2: Get Medication dispense details
     */
    public static function getMedicationDispenseDetails(string $id): array
    {
        return self::request(
            'GET',
            '/api/medication_dispenses/' . $id,
            self::getHeaders()
        );
    }
}
