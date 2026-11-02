<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\MedicalEventsErrorTranslator;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Contracts\ApiErrorTranslator;
use App\Enums\Device\Status;
use App\Enums\Equipment\Type as DeviceNameType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Device extends PatientApiBase
{
    /**
     * Get a list of summary info about devices.
     *
     * @param  string  $patientId
     * @param array{
     *     type?: string,
     *     asserted_date_from?: string,
     *     asserted_date_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-devices-by-search-params-(summary)
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDevices(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/$patientId/summary/devices", $mergedQuery);
    }

    /**
     * Get devices by search params.
     *
     * @param  string  $patientId
     * @param array{
     *     type?: string,
     *     encounter_id?: string,
     *     episode_id?: string,
     *     definition?: string,
     *     model_number?: string,
     *     manufacturer?: string,
     *     name?: string,
     *     recorder?: string,
     *     recorder_legal_entity_id?: string,
     *     status?: string,
     *     serial_number?: string,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/device/get-devices-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDevices(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, ['inserted_at_from', 'inserted_at_to'])
        );

        return $this->get(self::URL . "/$patientId/devices", $mergedQuery);
    }

    /**
     * Return detail data by ID.
     *
     * @param  string  $patientId
     * @param  string  $deviceId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/device/get-device-by-id
     */
    public function getById(string $patientId, string $deviceId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDevice(...));

        return $this->get(self::URL . "/$patientId/devices/$deviceId");
    }

    /**
     * Return detail data about a device of the patient summary by ID.
     *
     * @param  string  $patientId
     * @param  string  $deviceId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-device-by-id-(summary)
     */
    public function getSummaryById(string $patientId, string $deviceId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDevice(...));

        return $this->get(self::URL . "/$patientId/summary/devices/$deviceId");
    }

    /**
     * Get the translator of the errors the medical events resources return.
     *
     * @return ApiErrorTranslator
     */
    protected function errorTranslator(): ApiErrorTranslator
    {
        return new MedicalEventsErrorTranslator();
    }

    /**
     * Validate a single device from eHealth API response.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDevice(EHealthResponse $response): array
    {
        return $this->runDeviceValidation([$this->replaceEHealthPropNames($response->getData())])[0];
    }

    /**
     * Validate devices collection from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDevices(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        return $this->runDeviceValidation($replaced);
    }

    /**
     * Apply device validation rules to a pre-processed list of device data.
     *
     * @param  array  $replacedItems
     * @return array
     */
    private function runDeviceValidation(array $replacedItems): array
    {
        $rules = collect($this->deviceValidationRules())
            ->mapWithKeys(static fn (array $rule, string $key): array => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replacedItems, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Device validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for devices from eHealth.
     *
     * @return array
     */
    protected function deviceValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            // Basic fields
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['required', Rule::in(Status::values())],
                'primary_source' => ['required', 'boolean'],
                'model_number' => ['nullable', 'string', 'max:255'],
                'lot_number' => ['nullable', 'string', 'max:255'],
                'manufacturer' => ['nullable', 'string', 'max:255'],
                'serial_number' => ['nullable', 'string', 'max:255'],
                'manufacture_date' => ['nullable', 'date'],
                'expiration_date' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
                'ehealth_inserted_at' => ['required', 'date'],
                'ehealth_updated_at' => ['required', 'date']
            ],

            // Identifier relationships
            ValidationRuleBuilder::identifierRules('context', true),
            ValidationRuleBuilder::identifierRules('recorder', true),
            ValidationRuleBuilder::identifierRules('definition'),
            ValidationRuleBuilder::identifierRules('parent'),

            // Codeable concept relationships
            ValidationRuleBuilder::codeableConceptRules('type', true),
            ValidationRuleBuilder::codeableConceptRules('report_origin'),
            ValidationRuleBuilder::codeableConceptRules('status_reason'),

            // Names the device is known by
            [
                'name' => ['required', 'array'],
                'name.*.type' => ['required', Rule::in(DeviceNameType::values())],
                'name.*.value' => ['required', 'string', 'max:255']
            ],

            // Identifiers of the device in external systems
            [
                'identifier' => ['nullable', 'array'],
                'identifier.*.value' => ['required_with:identifier', 'string', 'max:255']
            ],
            ValidationRuleBuilder::codeableConceptRules('identifier.*.type'),

            // Properties, each carrying exactly one value
            [
                'property' => ['nullable', 'array'],
                'property.*.value_boolean' => ['nullable', 'boolean'],
                'property.*.value_integer' => ['nullable', 'integer'],
                'property.*.value_string' => ['nullable', 'string', 'max:255'],
                'property.*.value_range' => ['nullable', 'array']
            ],
            ValidationRuleBuilder::codeableConceptRules('property.*.code', true),
            ValidationRuleBuilder::codeableConceptRules('property.*.value_codeable_concept'),
            $this->quantityRules('property.*.value_quantity'),
            $this->quantityRules('property.*.value_range.low'),
            $this->quantityRules('property.*.value_range.high')
        );
    }

    /**
     * Generate validation rules for a quantity held by a device property.
     *
     * @param  string  $field
     * @return array
     */
    private function quantityRules(string $field): array
    {
        return [
            $field => ['nullable', 'array'],
            "$field.value" => ["required_with:$field", 'numeric'],
            "$field.comparator" => ['nullable', 'string', 'max:255'],
            "$field.unit" => ["required_with:$field", 'string', 'max:255'],
            "$field.system" => ['nullable', 'string', 'max:255'],
            "$field.code" => ['nullable', 'string', 'max:255']
        ];
    }
}
