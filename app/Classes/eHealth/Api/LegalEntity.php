<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Illuminate\Support\Facades\Log;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Client\ConnectionException;
use App\Classes\eHealth\EHealthRequest as Request;

class LegalEntity extends Request
{
    protected const string URL = '/api/v2/legal_entities';

    /**
     * Get full details data of a current legal entity
     *
     * @param  string  $url
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getDetails(string $url = self::URL): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->get($url . '/' . legalEntity()->uuid);
    }

    /**
     * Validate healthcare service response (create, activate, deactivate),
     * see: https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/healthcare-services/create-healthcare-service
     */
    protected function validateResponse(EHealthResponse $response): array
    {
        $data = $response->getData();

        $replaced = self::replaceEHealthPropNames($data);

        $validator = Validator::make($replaced, $this->validationRules());

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for healthcare service.
     *
     * @return array
     */
    protected function validationRules(): array
    {
        return [
            // Summary Data Block
            'accreditation' => 'nullable|array',
            'accreditation.category' => 'required_with:accreditation|string',
            'accreditation.order_no' => 'required_with:accreditation|string',
            'accreditation.issued_date' => 'nullable|string|date',
            'accreditation.order_date' => 'nullable|string|date',
            'accreditation.expiry_date' => 'nullable|string|date',

            'archive' => 'nullable|array',
            'archive.*.date' => 'required_with:archive|string|date',
            'archive.*.place' => 'required_with:archive|string',

            'beneficiary' => "nullable|string",

            'edr' => 'required|array',
            'edr.edrpou' => "required|string",
            'edr.uuid' => "required|string",
            'edr.kveds' => 'required|array',
            'edr.kveds.*.code' => 'required|string',
            'edr.kveds.*.is_primary' => 'required|boolean',
            'edr.kveds.*.name' => 'required|string',
            'edr.legal_form' => 'nullable|string',
            'edr.name' => 'required|string',
            'edr.short_name' => 'nullable|string',
            'edr.public_name' => 'nullable|string',
            'edr.registration_address' => 'required|array',
            'edr.registration_address.address' => 'nullable|string',
            'edr.registration_address.country' => 'nullable|string',
            'edr.registration_address.parts' => 'nullable|array',
            'edr.registration_address.parts.atu' => 'nullable|string',
            'edr.registration_address.parts.atu_code' => 'nullable|string',
            'edr.registration_address.parts.building' => 'nullable|string',
            'edr.registration_address.parts.building_type' => 'nullable|string',
            'edr.registration_address.parts.house' => 'nullable|string',
            'edr.registration_address.parts.house_type' => 'nullable|string',
            'edr.registration_address.parts.num' => 'nullable|string',
            'edr.registration_address.parts.num_type' => 'nullable|string',
            'edr.registration_address.parts.street' => 'nullable|string',
            'edr.registration_address.zip' => 'nullable|string',
            'edr.state' => 'required|integer',

            'edr_verified' => 'nullable|boolean',
            'edrpou' => "required|string",
            'email' => "required|string|email",
            'uuid' => "required|string",
            'is_active' => "required|boolean",

            'license' => 'nullable|array',
            'license.uuid' => 'nullable|string',
            'license.type' => 'nullable|string',
            'license.license_number' => 'nullable|string',
            'license.issued_by' => 'nullable|string',
            'license.issued_date' => 'nullable|string|date',
            'license.issuer_status' => "nullable|string",
            'license.is_active' => "nullable|boolean",
            'license.expiry_date' => 'nullable|string|date',
            'license.active_from_date' => 'nullable|string|date',
            'license.what_licensed' => 'nullable|string',
            'license.order_no' => 'nullable|string',
            'license.ehealth_inserted_at' => 'nullable|date',
            'license.ehealth_inserted_by' => 'nullable|uuid',
            'license.ehealth_updated_at' => 'nullable|date',
            'license.ehealth_updated_by' => 'nullable|uuid',

            'nhs_comment' => "nullable|string",
            'nhs_reviewed' => "required|boolean",
            'nhs_verified' => "required|boolean",

            'phones' => 'required|array',
            'phones.*.number' => 'required|string',
            'phones.*.type' => 'required|string',
            'phones.*.note' => 'sometimes|string',

            'receiver_funds_code' => "nullable|string",

            'residence_address' => 'required|array',
            'residence_address.type' => 'required|string',
            'residence_address.country' => 'required|string',
            'residence_address.area' => 'required|string',
            'residence_address.region' => 'nullable|string',
            'residence_address.settlement' => 'required|string',
            'residence_address.settlement_type' => 'required|string',
            'residence_address.settlement_id' => 'required|string',
            'residence_address.street' => 'nullable|string',
            'residence_address.street_type' => 'nullable|string',
            'residence_address.building' => 'nullable|string',
            'residence_address.apartment' => 'nullable|string',
            'residence_address.zip' => 'nullable|string',

            'status' => "required|string",
            'type' => "required|string",
            'website' => "nullable|string",

            'ehealth_inserted_at' => 'nullable|date',
            'inserted_by' => 'nullable|uuid',
            'ehealth_updated_at' => 'nullable|date',
            'updated_by' => 'nullable|uuid',

            'public_offer' => 'nullable|array',
            'public_offer.consent_text' => 'required_with:public_offer|string',
            'public_offer.consent' => 'required_with:public_offer|boolean',

            // This block may be added to the validation rules via merging data and $this->getUrgent() method
            'security' => 'nullable|array',
            'security.secret_key' => 'required_with:security|string',
            'security.client_id' => 'required_with:security|string',
            'security.redirect_uri' => 'required_with:security|string|url'
        ];
    }

    /**
     * Replace eHealth property names with the ones used in the application.
     * E.g., id => uuid.
     */
    protected static function replaceEHealthPropNames(array $properties): array
    {
        $replaced = [];

        foreach ($properties as $name => $value) {
            $newName = match ($name) {
                'id' => 'uuid',
                'inserted_at' => 'ehealth_inserted_at',
                'updated_at' => 'ehealth_updated_at',
                default => $name
            };

            $replaced[$newName] = $value;

            if (is_array($value)) {
                $replaced[$newName] = self::replaceEHealthPropNames($value);
            }
        }

        return $replaced;
    }
}
