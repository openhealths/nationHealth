<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\MedicationResponseContract;
use GuzzleHttp\Promise\PromiseInterface;

class MedicationRequestRequest extends PatientApiBase
{
    /** Read the unsigned request, including the snapshot required for signing. */
    public function getById(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDetails(...));

        return $this->get("/api/persons/{$patientId}/medication_request_requests/{$id}", $query);
    }

    protected function validateDetails(EHealthResponse $response): array
    {
        return MedicationResponseContract::validate($response, 'request_detail');
    }
}
