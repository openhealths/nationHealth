<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use Exception;
use App\Classes\eHealth\Api\Decoders\DivisionJsonDecoder;
use App\Classes\eHealth\Api\Encoders\DivisionJsonEncoder;
use App\Traits\WorkTimeUtilities;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Support\Facades\Validator;
use App\Classes\eHealth\EHealthRequest as Request;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Classes\eHealth\Api\Responses\Collections\DivisionCreate;

class Division extends Request
{
    use WorkTimeUtilities;

    public const string URL = '/api/divisions';

    private const string SEARCH_URL = '/reports/stats/divisions';

    public const string ACTIONS_ACTIVATE = '/actions/activate';

    public const string ACTIONS_DEACTIVATE = '/actions/deactivate';

    /**
     * Get list of Divisisons belong to the current LegalEntity
     *
     * Important: If the second parameter `$query` is provided,
     * it will override any previously set query parameters (e.g., via `withQueryParameters()`).
     *
     * If only the URL is provided (i.e., one argument) or nothing, the request will use the internal `$this->options`.
     *
     * @param  string  $url  The request URL.
     * @param  array|null  $query  Optional query parameters. If provided, it replaces any existing 'query' options.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(string $url = self::URL, $query = null): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            $query ?? []
        );

        return parent::get($url, $mergedQuery);
    }

    /**
     * Search the public registry, including divisions of other legal entities.
     * This response is not the administrative Get Divisions schema.
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-portal-and-reports/divisions/search-divisions
     */
    public function search(string $legalEntityUuid, int $page = 1): PromiseInterface|EHealthResponse
    {
        $this->validator = null;

        return parent::get(self::SEARCH_URL, [
            'legal_entity_id' => $legalEntityUuid,
            'page' => $page,
            'page_size' => 100,
        ]);
    }

    /**
     * Get Division details by UUID
     *
     * @param  string  $uuid  The unique identifier of the division.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getDetails(string $uuid): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateOne(...));

        return parent::get(self::URL . '/' . $uuid);
    }

    /**
     * Update the Division
     *
     * @param  string  $uuid
     * @param  mixed  $data  // Data for API request
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function update(?string $uuid, $data = []): PromiseInterface|EHealthResponse
    {
        // If $uuid is missed it means that Division's record was edited is DRAFT (newly created and saved)
        if (!$uuid) {
            return $this->create($data);
        }

        $this->setValidator($this->validateOne(...));

        return $this->withBody((new DivisionJsonEncoder())->encode($data), 'application/json')->patch(self::URL . '/' . $uuid);
    }

    /**
     * Create the Division
     *
     * @param  mixed  $data  // Data for API request
     * @return EHealthResponse|PromiseInterface
     */
    public function create($data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateOne(...));

        return $this->withBody((new DivisionJsonEncoder())->encode($data), 'application/json')->post(self::URL);
    }

    /**
     * @param  string  $uuid  unique eHealth identifier of the license
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException
     */
    public function activate(string $uuid): PromiseInterface|EHealthResponse
    {
        return parent::patch(self::URL . '/' . $uuid . self::ACTIONS_ACTIVATE);
    }

    /**
     * @param  string  $uuid  unique eHealth identifier of the license
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException
     */
    public function deactivate(string $uuid): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateOne(...));

        return parent::patch(self::URL . '/' . $uuid . self::ACTIONS_DEACTIVATE);
    }

    /**
      * Schema Create/Update Division
      *
       * @return array
      */
    public static function schemaRequest(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string'
                ],
                'addresses' => [
                    'type' => 'array'
                ],
                'phones' => [
                    'type' => 'array'
                ],
                'email' => [
                    'type' => 'string'
                ],
                'working_hours' => [
                    'type' => 'object',
                    'properties' => [
                        'mon' => [
                            'type' => 'array'
                        ],
                        'tue' => [
                            'type' => 'array'
                        ],
                        'wed' => [
                            'type' => 'array'
                        ],
                        'thu' => [
                            'type' => 'array'
                        ],
                        'fri' => [
                            'type' => "array"
                        ],
                        'sat' => [
                            'type' => 'array'
                        ],
                        'sun' => [
                            'type' => 'array'
                        ]
                    ]
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => [
                        'ACTIVE',
                        'INACTIVE',
                        'DRAFT',
                        'UNSYNCED'
                    ]
                ],
                'legal_entity_id' => [
                    'type' => 'string'
                ],
                'external_id' => [
                    'type' => 'string'
                ],
                'location' => [
                    'type' => 'object',
                    'properties' => [
                        'latitude' => [
                            'type' => 'number'
                        ],
                        'longitude' => [
                            'type' => 'number'
                        ]
                    ],
                    'required' => [
                        'latitude',
                        'longitude'
                    ]
                ]
            ],
            'required' => [
                'name',
                'addresses',
                'phones',
                'email',
                'type'
            ]
        ];
    }

    /**
     * validate get Divisions input,
     * see: https://esoz.docs.apiary.io/#reference/administration/divisions/get-divisions
     */
    protected function validateMany(EHealthResponse $response): array
    {
        if (!$response->successful()) {
            throw new Exception('validateMany: ' . $response->body());
        }

        $replaced = [];

        $divisionsList = (new DivisionJsonDecoder())->decode($response, list: true);

        $validationRules = ['*' => 'required|array'];

        foreach ($this->getValidationRules() as $key => $rule) {
            $validationRules["*.{$key}"] = $rule;
        }

        foreach ($divisionsList as $data) {
            $replaced[] = self::replaceEHealthPropNames($data);
        }

        $validator = Validator::make($replaced, $validationRules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * Validate single division response data
     * see; https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/divisions/get-division-details
     */
    protected function validateOne(EHealthResponse $response): DivisionCreate
    {
        if (!$response->successful()) {
            throw new Exception('validateOne: ' . $response->body());
        }

        $data = (new DivisionJsonDecoder())->decode($response);

        $replaced = self::replaceEHealthPropNames($data);

        $validator = Validator::make($replaced, $this->getValidationRules());

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return new DivisionCreate($validator->validate());
    }

    /**
     * Returns the validation rules array used for validating healthcare service data.
     *
     * @return array An associative array containing validation rules for healthcare service fields
     */
    protected function getValidationRules(): array
    {
        return [
            'addresses' => 'required|array',
            'addresses.*.apartment' => 'nullable|string',
            'addresses.*.area' => 'required|string',
            'addresses.*.building' => 'nullable|string',
            'addresses.*.country' => 'required|string',
            'addresses.*.region' => 'nullable|string',
            'addresses.*.settlement' => 'required|string',
            'addresses.*.settlement_id' => 'required|string',
            'addresses.*.settlement_type' => 'required|string',
            'addresses.*.street' => 'nullable|string',
            'addresses.*.street_type' => 'nullable|string',
            'addresses.*.type' => 'required|string',
            'addresses.*.zip' => 'nullable|string',
            'uuid' => 'required|uuid',
            'email' => 'required|string',
            'external_id' => 'nullable|string',
            'location' => 'nullable|array',
            'location.latitude' => 'required_with:location|numeric',
            'location.longitude' => 'required_with:location|numeric',
            'mountain_group' => 'sometimes|boolean',
            'name' => 'required|string',
            'phones' => 'required|array',
            'phones.*.number' => 'required|string',
            'phones.*.type' => 'required|string',
            'phones.*.note' => 'sometimes|string',
            'status' => 'required|string',
            'type' => 'required|string',
            'dls_id' => 'nullable|string',
            'dls_verified' => 'nullable|boolean',
            'working_hours' => 'nullable|array',
            'working_hours.sun' => 'nullable|array',
            'working_hours.sun.*.0' => 'required|string',
            'working_hours.sun.*.1' => 'required|string',
            'working_hours.mon' => 'nullable|array',
            'working_hours.mon.*.0' => 'required|string',
            'working_hours.mon.*.1' => 'required|string',
            'working_hours.tue' => 'nullable|array',
            'working_hours.tue.*.0' => 'required|string',
            'working_hours.tue.*.1' => 'required|string',
            'working_hours.wed' => 'nullable|array',
            'working_hours.wed.*.0' => 'required|string',
            'working_hours.wed.*.1' => 'required|string',
            'working_hours.thu' => 'nullable|array',
            'working_hours.thu.*.0' => 'required|string',
            'working_hours.thu.*.1' => 'required|string',
            'working_hours.fri' => 'nullable|array',
            'working_hours.fri.*.0' => 'required|string',
            'working_hours.fri.*.1' => 'required|string',
            'working_hours.sat' => 'nullable|array',
            'working_hours.sat.*.0' => 'required|string',
            'working_hours.sat.*.1' => 'required|string',

            'ehealth_inserted_at' => 'nullable|date',
            'inserted_by' => 'nullable|uuid',
            'ehealth_updated_at' => 'nullable|date',
            'updated_by' => 'nullable|uuid',

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
                'legal_entity_id' => 'legal_entity_uuid',
                'inserted_at' => 'ehealth_inserted_at',
                'updated_at' => 'ehealth_updated_at',
                default => $name
            };

            $replaced[$newName] = $value;

            // TODO: remove it if future use shows that it is not needed.
            if (is_array($value)) {
                $replaced[$newName] = self::replaceEHealthPropNames($value);
            }
        }

        return $replaced;
    }
}
