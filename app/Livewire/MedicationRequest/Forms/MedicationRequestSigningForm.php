<?php

declare(strict_types=1);

namespace App\Livewire\MedicationRequest\Forms;

use Illuminate\Support\Facades\Log;

use Livewire\Form;
use RuntimeException  ;

class MedicationRequestSigningForm extends Form
{
    public static function signableDetail(array $detail, string $personUuid, string $requestUuid): array
    {
        $requiredFields = [
            'request_number', 'created_at', 'started_at', 'ended_at',
            'dispense_valid_from', 'dispense_valid_to', 'legal_entity', 'division',
            'employee', 'person', 'medication_info', 'medical_program',
            'intent', 'category', 'dosage_instruction',
        ];
        $checks = [
            'requestIdMatches' => ($detail['id'] ?? null) === $requestUuid,
            'patientIdMatches' => data_get($detail, 'person.id') === $personUuid
                && (!array_key_exists('person_id', $detail) || $detail['person_id'] === $personUuid),
            'statusIsNew' => strtoupper((string) ($detail['status'] ?? '')) === 'NEW',
            'detailIsComplete' => array_diff($requiredFields, array_keys($detail)) === []
                && is_array($detail['medication_info'] ?? null) && $detail['medication_info'] !== []
                && is_array($detail['dosage_instruction'] ?? null) && $detail['dosage_instruction'] !== [],
            'seedIsValidWhenPresent' => !array_key_exists('seed', $detail)
                || (is_string($detail['seed']) && trim($detail['seed']) !== ''),
        ];
        $messages = [
            'requestIdMatches' => 'signing_details_wrong_request',
            'patientIdMatches' => 'signing_details_wrong_patient',
            'statusIsNew' => 'signing_details_not_new',
            'detailIsComplete' => 'signing_details_incomplete',
            'seedIsValidWhenPresent' => 'signing_details_invalid_seed',
        ];
        foreach ($checks as $check => $valid) {
            if (!$valid) {
                Log::warning('Medication request signing snapshot rejected', $checks);

                throw new RuntimeException(__('medication-requests.' . $messages[$check]));
            }
        }

        return $detail;
    }
}
