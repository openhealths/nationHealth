<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Rules\PhoneNumber;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class Verification extends Request
{
    protected const string URL = '/api/verifications';

    /**
     * Initialize OTP verification.
     *
     * @param  array{phone_number: string}  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/otp-verification/initialize-otp-verification
     */
    public function initialize(array $data): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL, $data);
    }

    /**
     * Complete OTP verification.
     *
     * @param  string  $phoneNumber
     * @param  array{code?: int}  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/otp-verification/complete-otp-verification
     */
    public function complete(string $phoneNumber, array $data): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/$phoneNumber/actions/complete", $data);
    }

    /**
     * Find verifications by phone number.
     *
     * @param  string  $phoneNumber
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/otp-verification/find-verifications-by-phone-number
     */
    public function findByPhoneNumber(string $phoneNumber, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateFindByPhoneNumber(...));

        return $this->get(self::URL . "/$phoneNumber", $query);
    }

    protected function validateFindByPhoneNumber(EHealthResponse $response): array
    {
        $data = $response->getData();

        $validator = Validator::make($data, ['phone_number' => ['required', new PhoneNumber()]]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }
}
