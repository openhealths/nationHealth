<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeviceDefinition extends Request
{
    protected const string URL = '/api/v2/device_definitions';

    /**
     * Search all active device definitions in the system.
     *
     * @param  array{
     *     classification_type_system?: string,  // The system of Classification type that corresponds to dictionary name
     *     classification_type_code?: string, // The code of Classification type that corresponds to dictionary value
     *     model_number?: string,  // Model number for the device
     *     name?: string,  // Device name
     *     name_type?: string,  // Device name type. Dictionary device_name_type
     *     medical_program_id?: string,
     *     is_active?: bool,
     *     page?: int,
     *     page_size?: int
     * }  $filters
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-devices/get-device-definitions-v2/get-device-definitions-v2
     */
    public function getMany(array $filters = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateDefinitions($response, true, isset($filters['medical_program_id'])));

        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            $filters
        );

        return $this->get(self::URL, $mergedQuery);
    }

    /**
     * Get a single device definition by its UUID.
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-devices/get-device-definitions-v2/get-device-definitions-v2
     */
    public function getById(string $id): PromiseInterface|EHealthResponse
    {
        $this->setValidator(function (EHealthResponse $response) use ($id): array {
            $definition = $this->validateDefinitions($response, false);
            if ($definition['id'] !== $id) {
                throw ValidationException::withMessages(['id' => 'Device definition detail returned a different resource.']);
            }

            return $definition;
        });

        return $this->get(self::URL . '/' . $id);
    }

    /** Validate the complete V2 catalog contract before quantity or eligibility decisions. */
    private function validateDefinitions(EHealthResponse $response, bool $collection, bool $withProgram = false): array
    {
        $data = $response->getData();
        $records = $collection ? $data : [$data];
        Validator::make(['records' => $records], [
            'records' => ['present', 'array', 'list'],
            'records.*' => ['required', 'array'],
            'records.*.id' => ['required', 'string', 'uuid'],
            'records.*.external_id' => ['sometimes', 'string'],
            'records.*.device_names' => ['present', 'array', 'list'],
            'records.*.device_names.*' => ['required', 'array'],
            'records.*.device_names.*.name' => ['required', 'string'],
            'records.*.device_names.*.type' => ['required', 'string', 'in:user_friendly,registered,patient_reported'],
            'records.*.description' => ['sometimes', 'string'],
            'records.*.manufacturer' => ['sometimes', 'array'],
            'records.*.manufacturer.name' => ['required_with:records.*.manufacturer', 'string'],
            'records.*.manufacturer.country' => ['required_with:records.*.manufacturer', 'string'],
            'records.*.model_number' => ['sometimes', 'string'],
            'records.*.part_number' => ['sometimes', 'string'],
            'records.*.note' => ['sometimes', 'string'],
            'records.*.packaging' => ['required', 'array'],
            'records.*.packaging.packaging_type' => ['required', 'string'],
            'records.*.packaging.packaging_count' => ['required', 'numeric'],
            'records.*.packaging.packaging_unit' => ['required', 'string'],
            'records.*.parent_id' => ['sometimes', 'string', 'uuid'],
            'records.*.program_devices' => [$withProgram ? 'present' : 'sometimes', 'array', 'list'],
            'records.*.program_devices.*' => ['required', 'array'],
            'records.*.program_devices.*.id' => ['required', 'string', 'uuid'],
            'records.*.program_devices.*.max_daily_count' => ['required', 'numeric'],
            'records.*.program_devices.*.start_date' => ['sometimes', 'date_format:Y-m-d'],
            'records.*.program_devices.*.end_date' => ['sometimes', 'date_format:Y-m-d'],
            'records.*.program_devices.*.device_request_allowed' => ['sometimes', 'boolean'],
            'records.*.program_devices.*.care_plan_activity_allowed' => ['sometimes', 'boolean'],
            'records.*.program_devices.*.registry_number' => ['sometimes', 'string'],
            'records.*.is_active' => ['required', 'boolean'],
            'records.*.inserted_at' => ['required', 'date'],
            'records.*.inserted_by' => ['required', 'string', 'uuid'],
            'records.*.updated_at' => ['required', 'date'],
            'records.*.updated_by' => ['required', 'string', 'uuid'],
            'records.*.classification_types' => ['present', 'array', 'list'],
            'records.*.classification_types.*' => ['required', 'array'],
            'records.*.classification_types.*.system' => ['required', 'string'],
            'records.*.classification_types.*.code' => ['required', 'string'],
            'records.*.properties' => ['sometimes', 'array', 'list'],
            'records.*.properties.*' => ['required', 'array'],
            'records.*.properties.*.type' => ['required', 'array'],
            'records.*.properties.*.type.system' => ['required', 'string'],
            'records.*.properties.*.type.code' => ['required', 'string'],
            'records.*.properties.*.value_integer' => ['sometimes', 'numeric'],
            'records.*.properties.*.value_decimal' => ['sometimes', 'numeric'],
            'records.*.properties.*.value_boolean' => ['sometimes', 'boolean'],
            'records.*.properties.*.value_string' => ['sometimes', 'string'],
            'records.*.properties.*.value_codeable_concept' => ['sometimes', 'array'],
            'records.*.properties.*.value_codeable_concept.coding' => ['required_with:records.*.properties.*.value_codeable_concept', 'array', 'list'],
            'records.*.properties.*.value_codeable_concept.coding.*.system' => ['required', 'string'],
            'records.*.properties.*.value_codeable_concept.coding.*.code' => ['required', 'string'],
            'records.*.properties.*.value_codeable_concept.text' => ['sometimes', 'string'],
        ])->validate();

        $valueFields = ['value_integer', 'value_decimal', 'value_boolean', 'value_string', 'value_codeable_concept'];
        foreach ($records as $index => $record) {
            foreach ($record['properties'] ?? [] as $propertyIndex => $property) {
                if (count(array_intersect_key($property, array_flip($valueFields))) !== 1) {
                    throw ValidationException::withMessages(["records.$index.properties.$propertyIndex" => 'Exactly one device property value is required.']);
                }
            }
        }
        if ($collection) {
            Validator::make($response->getPaging(), [
                'page_number' => ['required', 'integer', 'min:1'],
                'page_size' => ['required', 'integer', 'between:1,300'],
                'total_entries' => ['required', 'integer', 'min:0'],
                'total_pages' => ['required', 'integer', 'min:0'],
            ])->validate();
        }

        return $data;
    }
}
