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
     * Get device dispenses by search params in patient context.
     *
     * @param  string  $patientId
     * @param array{
     *     based_on?: string,
     *     location?: string,
     *     performer?: string,
     *     status?: string,
     *     when_handed_over_from?: string,
     *     when_handed_over_to?: string,
     *     part_of?: string,
     *     device?: string,
     *     performer_legal_entity?: string,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     page?: int,
     *     page_size?: int,
     *     encounter?: string,
     *     context_episode_id?: string,
     *     origin_episode_id?: string,
     *     device_code?: string,
     *     program?: string
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/device-dispenses/get-device-dispenses-by-search-params-in-patient-context/get-device-dispenses-by-search-params-in-patient-context
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDeviceDispenses(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, [
                'when_handed_over_from',
                'when_handed_over_to',
                'inserted_at_from',
                'inserted_at_to'
            ])
        );

        return $this->get(self::URL . "/$patientId/device_dispenses", $mergedQuery);
    }

    /**
     * Validate device dispenses collection from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDeviceDispenses(EHealthResponse $response): array
    {
        $replaced = [];

        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        return $this->runDeviceDispenseValidation($replaced);
    }

    /**
     * Apply device dispense validation rules to a pre-processed list of device dispense data.
     *
     * @param  array  $replacedItems
     * @return array
     */
    private function runDeviceDispenseValidation(array $replacedItems): array
    {
        $rules = collect($this->deviceDispenseValidationRules())
            ->mapWithKeys(static fn (array $rule, string $key): array => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replacedItems, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Device dispense validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for device dispenses from eHealth.
     *
     * @return array
     */
    protected function deviceDispenseValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['required', Rule::in(Status::values())],
                'when_handed_over' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
                'context_episode_id' => ['nullable', 'uuid'],
                'origin_episode_id' => ['nullable', 'uuid'],
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
                'ehealth_inserted_at' => ['nullable', 'date'],
                'ehealth_updated_at' => ['nullable', 'date'],

                'details' => ['required', 'array'],
                'details.*' => ['required', 'array'],
                'details.*.quantity' => ['required', 'array'],
                'details.*.quantity.value' => ['required', 'numeric'],
                'details.*.quantity.comparator' => ['nullable', 'string'],
                'details.*.quantity.unit' => ['nullable', 'string'],
                'details.*.quantity.system' => ['required', 'string'],
                'details.*.quantity.code' => ['required', 'string'],
                'details.*.sell_price' => ['nullable', 'numeric'],
                'details.*.reimbursement_amount' => ['nullable', 'numeric'],
                'details.*.discount_amount' => ['nullable', 'numeric']
            ],
            ValidationRuleBuilder::identifierRules('based_on'),
            ValidationRuleBuilder::identifierRules('performer'),
            ValidationRuleBuilder::identifierRules('location'),
            ValidationRuleBuilder::identifierRules('performer_legal_entity'),
            ValidationRuleBuilder::identifierRules('program'),
            ValidationRuleBuilder::identifierRules('part_of'),
            ValidationRuleBuilder::identifierRules('encounter'),
            ValidationRuleBuilder::codeableConceptRules('status_reason'),
            ValidationRuleBuilder::identifierRules('details.*.device'),
            ValidationRuleBuilder::codeableConceptRules('details.*.device_code'),
            ValidationRuleBuilder::identifierRules('details.*.program_device'),
            ValidationRuleBuilder::identifierCollectionRules('supporting_info')
        );
    }
}