<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Enums\DeviceDispense\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeviceDispense extends PatientApiBase
{
    /**
     * Get device dispenses by search params.
     *
     * Used both to fill the patient registry and to work out how much of a device request has already
     * been handed over, which is what tells a partial dispense apart from a full one (TV 3.22.1.1).
     *
     * @param  string  $patientId
     * @param  array{
     *     based_on?: string,
     *     encounter_id?: string,
     *     episode_id?: string,
     *     part_of?: string,
     *     performer?: string,
     *     location?: string,
     *     status?: string,
     *     device_definition_id?: string,
     *     when_handed_over_from?: string,
     *     when_handed_over_to?: string,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see REST API Get Device dispenses by search params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDeviceDispenses(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, ['inserted_at_from', 'inserted_at_to'])
        );

        return $this->get(self::URL . "/$patientId/device_dispenses", $mergedQuery);
    }

    /**
     * Get device dispense details by ID.
     *
     * @param  string  $patientId
     * @param  string  $deviceDispenseId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see REST API Get Device dispense details
     */
    public function getById(string $patientId, string $deviceDispenseId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDeviceDispense(...));

        return $this->get(self::URL . "/$patientId/device_dispenses/$deviceDispenseId");
    }

    /**
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDeviceDispense(EHealthResponse $response): array
    {
        return $this->runValidation([$this->replaceEHealthPropNames($response->getData())])[0];
    }

    /**
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDeviceDispenses(EHealthResponse $response): array
    {
        $replaced = [];

        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        return $this->runValidation($replaced);
    }

    /**
     * @param  array  $replacedItems
     * @return array
     */
    private function runValidation(array $replacedItems): array
    {
        $rules = collect($this->deviceDispenseValidationRules())
            ->mapWithKeys(static fn (array $rule, string $key): array => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replacedItems, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Device dispense validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for device dispenses returned by eHealth.
     *
     * @return array
     */
    protected function deviceDispenseValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['required', Rule::in(Status::values())],
                'when_handed_over' => ['required', 'date'],
                'ehealth_inserted_at' => ['nullable', 'date'],
                'ehealth_updated_at' => ['nullable', 'date']
            ],

            ValidationRuleBuilder::identifierRules('encounter', true),
            ValidationRuleBuilder::identifierRules('performer', true),
            ValidationRuleBuilder::identifierRules('location', true),
            ValidationRuleBuilder::identifierRules('part_of'),
            ValidationRuleBuilder::identifierCollectionRules('based_on'),
            ValidationRuleBuilder::identifierCollectionRules('supporting_info'),

            // The dispensed device is named either by its type or by its model, never by both (TV 3.22.2.1)
            [
                'details' => ['required', 'array'],
                'details.quantity' => ['required', 'integer', 'min:1'],
                'details.device_code' => ['nullable', 'array'],
                'details.device' => ['nullable', 'array']
            ],
            ValidationRuleBuilder::codeableConceptCollectionRules('details.device_code.*.code'),
            ValidationRuleBuilder::identifierCollectionRules('details.device.*.device_definition')
        );
    }
}
