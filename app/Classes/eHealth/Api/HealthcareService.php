<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\Api\Responses\Collections\HealthcareServiceDetails;
use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\HealthcareServiceErrorTranslator;
use App\Contracts\ApiErrorTranslator;
use App\Enums\HealthcareService\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Rules\InDictionary;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class HealthcareService extends Request
{
    protected const string URL = '/api/healthcare_services';

    protected const string ACTIONS_ACTIVATE = '/actions/activate';

    protected const string ACTIONS_DEACTIVATE = '/actions/deactivate';

    /**
     * Get list of Healthcare Services.
     *
     * @param  array{
     *     division_id?: string,
     *     speciality_type?: string, // Dictionary SPECIALITY_TYPE
     *     providing_condition?: string, // Dictionary PROVIDING_CONDITION
     *     category?: string, // Dictionary HEALTHCARE_SERVICE_CATEGORIES
     *     type?: string, // Dictionary of types for category
     *     status?: value-of<Status>,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $this->setValidator($this->validateMany(...));

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL, $mergedQuery);
    }

    /**
     * Get healthcare service details by ID.
     *
     * @param  string  $uuid  unique eHealth identifier
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getById(string $uuid): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->get(self::URL . '/' . $uuid);
    }

    /**
     * Update existing Healthcare service.
     * There are some mutable attributes in Healthcare service:comment, available_time, not_available.
     * All other attributes are immutable.
     *
     * @param  string  $uuid
     * @param  array  $data  // Data for API request
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function update(string $uuid, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->patch(self::URL . '/' . $uuid, $data);
    }

    /**
     * Create the Healthcare Service.
     *
     * @param  array  $data  // Data for API request
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->post(self::URL, $data);
    }

    /**
     * Activate previously deactivated healthcare service for the division of legal entity.
     *
     * @param  string  $uuid  unique eHealth identifier
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function activate(string $uuid): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->patch(self::URL . '/' . $uuid . self::ACTIONS_ACTIVATE);
    }

    /**
     * Deactivate healthcare service for the division of a legal entity.
     *
     * @param  string  $uuid  unique eHealth identifier
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function deactivate(string $uuid): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->patch(self::URL . '/' . $uuid . self::ACTIONS_DEACTIVATE);
    }

    /**
     * Get the translator of the errors the healthcare service resource returns.
     *
     * @return ApiErrorTranslator
     */
    protected function errorTranslator(): ApiErrorTranslator
    {
        return new HealthcareServiceErrorTranslator();
    }

    /**
     * Validate healthcare service response (get by id, create, update, activate, deactivate).
     */
    protected function validateResponse(EHealthResponse $response): HealthcareServiceDetails
    {
        $data = $response->getData();

        $replaced = self::replaceEHealthPropNames($data);

        $validator = Validator::make($replaced, $this->validationRules());

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return new HealthcareServiceDetails($validator->validate());
    }

    /**
     * Validate list of healthcare services; each item has the same shape as a single healthcare service response.
     *
     * @param  EHealthResponse  $response
     * @return array List of HealthcareServiceDetails
     */
    protected function validateMany(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = self::replaceEHealthPropNames($data);
        }

        // Add *. to every rule
        $rules = collect($this->validationRules())
            ->mapWithKeys(static fn (string|array $rule, string $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return array_map(
            static fn (array $item): HealthcareServiceDetails => new HealthcareServiceDetails($item),
            $validator->validate()
        );
    }

    /**
     * List of validation rules for healthcare service.
     *
     * @return array
     */
    protected function validationRules(): array
    {
        return [
            'available_time' => 'array',
            'available_time.*.all_day' => 'nullable|boolean',
            'available_time.*.available_end_time' => 'required_if:available_time.*.all_day,false|nullable|string',
            'available_time.*.available_start_time' => 'required_if:available_time.*.all_day,false|nullable|string',
            'available_time.*.days_of_week' => 'required|array',
            'available_time.*.days_of_week.*' => 'in:mon,tue,wed,thu,fri,sat,sun',
            'category' => 'required|array',
            'category.coding' => 'required|array',
            'category.coding.*.code' => [
                'required_with:category.coding',
                'string',
                new InDictionary('HEALTHCARE_SERVICE_CATEGORIES')
            ],
            'category.coding.*.system' => 'required_with:category.coding|string',
            'category.text' => 'nullable|string',
            'comment' => 'nullable|string',
            'coverage_area' => 'nullable|array',
            'coverage_area.*' => 'required_with:coverage_area|string',
            'division_id' => 'required|string',
            'uuid' => 'required|string',
            'ehealth_inserted_at' => 'required|date',
            'ehealth_inserted_by' => 'required|uuid',
            'is_active' => 'required|boolean',
            'legal_entity_id' => 'required|string',
            'license_id' => 'nullable|string',
            'licensed_healthcare_service' => 'nullable|array',
            'licensed_healthcare_service.status' => 'nullable|string',
            'licensed_healthcare_service.updated_at' => 'required_with:licensed_healthcare_service|string',
            'not_available' => 'array',
            'not_available.*.description' => 'required_with:not_available|string',
            'not_available.*.during' => 'required_with:not_available|array',
            'not_available.*.during.start' => 'required_with:not_available.*.during|string',
            'not_available.*.during.end' => 'required_with:not_available.*.during|string',
            'providing_condition' => ['nullable', 'string', new InDictionary('PROVIDING_CONDITION')],
            'speciality_type' => ['nullable', 'string', new InDictionary('SPECIALITY_TYPE')],
            'status' => 'required|string',
            'type' => 'nullable|array',
            'type.coding' => 'required_with:type|array',
            'type.coding.*' => 'required_with:type.coding|array',
            'type.coding.*.system' => 'required_with:type.coding.*|string',
            'type.coding.*.code' => [
                'required_with:type.coding.*',
                'string',
                new InDictionary('HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES')
            ],
            'type.text' => 'nullable|string',
            'ehealth_updated_at' => 'required|date',
            'ehealth_updated_by' => 'required|uuid'
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
                'inserted_by' => 'ehealth_inserted_by',
                'updated_at' => 'ehealth_updated_at',
                'updated_by' => 'ehealth_updated_by',
                default => $name
            };

            $replaced[$newName] = $value;
        }

        return $replaced;
    }
}
