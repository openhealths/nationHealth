<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;

class MedicationDispense extends PatientApiBase
{
    /**
     * Create a medication dispense draft (pharmacy redemption).
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post('/api/pharmacy/medication_dispenses', $payload);
    }

    /**
     * Sign and process a medication dispense (complete the redemption).
     *
     * @param  array<string, mixed>  $payload
     */
    public function process(string $id, array $payload): PromiseInterface|EHealthResponse
    {
        if (isset($payload['signed_content']) && !isset($payload['signed_medication_dispense'])) {
            $payload['signed_medication_dispense'] = $payload['signed_content'];
            unset($payload['signed_content']);
        }

        if (!isset($payload['signed_content_encoding'])) {
            $payload['signed_content_encoding'] = 'base64';
        }

        return $this->patch("/api/pharmacy/medication_dispenses/{$id}/actions/process", $payload);
    }
}
