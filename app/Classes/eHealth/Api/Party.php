<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Handles API requests related to the eHealth 'Party' and 'Party Verification' endpoints.
 */
class Party extends EHealthRequest
{
    /**
     * The base URL for the party endpoints.
     */
    protected const string URL = '/api/parties';

    /**
     * Fetches the detailed verification status for a single party.
     * Scope: party_verification:details — GET /api/parties/{id}/verification
     *
     * @param  string  $uuid  The UUID of the party.
     * @param  array|null  $query  Optional query parameters.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException
     */
    public function getDetails(string $uuid, ?array $query = null): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDetails(...));

        return $this->get(self::URL . '/' . $uuid . '/verification', $query);
    }

    /**
     * Sends a request to update a party's verification status.
     *
     * @param  string  $uuid  The UUID of the party to update.
     * @param  array  $data  The data for the update request.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException
     */
    public function update(string $uuid, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDetails(...));

        return $this->patch(self::URL . '/' . $uuid . '/verification', $data);
    }

    /**
     * Validates the response for a single party's verification details.
     *
     * @param  EHealthResponse  $response  The response from the eHealth API.
     * @return array
     * @throws ValidationException
     */
    protected function validateDetails(EHealthResponse $response): array
    {
        $data = $response->getData();

        $rules = [
            'verification_status' => 'required|string',
            'details' => 'required|array',

            // DRFO block
            'details.drfo' => 'present|array',
            'details.drfo.verification_status' => 'string',
            'details.drfo.verification_reason' => 'nullable|string',
            'details.drfo.result' => 'nullable|numeric',

            // DRACS Death block
            'details.dracs_death' => 'present|array',
            'details.dracs_death.verification_status' => 'string',
            'details.dracs_death.verification_reason' => 'nullable|string',
            'details.dracs_death.verification_comment' => 'nullable|string',

            // MVS Passport block
            'details.mvs_passport' => 'present|array',
            'details.mvs_passport.verification_status' => 'string',
            'details.mvs_passport.verification_reason' => 'nullable|string',
            'details.mvs_passport.status' => 'nullable|numeric',

            // DMS Passport block
            'details.dms_passport' => 'present|array',
            'details.dms_passport.verification_status' => 'string',
            'details.dms_passport.verification_reason' => 'nullable|string',
            'details.dms_passport.status' => 'nullable|numeric',

            // DRACS Name Change block
            'details.dracs_name_change' => 'present|array',
            'details.dracs_name_change.verification_status' => 'string',
            'details.dracs_name_change.verification_reason' => 'nullable|string',
            'details.dracs_name_change.verification_comment' => 'nullable|string',
        ];

        return Validator::make($data, $rules)->validated();
    }
}
