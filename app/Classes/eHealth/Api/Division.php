<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\Api\Responses\Collections\DivisionCreate;
use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\Division\Location as LocationData;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\Division as DivisionModel;
use App\Models\LegalEntity;
use App\Traits\WorkTimeUtilities;
use Brick\Math\BigDecimal;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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
        $this->setValidator(self::validateSearch(...));

        return parent::get(self::SEARCH_URL, [
            'legal_entity_id' => $legalEntityUuid,
            'page' => $page,
            'page_size' => 100,
        ]);
    }

    /** Public Search Divisions schema, General MIS snapshot 2026-10-22. */
    public static function validateSearch(EHealthResponse $response): array
    {
        Validator::make($response->json(), [
            'meta' => ['sometimes', 'array'],
            'meta.code' => ['sometimes'],
            'meta.url' => ['sometimes'],
            'meta.type' => ['sometimes'],
            'meta.request_id' => ['sometimes'],
            'data' => ['present', 'array', 'list'],
            'data.*' => ['present_with:data', 'array'],
            'data.*.id' => ['present_with:data.*', 'string'],
            'data.*.name' => ['present_with:data.*', 'string'],
            'data.*.type' => ['present_with:data.*', 'string', 'in:CLINIC,AMBULANT_CLINIC,FAP'],
            'data.*.addresses' => ['sometimes', 'array'],
            'data.*.addresses.type' => ['sometimes'],
            'data.*.addresses.country' => ['present_with:data.*.addresses'],
            'data.*.addresses.area' => ['sometimes'],
            'data.*.addresses.region' => ['sometimes'],
            'data.*.addresses.settlement' => ['sometimes'],
            'data.*.addresses.settlement_type' => ['sometimes'],
            'data.*.addresses.settlement_id' => ['sometimes'],
            'data.*.addresses.street_type' => ['sometimes'],
            'data.*.addresses.street' => ['sometimes'],
            'data.*.addresses.building' => ['sometimes'],
            'data.*.addresses.apartment' => ['sometimes'],
            'data.*.addresses.zip' => ['sometimes'],
            'data.*.contacts' => ['sometimes', 'array'],
            'data.*.contacts.email' => ['sometimes', 'string'],
            'data.*.contacts.phones' => ['sometimes', 'array', 'list'],
            'data.*.contacts.phones.*' => ['present_with:data.*.contacts.phones', 'array'],
            'data.*.contacts.phones.*.type' => ['present_with:data.*.contacts.phones.*'],
            'data.*.contacts.phones.*.number' => ['present_with:data.*.contacts.phones.*'],
            'data.*.coordinates' => ['sometimes', 'array'],
            'data.*.coordinates.latitude' => ['present_with:data.*.coordinates', 'numeric'],
            'data.*.coordinates.longitude' => ['present_with:data.*.coordinates', 'numeric'],
            'data.*.legal_entity' => ['present_with:data.*', 'array'],
            'data.*.legal_entity.name' => ['present_with:data.*.legal_entity', 'string'],
            'data.*.legal_entity.short_name' => ['sometimes', 'string'],
            'data.*.legal_entity.public_name' => ['sometimes', 'string'],
            'data.*.legal_entity.type' => ['present_with:data.*.legal_entity', 'string'],
            'data.*.legal_entity.owner_property_type' => ['present_with:data.*.legal_entity', 'string'],
            'data.*.legal_entity.legal_form' => ['present_with:data.*.legal_entity', 'string'],
            'data.*.legal_entity.edrpou' => ['present_with:data.*.legal_entity', 'string'],
            'data.*.legal_entity.kveds' => ['sometimes', 'string'],
            'data.*.legal_entity.addresses' => ['present_with:data.*.legal_entity', 'array', 'list'],
            'data.*.legal_entity.addresses.*' => ['present_with:data.*.legal_entity.addresses', 'array'],
            'data.*.legal_entity.addresses.*.type' => ['sometimes'],
            'data.*.legal_entity.addresses.*.country' => ['present_with:data.*.legal_entity.addresses.*'],
            'data.*.legal_entity.addresses.*.area' => ['sometimes'],
            'data.*.legal_entity.addresses.*.region' => ['sometimes'],
            'data.*.legal_entity.addresses.*.settlement' => ['sometimes'],
            'data.*.legal_entity.addresses.*.settlement_type' => ['sometimes'],
            'data.*.legal_entity.addresses.*.settlement_id' => ['sometimes'],
            'data.*.legal_entity.addresses.*.street_type' => ['sometimes'],
            'data.*.legal_entity.addresses.*.street' => ['sometimes'],
            'data.*.legal_entity.addresses.*.building' => ['sometimes'],
            'data.*.legal_entity.addresses.*.apartment' => ['sometimes'],
            'data.*.legal_entity.addresses.*.zip' => ['sometimes'],
            'data.*.legal_entity.phones' => ['present_with:data.*.legal_entity', 'array', 'list'],
            'data.*.legal_entity.phones.*' => ['present_with:data.*.legal_entity.phones', 'array'],
            'data.*.legal_entity.phones.*.type' => ['present_with:data.*.legal_entity.phones.*'],
            'data.*.legal_entity.phones.*.number' => ['present_with:data.*.legal_entity.phones.*'],
            'data.*.legal_entity.email' => ['present_with:data.*.legal_entity', 'string'],
            'data.*.legal_entity.nhs_verified' => ['sometimes', 'string'],
            'data.*.legal_entity.owner' => ['sometimes', 'array'],
            'data.*.legal_entity.owner.id' => ['present_with:data.*.legal_entity.owner', 'string'],
            'data.*.legal_entity.owner.position' => ['present_with:data.*.legal_entity.owner', 'string'],
            'data.*.legal_entity.owner.employee_type' => ['present_with:data.*.legal_entity.owner', 'string'],
            'data.*.legal_entity.owner.party' => ['sometimes', 'array'],
            'data.*.legal_entity.owner.party.id' => ['present_with:data.*.legal_entity.owner.party', 'string'],
            'data.*.legal_entity.owner.party.first_name' => ['present_with:data.*.legal_entity.owner.party', 'string'],
            'data.*.legal_entity.owner.party.second_name' => ['present_with:data.*.legal_entity.owner.party', 'string'],
            'data.*.legal_entity.owner.party.last_name' => ['present_with:data.*.legal_entity.owner.party', 'string'],
            'data.*.healthcare_services' => ['present_with:data.*', 'array', 'list'],
            'data.*.healthcare_services.*' => ['present_with:data.*.healthcare_services', 'array'],
            'data.*.healthcare_services.*.id' => ['present_with:data.*.healthcare_services.*', 'string'],
            'data.*.healthcare_services.*.division_id' => ['present_with:data.*.healthcare_services.*', 'string'],
            'data.*.healthcare_services.*.legal_entity_id' => ['present_with:data.*.healthcare_services.*', 'string'],
            'data.*.healthcare_services.*.license_id' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.speciality_type' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.providing_condition' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.category' => ['present_with:data.*.healthcare_services.*', 'array'],
            'data.*.healthcare_services.*.category.coding' => ['present_with:data.*.healthcare_services.*.category', 'array', 'list'],
            'data.*.healthcare_services.*.category.coding.*' => ['present_with:data.*.healthcare_services.*.category.coding', 'array'],
            'data.*.healthcare_services.*.category.coding.*.system' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.category.coding.*.code' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.category.text' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.type' => ['sometimes', 'array'],
            'data.*.healthcare_services.*.type.coding' => ['present_with:data.*.healthcare_services.*.type', 'array', 'list'],
            'data.*.healthcare_services.*.type.coding.*' => ['present_with:data.*.healthcare_services.*.type.coding', 'array'],
            'data.*.healthcare_services.*.type.coding.*.system' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.type.coding.*.code' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.type.text' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.status' => ['present_with:data.*.healthcare_services.*', 'string'],
            'data.*.healthcare_services.*.comment' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.coverage_area' => ['sometimes', 'array', 'list'],
            'data.*.healthcare_services.*.coverage_area.*' => ['present_with:data.*.healthcare_services.*.coverage_area', 'string'],
            'data.*.healthcare_services.*.available_time' => ['sometimes', 'array', 'list'],
            'data.*.healthcare_services.*.available_time.*' => ['present_with:data.*.healthcare_services.*.available_time', 'array'],
            'data.*.healthcare_services.*.available_time.*.days_of_week' => ['present_with:data.*.healthcare_services.*.available_time.*', 'array', 'list'],
            'data.*.healthcare_services.*.available_time.*.days_of_week.*' => ['present_with:data.*.healthcare_services.*.available_time.*.days_of_week', 'string'],
            'data.*.healthcare_services.*.available_time.*.all_day' => ['sometimes', 'boolean'],
            'data.*.healthcare_services.*.available_time.*.available_start_time' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.available_time.*.available_end_time' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.not_available' => ['sometimes', 'array', 'list'],
            'data.*.healthcare_services.*.not_available.*' => ['present_with:data.*.healthcare_services.*.not_available', 'array'],
            'data.*.healthcare_services.*.not_available.*.description' => ['present_with:data.*.healthcare_services.*.not_available.*', 'string'],
            'data.*.healthcare_services.*.not_available.*.during' => ['present_with:data.*.healthcare_services.*.not_available.*', 'array'],
            'data.*.healthcare_services.*.not_available.*.during.start' => ['present_with:data.*.healthcare_services.*.not_available.*.during', 'string'],
            'data.*.healthcare_services.*.not_available.*.during.end' => ['present_with:data.*.healthcare_services.*.not_available.*.during', 'string'],
            'data.*.healthcare_services.*.licensed_healthcare_service' => ['sometimes', 'array'],
            'data.*.healthcare_services.*.licensed_healthcare_service.status' => ['sometimes', 'string'],
            'data.*.healthcare_services.*.licensed_healthcare_service.updated_at' => ['present_with:data.*.healthcare_services.*.licensed_healthcare_service', 'string'],
            'data.*.healthcare_services.*.is_active' => ['present_with:data.*.healthcare_services.*', 'boolean'],
            'data.*.healthcare_services.*.inserted_at' => ['present_with:data.*.healthcare_services.*', 'string'],
            'data.*.healthcare_services.*.inserted_by' => ['present_with:data.*.healthcare_services.*', 'string'],
            'data.*.healthcare_services.*.updated_at' => ['present_with:data.*.healthcare_services.*', 'string'],
            'data.*.healthcare_services.*.updated_by' => ['present_with:data.*.healthcare_services.*', 'string'],
            'paging' => ['required', 'array'],
            'paging.page_number' => ['required', 'integer', 'min:1'],
            'paging.page_size' => ['required', 'integer', 'min:1'],
            'paging.total_entries' => ['required', 'integer', 'min:0'],
            'paging.total_pages' => ['required', 'integer', 'min:0'],
        ])->validate();

        return $response->getData();
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

        return $this->withBody($this->encodeRequestData($data), 'application/json')->patch(self::URL . '/' . $uuid);
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

        return $this->withBody($this->encodeRequestData($data), 'application/json')->post(self::URL);
    }

    /**
     * Encode coordinates as JSON numbers without a float conversion losing their digits.
     *
     * @param  array<string, mixed>  $data
     */
    protected function encodeRequestData(array $data): string
    {
        $fields = [];

        foreach ($data as $key => $value) {
            if ($key === 'location' && \is_array($value)) {
                $coordinates = [];

                foreach ($value as $axis => $coordinate) {
                    $number = \in_array($axis, ['latitude', 'longitude'], true) && $coordinate !== null
                        ? (string) BigDecimal::of(LocationData::normalizeCoordinate($coordinate))
                        : json_encode($coordinate, JSON_THROW_ON_ERROR);

                    $coordinates[] = json_encode($axis, JSON_THROW_ON_ERROR) . ':' . $number;
                }

                $encoded = '{' . implode(',', $coordinates) . '}';
            } else {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            }

            $fields[] = json_encode($key, JSON_THROW_ON_ERROR) . ':' . $encoded;
        }

        return '{' . implode(',', $fields) . '}';
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
     * Normalizes division response data for database upsert operation.
     *
     * This method transforms the division data received from eHealth API to a format
     * suitable for bulk upsert operation by:
     * 1. Removing nested relationship data (legal_entity_uuid, addresses, phones)
     * 2. Setting the legal_entity_id from the current legal entity
     * 3. Converting JSON-serializable fields (location, working_hours) to JSON strings
     *
     * @param  array  $divisionsList  Array of division data from eHealth API
     * @return array Normalized array ready for database upsert operation
     */
    public function normalizeResponseDataForUpsert(array $divisionsList, LegalEntity $legalEntity): array
    {
        foreach ($divisionsList as $index => $division) {
            unset($divisionsList[$index]['legal_entity_uuid']);
            unset($divisionsList[$index]['addresses']);
            unset($divisionsList[$index]['phones']);

            $divisionsList[$index]['legal_entity_id'] = $legalEntity->id;

            $divisionsList[$index]['location'] = empty($division['location'])
                ? null
                : json_encode($division['location']);

            $divisionsList[$index]['working_hours'] = empty($division['working_hours'])
                ? null
                : json_encode($division['working_hours']);
        }

        return $divisionsList;
    }

    /**
     * Prepares relationship data (table: 'addresses' and 'phones') for bulk database insertion.
     *
     * This method processes a list of division data from the eHealth API,
     * extracts the nested 'addresses' and 'phones' arrays, and transforms them
     * into a flat structure suitable for a bulk `insert()` operation. It uses a
     * provided map to link the eHealth UUIDs to local database primary keys.
     *
     * @param  array  $dvisionsData  The raw division data list from the eHealth API.
     * @param  Collection  $divisionIds  A collection mapping UUIDs (keys) to local division IDs (values).
     * @return array An associative array containing 'divisionIds', 'addresses', and 'phones' data ready for the repository.
     */
    public static function getRelationshipData(array $dvisionsData, Collection $divisionIds): array
    {
        $divisionIdsArray = $divisionIds->values()->toArray();

        $now = now();

        $addressesData = [];
        $phonesData = [];

        foreach ($dvisionsData as $division) {
            $divisionId = $divisionIds->get($division['uuid']);

            // ADDRESSES PREPARE
            foreach ($division['addresses'] ?? [] as $address) {
                $addressesData[] = [
                    'type' => $address['type'],
                    'country' => $address['country'],
                    'area' => $address['area'],
                    'region' => $address['region'] ?? null,
                    'settlement' => $address['settlement'],
                    'settlement_type' => $address['settlement_type'],
                    'settlement_id' => $address['settlement_id'],
                    'street_type' => $address['street_type'] ?? null,
                    'street' => $address['street'] ?? null,
                    'building' => $address['building'] ?? null,
                    'apartment' => $address['apartment'] ?? null,
                    'zip' => $address['zip'] ?? null,
                    'addressable_id' => $divisionId,
                    'addressable_type' => DivisionModel::class,
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }

            // PHONES PREPARE
            foreach ($division['phones'] ?? [] as $phone) {
                $phonesData[] = [
                    'type' => $phone['type'],
                    'number' => $phone['number'],
                    'phoneable_id' => $divisionId,
                    'phoneable_type' => DivisionModel::class,
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }
        }

        return [
            'divisionIds' => $divisionIdsArray,
            'addresses' => $addressesData,
            'phones' => $phonesData
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

        $divisionsList = $response->getData();

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

        $data = $response->getData();

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
